<?php
/**
 * runtime/adapters/ultimatemember.php (Batch 4)
 * ══════════════════════════════════════════════════════════════
 * Role: the whole Ultimate Member integration — the three official
 * shortcodes and the post-login redirect filter. There is no proven official
 * alternative in the code for these
 * shortcodes (security-sensitive paths: password change) — they remain a
 * primary mechanism, not a fallback.
 *
 * Source: verbatim 1:1 port from legacy mu-plugins/hossam-dashboard/adapters/
 * ultimatemember.php (Batch 4 of HAL Frontend Dashboard).
 *
 * The approved project owner decision (2026-08-24, carried over from the source header): the file
 * stays deliberately empty as a documented future integration point only — the
 * um_login_redirect_url hook is owned by core/setup.php exclusively (double registration =
 * a doubled invocation of the same filter with no change in the result). The shortcodes
 * are consumed from the template (profile.php, Batch 7), not from here.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
