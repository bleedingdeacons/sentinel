=== Sentinel ===
Contributors: thebleedingdeacons
Tags: dashboard, monitoring, status, integrity, api
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.3.1
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Dashboard displaying the Intergroup plugin(s) status.

== Description ==

**Dashboard widget displaying the activation status and version of each Intergroup plugin.**

Sentinel adds a WordPress admin dashboard widget that monitors the health of the Intergroup plugin suite — Unity, Scrutiny, Integrity, Concordance, and Amber. It shows each plugin's version and active/inactive status at a glance, with automatic AJAX refresh when plugins are activated or deactivated.

**Key features:**

* **Plugin status dashboard** — a single widget on the WordPress admin dashboard showing version and activation status for every monitored Intergroup plugin.
* **Automatic refresh** — the widget updates via AJAX whenever a monitored plugin fires its `loaded` action or is activated/deactivated, without requiring a full page reload.
* **No dependencies** — Sentinel has no plugin dependencies; it initialises on `plugins_loaded` at priority 5 and monitors whatever Intergroup plugins happen to be installed.
* **Lightweight** — minimal footprint; a single dashboard widget class with bundled CSS and JS.

== Installation ==

= From a .zip archive =

1. Download or build the `sentinel.zip` archive.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**.
4. Activate the plugin.

= Manual installation =

1. Clone or copy the `sentinel` directory into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin.

No configuration is required. The dashboard widget appears automatically once the plugin is active.

== Frequently Asked Questions ==

= Where can I get support? =

Contact The Bleeding Deacons at thebleedingdeacons@gmail.com.

== Screenshots ==

1. Plugin admin settings page.

== Changelog ==

= 1.0.4 =
* Current stable release.

== Upgrade Notice ==

= 1.0.4 =
Latest stable release of Sentinel.

== Architecture ==

Sentinel is intentionally minimal — a single dashboard widget backed by a thin plugin bootstrap.

```
sentinel/
├── Sentinel.php                     # Plugin bootstrap & hook registration
├── composer.json                    # Dependencies & PSR-4 autoloading
├── build.php                        # Cross-platform build/packaging script
├── assets/
│   ├── dashboard.css                # Widget styles
│   └── dashboard.js                 # AJAX refresh logic
└── src/
    ├── Plugin.php                   # Initialisation (admin-only)
    └── Admin/
        └── DashboardWidget.php      # Widget registration, rendering & AJAX endpoint
```

The `DashboardWidget` class maintains a static registry of monitored plugins (file paths and labels) and reads their status via `is_plugin_active()` and `get_plugin_data()`.

== Requirements ==

* **WordPress** 6.0+
* **PHP** 8.1+

Sentinel does not depend on any other Intergroup plugins — it simply monitors whichever ones are installed.

== Usage ==

Once activated, a **Plugin Status** widget appears on the WordPress admin dashboard. It lists the following plugins with their version and active/inactive badge:

* Unity
* Scrutiny
* Integrity
* Concordance
* Amber

The widget listens for the `unity/loaded`, `scrutiny_loaded`, `integrity_loaded`, `concordance/loaded`, and `amber/loaded` action hooks, as well as the core `activated_plugin` and `deactivated_plugin` hooks, and refreshes its display automatically.
