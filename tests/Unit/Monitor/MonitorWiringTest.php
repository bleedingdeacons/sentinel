<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use BleedingDeacons\WpMocks\WpState;
use Guardian\ProviderRegistry;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Sentinel\Monitor\Auth\DeviceTokenMinter;
use Sentinel\Monitor\Core\MonitorServiceProvider;
use Sentinel\Monitor\Core\Settings;
use Sentinel\Monitor\Devices\CurrentDevice;
use Sentinel\Monitor\Devices\DeviceRepository;
use Sentinel\Monitor\Devices\MemberLookupUnavailable;
use Sentinel\Monitor\Devices\ResponderGate;
use Sentinel\Monitor\MonitorServices;
use Sentinel\Monitor\Plugin;
use Sentinel\Tests\Monitor\Fixtures\InMemoryDeviceRepository;
use Sentinel\Tests\Monitor\Fixtures\MemberStub;
use Sentinel\Tests\Monitor\Fixtures\WpdbStub;
use Unity\Auth\Interfaces\PasswordCredentialRepository;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Testing\Doubles\FakeContainer;
use Unity\Testing\Doubles\InMemoryCommitteeRepository;
use Unity\Testing\Doubles\InMemoryGroupRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Monitor module's wiring: {@see MonitorServiceProvider}, which registers
 * every service into Unity's container, and {@see Plugin}, which drives that
 * registration and hangs the routes, filters and cron off WordPress hooks.
 *
 * Also the one behaviour that differs from Reach on purpose: a member lookup
 * that fails outright answers 503 and leaves the handset enrolled, where Reach
 * would have revoked it.
 */

function monitorContainer(?MemberRepository $members = null): FakeContainer
{
    return new FakeContainer([
        MemberRepository::class             => $members ?? new InMemoryMemberRepository([]),
        CommitteeRepository::class          => new InMemoryCommitteeRepository(),
        GroupRepository::class              => new InMemoryGroupRepository([]),
        PasswordCredentialRepository::class => new InMemoryPasswordCredentialRepository(),
        AuditLogger::class                  => new SpyAuditLogger(),
    ]);
}

function resetMonitorPlugin(): void
{
    $ref = new ReflectionClass(Plugin::class);
    $ref->getProperty('container')->setValue(null, null);
    $ref->getProperty('initialized')->setValue(null, false);
}

beforeEach(function () {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new WpdbStub();

    $this->captureActions(['rest_api_init', 'admin_menu', 'unity/member_deleted', Plugin::ALERT_PURGE_CRON_HOOK]);
    $this->captureFilters(['rest_post_dispatch', 'rest_dispatch_request']);

    WpState::$cron = [];
    WpState::$options = [];
    WpState::$restRoutes = [];
    WpState::$isAdmin = false;
    resetMonitorPlugin();
});

