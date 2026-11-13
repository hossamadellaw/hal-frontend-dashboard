<?php
/**
 * runtime/templates/dashboard/profile-links.php — «My Profile» links extension point
 * ══════════════════════════════════════════════════════════════
 * Change Request: CR-2026-09-25-PROFILE-LINKS (the owner's current request; §15.35).
 * Declared filter: `hal_frontend_dashboard_profile_links`.
 *
 * The contract:
 *   - Invoked at render time (from the profile.php panel after the feature
 *     gate), after plugins are loaded — not during Bootstrap and not on an
 *     early path.
 *   - Receives: an empty array by default + the current user ID from
 *     get_current_user_id() (not from request input).
 *   - Each valid item: a unique fixed string id (first wins) + a non-empty
 *     string label without HTML + an absolute http/https url with a space-free
 *     host + eligible === true literally + an optional integer order
 *     (absent alone = 100; present-but-invalid, including null, is skipped and
 *     never defaulted; smaller first, and input order on ties).
 *   - A non-array result = an empty list. An invalid item is skipped alone.
 *   - The link appearing grants no capability: the target page enforces
 *     access itself.
 *   - Boundaries: links only in this position — no panels, no forms, no
 *     saving, no secrets and no other users' data. No HTML/JS from the
 *     provider, and no fetching or storing of link content.
 *
 * Example standalone provider (in the providing plugin, not here):
 *
 *   add_filter( 'hal_frontend_dashboard_profile_links', function ( $links, $user_id ) {
 *       $links[] = array(
 *           'id'       => 'member-profiles.overview',
 *           'label'    => __( 'Member overview', 'member-profiles' ),
 *           'url'      => home_url( '/member/' . $user_id . '/' ),
 *           'eligible' => current_user_can( 'read' ),
 *           'order'    => 10,
 *       );
 *       return $links;
 *   }, 10, 2 );
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_profile_link_url_allowed' ) ) {
	/**
	 * Accepts only an absolute URL with an HTTP/HTTPS protocol and a non-empty host.
	 */
	function hossam_profile_link_url_allowed( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		$host = (string) ( $parts['host'] ?? '' );
		if ( '' === $host || 1 === preg_match( '/\s/', $host ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'hossam_profile_links' ) ) {
	/**
	 * Collects «My Profile» links from the declared filter and returns only the accepted ones, sorted.
	 *
	 * @return array[] Each item: id/label/url/order (sorted, without temporary keys).
	 */
	function hossam_profile_links(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$links = function_exists( 'apply_filters' )
			? apply_filters( 'hal_frontend_dashboard_profile_links', array(), $user_id )
			: array();
		if ( ! is_array( $links ) ) {
			return array();
		}
		$accepted = array();
		$seen = array();
		$index = 0;
		foreach ( $links as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = $item['id'] ?? null;
			if ( ! is_string( $id ) || '' === trim( $id ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$label = $item['label'] ?? null;
			if ( ! is_string( $label ) || '' === trim( $label ) || $label !== strip_tags( $label ) ) {
				continue;
			}
			$url = $item['url'] ?? null;
			if ( ! hossam_profile_link_url_allowed( $url ) ) {
				continue;
			}
			if ( ! array_key_exists( 'eligible', $item ) || true !== $item['eligible'] ) {
				continue;
			}
		$order = null;
		if ( ! array_key_exists( 'order', $item ) ) {
			$order = 100;
		} elseif ( ! is_int( $item['order'] ) ) {
			continue; // present-but-invalid order (incl. null): skip, never default
		} else {
			$order = $item['order'];
		}
			$seen[ $id ] = true;
			$accepted[] = array(
				'id'    => $id,
				'label' => $label,
				'url'   => $url,
				'order' => $order,
				'index' => $index++,
			);
		}
		usort(
			$accepted,
			static function ( array $a, array $b ): int {
				if ( $a['order'] !== $b['order'] ) {
					return $a['order'] < $b['order'] ? -1 : 1;
				}
				return $a['index'] < $b['index'] ? -1 : ( $a['index'] > $b['index'] ? 1 : 0 );
			}
		);
		$sorted = array();
		foreach ( $accepted as $entry ) {
			unset( $entry['index'] );
			$sorted[] = $entry;
		}
		return $sorted;
	}
}

if ( ! function_exists( 'hossam_render_profile_links_section' ) ) {
	/**
	 * Renders the «Additional Links» section in Dashboard styling after the
	 * Profile, Settings and Security cards. Nothing when there are no accepted
	 * links. Ordinary navigation links with escaped output (esc_url/esc_html)
	 * only.
	 */
	function hossam_render_profile_links_section(): void {
		$links = hossam_profile_links();
		if ( array() === $links ) {
			return;
		}
		echo '<div class="card mt-12"><div class="card-title mb-12">'
			. esc_html( function_exists( 'hossam_t' ) ? hossam_t( 'Additional links' ) : 'Additional links' )
			. '</div><div class="form-actions">';
		foreach ( $links as $link ) {
			echo '<a href="' . esc_url( $link['url'] ) . '" class="btn btn-ghost btn-sm">'
				. esc_html( $link['label'] ) . '</a>';
		}
		echo '</div></div>';
	}
}
