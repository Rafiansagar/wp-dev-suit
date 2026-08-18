# WP Dev Suit

Developer tooling for this WordPress site.

WP Dev Suit is organized as a small suite: the main plugin discovers modules from
`modules/`, registers one WordPress admin menu, and installs an early must-use
loader for tools that need to run before normal plugins.

The current module is **Site Analytics**, a request-level performance profiler
for finding where WordPress requests spend time.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- Administrator access in WordPress
- A writable `wp-content/mu-plugins` directory for early boot profiling

## Installation

1. Put this directory at:

   ```text
   wp-content/plugins/wp-dev-suit
   ```

2. Activate **WP Dev Suit** from **Plugins** in WordPress admin.

3. On activation, the plugin copies its must-use loader to:

   ```text
   wp-content/mu-plugins/000-wp-dev-suit.php
   ```

   The filename starts with `000-` so it loads before other MU plugins where
   possible.

4. Open **WP Dev Suit -> Site Analytics** in the WordPress admin.

If the loader cannot be installed, the plugin shows an admin notice with the
target path. Fix the `mu-plugins` directory permissions and reload an admin page;
the plugin attempts to self-heal the loader on `admin_init`.

## Site Analytics

Site Analytics records request snapshots and turns them into practical
performance views:

- **Plugins**: average cost per plugin, including plugin include time and `init`
  callback time.
- **Boot phases**: average time, files, and memory used between WordPress boot
  milestones.
- **Requests**: recent individual requests, newest first, with request type,
  total time, query count, memory, and the slowest `init` callbacks.
- **Settings**: recording toggle, `init` callback profiling toggle, request
  type filters, log length, and sample rate.

The module can record these request types:

- Front end
- Admin
- AJAX
- REST
- Cron

WP-CLI requests can be classified internally, but they are not selectable in the
admin settings by default.

## What Gets Stored

Snapshots are stored in a non-autoloaded WordPress option:

```text
wpds_log
```

Settings are stored in:

```text
wpds_settings
```

Each snapshot can include:

- Request time, type, method, URL, AJAX action, or REST route
- Total request duration
- Peak memory usage
- Included file count
- Query count
- Query time when `SAVEQUERIES` is enabled
- Boot phase timings
- Per-plugin include cost
- Aggregated `init` callback time by owner
- The slowest `init` callbacks for the request

The log is capped by the configured log length. The default is 100 requests, with
an allowed range of 10 to 500. Recording is disabled by default.

## Performance Notes

This is a development/profiling tool. Keep recording disabled when you are not
actively profiling.

The profiler stores snapshots in one option row. A full log is rewritten each
time an eligible request is recorded. The admin UI notes that entries are roughly
5 KB each, so a 100-entry log can mean about a 500 KB option write per recorded
request.

Timing every callback on the `init` hook gives better attribution, but it also
wraps callbacks and adds a small amount of overhead. Disable **Time every
callback on the init hook** when you only need broader request and phase timing.

## Directory Structure

```text
wp-dev-suit.php
includes/
  abstracts/class-module.php
  class-menu.php
  class-modules.php
mu-loader/
  wp-dev-suit-mu.php
modules/
  site-analytics/
    module.php
    mu.php
    includes/
      class-admin.php
      class-collector.php
      class-store.php
    assets/
      admin.css
```

## Adding A Module

Modules are discovered from the filesystem. To add one:

1. Create a new directory under `modules/`.
2. Add a `module.php` file.
3. Return an object that extends `WP_Dev_Suit\Module`.
4. Implement the module methods such as `id()`, `title()`, `boot()`, and, if it
   has an admin page, `has_screen()` and `render_screen()`.
5. Add a `mu.php` file only if the module needs to run from the must-use loader
   before normal plugins load.

The suite registers one top-level **WP Dev Suit** menu and adds a submenu for
each module that exposes an admin screen.

## Activation And Deactivation Behavior

On activation, WP Dev Suit:

- Migrates legacy `site_analytics_*` options to `wpds_*` option names.
- Removes the old `000-site-analytics.php` MU loader if present.
- Installs `000-wp-dev-suit.php` into `wp-content/mu-plugins`.

On deactivation, WP Dev Suit removes its generated MU loader. Stored settings and
logs are not deleted by deactivation.

## Troubleshooting

**No data is being recorded**

- Open **WP Dev Suit -> Site Analytics -> Settings** and enable recording.
- Make sure the request type you are testing is selected.
- Confirm `wp-content/mu-plugins/000-wp-dev-suit.php` exists.
- Check for an admin notice about the loader path being unwritable.
- Load a few non-WP-Dev-Suit pages, then return to the dashboard. The profiler
  intentionally avoids recording its own admin screens.

**Database query time is always zero**

Enable `SAVEQUERIES` in WordPress if you need query duration totals. Query count
is available without it, but query time is not.

**The plugin list shows little load time**

Many plugins do most of their work on `init`, not during file include. Enable
`Time every callback on the init hook` to attribute that work to plugin, theme,
MU plugin, WordPress core, or unknown owners.

## License

GPL-2.0-or-later.
