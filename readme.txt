=== HAL Frontend Dashboard ===
Contributors: hossamadellaw
Tags: dashboard, frontend, administration
Requires at least: 7.0
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 1.1.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Carrier metadata and installation entry point for HAL Frontend Dashboard.

== Description ==

HAL Frontend Dashboard ships as a small WordPress Carrier with an independently managed, signed MU Runtime release. The runtime serves the HAL frontend dashboard on the owned page — posts with the rich editor embedded in the HAL panel (the textarea stays the field of truth), files, and appointments behind an Amelia readiness gate (plugin active and its three tables present) — the runtime bootstrap registers the health-check loopback handler so release activation acknowledgement is reachable, and the unpacked carrier runtime is verified with the health wiring regression test before publishing.

== Installation ==

1. Download the official `hal-frontend-dashboard-1.1.16.zip` Carrier from the Releases section of the project repository (https://github.com/hossamadellaw/hal-frontend-dashboard).
2. In the WordPress admin dashboard, upload the ZIP under Plugins → Add New → Upload Plugin, then activate it.
3. Activation runs the installer, which provisions the signed Runtime release. The release publishes the ZIP SHA256 alongside the asset for verification before uploading.

Requires WordPress 7.0 or higher and PHP 8.3. Does not require the sodium extension: the Ed25519 signature capability comes from the sodium extension or the WordPress-bundled sodium_compat polyfill. Requires the PHP zip and zlib extensions (usually enabled in standard PHP), HTTPS, a writable mu-plugins directory, direct filesystem access, DISALLOW_FILE_MODS not enabled, and no conflicting legacy loader in mu-plugins. No site data is deleted and no hosting changes are required.

== Frequently Asked Questions ==

= Does the Carrier run updater or Runtime logic on ordinary requests? =

No. Its main file only defines identity and registers an activation callback. Installer code is loaded only if WordPress invokes that callback.

== Changelog ==

= 1.1.16 =
* The Change case menu applies its four transforms again: the menu wiring passed an undefined mode on every click, so the items only announced a missing selection.
* List tools handle existing lists properly: the bullet and numbered buttons switch an existing list between ordered and unordered through the official attribute instead of doing nothing.
* Justify full now works through the same alignment channel as left, center, and right, and line spacing applies to the selected paragraph and survives saving (it previously shifted every block and was lost on save).
* Reference links, footnotes, and the legal callout insert real elements again: links carry the official link format, footnotes become superscript citations, and the callout lands as a paragraph with a yellow background.
* The direction switch flips on the first click on Arabic canvases, and the four font tools announce a missing selection instead of failing silently; multilevel list and the indent tools are honestly disabled with their documented reasons.
* Version identity 1.1.16 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 24.

= 1.1.15 =
* Restoring unsaved work is now integrity-checked: the restore waits for the editor to finish loading, every field is written to its own target and verified after the restore, and the vault can never re-capture a corrupted intermediate state.
* The writing tools that failed silently were fixed: clear formatting removes every pinned inline format again (including strikethrough), the highlight and text-color Apply buttons respect the selected suggested color while the free picker stays intact, and the More fold plus every dropdown carries a real open/close state.
* The restore refusal now survives a page reload: the last-saved time is kept with the article server-side and compared against the vault, so an older injected draft can no longer overwrite newer saved content.
* Writing focus is now deterministic: the canvas receives the caret once the editor is ready, a click on an unfocused text block selects it, Enter in the title jumps into the content, and failed tool actions announce themselves and recover the selection instead of failing silently.
* Paragraph direction switched through the direction button now uses the official paragraph attribute when the WordPress core supports it, and the single-block list behavior is documented as shipped.
* Version identity 1.1.15 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 23.

= 1.1.14 =
* The writing form now rescues unsaved work on its own: every field of the article form is captured to a session vault as you type, an explicit banner offers restoring it after any page loss, and a single dirty indicator warns before leaving with unsaved changes - including the in-panel navigation.
* After the first save a decision card offers continuing in place or opening the editor, the automatic redirect cancels on the first input (for saves and translations), and a second save is intercepted instead of creating a duplicate.
* The edit panel now autosaves through the official autosaves endpoint, with the REST nonce issued to every writer role.
* The writing-critical interface strings (the title placeholder, the heading-policy lines, the official-editor gateway, and the Media toolbar label) moved to the registered translation channel.
* Version identity 1.1.14 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 22.

= 1.1.13 =
* The formatting tools now write through the editor official serializer: strikethrough, subscript, superscript, highlight, text color, clear formatting, change case, and alignment apply cleanly and survive saving.
* Version identity 1.1.13 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 21.

= 1.1.12 =
* The end-of-content click is now exact for every content type: the caret lands where the text ends regardless of block state, and blocks without text (dividers, images) keep the append behavior.
* Version identity 1.1.12 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 20.

= 1.1.11 =
* Clicking in the empty area of the content now places the caret at the end of what is written, so writing always continues from where the content ends instead of dropping a floating paragraph in the middle; a cleared article still starts from the top.
* The editor body is now writing-only: the floating editor toolbars that used to appear over the text are gone, and the single fixed toolbar above the content is the only one.
* The previously unresponsive formatting tools now work through the editor official channels: strikethrough, subscript, superscript, clear formatting, highlight, text color, change case, and left/center/right alignment.
* Headings and the legal quote now produce real editor heading and quote blocks.
* Writing direction follows the site language switcher: the Arabic dashboard writes right-to-left and the English dashboard left-to-right.
* Version identity 1.1.11 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 19.

= 1.1.10 =
* Clicking in the empty area below the content now reliably starts a new paragraph: the click-surface check covers the whole editor frame, so the first click lands exactly where the writer points - on desktop, phone, and tablet.
* Version identity 1.1.10 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 18.

= 1.1.9 =
* Clicking anywhere in the content canvas now writes: a new paragraph is created right where the writer clicked - including between existing paragraphs - through the editor official block store, on desktop, phone, and tablet.
* The between-paragraph insertion affordance (the editor native insertion line) is restored, so pointing between two paragraphs behaves like the standard editor again.
* The bullet and numbered list buttons now produce real lists through the official editor store instead of being silently swallowed.
* The divider, collapsible section, and legal comparison table buttons now insert real editor blocks that save, render, and stay editable.
* Version identity 1.1.9 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 17.

= 1.1.8 =
* Clicking anywhere in the content area now writes: the invisible starter area below the text is a full-height click zone routed through the editor native insert path, so typing works from the first click on every device - not only at the top line.
* On phones and tablets the whole content area is clickable again instead of only a thin first line.
* The cleanup stays clean: no starter prompt, no icon, no visual chrome - the click zone is fully transparent, and nested in-canvas starters remain hidden.
* Version identity 1.1.8 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 16.

= 1.1.7 =
* Writing is fixed: an empty article now opens with a ready-to-type paragraph, so clicking into the content area and typing works again on every device - the hidden starter that a previous cleanup had removed is no longer the only way to begin.
* Clearing all content and typing again works too: an emptied canvas remounts a fresh paragraph in place instead of going dead.
* The full editor toolbar is inventoried and its behavior is measured against real typed text, with fixes routed through the official editor channels.
* A new acceptance rule: editor surfaces are only reported as working after real typed interaction is verified end to end (typed, saved, and read back from the saved article).
* Version identity 1.1.7 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 15.

= 1.1.6 =
* The editor toolbar drawer now slides fully into view on phones: the open rule carries the panel scope in both text directions, so formatting tools stay reachable on small screens while the backdrop, focus, and Escape behavior are unchanged.
* A wp-config circuit breaker is available: defining HAL_FRONTEND_DASHBOARD_DISABLE_AUTO_UPDATE as true stops automatic updates of this plugin only (manual updates are unaffected), while leaving the constant out keeps the current behavior.
* The release sequence gate now requires each release to be exactly the latest published sequence plus one, with clearer block and success messages.
* CI runs a weekly scheduled dependency review that compares the locked plugin-update-checker version against the upstream latest release and fails loudly when an update is available or the upstream cannot be reached.
* Version identity 1.1.6 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 14.

= 1.1.5 =
* The editor cards follow the owner-ordered layout (Metadata, Tags, Translations Hub, SEO & SERP, AI Assistant, Publishing) and every card is live: Metadata carries the featured image and category, Tags suggests site tags from the same category under a hard 15-tag limit, Translations exposes its action buttons, the SEO counters update while writing, and the AI card icon is a real button.
* The article excerpt sits directly under the title in both the write and edit editors, and the title carries the plugin styling in both contexts.
* All add-block controls inside the writing canvas are hidden through the official editor styles channel, so the toolbar button remains the only add path and the writing body stays clean.
* The edit editor now mounts the same six-card side column, unified heads, and bottom action bar as the write editor, and the articles table shows the author name and site link beside the admin actions.
* Opening More inside the phone drawer keeps the backdrop, focus, and Escape behavior in sync with the drawer state.
* AI generation is repaired end to end: the pinned model moves to gemini-3.6-flash verified against the live provider list, the provider-live probe now checks that the pinned model is still offered, and a stale configured model fails loudly instead of silently.
* Version identity 1.1.5 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 13.

= 1.1.4 =
* AI generation is repaired end to end: failures carry classified sanitized codes (rejected key, scope, quota, request shape, or a live provider list), the pinned model moves to the current Flash generation with a provenance docblock, a free provider-live probe joins the hourly health cron as a second verdict level, and AI buttons enter a waiting state with a pinned width instead of collapsing.
* The articles table reports the article author and their site link for non-admin roles instead of action buttons, while administrators keep every existing action.
* The More fold in the editor toolbar is admin-only per the server capability flag, a phone drawer trigger outside the sliding panel opens the tool drawer for every role, and Escape or a backdrop click closes it and restores focus.
* The Add-block toolbar button opens the official inserter popover directly and the standalone inserter row is hidden by CSS, keeping the writing canvas clean.
* The write column gains Translations Hub and SEO & SERP availability cards that activate with the saved-state wave, the edit editor mounts the same bottom action bar and states as the write editor, and the sidebar cards follow the documented model order with unified heads.
* Language status chips expose an aria label alongside their tooltip, the overview counters render tabular figures in the text font, and the bottom-bar publish button reads Publish Article Now.
* Version identity 1.1.4 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 12.

= 1.1.3 =
* The article editor gains a professional formatting toolbar above the writing canvas: ten tool groups plus a History group and an admin-only More menu, a static bottom action bar (Publish / Save Draft / Discard), and responsive tablet and phone layouts — all rendered with the existing dashboard stylesheet.
* The editor sidebar gains the Publishing, Metadata, Tags, Translations Hub, and SEO & SERP cards, along with the empty/writing/saved/error editor states, the untranslated-language chip, legacy hidden-block handling, and RankMath suggestions — each behind the existing server-side capability gates.
* All runtime source files are English-only: user-facing strings use the standard translation helper with registered English sources, and any remaining Arabic source text is confined to the tracked build/arabic-allowlist.txt file, which must shrink with every later release.
* A new release tooling script (build/bump-release.sh) updates the plugin header, version constant, stable tag, installation ZIP name, changelog entry, and release sequence in one command, with pre-flight consistency checks, post-verify reporting, and an idempotent re-run.
* The release workflow validates the Requires WordPress and Requires PHP header values against the built manifest, checks the installation ZIP name, the changelog entry, and the release sequence shape, and installs PHP 8.3 explicitly before the full test run.
* Version identity 1.1.3 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 11.

= 1.1.2 =
* The AI assistant card is fully in English: the "Optional" tag and the card description now use the standard translation helper with English source strings registered for translation.
* Failed secret saves report the specific reason (missing sodium extension, missing or invalid external key, or a database write failure) instead of one generic message, and the Status tab adds a copy-ready wp-config.php key line with the expected read-back check.
* Release notes are generated from a neutral version-variable template instead of text pinned to an older release.
* The security policy states that fixes target the latest stable MINOR line only.
* Version identity 1.1.2 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 10.

= 1.1.1 =
* Approved design for the new-article screen: the Write panel is re-wrapped per the approved mockup (two-column shell, title badge, side AI card) while the same block editor is reused as-is; the sidebar and every other panel are untouched.
* Text formatting toolbar: Bold/Italic/list controls appear automatically when a text block is focused, via the official WordPress format-library package behind the existing safe-load guards.
* Encrypted Amelia key entry in the admin UI: a new field in the Integrations tab stores the key in the encrypted store, and saving or deleting it runs an immediate health check so the result shows instantly instead of after the next cycle.
* Site name and tagline from settings: two new Branding fields (site title up to 60 characters, tagline up to 80) replace the hardcoded texts in the panel header and card marks, with each position keeping its own fallback text.
* Dashboard page shown read-only: an info block displays the owned dashboard page (id, status, link, open button) with no UI writing to the page setting.
* Site-section links can be overridden: the eight closed section links accept a manual replacement URL (http/https only; invalid values are ignored) on the fallback path only — the primary menu still wins when present.
* A description under every feature: the Features tab shows a one-line explanation under each key instead of the raw name.
* Rich editor modes explained: plain-language descriptions for official/embed/off, the smart default, and the master-switch rule.
* The manual-update button is visually marked as an admin gate: still admin-only in official mode, with a tagged visual distinction, no cross-window messaging, and no wp-admin embedding inside the panel.
* New admin strings are translatable: wp-admin descriptions follow the interface language via the standard translation pattern.
* Version identity 1.1.1 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 9 (basis: the 2026-11-05 acceptance audit ACCEPTED and the execution ledger c012452b...).
* UNVERIFIED (post-launch by owner decision): the live acceptance scene (section 9-1 visual acceptance on both accounts including the sidebar before/after), the live kses round-trip matrix, the owner staging measurements (M1-M4), the manual popup-blocker pass, Amelia env/constant setup, the six acceptance drafts deletion, the design-decisions record (D1-D4), and the nine console warnings review — all scheduled for the 1.1.2 acceptance cycle (Y1-Y8).

= 1.1.0 =
* Rate limiting on every write point (article create/update/translate/trash-restore, uploads, messages, SEO save, AI polling) with per-minute and daily caps — abusive bursts get a short wait message instead of loading the site.
* Internal audit log (90 days, auto-cleaned): every important action is recorded — article lifecycle, file upload/delete/restore, SEO save, settings save, AI job lifecycle, secrets lifecycle — with one daily retention sweep covering the audit log, AI jobs, notifications, and messages.
* Revisions capped at 10 per article to keep the database lean.
* Upload hardening: 8MB size cap (filterable, 0 disables), unified MIME allow-list intersection (never widens), real-type fileinfo check with an admin notice when the extension is unavailable.
* Zombie AI jobs closed automatically inside the existing scheduled sweep (exhausted attempts + orphaned pre-upgrade pending rows) — no more stuck-forever pending jobs.
* Unified 404 across the seven endpoints for missing or foreign objects — the interface can no longer probe existence (403/404 indistinguishable), while the high-level feature gates stay 403.
* Conditional REST nonce (issued only when the rich editor is enabled AND the user can upload_files — empty otherwise) and payment-methods payload trimmed (no internal ids; the accepted-risk disclosure is documented).
* OEB bridge: in official mode admins get a one-click native WordPress-editor window (new window + tab fallback + load-baseline return sync with dirty-before-open protection) while the embed stays for everyone else and off drops the editor packages (about 2.7MB saved); H1 paste normalization, advisory block-type restriction, and robots chips locked to the closed list.
* New articles are stamped with the member's current language (site default fallback; without WPML the behavior is byte-identical to 1.0.8).
* Performance: ai_jobs schema 1.1.2 with three indexes (zombie sweep + quota paths), settings autoload on, template-controller per-request cache, finance summary capped at 20 pages with a revenue_capped flag, and a cron/CLI early guard.
* Version identity 1.1.0 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 8 (basis: acceptance audit ADVISORY-DECISION 5aaa24f2... and the execution ledger 12085f26...).
* UNVERIFIED: the live acceptance session (owner-guarantee reference scene on both accounts per H1 section 7, corrected gate 9, manual popup-blocker acceptance, final visual shape per H1 section 9) and the owner staging measurements (live MySQL8/MariaDB migration T4-1 + EXPLAIN, live R1 measurement, R5 profit) — both feed 1.1.1 by owner decision.

= 1.0.8 =
* Editor recovery release (Docs/versions/v1.0.8.md REV3.1): the rich editor stays the v1.0.7 embed path inside the HAL panel — no modes switch and no official-editor interface in this version (OEB is a separate v1.0.9 draft).
* S4/503 fix: the overview readiness gate requires the Amelia plugin active AND its three tables present (fail-closed function_exists guards) before $sees_calendar turns true, so the appointments card/script, the quick-actions branch, and the My Schedule button follow real readiness instead of surfacing the lazy endpoint's 503.
* REC-2 self-hardening: ZIP AI front assets (the zip-ai-sidebar script and style) are dequeued on HAL dashboard pages only, after the registration priority with safe existence checks; the admin path is untouched and the classification (أ/ب/ج) is recorded at post-release acceptance.
* Re-initialization regression test O9: re-initializing the embed after teardown keeps registerCoreBlocks at exactly one call per page (no Format "core/footnote" is already registered.); batch9-rich-editor is now 29/29.
* CI: PHP 8.3 pinned explicitly — batch12-cutover requires 8.3.x literally and rejects 8.4+.
* Version identity 1.0.8 across the plugin header, version constant, stable tag, and installation ZIP name; release sequence 7 (basis: archived A2 evidence).
* UNVERIFIED: field gates stay unverified — the live acceptance session (classification أ/ب/ج + dequeue proof + A1-SEAL measurements), the non-admin writer checklist, and release_sequence (recorded locally as 7; the tag/publish chain is a separate owner-delegated step).

= 1.0.7 =
* Local implementation of Docs/versions/v1.0.7.md clauses on the existing files only: posts.js rich-editor capture fixes marked [B9-09] — silent skip when the editor state is absent (user text preserved) with diagnostics only for not-ready/sequencing states, an idempotent editor-root registry with an inner container, and deferred textarea hiding only on the first real frame signal (a 10s probe cap leaves the textarea visible when no signal arrives).
* i18n closure on the existing files: 14 keys added to the translation map and 129 strings added to the registered set (registered set 376, map 142, zero missing literal calls; loadFailed stays fallback-only by owner decision); the batch9/batch10 witnesses were updated to assert the exact fallback-only set, and stale comments plus the dead [B9-U2] CSS were cleaned with their test anchor updated.
* Version identity 1.0.7 across the plugin header, version constant, stable tag, and installation ZIP name.
* UNVERIFIED: field gates stay unverified — the live ZT-1 hybrid round for the prepared (not yet installed) REC-2 dequeue block, owner entry of the Arabic strings in WPML String Translation, the triple-snapshot and console/timeline field checks of the posts.js rounds, and release_sequence (recorded locally as 6; v1.0.6 published manifest=5 verified; release workflow gate pending).

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
