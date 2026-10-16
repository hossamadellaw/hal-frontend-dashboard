<?php
/**
 * runtime/adapters/wordpress-posts.php (الدفعة 4)
 * ══════════════════════════════════════════════════════════════
 * الدور: CRUD أصلي للمقالات عبر WordPress Core APIs مباشرة.
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/adapters/
 * wordpress-posts.php (الدفعة 4 من HAL Frontend Dashboard) — صفر تعديل
 * في العقود.
 *
 * العقد المنقول كما هو (ملخَّص من رأس المصدر):
 *   - الإنشاء: current_user_can('edit_posts') — لا edit_post على مقال
 *     غير موجود؛ النشر حصرًا مع publish_posts؛ حالات الحفظ allow-list
 *     (draft/pending + publish المشروطة).
 *   - التعديل/الحذف/الاسترجاع: current_user_can('edit_post', $post_id) —
 *     map_meta_cap من WordPress Core يحسم مالك/غير مالك تلقائيًا، لا
 *     منطق post_author يدوي (المرجع الموحد سطر 142).
 *   - التصنيفات: فلترة لأعداد صحيحة موجبة + term_exists() فعلي قبل
 *     wp_set_post_categories()؛ الصورة البارزة: wp_attachment_is_image()
 *     + edit_post على الـattachment قبل set_post_thumbnail().
 *   - التعويض: كل حالة يلمسها التحديث تُستعاد ثم يُعاد التحقق منها فعليًا
 *     (hossam_restore_article_snapshot) — نجاح Core وحده لا يكفي؛ فشل
 *     التعويض يعلن hossam_partial_failure صراحة؛ فشل تنظيف الـdraft عند
 *     الإنشاء يعلن hossam_partial_failure بـcleanup=failed.
 *   - sanitize_text_field()/wp_kses_post() على كل مدخل نصي.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'hossam_create_article' ) ) {
	/**
	 * @param array{title?:string,content?:string,status?:string,excerpt?:string,categories?:array<int,int>,thumbnail_id?:int} $data
	 * @return int|WP_Error
	 */
	function hossam_create_article( array $data ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to create articles.', 'astra-child' ) );
		}

		$allowed_statuses = [ 'draft', 'pending' ];
		$status           = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';
		if ( 'publish' === $status ) {
			if ( ! current_user_can( 'publish_posts' ) ) {
				return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to publish articles.', 'astra-child' ) );
			}
			$allowed_statuses[] = 'publish';
		}
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		// v1.0.6 §5: the creation target is the site default language, read
		// before any WPML assignment via the official filter only.
		$target_language = '';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$maybe_default = apply_filters( 'wpml_default_language', null );
			if ( is_string( $maybe_default ) && '' !== $maybe_default ) {
				$target_language = sanitize_key( $maybe_default );
			}
		}

		$validation = hossam_validate_article_taxonomy_and_thumbnail( $data, $target_language );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		// The very list resolved pre-save goes to save — never the raw IDs,
		// never a second resolution after the write.
		$save_data = $data;
		if ( is_array( $validation ) && isset( $validation['resolved_category_ids'] ) && is_array( $validation['resolved_category_ids'] ) ) {
			$save_data['categories'] = array_values( array_map( 'absint', $validation['resolved_category_ids'] ) );
		}

		$postarr = [
			'post_type'    => 'post',
			'post_status'  => $status,
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'post_author'  => get_current_user_id(),
		];
		if ( isset( $data['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}
		$post_id = wp_insert_post(
			$postarr,
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$related_result = hossam_apply_article_taxonomy_and_thumbnail( $post_id, $save_data );
		if ( is_wp_error( $related_result ) ) {
			$deleted = wp_delete_post( $post_id, true );
			if ( ! $deleted || is_wp_error( $deleted ) || null !== get_post( $post_id ) ) {
				error_log( 'Hossam Dashboard draft cleanup failed for post ' . $post_id );
				return new WP_Error(
					'hossam_partial_failure',
					__( 'Article related data could not be saved and the draft cleanup failed.', 'astra-child' ),
					[ 'post_id' => $post_id, 'stage' => 'create_related_write', 'cleanup' => 'failed' ]
				);
			}

			return new WP_Error(
				'hossam_related_write_failed',
				$related_result->get_error_message(),
				[ 'stage' => 'create_related_write', 'cleanup' => 'done' ]
			);
		}

		return $post_id;
	}
}

if ( ! function_exists( 'hossam_update_article' ) ) {
	/**
	 * @param array{title?:string,content?:string,status?:string,excerpt?:string,categories?:array<int,int>,thumbnail_id?:int} $data
	 * @return bool|WP_Error
	 */
	function hossam_update_article( int $post_id, array $data ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'hossam_not_found', __( 'Article not found.', 'astra-child' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to edit this article.', 'astra-child' ) );
		}

		// v1.0.6 §5: the update target is the article's existing language.
		$target_language = '';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'hossam_wpml_get_element_language_code' ) ) {
			$target_language = hossam_wpml_get_element_language_code( $post_id );
		}

		$validation = hossam_validate_article_taxonomy_and_thumbnail( $data, $target_language );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		// The very list resolved pre-save goes to save — never the raw IDs,
		// never a second resolution after the write.
		$save_data = $data;
		if ( is_array( $validation ) && isset( $validation['resolved_category_ids'] ) && is_array( $validation['resolved_category_ids'] ) ) {
			$save_data['categories'] = array_values( array_map( 'absint', $validation['resolved_category_ids'] ) );
		}

		$postarr = [ 'ID' => $post_id ];

		if ( isset( $data['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $data['content'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}
		if ( isset( $data['status'] ) ) {
			$allowed_statuses = [ 'draft', 'pending' ];
			$status            = sanitize_key( $data['status'] );
			if ( 'publish' === $status ) {
				if ( ! current_user_can( 'publish_posts' ) ) {
					return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to publish articles.', 'astra-child' ) );
				}
				$allowed_statuses[] = 'publish';
			}
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$postarr['post_status'] = $status;
			}
		}

		$restore_main       = count( $postarr ) > 1;
		$restore_categories = isset( $data['categories'] );
		$restore_thumbnail  = isset( $data['thumbnail_id'] );
		$orig_post          = [
			'post_title'   => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_status'  => (string) $post->post_status,
			'post_excerpt' => isset( $post->post_excerpt ) ? (string) $post->post_excerpt : '',
		];
		$orig_categories = $restore_categories && function_exists( 'wp_get_post_categories' ) ? wp_get_post_categories( $post_id ) : [];
		if ( is_wp_error( $orig_categories ) ) {
			return new WP_Error( 'hossam_snapshot_failed', __( 'Article state could not be read before saving.', 'astra-child' ) );
		}
		$orig_thumbnail  = $restore_thumbnail && function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;

		if ( $restore_main ) {
			$result = wp_update_post( $postarr, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$related_result = hossam_apply_article_taxonomy_and_thumbnail( $post_id, $save_data );
		if ( is_wp_error( $related_result ) ) {
			$compensated = hossam_restore_article_snapshot(
				$post_id,
				$orig_post,
				$orig_categories,
				$orig_thumbnail,
				$restore_main,
				$restore_categories,
				$restore_thumbnail
			);
			if ( ! $compensated ) {
				return new WP_Error(
					'hossam_partial_failure',
					__( 'Article related data could not be saved and its previous state could not be fully restored.', 'astra-child' ),
					[ 'post_id' => $post_id, 'stage' => 'update_related_write', 'compensated' => false ]
				);
			}

			return new WP_Error(
				'hossam_related_write_failed',
				$related_result->get_error_message(),
				[ 'post_id' => $post_id, 'stage' => 'update_related_write', 'compensated' => true ]
			);
		}

		return true;
	}
}

if ( ! function_exists( 'hossam_trash_article' ) ) {
	/**
	 * @return bool|WP_Error
	 */
	function hossam_trash_article( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'hossam_not_found', __( 'Article not found.', 'astra-child' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to delete this article.', 'astra-child' ) );
		}

		return false !== wp_trash_post( $post_id );
	}
}

if ( ! function_exists( 'hossam_restore_article' ) ) {
	/**
	 * Restores a trashed article. Same contract as trash/update: the resource
	 * must be a real post and the current user must pass edit_post on it —
	 * map_meta_cap resolves author/editor/admin without manual post_author checks.
	 *
	 * @return bool|WP_Error
	 */
	function hossam_restore_article( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'hossam_not_found', __( 'Article not found.', 'astra-child' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'hossam_forbidden', __( 'You do not have permission to edit this article.', 'astra-child' ) );
		}

		return false !== wp_untrash_post( $post_id );
	}
}

if ( ! function_exists( 'hossam_validate_article_taxonomy_and_thumbnail' ) ) {
	/**
	 * v1.0.6 §5 — pre-save validation. Takes the target language and resolves
	 * every category through the WPML contract (§6) BEFORE any insert/update:
	 * success returns array('resolved_category_ids' => int[]) alongside the
	 * result (boolean true when no categories were supplied); any failure is
	 * hossam_invalid_categories (or the thumbnail errors). Zero is never
	 * passed to save and nothing is dropped silently.
	 *
	 * @return array{resolved_category_ids:array<int,int>}|true|WP_Error
	 */
	function hossam_validate_article_taxonomy_and_thumbnail( array $data, string $target_language = '' ) {
		if ( isset( $data['categories'] ) ) {
			if ( ! is_array( $data['categories'] ) ) {
				return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
			}
			$input_ids = array();
			foreach ( $data['categories'] as $term_id ) {
				if ( ! is_scalar( $term_id ) || ! is_numeric( $term_id ) ) {
					return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
				}
				$tid = absint( $term_id );
				if ( $tid < 1 || ! term_exists( $tid, 'category' ) ) {
					return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
				}
				$input_ids[] = $tid;
			}
			$target_language = sanitize_key( $target_language );
			if ( '' === $target_language || ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
				return array( 'resolved_category_ids' => $input_ids );
			}
			// Guard 1 (official hook reference): an untranslated taxonomy
			// keeps the IDs as-is, with no conversion.
			$taxonomy_translated = false;
			if ( has_filter( 'wpml_is_translated_taxonomy' ) ) {
				$taxonomy_translated = (bool) apply_filters( 'wpml_is_translated_taxonomy', false, 'category' );
			}
			if ( ! $taxonomy_translated ) {
				return array( 'resolved_category_ids' => $input_ids );
			}
			// Guard 2: the target language must be active — early rejection.
			if ( has_filter( 'wpml_language_is_active' ) && ! (bool) apply_filters( 'wpml_language_is_active', false, $target_language ) ) {
				return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
			}
			$default_language = '';
			$maybe_default    = apply_filters( 'wpml_default_language', null );
			if ( is_string( $maybe_default ) && '' !== $maybe_default ) {
				$default_language = sanitize_key( $maybe_default );
			}
			$term_element_type = apply_filters( 'wpml_element_type', 'category' );
			if ( ! is_string( $term_element_type ) || '' === $term_element_type ) {
				$term_element_type = 'category';
			}
			$resolved_ids = array();
			foreach ( $input_ids as $input_term_id ) {
				// Target language first with return_original_if_missing=false;
				// on a missing target translation fall back to an explicit
				// default-language request — never to the implicit original.
				$mapped_term_id    = function_exists( 'hossam_wpml_term_id_for_language' )
					? hossam_wpml_term_id_for_language( $input_term_id, $target_language )
					: 0;
				$expected_language = $target_language;
				if ( $mapped_term_id < 1 && '' !== $default_language && $default_language !== $target_language && function_exists( 'hossam_wpml_term_id_for_language' ) ) {
					$mapped_term_id    = hossam_wpml_term_id_for_language( $input_term_id, $default_language );
					$expected_language = $default_language;
				}
				if ( $mapped_term_id < 1 || ! term_exists( $mapped_term_id, 'category' ) ) {
					return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
				}
				// Verify the result language against its term_taxonomy_id (§5/§6).
				$term_taxonomy_id = $mapped_term_id;
				if ( function_exists( 'get_term' ) ) {
					$term_object = get_term( $mapped_term_id );
					if ( $term_object instanceof WP_Term && isset( $term_object->term_taxonomy_id ) ) {
						$term_taxonomy_id = (int) $term_object->term_taxonomy_id;
					} elseif ( is_array( $term_object ) && isset( $term_object['term_taxonomy_id'] ) ) {
						$term_taxonomy_id = (int) $term_object['term_taxonomy_id'];
					}
				}
				$result_language = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $term_taxonomy_id, 'element_type' => $term_element_type ] );
				if ( ! is_string( $result_language ) || sanitize_key( $result_language ) !== $expected_language ) {
					return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
				}
				$resolved_ids[] = $mapped_term_id;
			}
			return array( 'resolved_category_ids' => $resolved_ids );
		}
		if ( isset( $data['thumbnail_id'] ) ) {
			if ( ! is_scalar( $data['thumbnail_id'] ) || ! is_numeric( $data['thumbnail_id'] ) ) {
				return new WP_Error( 'hossam_invalid_thumbnail', __( 'The selected featured image is invalid.', 'astra-child' ) );
			}
			$thumbnail_id = absint( $data['thumbnail_id'] );
			if ( $thumbnail_id > 0 ) {
				if ( ! wp_attachment_is_image( $thumbnail_id ) ) {
					return new WP_Error( 'hossam_invalid_thumbnail', __( 'The selected featured image is invalid.', 'astra-child' ) );
				}
				if ( ! current_user_can( 'edit_post', $thumbnail_id ) ) {
					return new WP_Error( 'hossam_thumbnail_forbidden', __( 'You do not have permission to use this featured image.', 'astra-child' ) );
				}
			}
		}
		return true;
	}
}

if ( ! function_exists( 'hossam_wpml_translation_runtime_ready' ) ) {
	/**
	 * v1.0.6 §14 — full WPML runtime gate for the translations panel.
	 *
	 * Completes the Group C partial gate (contract-level has_filter checks):
	 * after `wpml_loaded`, every interface the panel and the translate
	 * endpoint consume must be registered — the nine filters
	 * (wpml_active_languages, wpml_element_language_details,
	 * wpml_element_language_code, wpml_element_trid,
	 * wpml_get_element_translations, wpml_object_id, wpml_default_language,
	 * wpml_is_translated_taxonomy, wpml_language_is_active) plus the
	 * wpml_set_element_language_details action — and wpml_active_languages
	 * must return an array. No translation writes happen before this gate
	 * passes: the panel disables its translation UI safely (articles stay
	 * intact) and the textual reason follows the integration-capability
	 * log pattern (setup.php reason strings for the next version).
	 *
	 * Stubs prove branches only — never a live WPML integration.
	 *
	 * @return array{ready:bool,reason:string}
	 */
	function hossam_wpml_translation_runtime_ready(): array {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return array( 'ready' => false, 'reason' => 'WPML is not active on this site.' );
		}
		if ( ! function_exists( 'did_action' ) ) {
			return array( 'ready' => false, 'reason' => 'WPML runtime probe is unavailable (did_action).' );
		}
		if ( did_action( 'wpml_loaded' ) < 1 ) {
			return array( 'ready' => false, 'reason' => 'WPML runtime has not finished loading (wpml_loaded).' );
		}
		if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) ) {
			return array( 'ready' => false, 'reason' => 'WPML hook probe is unavailable.' );
		}
		$required_filters = array(
			'wpml_active_languages',
			'wpml_element_language_details',
			'wpml_element_language_code',
			'wpml_element_trid',
			'wpml_get_element_translations',
			'wpml_object_id',
			'wpml_default_language',
			'wpml_is_translated_taxonomy',
			'wpml_language_is_active',
		);
		foreach ( $required_filters as $hook_name ) {
			if ( ! has_filter( $hook_name ) ) {
				return array( 'ready' => false, 'reason' => 'WPML interface is not registered: ' . $hook_name . '.' );
			}
		}
		// Actions share the hook registry: has_action when WordPress
		// provides it, otherwise the equivalent has_filter probe.
		$has_link_action = function_exists( 'has_action' )
			? (bool) has_action( 'wpml_set_element_language_details' )
			: (bool) has_filter( 'wpml_set_element_language_details' );
		if ( ! $has_link_action ) {
			return array( 'ready' => false, 'reason' => 'WPML interface is not registered: wpml_set_element_language_details.' );
		}
		// The array proof reads the raw filter answer: the adapter helper
		// coerces non-arrays to [] and would mask a broken contract.
		$active_languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $active_languages ) ) {
			return array( 'ready' => false, 'reason' => 'WPML did not return an active-languages array.' );
		}
		return array( 'ready' => true, 'reason' => '' );
	}
}

