<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use function Brain\Monkey\Functions\when;
use function Brain\Monkey\Actions\expectAdded;

use BleedingDeacons\WpMocks\TestCase;
use Brain\Monkey\Filters;
use Sentinel\Monitor\MonitorServices;

/**
 * Base case for the Monitor module's tests.
 *
 * Reach's base case, which these tests were written against, less the
 * session helpers that belonged to Reach's website. It adds two things to
 * wp-mocks' TestCase:
 *
 *  - hook capture: the wiring tests take a registered callback back out and
 *    invoke it. captureAction()/captureFilter() must be called *before* the
 *    code that registers the hook, and only named hooks are captured.
 *  - a wp_salt() alias over a mutable $salts array, because rotating a salt
 *    is a scenario under test.
 */
abstract class MonitorTestCase extends TestCase
{
    /** @var array<string, array<int, callable>> */
    protected array $addedActions = [];

    /** @var array<string, array<int, callable>> */
    protected array $addedFilters = [];

    /** @var array<string, string> */
    protected array $salts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->addedActions = [];
        $this->addedFilters = [];

        $this->salts = [
            'auth'      => 'test-auth-salt-' . str_repeat('x', 48),
            'logged_in' => 'test-login-salt-' . str_repeat('y', 48),
        ];

        // Safe to register here: nothing overrides wp_salt() per test —
        // they mutate $this->salts instead.
        when('wp_salt')->alias(
            fn (string $scheme = 'auth'): string => $this->salts[$scheme] ?? 'fallback-salt'
        );

        // The Unity-free services are built once and shared; a test that
        // swaps $wpdb must not inherit a repository over the last one.
        MonitorServices::reset();
    }

    /**
     * Start recording the callbacks hung on an action.
     *
     * zeroOrMoreTimes() because "nothing was registered here" is a real
     * assertion in these tests.
     */
    protected function captureAction(string $hook): void
    {
        expectAdded($hook)->zeroOrMoreTimes()->whenHappen(
            function (callable $callback, int $priority = 10, int $acceptedArgs = 1) use ($hook): void {
                $this->addedActions[$hook][] = $callback;
            }
        );
    }

    protected function captureFilter(string $hook): void
    {
        Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(
            function (callable $callback, int $priority = 10, int $acceptedArgs = 1) use ($hook): void {
                $this->addedFilters[$hook][] = $callback;
            }
        );
    }

    /** @param array<int, string> $hooks */
    protected function captureActions(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $this->captureAction($hook);
        }
    }

    /** @param array<int, string> $hooks */
    protected function captureFilters(array $hooks): void
    {
        foreach ($hooks as $hook) {
            $this->captureFilter($hook);
        }
    }

    /**
     * Answer every outbound HTTP call with the given responder, which takes
     * ($url, $args) and returns a WP HTTP response array or a WP_Error.
     */
    protected function stubHttp(callable $responder): void
    {
        foreach (['wp_remote_get', 'wp_remote_post', 'wp_remote_request'] as $function) {
            when($function)->alias($responder);
        }
    }

    /** @return array<int, callable> */
    protected function actionCallbacks(string $hook): array
    {
        return $this->addedActions[$hook] ?? [];
    }

    /** @return array<int, callable> */
    protected function filterCallbacks(string $hook): array
    {
        return $this->addedFilters[$hook] ?? [];
    }
}
