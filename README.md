# Database-Inspector
WP Database Inspector is a lightweight WordPress admin tool that inspects database and cache usage, highlights autoloaded options and transients, and provides safe, manual cleanup actions with a clear health overview.
# Database Inspector

**Database Inspector** is a lightweight, admin-only utility plugin that helps you inspect database and cache usage in WordPress and safely clean up clutter when needed.

This plugin focuses on **visibility first** — showing you where bloat exists before offering optional, manual cleanup actions.

---

## Features

- Database health gauge with clear visual feedback
- View total database size (when supported by hosting)
- Inspect autoloaded options that load on every page
- Identify expired and unused transients
- Detect post revisions, auto-drafts, spam, and trash
- Find orphaned post meta and comment meta
- Flush external object cache (when enabled)
- Optional **read-only mode** for audit-only environments
- Multisite-aware
- Shared-host safe
- **Admin-only** (no frontend impact)

All cleanup actions are manual, protected by WordPress nonces, and require explicit confirmation.

---

## Why This Plugin Exists

Many “optimizer” plugins aggressively delete data without showing what’s happening first.  
WordPress Database Inspector takes a safer approach:

> **Inspect → Understand → Decide → Clean**

You stay in control.

---

## Installation

1. Download or clone this repository.
2. Upload the plugin folder to `/wp-content/plugins/`, or install via the WordPress Plugins screen.
3. Activate the plugin from the **Plugins** menu.
4. Navigate to **Tools → DB Inspector**.

---

## Usage

Once activated, go to:

**Tools → DB Inspector**

You’ll see:
- A database health gauge
- A summary of database and cache usage
- Optional cleanup actions (disabled in read-only mode)
- A table of the largest autoloaded options

---

## Read-Only Mode

Cleanup actions can be disabled entirely using a filter:

```php
add_filter( 'wpdi_read_only', '__return_true' );
