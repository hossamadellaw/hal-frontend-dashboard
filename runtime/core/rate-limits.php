<?php
/**
 * runtime/core/rate-limits.php — وحدة الحدود المركزية (v1.1.0 — صف R1 — ملف جديد)
 * ══════════════════════════════════════════════════════════════
 * الدور: الدالة الموحدة لاستهلاك حصة الكتابة + سقف revisions + حمايتا
 * هوك الرفع (حد الحجم + تقاطع MIME + العدّ) + إفصاح fileinfo.
 *
 * المصدر الحاكم: G3-RATE-LIMITS-POSITION.md — §1.1 (عقد الدالة الثماني)،
 * §1.2 (وصفة التأهيل)، §1.4 (فرع object cache)، §2 (سقف revisions)،
 * §4 بنود 1-13 — وG6-IO-POSITION.md §7-أ/7-ب/7-ج و§4 بنود 1-3.
 * النمط القائم المنقول: runtime/ajax/inbox.php:36-60 (قراءة→فحص→كتابة
 * داخل GET_LOCK مع RELEASE في finally).
 *
 * عقد الدالة hossam_rate_limit_consume الثماني (G3 §1.1 حرفيًا):
 *   1. الاستهلاك بعد اكتمال كل التحققات (nonce/401/feature/caps/ملكية/
 *      تطهير المدخلات) وقبل الكتابة الفعلية مباشرة — بلا تراجع (فشل
 *      الكتابة اللاحقة يهدر وحدة واحدة ≤1؛ نمط تراجع ai.php لا يُعمم).
 *   2. مفتاح transient `hossam_rl_{sanitize_key(scope)}_{user_id}` بعتبة
 *      limit وTTL window_seconds — نافذة منزلقة (كل كتابة تجدد TTL).
 *      النافذة اليومية: مفتاح `hossam_rl_{scope}_day_{user_id}` بنافذة
 *      DAY_IN_SECONDS — منزلقة 24 ساعة عمدًا، والفرق عن يوم ai.php
 *      التقويمي UTC موثق مقصود (G3 §1.1-2 / V3 §3-د).
 *   3. GET_LOCK(اسم مؤهَّل §1.2, 2) قبل القراءة؛ غير 1 ⇒ 503 مغلقًا
 *      (لا كتابة تمر)؛ RELEASE_LOCK في finally على كل المسارات
 *      (نجاح/429/503/استثناء).
 *   4. فشل set_transient ⇒ 503 مغلقًا (لا نمرير عند العطب).
 *   5. الرفض 429 بعائلة القائم حرفيًا — WP_Error 'rate_limit' برسالة
 *      'Rate limit exceeded. Try later.' وerror_data['status']=429 تترجمها
 *      نقطة الاستدعاء بنمط inbox.php:225-232. بلا كشف بقية الحصة.
 *   6. بلا إعفاء manage_options — الحد يحمي أيضًا من الجلسة المخترقة.
 *   7. لا يُستدعى داخل hossam_ai_insert_job_under_quota (تداخل GET_LOCK
 *      على اتصال واحد) — ai.php لا يُلمس في هذه الدفعة إطلاقًا.
 *   8. الموضع: هذا الملف + سطرا require في runtime/bootstrap.php بعد
 *      كتلة core وقبل settings/adapters/ajax. القيم 429/503 ثابتة العقد —
 *      لا options ولا UI للعتبات (فلسفة setup.php).
 *
 * رسالتا الـ503 المغلقتان (T1 يطابقهما حرفيًا — G3 §1.1):
 *   'quota_lock_failed'  [503] 'Service is busy. Try again.'
 *   'quota_write_failed' [503] 'Service is unavailable. Try again.'
 *   (وعائلة الرفض: 'rate_limit' [429] 'Rate limit exceeded. Try later.')
 *
 * فرع wp_using_ext_object_cache() (G3 §1.4/م3): يُتخطى القفل — مسار
 * ذري add(0)→incr على مجموعة 'hossam_rl'؛ فشل add لأن المفتاح قائم ⇒
 * incr مباشر؛ فشل incr ⇒ إعادة add+incr مرة واحدة؛ ثانية فشل ⇒ 503
 * مغلقًا؛ الفحص بعد الزيادة (reject-after عند n > limit) — تجاوز محدود
 * بالطلبات المتوازية في النافذة، مقبول موثق (V3 ن5).
 *
 * ═══ إعلان الوصول (G6 §7-ب-أ — يُنقل للمالك في حزمة الاعتماد) ═══
 * هوك wp_handle_upload_prefilter موقعي النطاق: يمر عليه حصرًا كل مسار
 * رفع عبر wp_handle_upload/_wp_handle_upload (file.php:833 — منذ 2.9.0):
 * مسارا AJAX للإضافة (hossam_upload_file → media_handle_upload في
 * adapters/wordpress-uploads.php:83) + wp-admin/async-upload + REST
 * wp/v2/media (wp_handle_upload داخل upload_from_file). أي أن حمايتي
 * الحجم وMIME والعدّ تسري على رفع wp-admin الرسمي ذاته — وصول موقعي
 * معلن للمالك في حزمة الاعتماد، لا يُمرَّر صمتًا. التقاطع MIME
 * array_intersect_assoc لا يوسع سياسة الموقع أبدًا (عقد
 * wordpress-uploads.php:43 محفوظ حرفيًا) — وممنوع فلتر upload_mimes
 * عالمي دائم (§ب-49).
 *
 * ليس من هذا الملف: nonce المشروط لسطح الرفع (صف R31 — ملف setup.php).
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HOSSAM_UPLOADS_MAX_FILE_SIZE' ) ) {
	// ق2 (G6 §7-أ): حد حجم لكل ملف — 8 ميغابايت افتراضيًا، قابل للضبط
	// بفلتر واحد hossam_uploads_max_file_size (القيمة 0 = تعطيل الحد).
	define( 'HOSSAM_UPLOADS_MAX_FILE_SIZE', 8388608 );
}

if ( ! function_exists( 'hossam_rate_limit_get_lock_name' ) ) {
	/**
	 * تأهيل القفل المسماى الموحد — نسخة مطابقة لوصفة ai.php:85-97 (F-04)
	 * ببادئة مساحة أسماء خاصة `hossam_rl_` حتى لا تتصادم مع `hossam_ai_`.
	 * الهوية: DB_HOST\0DB_NAME\0prefix\0blog_id\0{scope}:{user_id} —
	 * sha256[:40] (G3 §1.2 حرفيًا). عزل قاعدة + prefix + موقع + مورد.
	 *
	 * @param string $scope   مفتاح النطاق (upload/create/msg/...).
	 * @param int    $user_id معرف المستخدم صاحب الحصة.
	 * @return string اسم قفل GET_LOCK مؤهَّل بالبيئة والموقع والمورد.
	 */
	function hossam_rate_limit_get_lock_name( string $scope, int $user_id ): string {
		global $wpdb;

		$database = ( defined( 'DB_HOST' ) ? (string) DB_HOST : '' ) . "\0"
			. ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
		$blog_id = function_exists( 'get_current_blog_id' )
			? (int) get_current_blog_id()
			: (int) ( $wpdb->blogid ?? 0 );
		$resource = $scope . ':' . $user_id;
		$identity = $database . "\0" . (string) $wpdb->prefix . "\0" . $blog_id . "\0" . $resource;

		return 'hossam_rl_' . substr( hash( 'sha256', $identity ), 0, 40 );
	}
}

