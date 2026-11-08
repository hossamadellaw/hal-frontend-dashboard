<?php
/**
 * runtime/adapters/rankmath.php (Batch 4)
 * ══════════════════════════════════════════════════════════════
 * Role: a front-end control panel for Rank Math (read + write) — through
 * the standard get_post_meta()/update_post_meta() of WordPress Core
 * only. No Hook and no Function from Rank Math itself anywhere.
 *
 * Source: verbatim 1:1 port from legacy mu-plugins/hossam-dashboard/adapters/
 * rankmath.php (Batch 4 of HAL Frontend Dashboard) — zero changes to
 * contracts, fields, or the allowlists.
 *
 * The contract as carried over (summarized from the source header):
 *   - Only four pinned fields (rank_math_seo_score/focus_keyword/
 *     description/robots) — no extended fields, and they are returned
 *     only after the live verification described in
 *     dashboard-unified-execution-reference.md line 245
 *     (var_dump(get_post_meta($id)) on a real article + a postmeta
 *     before/after comparison on staging), not merely on key-name similarity.
 *   - v1.0.6 §7: rank_math_title was added (its key is pinned in the official
 *     source rest/class-sanitize.php:50 and confirmed live in probe 2026-10-14)
 *     with the same read-back and restore — the gate is implemented in v1.0.6 §8
 *     (detecting verified_fields running — setup.php:267 — + owner key rank_math_seo).
 *   - rank_math_seo_score is read-only — deliberately has no write path
 *     (computed internally by Rank Math itself).
 *   - robots is a closed list from hossam_get_allowed_rank_math_robots() —
 *     foreign values are ignored and the write is skipped entirely if no valid
 *     value remains
 *     (fail-safe, no destruction of the existing value).
 *   - [H6 §5-a — final lock v1.1.0] the robots chips are finally locked to
 *     the closed four-item list [index,noindex,follow,nofollow] — the extension
 *     (NoArchive/NoSnippet) is not an item in the list at all; its only path
 *     is the hossam_rank_math_allowed_robots filter from trusted PHP after a live
 *     verification against the actual Rank Math version, triggered by the owner alone
 *     (a site snippet via the official filter; success = keep it, failure = withdraw it) —
 *     no «conditional without a deadline».
 *   - hossam_update_rankmath_meta: compare before writing (update_post_meta
 *     returns false with a dual meaning — no change is not a failure).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_get_rankmath_seo_data' ) ) {
	/**
	 * Read — the four originally confirmed from the actual code — unchanged.
	 *
	 * @return array
	 */
	function hossam_get_rankmath_seo_data( int $post_id ): array {
		return [
			// The four originally confirmed from the actual code + title (v1.0.6 §7) — no contract changes.
			'seo_score'     => (int) get_post_meta( $post_id, 'rank_math_seo_score', true ), // read-only, never written
			'title'         => (string) get_post_meta( $post_id, 'rank_math_title', true ), // v1.0.6 §7
			'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ), // may contain comma-separated words
			'description'   => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'robots'        => get_post_meta( $post_id, 'rank_math_robots', true ),
		];
	}
}

if ( ! function_exists( 'hossam_get_allowed_rank_math_robots' ) ) {
	/**
	 * Closed allow-list for rank_math_robots values (reference §Rank Math b.4:
	 * «robots accepts no open array, only a list of allowed values»).
	 *
	 * [H6 §5-a — final lock v1.1.0] the closed four-item list remains the sole
	 * governing source for drawing and verification: the extension (NoArchive/NoSnippet)
	 * is not an item in the list at all — its only path is the
	 * hossam_rank_math_allowed_robots filter from trusted PHP after a live verification
	 * against the actual Rank Math version, triggered by the owner alone
	 * (a site snippet via the official filter; success = keep it, failure = withdraw it) —
	 * no «conditional without a deadline».
	 *
	 * @return string[]
	 */
	function hossam_get_allowed_rank_math_robots(): array {
		$base       = [ 'index', 'noindex', 'follow', 'nofollow' ];
		$extensions = apply_filters(
			'hossam_rank_math_allowed_robots',
			[]
		);

		if ( ! is_array( $extensions ) ) {
			$extensions = [];
		}

		$extensions = array_map( 'sanitize_key', array_filter( $extensions, 'is_string' ) );
		return array_values( array_unique( array_merge( $base, array_filter( $extensions ) ) ) );
	}
}

