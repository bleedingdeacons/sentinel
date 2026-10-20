<?php

declare(strict_types=1);

namespace Sentinel\Monitor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Guardian\Firebase\FirebaseIdTokenVerifier;
use Guardian\Jwt\JwtVerifier;
use Guardian\ProviderRegistry;
use Guardian\Providers\FacebookProvider;
use Guardian\Providers\GoogleProvider;
use Guardian\Providers\MicrosoftProvider;
use Psr\Container\ContainerInterface;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Sentinel\Monitor\Admin\DevicesPage;
use Sentinel\Monitor\Admin\SendMessagePage;
use Sentinel\Monitor\Admin\SettingsPage;
use Sentinel\Monitor\Alerts\AcknowledgementNotifier;
use Sentinel\Monitor\Alerts\AlertApi;
use Sentinel\Monitor\Alerts\AlertContactRepository;
use Sentinel\Monitor\Alerts\AlertDispatcher;
use Sentinel\Monitor\Alerts\AlertReplyRepository;
use Sentinel\Monitor\Alerts\AlertRepository;
use Sentinel\Monitor\Alerts\Fcm\FcmClient;
use Sentinel\Monitor\Alerts\Fcm\ServiceAccount;
use Sentinel\Monitor\Alerts\RecipientResolver;
use Sentinel\Monitor\Alerts\Transport\FcmTransport;
use Sentinel\Monitor\Auth\DeviceCodeStore;
use Sentinel\Monitor\Auth\DeviceRedirectValidator;
use Sentinel\Monitor\Auth\DeviceTokenMinter;
use Sentinel\Monitor\Auth\FirebaseSignIn;
use Sentinel\Monitor\Auth\PasswordAuthenticator;
use Sentinel\Monitor\Auth\StateStore;
use Sentinel\Monitor\Devices\CurrentDevice;
use Sentinel\Monitor\Devices\DeviceRepository;
use Sentinel\Monitor\Devices\ResponderGate;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSender;
use Sentinel\Monitor\ErrorAlerts\ErrorAlertSettings;
use Sentinel\Monitor\MonitorServices;
use Sentinel\Monitor\Rest\AlertController;
use Sentinel\Monitor\Rest\DeviceAuthController;
use Sentinel\Monitor\Rest\DirectoryController;
use Sentinel\Monitor\Rest\OAuthCallbackController;
use Unity\Auth\Interfaces\PasswordCredentialRepository;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Register the Monitor module's services into Unity's container.
 *
 * The Monitor block of Reach's service provider, moved here unchanged in
 * shape. The services that need nothing from Unity are built by
 * {@see MonitorServices} and registered as those same instances, so the
 * container and the Unity-free path share one of each.
 *
 * The provider registry and the JWT verifier are registered under
 * Monitor's own ids, never under Guardian's class names: this is Unity's
 * container, shared with Reach and Fellowship, which register their own
 * registries, and the last to register a class name wins it.
 */
final class MonitorServiceProvider
{
    /** Monitor's Guardian provider registry, read from Monitor's Settings. */
    public const PROVIDER_REGISTRY = 'sentinel.monitor.guardian.providers';

    /** Monitor's Guardian ID-token verifier, logging to Monitor's channel. */
    public const JWT_VERIFIER = 'sentinel.monitor.guardian.jwt';

