/**
 * assets/js/modules/translations.js
 * ══════════════════════════════════════════════════════════════
 * Linked tasks: T-22
 * ⚠ There is no dedicated AJAX loader function for the translation panel
 * at all (its content is rendered entirely directly from PHP, without
 * Lazy-load). This file may remain effectively empty after the move, or
 * may in the future receive the custom WPML switcher logic (T-29) should
 * any client-side part be needed (an extra interaction that does not
 * exist in the original version).
 *
 * TODO:
 * [x] Confirming that no loader function missed by the move from the
 *     original dashboard.js pertains to this panel specifically (apart
 *     from the IIFE that opens/closes the language list in the Header,
 *     which stays in the shell because it is not specific to the panel
 *     itself) — confirmed 2026-08-24 by inspecting the original: no
 *     loader for the panel; the only related function translateArticle()
 *     (:808 in the original) was moved to modules/posts.js verbatim with
 *     its listener, and the language list stayed in dashboard.js as
 *     described
 * ══════════════════════════════════════════════════════════════
 */