if ( ! function_exists( 'hossam_rate_limit_consume' ) ) {
	/**
	 * يستهلك وحدة واحدة من حصة {scope} للمستخدم تحت قفل مسمى مؤهَّل —
	 * عقد inbox.php:36-60 (قراءة→فحص→كتابة داخل GET_LOCK + RELEASE في
	 * finally) بتأهيل وصفة ai.php:85-97، بلا تراجع (G3 §1.1 — العقد
	 * الثماني كاملًا في توثيق رأس الملف).
	 *
	 * @param string $scope          مفتاح النطاق (يُطبَّع sanitize_key في المفتاح).
	 * @param int    $user_id        معرف المستخدم.
	 * @param int    $limit          عتبة النافذة (الحد الأقصى الممنوح).
	 * @param int    $window_seconds عمر النافذة بالثواني (60/86400 — منزلقة).
	 * @return true|WP_Error true = ممنوح
	 *   'rate_limit'         [429] 'Rate limit exceeded. Try later.'
	 *   'quota_lock_failed'  [503] 'Service is busy. Try again.'
	 *   'quota_write_failed' [503] 'Service is unavailable. Try again.'
	 */
	function hossam_rate_limit_consume( string $scope, int $user_id, int $limit, int $window_seconds ): true|WP_Error {
		global $wpdb;

		$key = 'hossam_rl_' . sanitize_key( $scope ) . '_' . $user_id;

		// فرع object cache الخارجي (G3 §1.4): يُتخطى القفل — wp_cache_incr
		// ذري؛ الفحص بعد الزيادة (reject-after). الفحص runtime في كل نداء
		// بلا إعداد يدوي.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'hossam_rl', $window_seconds );
			$count = wp_cache_incr( $key, 1, 'hossam_rl' );
			if ( false === $count ) {
				// إعادة add+incr مرة واحدة؛ ثانية فشل ⇒ 503 مغلقًا.
				wp_cache_add( $key, 0, 'hossam_rl', $window_seconds );
				$count = wp_cache_incr( $key, 1, 'hossam_rl' );
				if ( false === $count ) {
					return new WP_Error( 'quota_write_failed', 'Service is unavailable. Try again.', [ 'status' => 503 ] );
				}
			}
			if ( $count > $limit ) {
				return new WP_Error( 'rate_limit', 'Rate limit exceeded. Try later.', [ 'status' => 429 ] );
			}

			return true;
		}

		// المسار الافتراضي (transient): GET_LOCK قبل القراءة — transient ليس
		// ذريًا read-modify-write (S2/S3)؛ المهلة 2s كلفة محصورة بنفس
		// المستخدم لأن القفل مؤهَّل به (V3 ن9).
		$lock_name = hossam_rate_limit_get_lock_name( $scope, $user_id );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
		if ( 1 !== $locked ) {
			return new WP_Error( 'quota_lock_failed', 'Service is busy. Try again.', [ 'status' => 503 ] );
		}

		try {
			$cnt = (int) get_transient( $key );
			if ( $cnt >= $limit ) {
				// الرفض بلا زيادة — العدّاد بعد الرفض بلا مساس (G3 §4-1).
				return new WP_Error( 'rate_limit', 'Rate limit exceeded. Try later.', [ 'status' => 429 ] );
			}

			// الكتابة تتجدد TTL ⇒ نافذة منزلقة (كل كتابة تجدد العمر).
			if ( ! set_transient( $key, $cnt + 1, $window_seconds ) ) {
				return new WP_Error( 'quota_write_failed', 'Service is unavailable. Try again.', [ 'status' => 503 ] );
			}

			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

if ( ! function_exists( 'hossam_rate_limit_revisions_to_keep' ) ) {
	/**
	 * ق4 (G3 §2/م6): سقف revisions — فلتر النواة `wp_revisions_to_keep`
	 * (revision.php:829 — منذ 3.6.0) بقيمة 10، نطاق الموقع كله (كل الأنواع
	 * الداعمة لrevisions؛ المرفقات وغير الداعم مستثناة بالنواة ذاتها = 0).
	 *
	 * سلم الضبط الثلاثي (الأدق فوق الأعم):
	 *   1. ثابت WP_POST_REVISIONS (النواة — الافتراضي true = بلا حد).
	 *   2. الفلتر العام wp_revisions_to_keep (هذا الفلتر — يعيد 10).
	 *   3. الفلتر النوعي wp_{$post_type}_revisions_to_keep (منذ 5.8.0 —
	 *      يتفوق على الاثنين معًا — نقطة الضبط الأدق للمالك لاحقًا).
	 *
	 * دلالة C2 (G3 §4-12): الحفظ المتطابق لا ينشئ revision ولا يحذف شيئًا
	 * (normalize_whitespace مع آخر revision)؛ الحفظ المتغير الحادي عشر
	 * يحذف الأقدم (عدّ revisions يثبت عند 10)؛ autosaves مستثناة من
	 * التقليم (revision.php:250-253)؛ و`preview` صفر كتابات (المعاينة
	 * تقرأ المسودة ذاتها لا revisions). مع حد 30 حفظًا/دقيقة يصير النمو
	 * التراكمي مقيدًا بسقف مطلق 10 revisions لكل مقال مهما كان الهجوم.
	 *
	 * @param mixed $num  القيمة الواردة من السلم الأدنى (تُستبدل بقيمة 10).
	 * @param mixed $post كائن المقال (غير مستعمل — السقف موقعي النطاق).
	 * @return int 10 دائمًا.
	 */
	function hossam_rate_limit_revisions_to_keep( $num, $post ): int {
		unset( $num, $post );

		return 10;
	}
}

add_filter( 'wp_revisions_to_keep', 'hossam_rate_limit_revisions_to_keep', 10, 2 );

if ( ! function_exists( 'hossam_rate_limit_human_size' ) ) {
	/**
	 * حجم مقروء بالعربية لرسالة تجاوز الحد (بايت/كيلوبايت/ميغابايت/جيجابايت).
	 *
	 * @param int $bytes الحجم بالبايت.
	 * @return string نص عربي مقروء.
	 */
	function hossam_rate_limit_human_size( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return sprintf( '%.1f جيجابايت', $bytes / 1073741824 );
		}
		if ( $bytes >= 1048576 ) {
			return sprintf( '%.1f ميغابايت', $bytes / 1048576 );
		}
		if ( $bytes >= 1024 ) {
			return sprintf( '%.1f كيلوبايت', $bytes / 1024 );
		}

		return sprintf( '%d بايت', $bytes );
	}
}

if ( ! function_exists( 'hossam_rate_limit_upload_prefilter' ) ) {
	/**
	 * هوك الرفع الواحد — حمايتان + العدّ (G6 §7-أ/7-ب + G3 §1.3 صفوف 6-7):
	 *   (1) حد الحجم: ثابت HOSSAM_UPLOADS_MAX_FILE_SIZE (8MB) مع فلتر
	 *       `hossam_uploads_max_file_size` (0 = تعطيل) — التجاوز يضبط
	 *       $file['error'] برسالة عربية بالحجم والحد والنواة تجهض قبل أي
	 *       كتابة ملف (file.php:867-869).
	 *   (2) تقاطع MIME: فحص امتداد $file['name'] عبر
	 *       wp_check_filetype($file['name'], hossam_get_allowed_upload_mimes())
	 *       بنفس دلالة wordpress-uploads.php:69 — الرفض عند فراغ ext/type؛
	 *       التقاطع array_intersect_assoc لا يوسع سياسة الموقع أبدًا،
	 *       والاعتماد النووي العميق (wp_check_filetype_and_ext بالمحتوى
	 *       الحقيقي) شبكة ثانية باقية.
	 *   (3) العدّ: upload 10/د + upload_day 100/يوم — نداءا
	 *       hossam_rate_limit_consume بعد اجتياز الفحصين (الملف المرفوض
	 *       بالحجم أو النوع لا يستهلك حصة) وبلا عدّ مزدوج: نقطة
	 *       الاختناق الرسمية الواحدة تفرض الحد أيًّا كانت واجهة الرفع.
	 *       رفض الحصة يضبط $file['error'] بعائلة الرسائل القائمة حرفيًا
	 *       ('Rate limit exceeded. Try later.') بلا كشف الحصة وبلا حالة
	 *       HTTP يدوية (عقد النواة لا يُعبث به).
	 *
	 * إعلان الوصول الموقعي (G6 §7-ب-أ) في توثيق رأس الملف — يمر عليه
	 * AJAX + wp-admin/async-upload + REST wp/v2/media.
	 *
	 * @param array $file مصفوفة الملف الواردة من النواة (تضم name/size).
	 * @return array المصفوفة ذاتها مع $file['error'] عند الرفض.
	 */
	function hossam_rate_limit_upload_prefilter( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}

		// (1) حد الحجم — قبل أي كتابة.
		$max_bytes = (int) apply_filters( 'hossam_uploads_max_file_size', (int) HOSSAM_UPLOADS_MAX_FILE_SIZE );
		if ( $max_bytes > 0 && isset( $file['size'] ) && is_numeric( (string) $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			$file['error'] = sprintf(
				'حجم الملف (%1$s) بمقدار %2$s يتجاوز الحد المسموح %3$s — يرجى ضغط الملف أو رفع نسخة أصغر.',
				(string) ( $file['name'] ?? '' ),
				hossam_rate_limit_human_size( (int) $file['size'] ),
				hossam_rate_limit_human_size( $max_bytes )
			);

			return $file;
		}

		// (2) تقاطع MIME — بنفس دلالة wordpress-uploads.php:69 (التقاطع لا يوسع أبدًا).
		if ( isset( $file['name'] ) && is_string( $file['name'] ) && '' !== $file['name'] ) {
			$filetype = wp_check_filetype( $file['name'], hossam_get_allowed_upload_mimes() );
			if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
				$file['error'] = 'نوع الملف غير مسموح.';

				return $file;
			}
		}

		// (3) العدّ — 10/دقيقة ثم 100/يوم (مفتاح يومي منزلق 24 ساعة)؛
		//     بلا عدّ مزدوج: استدعاء واحد للرفعة الواحدة عند النقطة الرسمية.
		$user_id = (int) get_current_user_id();

		$minute = hossam_rate_limit_consume( 'upload', $user_id, 10, MINUTE_IN_SECONDS );
		if ( is_wp_error( $minute ) ) {
			$file['error'] = 'Rate limit exceeded. Try later.';

			return $file;
		}

		$daily = hossam_rate_limit_consume( 'upload_day', $user_id, 100, DAY_IN_SECONDS );
		if ( is_wp_error( $daily ) ) {
			$file['error'] = 'Rate limit exceeded. Try later.';

			return $file;
		}

		return $file;
	}
}