if ( ! function_exists( 'hossam_restore_article_snapshot' ) ) {
	/**
	 * Restores and then re-reads every state touched by an article update.
	 * A successful Core return alone is not enough to claim compensation.
	 *
	 * @param array{post_title:string,post_content:string,post_status:string,post_excerpt:string} $orig_post
	 * @param array<int,int>   $orig_categories
	 */
	function hossam_restore_article_snapshot(
		int $post_id,
		array $orig_post,
		array $orig_categories,
		int $orig_thumbnail,
		bool $restore_main,
		bool $restore_categories,
		bool $restore_thumbnail
	): bool {
		$all_restored = true;
		if ( $restore_main ) {
			$restore = wp_update_post(
				[
					'ID'           => $post_id,
					'post_title'   => $orig_post['post_title'],
					'post_content' => $orig_post['post_content'],
					'post_status'  => $orig_post['post_status'],
					'post_excerpt' => $orig_post['post_excerpt'],
				],
				true
			);
			$restored_post = get_post( $post_id );
			if ( is_wp_error( $restore ) || 0 === $restore || ! $restored_post
				|| $restored_post->post_title !== $orig_post['post_title']
				|| $restored_post->post_content !== $orig_post['post_content']
				|| $restored_post->post_status !== $orig_post['post_status']
				|| (string) ( $restored_post->post_excerpt ?? '' ) !== $orig_post['post_excerpt'] ) {
				$all_restored = false;
				error_log( 'Hossam Dashboard post rollback failed for post ' . $post_id );
			}
		}

		if ( $restore_categories ) {
			$cat_restore         = wp_set_post_categories( $post_id, $orig_categories );
			$current_categories  = wp_get_post_categories( $post_id );
			$expected_categories = array_values( array_map( 'absint', $orig_categories ) );
			if ( is_array( $current_categories ) ) {
				$current_categories = array_values( array_map( 'absint', $current_categories ) );
				sort( $current_categories );
				sort( $expected_categories );
			}
			if ( false === $cat_restore || is_wp_error( $cat_restore ) || ! is_array( $current_categories ) || $current_categories !== $expected_categories ) {
				$all_restored = false;
				error_log( 'Hossam Dashboard category rollback failed for post ' . $post_id );
			}
		}

		if ( $restore_thumbnail ) {
			if ( $orig_thumbnail > 0 ) {
				$thumbnail_restore = set_post_thumbnail( $post_id, $orig_thumbnail );
			} else {
				$thumbnail_restore = delete_post_thumbnail( $post_id );
			}
			if ( false === $thumbnail_restore && (int) get_post_thumbnail_id( $post_id ) !== $orig_thumbnail ) {
				$all_restored = false;
				error_log( 'Hossam Dashboard thumbnail rollback failed for post ' . $post_id );
			} elseif ( (int) get_post_thumbnail_id( $post_id ) !== $orig_thumbnail ) {
				$all_restored = false;
				error_log( 'Hossam Dashboard thumbnail rollback verification failed for post ' . $post_id );
			}
		}

		return $all_restored;
	}
}

