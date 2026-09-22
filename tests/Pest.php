<?php

declare(strict_types=1);

// Pest configuration.
//
// The suite has three tiers of base case, and this file reproduces which test
// ran on which:
//
//   - Sentinel\Tests\AdminTestCase — the admin screens and the plugin wiring.
//     It stands up the Settings API no-ops, the is_plugin_active() and
//     get_plugin_data() stubs, and the on-disk fixtures (wp-config.php, fixture
//     plugins) those screens read and rewrite; its helpers are called from the
//     tests as $this->makePlugin(), $this->writeWpConfig(), $this->capture()...
//   - Sentinel\Tests\TestCase — the logger tests. It loads the real
//     sentinel-logger.php and seeds the schema-version option so
//     Sentinel_Logger::instance() never reaches dbDelta.
//   - wp-mocks' own TestCase — LoggerManagerTest, which is purely filesystem-
//     shaped and has no use for the logger singleton.
//
// LogLevelTest touches none of WordPress and stays on Pest's default, plain
// PHPUnit.
//
// So this list is load-bearing: a test that reaches Brain Monkey from a file
// not named here finds none of its functions defined.
//
// Two files stay PHPUnit classes rather than Pest closures, because their
// tests define UNITY_KILL — which cannot be undone once defined — and so
// must run in a separate process, which Pest refuses outright. See
// Unit/Admin/StatusDashboardKilledTest.php and
// Unit/Admin/UnityControlPageKilledTest.php. Pest still runs them.

use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;
use Sentinel\Tests\AdminTestCase;
use Sentinel\Tests\TestCase;

pest()->extend(AdminTestCase::class)->in(
    'Unit/Admin',
    'Unit/PluginTest.php',
);

pest()->extend(TestCase::class)->in(
    'Unit/Logger/HasLoggerTest.php',
    'Unit/Logger/LogChannelTest.php',
    'Unit/Logger/LoggerManagerDeploymentTest.php',
    'Unit/Logger/SentinelLoggerEngineTest.php',
    'Unit/Logger/SentinelLoggerTest.php',
);

pest()->extend(WpMocksTestCase::class)->in(
    'Unit/Logger/LoggerManagerTest.php',
);