add_filter( 'wp_handle_upload_prefilter', 'hossam_rate_limit_upload_prefilter' );

if ( ! function_exists( 'hossam_rate_limit_fileinfo_loaded' ) ) {
	/**
	 * نتيجة فحص امتداد fileinfo — بذاكرة per-request (نمط المعتمد C1):
	 * الفحص الأول بلا استعلام، والنتيجة ثابتة لبقية الطلب (G6 §7-ج-1).
	 *
	 * @return bool هل الامتداد محمَّل.
	 */
	function hossam_rate_limit_fileinfo_loaded(): bool {
		static $loaded = null;
		if ( null === $loaded ) {
			$loaded = extension_loaded( 'fileinfo' );
		}

		return $loaded;
	}
}

if ( ! function_exists( 'hossam_rate_limit_fileinfo_notice' ) ) {
	/**
	 * إفصاح fileinfo (G6 §7-ج-2 / V2-12): عند غياب الامتداد — إشعار إداري
	 * دائم حصرًا لـmanage_options يوصي بتمكين امتداد PHP `fileinfo`.
	 * بلا آلية رفض/إخفاء لكل مستخدم وبلا خيار جديد (الإشعار يزول ذاتيًا
	 * بمجرد تمكين الامتداد). السلوك البديل الموثق: بلا fileinfo يعتمد
	 * الفحص العميق للمحتوى في wp_check_filetype_and_ext على الامتداد —
	 * الامتداد المخزن يبقى من القائمة المسموحة والرفض بالقائمة المقيّدة
	 * باقٍ (توثيق الرفع R26 يفصّله). لا كود تفتيش يدوي بديل إطلاقًا.
	 *
	 * @return void
	 */
	function hossam_rate_limit_fileinfo_notice(): void {
		if ( hossam_rate_limit_fileinfo_loaded() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html( 'امتداد PHP «fileinfo» غير مفعّل على هذا الخادم — يرجى تمكينه لدعم الفحص العميق للمحتوى عند رفع الملفات.' )
			. '</p></div>';
	}
}

add_action( 'admin_notices', 'hossam_rate_limit_fileinfo_notice' );
