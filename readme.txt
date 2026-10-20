=== HAL Frontend Dashboard ===
Contributors: hossamadellaw
Tags: dashboard, frontend, administration
Requires at least: 7.0
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Carrier metadata and installation entry point for HAL Frontend Dashboard.

== Description ==

HAL Frontend Dashboard ships as a small WordPress Carrier with an independently managed, signed MU Runtime release. The runtime bootstrap registers the unauthenticated health-check loopback handler so release activation acknowledgement is reachable, and the unpacked carrier runtime is verified with the health wiring regression test before publishing.

== Installation ==

1. Download the official `hal-frontend-dashboard-1.0.7.zip` Carrier from the Releases section of the project repository (https://github.com/hossamadellaw/hal-frontend-dashboard).
2. In the WordPress admin dashboard, upload the ZIP under Plugins → Add New → Upload Plugin, then activate it.
3. Activation runs the installer, which provisions the signed Runtime release. The release publishes the ZIP SHA256 alongside the asset for verification before uploading.

Requires WordPress 7.0 or higher and PHP 8.3. Does not require the sodium extension: the Ed25519 signature capability comes from the sodium extension or the WordPress-bundled sodium_compat polyfill. Requires the PHP zip and zlib extensions (usually enabled in standard PHP), HTTPS, a writable mu-plugins directory, direct filesystem access, DISALLOW_FILE_MODS not enabled, and no conflicting legacy loader in mu-plugins. No site data is deleted and no hosting changes are required.

== Frequently Asked Questions ==

= Does the Carrier run updater or Runtime logic on ordinary requests? =

No. Its main file only defines identity and registers an activation callback. Installer code is loaded only if WordPress invokes that callback.

== Changelog ==

= 1.0.7 =
* Local implementation of Docs/versions/v1.0.7.md clauses on the existing files only: posts.js rich-editor capture fixes marked [B9-09] — silent skip when the editor state is absent (user text preserved) with diagnostics only for not-ready/sequencing states, an idempotent editor-root registry with an inner container, and deferred textarea hiding only on the first real frame signal (a 10s probe cap leaves the textarea visible when no signal arrives).
* i18n closure on the existing files: 14 keys added to the translation map and 129 strings added to the registered set (registered set 376, map 142, zero missing literal calls; loadFailed stays fallback-only by owner decision); the batch9/batch10 witnesses were updated to assert the exact fallback-only set, and stale comments plus the dead [B9-U2] CSS were cleaned with their test anchor updated.
* Version identity 1.0.7 across the plugin header, version constant, stable tag, and installation ZIP name.
* UNVERIFIED: field gates stay unverified — the live ZT-1 hybrid round for the prepared (not yet installed) REC-2 dequeue block, owner entry of the Arabic strings in WPML String Translation, the triple-snapshot and console/timeline field checks of the posts.js rounds, and release_sequence (recorded locally as 5; manifest check by a separate network delegation).

= 1.0.6 =
* Local implementation of Docs/versions/v1.0.6.md clauses on the existing files only: lazy media check at click with a visible message, thumbnail_id '0' removal, clickable success-card links (view/preview/edit) kept usable before any deferred transition, parent-children category tree with RTL indent and per-term language badge (display only, categories[] contract unchanged), read-only Permalink with a draft-temporary label, edit-panel-only SEO group behind the owner key plus runtime detection, official block-editor instance inside the HAL shell with textarea fallback (server contract stays plain text), and a manual article-translations list reusing the existing endpoint with read-back verification and fail-closed cleanup.
* Tests and identity only in this step: existing wings updated to the documented deltas/inventories (posts/visual deltas, CSS markers, deferred-transition expectations, seo ninth in every action inventory, frozen read-back mock); no product logic touched here.
* UNVERIFIED: field gates stay unverified — browser acceptance on the live site (WordPress 7.1.3), the post-install isolated-draft cycle with read-back/rollback proof, WPML runtime detection, and release_sequence=5 (recorded locally, manifest check by a separate network delegation).

= 1.0.5 =
* Article categories in the frontend editor are now real site-category checkboxes by name (write and edit panels, with prior checked state in edit and a keep-current-categories disclosure); the numeric-IDs text field is removed.
* Featured image selection via the standard media picker (upload_files-gated, select/replace only) and an excerpt field, both saved through the existing server contract.
* Sidebar submenu uses dynamic height on all open routes (click, hash, ?panel=) with resize handling, so all five article items display; direct trash-articles links open the submenu with the correct title.
* Version identity 1.0.5 across the plugin header, version constant, and stable tag.

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