if ( ! function_exists( 'hossam_apply_article_taxonomy_and_thumbnail' ) ) {
	/**
	 * Shared by create/update: validates categories against the real taxonomy and
	 * the featured image against the real attachment before writing either one.
	 *
	 * @param array{categories?:array<int,int>,thumbnail_id?:int} $data
	 */
	function hossam_apply_article_taxonomy_and_thumbnail( int $post_id, array $data ) {
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			// v1.0.6 §5: IDs arrive already resolved pre-save — apply them
			// verbatim (no silent drop, no 0), then read back and compare.
			$categories      = array_values( array_map( 'absint', $data['categories'] ) );
			$category_result = wp_set_post_categories( $post_id, $categories );
			if ( false === $category_result || is_wp_error( $category_result ) ) {
				return is_wp_error( $category_result )
					? $category_result
					: new WP_Error( 'hossam_categories_failed', __( 'Article categories could not be saved.', 'astra-child' ) );
			}
			$stored_categories = function_exists( 'wp_get_post_categories' ) ? wp_get_post_categories( $post_id ) : $categories;
			if ( is_wp_error( $stored_categories ) || ! is_array( $stored_categories ) ) {
				return new WP_Error( 'hossam_categories_failed', __( 'Article categories could not be saved.', 'astra-child' ) );
			}
			$stored_compare   = array_values( array_map( 'absint', $stored_categories ) );
			$expected_compare = $categories;
			sort( $stored_compare );
			sort( $expected_compare );
			if ( $stored_compare !== $expected_compare ) {
				return new WP_Error( 'hossam_categories_failed', __( 'Article categories could not be saved.', 'astra-child' ) );
			}
		}

		if ( isset( $data['thumbnail_id'] ) ) {
			$thumbnail_id = absint( $data['thumbnail_id'] );
			if ( $thumbnail_id > 0 ) {
				if ( ! wp_attachment_is_image( $thumbnail_id ) ) {
					return new WP_Error( 'hossam_invalid_thumbnail', __( 'The selected featured image is invalid.', 'astra-child' ) );
				}
				if ( ! current_user_can( 'edit_post', $thumbnail_id ) ) {
					return new WP_Error( 'hossam_thumbnail_forbidden', __( 'You do not have permission to use this featured image.', 'astra-child' ) );
				}
				$thumbnail_result = set_post_thumbnail( $post_id, $thumbnail_id );
				if ( false === $thumbnail_result && get_post_thumbnail_id( $post_id ) !== $thumbnail_id ) {
					return new WP_Error( 'hossam_thumbnail_failed', __( 'The featured image could not be saved.', 'astra-child' ) );
				}
			} elseif ( 0 === $thumbnail_id ) {
				// v1.0.6 §5: the explicit removal signal (0 — never -1) deletes
				// the thumbnail, verified by re-reading.
				if ( function_exists( 'delete_post_thumbnail' ) ) {
					delete_post_thumbnail( $post_id );
				}
				if ( function_exists( 'get_post_thumbnail_id' ) && 0 !== (int) get_post_thumbnail_id( $post_id ) ) {
					return new WP_Error( 'hossam_thumbnail_failed', __( 'The featured image could not be saved.', 'astra-child' ) );
				}
			}
		}

		return true;
	}
}
