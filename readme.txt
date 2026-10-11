=== HAL Frontend Dashboard ===
Contributors: hossamadellaw
Tags: dashboard, frontend, administration
Requires at least: 7.0
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Carrier metadata and installation entry point for HAL Frontend Dashboard.

== Description ==

HAL Frontend Dashboard ships as a small WordPress Carrier with an independently managed, signed MU Runtime release. The runtime bootstrap registers the unauthenticated health-check loopback handler so release activation acknowledgement is reachable, and the unpacked carrier runtime is verified with the health wiring regression test before publishing.

== Installation ==

1. Download the official `hal-frontend-dashboard-1.0.4.zip` Carrier from the Releases section of the project repository (https://github.com/hossamadellaw/hal-frontend-dashboard).
2. In the WordPress admin dashboard, upload the ZIP under Plugins → Add New → Upload Plugin, then activate it.
3. Activation runs the installer, which provisions the signed Runtime release. The release publishes the ZIP SHA256 alongside the asset for verification before uploading.

Requires WordPress 7.0 or higher and PHP 8.3. Does not require the sodium extension: the Ed25519 signature capability comes from the sodium extension or the WordPress-bundled sodium_compat polyfill. Requires the PHP zip and zlib extensions (usually enabled in standard PHP), HTTPS, a writable mu-plugins directory, direct filesystem access, DISALLOW_FILE_MODS not enabled, and no conflicting legacy loader in mu-plugins. No site data is deleted and no hosting changes are required.

== Frequently Asked Questions ==

= Does the Carrier run updater or Runtime logic on ordinary requests? =

No. Its main file only defines identity and registers an activation callback. Installer code is loaded only if WordPress invokes that callback.

== Changelog ==

= 1.0.4 =
* Activation and package verification check the Ed25519 signature capability (the sodium verify function and its two constants) instead of the sodium extension, so installation works with the WordPress-bundled sodium_compat polyfill when the extension is absent; the preflight now also checks zlib and reports every failing check together behind closed failure codes.
* Activation failures display readable failure codes with their remedies through wp_die instead of raw PHP errors; unexpected errors collapse to a single code with the exception class name only.
* Version identity 1.0.4 across the plugin header, version constant, and stable tag.

= 1.0.3 =
* Register both health-check handlers (authenticated and unauthenticated AJAX) in the Runtime bootstrap, guarded against redeclaration, so the release activation acknowledgement loopback is reachable.
* Verify the unpacked carrier runtime with the health wiring regression test before publishing.
* Version identity 1.0.3 across the plugin header, version constant, and stable tag.

= 1.0.0 =
* Establish the canonical Carrier identity and metadata.
* Lock the Plugin Update Checker dependency for later Runtime packaging.
* Record the 49-file legacy source baseline without packaging local documentation or branding media.
