<?php
/**
 * runtime/ajax/seo.php (Batch 5)
 * ══════════════════════════════════════════════════════════════
 * Role: a single AJAX action — wp_ajax_hossam_save_seo to save SEO data.
 * The write logic is owned by adapters/rankmath.php (Batch 4); this endpoint
 * only translates — exactly as the source header states verbatim.
 *
 * Source: verbatim 1:1 transfer of all code from legacy mu-plugins/hossam-dashboard/
 * ajax/seo.php — the source fingerprint is pinned byte-for-byte in the Batch 5
 * inventory (build/source-inventory-batch-5.json). Zero changes to the contracts.
 *
 * The authorized delta (v1.1.0 merge — row R10 «v1.1.0 merge: the unified 404»):
 *   - the unified 404 (H3 §1.2/§1.5): missing, not-owned, and no-capability =
 *     404 with the same missing-message — one gate swallows existence and ownership together
 *     (zero JS touch).
 *   - consume seo 30/min after all validations complete and before writing post_meta
 *     (G3 §1.3-9 — the existing mitigator in rankmath.php remains).
 *   - a single audit article_save_seo (G4 §3-a-6 — saved field names
 *     exclusively, never values).
 *
 * Load state — v1.0.6 §8.3 (the unified decision a/c): it loads ninth from
 * runtime/bootstrap.php; the gate is a runtime detection (verified_fields in
 * the registry) + the owner key rank_math_seo for display. In the absence of Rank Math
 * the endpoint stays 503 and the template read-only (a safe fallback that disables nothing else).
 *
 * The carried-over contract as-is: check_ajax_referer('hossam_nonce','nonce') then
 * a 401 guard; 503 with the registry reasons when not available or missing
 * plugin_contract/verified_fields; 400 for non-scalar/types and 404 for non-
 * post/not-owned/no edit_post capability (the unified 404 with the missing-message
 * — v1.1.0 merge R10); all sanitization inside the adapter; the response shows
 * a server-side read-back only {message,fields}.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_hossam_save_seo', function(): void {
	check_ajax_referer( 'hossam_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }

	$rm_decision = hossam_get_integration_decision( 'rank_math' );
	$rm_caps     = is_array( $rm_decision['capabilities'] ?? null ) ? $rm_decision['capabilities'] : [];
	if ( 'available' !== $rm_decision['status'] || empty( $rm_caps['plugin_contract'] ) || empty( $rm_caps['verified_fields'] ) ) {
		wp_send_json_error( [ 'message' => $rm_decision['reason'] ?: 'SEO editing is read-only until Rank Math fields are verified.' ], 503 );
	}

	$post_id_raw = $_POST['post_id'] ?? '';
	if ( ! is_scalar( $post_id_raw ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Invalid article.' ) ], 400 );
	}
	$post_id = absint( $post_id_raw );
	$post    = $post_id ? get_post( $post_id ) : null;
	// R10 (v1.1.0 merge — the unified 404, H3 §1.2/§1.5): missing, not-owned,
	// and no-capability = 404 with the same missing-message — one gate swallows existence
	// and ownership together so no existence leaks before the reply.
	if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Article not found.' ) ], 404 );
	}

	$data = [];
	if ( isset( $_POST['title'] ) ) {
		if ( ! is_string( $_POST['title'] ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid title.' ) ], 400 );
		}
		// v1.0.6 §8.4: title is optional — the same template limit (maxlength=255).
		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $_POST['title'] ) : strlen( $_POST['title'] );
		if ( $title_length > 255 ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid title.' ) ], 400 );
		}
		$data['title'] = $_POST['title'];
	}
	if ( isset( $_POST['focus_keyword'] ) ) {
		if ( ! is_string( $_POST['focus_keyword'] ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid focus keyword.' ) ], 400 );
		}
		$data['focus_keyword'] = $_POST['focus_keyword'];
	}
	if ( isset( $_POST['description'] ) ) {
		if ( ! is_string( $_POST['description'] ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid description.' ) ], 400 );
		}
		$data['description'] = $_POST['description'];
	}
	if ( ! isset( $_POST['robots'] ) || ! is_array( $_POST['robots'] ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'Choose at least one valid robots directive.' ) ], 400 );
	}
	foreach ( $_POST['robots'] as $robot ) {
		if ( ! is_string( $robot ) ) {
			wp_send_json_error( [ 'message' => hossam_t( 'Invalid robots directive.' ) ], 400 );
		}
	}
	$data['robots'] = $_POST['robots'];

	// R10 (G3 §1.3-9): consume seo 30/min — after nonce/401/rm-decision/ownership
	// and input sanitization and directly before writing post_meta (contract G3 §1.1-1); the translation
	// in the error_data['status'] pattern.
	$quota = hossam_rate_limit_consume( 'seo', (int) get_current_user_id(), 30, MINUTE_IN_SECONDS );
	if ( is_wp_error( $quota ) ) {
		$data_status = $quota->get_error_data();
		wp_send_json_error(
			[ 'message' => $quota->get_error_message() ],
			is_array( $data_status ) && isset( $data_status['status'] ) ? (int) $data_status['status'] : 503
		);
	}

	if ( ! hossam_save_rankmath_seo_data( $post_id, $data ) ) {
		wp_send_json_error( [ 'message' => hossam_t( 'SEO settings could not be saved.' ) ], 500 );
	}

	// R10 (G4 §3-a-6): article_save_seo after success and before success — meta
	// fields with the saved field names exclusively, never values; the return value
	// is deliberately ignored (G4 §5).
	hossam_audit_log(
		'article_save_seo',
		[
			'object_id' => (int) $post_id,
			'meta'      => [ 'fields' => array_keys( $data ) ],
		]
	);

	wp_send_json_success( [
		'message' => hossam_t( 'SEO settings saved.' ),
		'fields'  => hossam_get_rankmath_seo_data( $post_id ),
	] );
} );