afterEach(function () {
    resetMonitorPlugin();
    WpState::$isAdmin = false;
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

// --- MonitorServiceProvider -------------------------------------------
test('service provider registers and resolves every service', function () {
    $container = monitorContainer();
    (new MonitorServiceProvider())->register($container);

    foreach ($container->registeredIds() as $id) {
        $this->assertIsObject($container->get($id), "service $id should resolve to an object");
    }

    $registry = $container->get(MonitorServiceProvider::PROVIDER_REGISTRY);
    $this->assertInstanceOf(ProviderRegistry::class, $registry);
    // Server-side providers only: the handset flow never accepted Apple.
    $this->assertEqualsCanonicalizing(['google', 'microsoft', 'facebook'], $registry->names());
});

test('the container and the unity free path share one of each service', function () {
    $container = monitorContainer();
    (new MonitorServiceProvider())->register($container);

    $this->assertSame(MonitorServices::settings(), $container->get(Settings::class));
    $this->assertSame(MonitorServices::devices(), $container->get(DeviceRepository::class));
});

test('nothing is registered under a Guardian class name', function () {
    // Unity's container is shared with Reach and Fellowship, and the last to
    // register a class name wins it.
    $container = monitorContainer();
    (new MonitorServiceProvider())->register($container);

    foreach ($container->registeredIds() as $id) {
        $this->assertStringStartsNotWith('Guardian\\', $id, "Monitor registered $id, which another plugin can overwrite");
    }
});

// --- Plugin::init -----------------------------------------------------
test('init registers every monitor route under sentinel v1, once', function () {
    $container = monitorContainer();
    Plugin::init($container);

    foreach ($this->actionCallbacks('rest_api_init') as $callback) {
        $callback();
    }

    $routes = array_map(fn (array $r): string => $r['namespace'] . $r['route'], WpState::$restRoutes);
    foreach (
        [
            'sentinel/v1/oauth/callback',
            'sentinel/v1/auth/device/start',
            'sentinel/v1/auth/device/exchange',
            'sentinel/v1/auth/device/password',
            'sentinel/v1/auth/device/push',
            'sentinel/v1/auth/device/session',
            'sentinel/v1/auth/device/signout',
            'sentinel/v1/alerts',
            'sentinel/v1/alerts/unreadable',
            'sentinel/v1/members',
            'sentinel/v1/committees',
        ] as $route
    ) {
        $this->assertContains($route, $routes);
    }
    foreach ($routes as $route) {
        $this->assertStringStartsWith('sentinel/v1/', $route);
    }

    $this->assertSame($container, Plugin::getContainer());
    $this->assertTrue(Plugin::isInitialized());

    $hooks = count($this->actionCallbacks('rest_api_init'));
    Plugin::init($container);
    $this->assertCount($hooks, $this->actionCallbacks('rest_api_init'));
});

test('init installs the schema and schedules the alert sweep', function () {
    Plugin::init(monitorContainer());

    $this->assertSame(\Sentinel\Monitor\Core\Schema::VERSION, (int) get_option(\Sentinel\Monitor\Core\Schema::OPTION, 0));
    $this->assertArrayHasKey(Plugin::ALERT_PURGE_CRON_HOOK, WpState::$cron);
    $this->assertCount(1, $this->actionCallbacks(Plugin::ALERT_PURGE_CRON_HOOK));
});

test('admin pages are only registered in admin', function () {
    Plugin::init(monitorContainer());
    $this->assertSame([], $this->actionCallbacks('admin_menu'));

    resetMonitorPlugin();
    WpState::$isAdmin = true;
    Plugin::init(monitorContainer());
    // Devices, Message and Settings.
    $this->assertCount(3, $this->actionCallbacks('admin_menu'));
});

test('the member deleted handler revokes through the repository init resolved', function () {
    Plugin::init(monitorContainer());
    $devices = Plugin::getContainer()->get(DeviceRepository::class);
    $this->assertSame(MonitorServices::devices(), $devices);

    $wpdb = $GLOBALS['wpdb'];
    $callback = $this->actionCallbacks('unity/member_deleted')[0];
    $callback(123, new MemberStub(personalEmail: ' Gone@Example.com '));

    $sql = implode("\n", $wpdb->queries) . json_encode($wpdb->updated);
    $this->assertStringContainsString('gone@example.com', $sql);
});

test('a member deleted without the member attached is ignored', function () {
    Plugin::init(monitorContainer());
    $wpdb = $GLOBALS['wpdb'];

    $this->actionCallbacks('unity/member_deleted')[0](123);

    $this->assertSame([], $wpdb->queries);
    $this->assertSame([], $wpdb->updated);
});

// --- rest filters -----------------------------------------------------
test('monitor routes are never cached and others are left alone', function () {
    Plugin::init(monitorContainer());
    $filter = $this->filterCallbacks('rest_post_dispatch')[0];

    $ours = new WP_REST_Response(['x' => 1]);
    $filter($ours, null, new WP_REST_Request([], '/sentinel/v1/alerts'));
    $this->assertStringContainsString('no-store', $ours->get_headers()['Cache-Control'] ?? '');

    $theirs = new WP_REST_Response(['x' => 1]);
    $filter($theirs, null, new WP_REST_Request([], '/reach/v1/nearest-members'));
    $this->assertArrayNotHasKey('Cache-Control', $theirs->get_headers());
});

test('a member lookup that fails outright answers 503', function () {
    $handler = ['callback' => function (): never {
        throw new MemberLookupUnavailable('Unity is having a bad minute');
    }];

    $result = Plugin::dispatchGuarded(null, new WP_REST_Request([], '/sentinel/v1/alerts'), '/sentinel/v1/alerts', $handler);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('sentinel_unavailable', $result->get_error_code());
    $this->assertSame(503, $result->get_error_data()['status']);
});

test('the guard runs the callback and hands back what it returns', function () {
    $response = new WP_REST_Response(['ok' => true]);
    $handler = ['callback' => fn (WP_REST_Request $request): WP_REST_Response => $response];

    $this->assertSame(
        $response,
        Plugin::dispatchGuarded(null, new WP_REST_Request([], '/sentinel/v1/alerts'), '/sentinel/v1/alerts', $handler),
    );
});

test('the guard leaves other plugins routes and earlier answers alone', function () {
    $handler = ['callback' => function (): never {
        throw new RuntimeException('must not be called');
    }];

    // Another namespace: WordPress calls the callback itself.
    $this->assertNull(Plugin::dispatchGuarded(null, new WP_REST_Request([], '/reach/v1/x'), '/reach/v1/x', $handler));

    // Something earlier already answered.
    $earlier = new WP_REST_Response(['earlier' => true]);
    $this->assertSame(
        $earlier,
        Plugin::dispatchGuarded($earlier, new WP_REST_Request([], '/sentinel/v1/alerts'), '/sentinel/v1/alerts', $handler),
    );
});

test('a failed lookup leaves the handset enrolled', function () {
    // Reach revoked on any gate failure. A repository that throws has said
    // nothing about the member, and revoking on it would sign every handset
    // out whenever Unity's storage fails.
    $devices = new InMemoryDeviceRepository();
    $minter = new DeviceTokenMinter();
    $token = $minter->mint();
    $device = $devices->create($minter->hash($token), 'r@example.com', 7, 'Pixel', 'android', '', '', 1000, base64_encode(random_bytes(32)));

    $members = Mockery::mock(MemberRepository::class);
    $members->shouldReceive('findByEmail')->andThrow(new RuntimeException('database has gone away'));

    $current = new CurrentDevice($devices, $minter, new ResponderGate($members));
    $request = new WP_REST_Request();
    $request->set_header('authorization', 'Bearer ' . $token);

    try {
        $current->fromRequest($request, 2000);
        $this->fail('expected MemberLookupUnavailable');
    } catch (MemberLookupUnavailable) {
        // expected
    }

    $this->assertNull($devices->findById($device->id)?->revokedAt);
});

test('a member who is refused still has the handset revoked, as before', function () {
    $devices = new InMemoryDeviceRepository();
    $minter = new DeviceTokenMinter();
    $token = $minter->mint();
    $device = $devices->create($minter->hash($token), 'gone@example.com', 7, 'Pixel', 'android', '', '', 1000, base64_encode(random_bytes(32)));

    $current = new CurrentDevice($devices, $minter, new ResponderGate(new InMemoryMemberRepository([])));
    $request = new WP_REST_Request();
    $request->set_header('authorization', 'Bearer ' . $token);

    $this->assertNull($current->fromRequest($request, 2000));
    $this->assertNotNull($devices->findById($device->id)?->revokedAt);
});

// --- stand down -------------------------------------------------------
test('the module stands down without scrutiny', function () {
    if (function_exists('scrutiny')) {
        $this->markTestSkipped('scrutiny() is defined in this process');
    }

    $this->assertStringContainsString('Scrutiny', (string) Plugin::standDownReason());
});
