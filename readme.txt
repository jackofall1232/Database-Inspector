=== SAC Database Inspector ===
Contributors: jackofall1232
Tags: database cleaner, transients, cache, performance, admin tools
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect database usage, autoloaded options, transients, and safely clean database clutter from a single admin dashboard.

== Description ==

**SAC Database Inspector** is a lightweight, admin-only utility designed to help site administrators understand what is stored in their site’s database and cache — and clean it up safely when needed.

Unlike aggressive “optimizer” plugins, Database Inspector focuses on **visibility first**, showing you where bloat exists before offering optional, manual cleanup actions.

### Key features:
* Database health gauge with clear visual feedback
* View total database size (when supported by hosting)
* Inspect autoloaded options that load on every page
* Identify expired and unused transients
* Detect post revisions, auto-drafts, spam, and trash
* Find orphaned post meta and comment meta
* Flush external object cache (when enabled)
* Optional read-only mode via filter for audit-only environments
* Multisite-aware and shared-host safe
* No frontend impact — admin-only

All cleanup actions require explicit confirmation and are protected by nonces and capability checks.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via the Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Navigate to **Tools → DB Inspector** to view database statistics.

== Frequently Asked Questions ==

= Is this plugin safe to use? =
Yes. All cleanup actions are manual, protected, and clearly labeled. No background jobs or automatic deletions are performed.

= Does this work on shared hosting? =
Yes. If database size information is restricted by your host, the plugin degrades gracefully without errors.

= Does this support multisite? =
Yes. Multisite installations are supported, including site transients and object cache detection.

= Does this affect frontend performance? =
No. The plugin loads only in the admin area.

= Can I disable cleanup actions? =
Yes. Developers can enable read-only mode using the `wpdi_read_only` filter.

== Screenshots ==

1. Database health gauge and overview
2. Cleanup actions dashboard
3. Top autoloaded options table

== Changelog ==

= 0.1.1 =
* Naming and compliance updates
* Plugin Check fixes
* No functional changes

= 0.1.0 =
* Initial release
* Database inspection dashboard
* Safe manual cleanup actions
* Read-only mode support
* Multisite compatibility

== Upgrade Notice ==

= 0.1.2 =
Compliance update. No functional changes.

== Developer Notes ==

This plugin follows coding standards and avoids aggressive optimization tactics. It is intended as a transparent inspection and maintenance tool, not an automatic optimizer.

Filters and actions are provided for extensibility.
