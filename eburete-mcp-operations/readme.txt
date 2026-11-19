=== Eburete MCP Operations ===
Contributors: eburete
Tags: mcp, litespeed, diagnostics
Requires at least: 7.1
Requires PHP: 8.1
Stable tag: 0.5.1
License: GPL-2.0-or-later

Restricted operational abilities for the official WordPress MCP Adapter.

== What it adds ==

* Read-only site diagnostics for an authenticated administrator.
* Read-only LiteSpeed Cache status.
* A narrowly scoped LiteSpeed page-cache purge.
* Updates to allowlisted response-time settings: page cache, browser/mobile cache,
  guest optimization, CSS/JS/HTML minification, asynchronous CSS, font display,
  lazy loading, automatic image optimization, and WebP delivery.

== Security model ==

Every ability requires the current MCP-authenticated WordPress user to have the
`manage_options` capability. It uses LiteSpeed's supported configuration API,
not direct option writes. The plugin does not expose passwords, API keys, CDN or
object-cache credentials, payment configuration, user management, deletions,
plugin activation, or generic WordPress option editing.

== Requirements ==

* WordPress 7.1 or newer with the Abilities API.
* The official MCP Adapter plugin active.
* LiteSpeed Cache active for the LiteSpeed abilities.

== Installation ==

1. Upload the `eburete-mcp-operations` directory to `/wp-content/plugins/`.
2. Activate **Eburete MCP Operations** from Plugins.
3. Reconnect or refresh the MCP connection.
4. Use `mcp-adapter/discover-abilities`; the `eburete-operations/*`
   abilities should appear.

== Notes ==

The LiteSpeed purge uses `LiteSpeed\Purge::purge_all_lscache` (LiteSpeed 7.7+).
Earlier releases of this plugin used a broader purge action; 0.3.0 restricts it
to page cache, matching the ability's name.
No cache is purged on installation or activation.

== Performance diagnostics (0.3.0) ==

`eburete-operations/performance-health` reports effective PHP limits, OPcache
availability, persistent object cache, autoload bytes and largest option names
(never values), WP-Cron overdue events and Action Scheduler status counts.

`eburete-operations/profile-public-page` accepts post_id=0 for the homepage or
an existing published, unprotected post/page/product ID. It runs one anonymous
HTTPS GET to the same site, with no cookies, redirects or arbitrary URL input.
A random one-use ticket expires after 90 seconds; requests are rate-limited to
one per minute. Cache bypass applies only to that diagnostic request. Reports
expire after three minutes and are removed when retrieved. No persistent
profiling, queue execution, background job or public report endpoint is added.

Reports include PHP phase checkpoints, database timing grouped by callers,
WordPress HTTP API timing by hostname, and memory peak. They never include SQL,
HTTP paths/query strings, bodies, headers, job arguments or credentials.
Profiling begins at plugins_loaded; early bootstrap and earlier plugin loading
are only represented by elapsed time, not attributed to individual plugins.
Database logging adds overhead and may be unavailable if SAVEQUERIES is already
defined as false. Native cURL calls outside the WordPress HTTP API are not timed.
The report is not an exclusive CPU profile or a full end-to-end browser test.

No new automatic fix is executed. Existing performance setting abilities remain
available; changes should be selected from measured evidence and checked after
saving. Server tuning, PHP workers and Redis provisioning require hosting access.

Upgrade through Plugins > Add New > Upload Plugin with the new ZIP, then choose
Replace current with uploaded. Keep MCP Adapter active. This plugin does not
change site configuration on activation.

== Self-update through MCP (0.4.0) ==

After installing 0.4.0 once, check-self-update reads the fixed public manifest at
DeathQueenX/wp-plugins/main/releases/eburete-mcp-operations.json. install-self-update
requires the exact version and SHA-256 returned by the check. It can replace only
this plugin, only with a newer version, using WordPress Plugin_Upgrader and its
temporary backup mechanism. It requires manage_options, update_plugins, permitted
file modifications, ZipArchive and direct filesystem access (single-site only).
No automatic update job or generic plugin-install ability is added.

Packages are downloaded over verified HTTPS from the fixed repository, limited
to 5 MB, checked against SHA-256, and inspected for wrong root, traversal,
duplicates, symlinks, version mismatch and expanded size before installation.
SHA-256 is an integrity check, not an independent digital signature. Anyone with
write access to that repository can publish executable PHP updates. Protect that
repository accordingly. No GitHub credential needs to be stored on the site.

Installation still requires a final manual replacement once if the installed
version predates the updater. The updater does not grant itself permissions.
WordPress rollback during file-install failure is not a guarantee against bugs
in a future release; keep a full backup and verify the next request after updates.