    public function register(Container $container): void
    {
        // ── Unity-free: shared with MonitorServices ──
        $container->register(Settings::class, fn() => MonitorServices::settings());
        $container->register(DeviceRepository::class, fn() => MonitorServices::devices());
        $container->register(AlertRepository::class, fn() => MonitorServices::alerts());
        $container->register(AlertContactRepository::class, fn() => MonitorServices::alertContacts());
        $container->register(AlertReplyRepository::class, fn() => MonitorServices::alertReplies());
        $container->register(FcmClient::class, fn() => MonitorServices::fcmClient());
        $container->register(FcmTransport::class, fn() => MonitorServices::fcmTransport());
        $container->register(ErrorAlertSettings::class, fn() => MonitorServices::errorAlertSettings());
        $container->register(ErrorAlertSender::class, fn() => MonitorServices::errorAlertSender());

        $container->register(RateLimiter::class, fn() => new RateLimiter());
        $container->register(StateStore::class, fn() => new StateStore());
        $container->register(DeviceTokenMinter::class, fn() => new DeviceTokenMinter());
        $container->register(DeviceCodeStore::class, fn() => new DeviceCodeStore());
        $container->register(DeviceRedirectValidator::class, fn() => new DeviceRedirectValidator());
        $container->register(self::JWT_VERIFIER, fn() => new JwtVerifier(UserAgent::plugin(), 'monitor'));

        // ── Needs Unity ──
        //
        // The gate is registered first and shared: it is the single
        // answer to "may this person use Monitor?", consulted by both
        // enrolment paths, by every authenticated request, and by the
        // dispatcher deciding who an alert may reach.
        $container->register(ResponderGate::class, fn(ContainerInterface $c) => new ResponderGate(
            $c->get(MemberRepository::class),
        ));
        $container->register(CurrentDevice::class, fn(ContainerInterface $c) => new CurrentDevice(
            $c->get(DeviceRepository::class),
            $c->get(DeviceTokenMinter::class),
            $c->get(ResponderGate::class),
        ));

        $container->register(RecipientResolver::class, fn(ContainerInterface $c) => new RecipientResolver(
            $c->get(DeviceRepository::class),
            $c->get(MemberRepository::class),
            $c->get(CommitteeRepository::class),
        ));

        // Transports are passed as a list so adding one is a change here
        // and nowhere else.
        $container->register(AlertDispatcher::class, fn(ContainerInterface $c) => new AlertDispatcher(
            $c->get(AlertRepository::class),
            $c->get(AlertContactRepository::class),
            $c->get(DeviceRepository::class),
            $c->get(ResponderGate::class),
            [$c->get(FcmTransport::class)],
        ));
        $container->register(AlertApi::class, fn(ContainerInterface $c) => new AlertApi(
            $c->get(AlertDispatcher::class),
        ));
        $container->register(AcknowledgementNotifier::class, fn(ContainerInterface $c) => new AcknowledgementNotifier(
            $c->get(AlertRepository::class),
            $c->get(AlertDispatcher::class),
        ));

        // Server-side providers only: the handset flow has never accepted
        // Apple, which signs in client-side.
        $container->register(self::PROVIDER_REGISTRY, function (ContainerInterface $c) {
            $settings = $c->get(Settings::class);
            $verifier = $c->get(self::JWT_VERIFIER);
            $registry = new ProviderRegistry();
            $registry->register(new GoogleProvider($settings, $verifier, UserAgent::plugin()));
            $registry->register(new MicrosoftProvider($settings, $verifier, UserAgent::plugin()));
            $registry->register(new FacebookProvider($settings, $verifier, UserAgent::plugin()));
            return $registry;
        });

        // Native Google sign-in, pinned to the project Sentinel already
        // pushes through. See FirebaseSignIn on why that is the right one.
        $container->register(FirebaseSignIn::class, function (ContainerInterface $c) {
            $settings = $c->get(Settings::class);
            return new FirebaseSignIn(
                new FirebaseIdTokenVerifier($c->get(self::JWT_VERIFIER), 'monitor'),
                static fn(): string => ServiceAccount::fromJson($settings->getFcmServiceAccount())?->projectId ?? '',
            );
        });

        // The credential store is Unity's and is deliberately not
        // registered here: tsml-for-unity supplies it, and a member has
        // one password whichever plugin they sign in through.
        $container->register(PasswordAuthenticator::class, fn(ContainerInterface $c) => new PasswordAuthenticator(
            $c->get(PasswordCredentialRepository::class),
        ));

        // REST controllers.
        $container->register(OAuthCallbackController::class, fn(ContainerInterface $c) => new OAuthCallbackController(
            $c->get(self::PROVIDER_REGISTRY),
            $c->get(StateStore::class),
            $c->get(MemberRepository::class),
            $c->get(DeviceCodeStore::class),
            $c->get(DeviceRedirectValidator::class),
            $c->get(ResponderGate::class),
            $c->get(RateLimiter::class),
        ));

        $container->register(DeviceAuthController::class, fn(ContainerInterface $c) => new DeviceAuthController(
            $c->get(DeviceRepository::class),
            $c->get(DeviceTokenMinter::class),
            $c->get(DeviceCodeStore::class),
            $c->get(DeviceRedirectValidator::class),
            $c->get(ResponderGate::class),
            $c->get(CurrentDevice::class),
            $c->get(PasswordAuthenticator::class),
            $c->get(self::PROVIDER_REGISTRY),
            $c->get(StateStore::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLogger::class),
            $c->get(FirebaseSignIn::class),
        ));

        $container->register(AlertController::class, fn(ContainerInterface $c) => new AlertController(
            $c->get(AlertRepository::class),
            $c->get(AlertContactRepository::class),
            $c->get(CurrentDevice::class),
            $c->get(AuditLogger::class),
            $c->get(DeviceRepository::class),
            $c->get(AcknowledgementNotifier::class),
            $c->get(AlertReplyRepository::class),
            $c->get(RecipientResolver::class),
            $c->get(AlertApi::class),
            $c->get(RateLimiter::class),
        ));

        $container->register(DirectoryController::class, fn(ContainerInterface $c) => new DirectoryController(
            $c->get(CurrentDevice::class),
            $c->get(MemberRepository::class),
            $c->get(GroupRepository::class),
            $c->get(RecipientResolver::class),
            $c->get(AuditLogger::class),
            $c->get(RateLimiter::class),
        ));

        // Admin.
        $container->register(SettingsPage::class, fn(ContainerInterface $c) => new SettingsPage(
            $c->get(Settings::class),
            $c->get(ErrorAlertSettings::class),
            $c->get(ErrorAlertSender::class),
        ));

        $container->register(DevicesPage::class, fn(ContainerInterface $c) => new DevicesPage(
            $c->get(DeviceRepository::class),
            $c->get(AlertRepository::class),
            $c->get(AlertApi::class),
            $c->get(MemberRepository::class),
            $c->get(AlertReplyRepository::class),
        ));

        $container->register(SendMessagePage::class, fn(ContainerInterface $c) => new SendMessagePage(
            $c->get(DeviceRepository::class),
            $c->get(AlertApi::class),
            $c->get(MemberRepository::class),
            $c->get(RecipientResolver::class),
        ));
    }
}
