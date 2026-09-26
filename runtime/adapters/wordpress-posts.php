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
	 * @param array{title?:string,content?:string,status?:string,categories?:array<int,int>,thumbnail_id?:int} $data
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

		$validation = hossam_validate_article_taxonomy_and_thumbnail( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'post',
				'post_status'  => $status,
				'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
				'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
				'post_author'  => get_current_user_id(),
			],
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$related_result = hossam_apply_article_taxonomy_and_thumbnail( $post_id, $data );
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
	 * @param array{title?:string,content?:string,status?:string,categories?:array<int,int>,thumbnail_id?:int} $data
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

		$validation = hossam_validate_article_taxonomy_and_thumbnail( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$postarr = [ 'ID' => $post_id ];

		if ( isset( $data['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $data['content'] );
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

		$related_result = hossam_apply_article_taxonomy_and_thumbnail( $post_id, $data );
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
	function hossam_validate_article_taxonomy_and_thumbnail( array $data ) {
		if ( isset( $data['categories'] ) ) {
			if ( ! is_array( $data['categories'] ) ) {
				return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
			}
			foreach ( $data['categories'] as $term_id ) {
				if ( ! is_scalar( $term_id ) || ! is_numeric( $term_id ) ) {
					return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
				}
				$tid = absint( $term_id );
				if ( $tid < 1 || ! term_exists( $tid, 'category' ) ) {
					return new WP_Error( 'hossam_invalid_categories', __( 'Article categories are invalid.', 'astra-child' ) );
				}
			}
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

if ( ! function_exists( 'hossam_restore_article_snapshot' ) ) {
	/**
	 * Restores and then re-reads every state touched by an article update.
	 * A successful Core return alone is not enough to claim compensation.
	 *
	 * @param array{post_title:string,post_content:string,post_status:string} $orig_post
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
				],
				true
			);
			$restored_post = get_post( $post_id );
			if ( is_wp_error( $restore ) || 0 === $restore || ! $restored_post
				|| $restored_post->post_title !== $orig_post['post_title']
				|| $restored_post->post_content !== $orig_post['post_content']
				|| $restored_post->post_status !== $orig_post['post_status'] ) {
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
			$categories = array_values(
				array_filter(
					array_map( 'absint', $data['categories'] ),
					function ( int $term_id ): bool {
						return $term_id > 0 && term_exists( $term_id, 'category' );
					}
				)
			);
			$category_result = wp_set_post_categories( $post_id, $categories );
			if ( false === $category_result || is_wp_error( $category_result ) ) {
				return is_wp_error( $category_result )
					? $category_result
					: new WP_Error( 'hossam_categories_failed', __( 'Article categories could not be saved.', 'astra-child' ) );
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
			}
		}

		return true;
	}
}