if ( ! function_exists( 'hossam_update_rankmath_meta' ) ) {
	function hossam_update_rankmath_meta( int $post_id, string $meta_key, $value ): bool {
		$current = get_post_meta( $post_id, $meta_key, true );
		if ( $current === $value ) {
			return true;
		}

		return false !== update_post_meta( $post_id, $meta_key, $value );
	}
}

if ( ! function_exists( 'hossam_restore_rankmath_meta_snapshot' ) ) {
	/**
	 * v1.0.6 §7 — restore a previous snapshot: absent meta is deleted, and present-but-empty meta is written as ''.
	 *
	 * @param array<string,array{exists:bool,value:mixed}> $snapshot
	 */
	function hossam_restore_rankmath_meta_snapshot( int $post_id, array $snapshot ): void {
		foreach ( $snapshot as $meta_key => $previous ) {
			if ( empty( $previous['exists'] ) ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $previous['value'] );
			}
		}
	}
}

if ( ! function_exists( 'hossam_save_rankmath_seo_data' ) ) {
	/**
	 * Write — deliberately does not write rank_math_seo_score. Pinned fields:
	 * title/focus_keyword/description/robots (v1.0.6 §7 added title).
	 * robots now accepts only the allowed values from hossam_get_allowed_rank_math_robots()
	 * — foreign values are ignored, and the write is skipped entirely if no valid value remains.
	 *
	 * v1.0.6 §7 (partial-failure pattern — the gate is implemented in v1.0.6 §8): before any write,
	 * the previous values of the target fields are read (existence and value) → write →
	 * re-read and match for every field → on any failure the previous state is restored
	 * in full before return false.
	 *
	 * @param int   $post_id
	 * @param array $data ['title','focus_keyword','description','robots']
	 * @return bool
	 */
	function hossam_save_rankmath_seo_data( int $post_id, array $data ): bool {
		if ( ! isset( $data['robots'] ) || ! is_array( $data['robots'] ) ) {
			return false;
		}

		$fields_map = [
			'title'         => 'rank_math_title',
			'focus_keyword' => 'rank_math_focus_keyword',
			'description'   => 'rank_math_description',
		];

		$clean_fields = [];
		foreach ( $fields_map as $data_key => $meta_key ) {
			if ( ! isset( $data[ $data_key ] ) ) {
				continue;
			}
			if ( ! is_string( $data[ $data_key ] ) ) {
				return false;
			}

			$clean_fields[ $meta_key ] = 'description' === $data_key
				? sanitize_textarea_field( wp_unslash( $data[ $data_key ] ) )
				: sanitize_text_field( wp_unslash( $data[ $data_key ] ) );
		}

		if ( isset( $clean_fields['rank_math_title'] ) ) {
			$title_length = function_exists( 'mb_strlen' )
				? mb_strlen( $clean_fields['rank_math_title'] )
				: strlen( $clean_fields['rank_math_title'] );
			if ( $title_length > 255 ) {
				return false;
			}
		}

		$robots = [];
		foreach ( $data['robots'] as $robot ) {
			if ( ! is_string( $robot ) ) {
				return false;
			}
			$robots[] = sanitize_key( wp_unslash( $robot ) );
		}
		$clean_robots = array_values( array_unique( array_intersect( $robots, hossam_get_allowed_rank_math_robots() ) ) );
		if ( empty( $clean_robots ) ) {
			return false;
		}

		// Snapshot before any write — target fields only.
		$targets = $clean_fields;
		$targets['rank_math_robots'] = $clean_robots;
		$snapshot = [];
		foreach ( $targets as $meta_key => $new_value ) {
			$snapshot[ $meta_key ] = [
				'exists' => metadata_exists( 'post', $post_id, $meta_key ),
				'value'  => get_post_meta( $post_id, $meta_key, true ),
			];
		}

		foreach ( $targets as $meta_key => $new_value ) {
			if ( ! hossam_update_rankmath_meta( $post_id, $meta_key, $new_value ) ) {
				hossam_restore_rankmath_meta_snapshot( $post_id, $snapshot );
				return false;
			}
			if ( get_post_meta( $post_id, $meta_key, true ) !== $new_value ) {
				hossam_restore_rankmath_meta_snapshot( $post_id, $snapshot );
				return false;
			}
		}

		return true;
	}
}

// v1.0.6 §7: rank_math_title was added with the same read-back and restore
// (the gate is implemented in v1.0.6 §8) — no other extended fields in this file.
