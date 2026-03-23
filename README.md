# Sentinel

**Dashboard widget displaying the activation status and version of each Intergroup plugin.**

Sentinel adds a WordPress admin dashboard widget that monitors the health of the Intergroup plugin suite — Unity, Scrutiny, Integrity, Concordance, and Amber. It shows each plugin's version and active/inactive status at a glance, with automatic AJAX refresh when plugins are activated or deactivated.

**Version:** 1.2.0
**Requires:** WordPress 6.0+ · PHP 8.1+
**License:** MIT (Modified — see [License](#license))
**Author:** [The Bleeding Deacons](mailto:thebleedingdeacons@gmail.com)

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Requirements](#requirements)
- [Usage](#usage)
- [Architecture](#architecture)
- [Building for Production](#building-for-production)
- [License](#license)

---

## Features

- **Plugin status dashboard** — a single widget on the WordPress admin dashboard showing version and activation status for every monitored Intergroup plugin.
- **Automatic refresh** — the widget updates via AJAX whenever a monitored plugin fires its `loaded` action or is activated/deactivated, without requiring a full page reload.
- **No dependencies** — Sentinel has no plugin dependencies; it initialises on `plugins_loaded` at priority 5 and monitors whatever Intergroup plugins happen to be installed.
- **Lightweight** — minimal footprint; a single dashboard widget class with bundled CSS and JS.

---

## Installation

### From a .zip archive

1. Download or build the `sentinel.zip` archive.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**.
4. Activate the plugin.

### Manual installation

1. Clone or copy the `sentinel` directory into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin.

No configuration is required. The dashboard widget appears automatically once the plugin is active.

---

## Requirements

- **WordPress** 6.0+
- **PHP** 8.1+

Sentinel does not depend on any other Intergroup plugins — it simply monitors whichever ones are installed.

---

## Usage

Once activated, a **Plugin Status** widget appears on the WordPress admin dashboard. It lists the following plugins with their version and active/inactive badge:

- Unity
- Scrutiny
- Integrity
- Concordance
- Amber

The widget listens for the `unity/loaded`, `scrutiny_loaded`, `integrity_loaded`, `concordance/loaded`, and `amber/loaded` action hooks, as well as the core `activated_plugin` and `deactivated_plugin` hooks, and refreshes its display automatically.

---

## Architecture

Sentinel is intentionally minimal — a dashboard widget backed by a thin plugin bootstrap and a shared PSR-3 logger that writes to a dedicated database table (`wp_sentinel_log_entries`).

```
sentinel/
├── Sentinel.php                     # Plugin bootstrap, hook registration & WP-CLI commands
├── composer.json                    # Dependencies & PSR-4 autoloading
├── build.php                        # Cross-platform build/packaging script
├── uninstall.php                    # Cleanup on plugin deletion
├── assets/
│   ├── dashboard.css                # Widget styles
│   ├── dashboard.js                 # AJAX refresh logic
│   ├── log-viewer.css               # Log viewer page styles
│   └── log-viewer.js                # Log viewer AJAX refresh
└── src/
    ├── Plugin.php                   # Initialisation (admin-only)
    ├── Admin/
    │   ├── DashboardWidget.php      # Widget registration, rendering & AJAX endpoint
    │   ├── LogViewerPage.php        # Tools → Sentinel Logs admin page
    │   └── SettingsPage.php         # Settings → Sentinel options page
    └── Logger/
        ├── sentinel-logger.php     # Shared mu-plugin (deployed to mu-plugins/)
        ├── HasLogger.php            # Convenience trait for other plugins
        └── LoggerManager.php        # Deploy/remove lifecycle for the mu-plugin
```

### Logging

The shared logger (`sentinel-logger.php`) is deployed as a must-use plugin and stores all log entries in the `{prefix}_sentinel_log_entries` database table.

Log entries are buffered in memory during each request and flushed to the database in a single bulk INSERT — either when the buffer is full, at shutdown, or via a manual flush. This reduces per-entry DB overhead significantly.

Key configuration constants for `wp-config.php`:

| Constant | Default | Description |
|---|---|---|
| `SENTINEL_LOG_ENABLED` | `true` | Master on/off switch |
| `SENTINEL_LOG_LEVEL` | `debug` | Minimum severity to record |
| `SENTINEL_LOG_MAX_ROWS` | `10000` | Max rows kept (oldest pruned automatically) |
| `SENTINEL_LOG_BUFFER_SIZE` | `50` | Entries buffered in memory before auto-flushing to DB |

**WP-CLI commands:**

```bash
wp sentinel deploy-logger   # Deploy/update the mu-plugin
wp log tail                 # Show recent entries (--lines=50 --channel=unity --level=error)
wp log clear                # Truncate the log table
wp log table                # Print the full table name
wp log flush                # Flush the in-memory buffer to the database immediately
```

### Settings

Navigate to **Settings → Sentinel** to configure uninstall behaviour:

| Option | Default | Description |
|---|---|---|
| Drop log table on uninstall | **Off** | When checked, deleting Sentinel from the Plugins page will also drop the `sentinel_log_entries` table and all stored entries. When unchecked (the default), the table is preserved so other Bleeding Deacons plugins can continue to use it. |

---

## Building for Production

The included `build.php` script packages the plugin into a distributable `.zip` archive, stripping development files.

```bash
# Production build
php build.php build:production

# Development build (includes tests)
php build.php build:dev

# Clean the build directory
php build.php clean
```

You can override the version number with `--version=X.X` and add `--clean` to wipe the build directory before packaging.

---

## License

MIT License (Modified) — Copyright © 2025 The Bleeding Deacons.

This software is provided under the standard MIT license with one additional restriction: the licensee may not sell the Software, alone or as part of an aggregate software distribution containing the Software.

See [LICENSE](./LICENSE) for the full text.
