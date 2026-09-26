<?php
declare( strict_types=1 );
/**
 * runtime/templates/dashboard.php — Shell قالب لوحة التحكم (الدفعة 7)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/page-dashboard.php (بصمة المصدر بالبايت مثبتة
 * في inventory الدفعة 7 — build/source-inventory-batch-7.json، snapshot
 * 67044A2FDB43D661F888904CDF8E2802445CEF8960D89C31F688E7D2C1A0BDB1).
 *
 * الدلتا المصرَّح بها الوحيدة (عقد §18 صف page-dashboard.php: «إزالة
 * Template Header والاعتماد على theme؛ محمل أجزاء Runtime؛ حفظ Auth
 * Guard وshell» + قرار المالك B3-08 — الخيار أ §15.14/§15.16/§15.19):
 *   1. استدعاءات get_template_part() الاثنا عشر استُبدلت بمحمل أجزاء
 *      Runtime حصرًا: HAL_Frontend_Dashboard_Template_Controller::
 *      render_part() بـargs صريحة مسماة ($template_args) بدل
 *      get_defined_vars() — نفس الترتيب ونفس الشروط.
 *   2. بوابة B3-08 template gate (نقاط الاستهلاك الداخلة في هذه
 *      الدفعة): ميزة معطلة من المالك لا تُستهلك في التنقل ولا في
 *      رندر الأجزاء ولا في quick actions — القائمة:
 *      posts/files/amelia/finance/store/inbox/members (تنقل + أجزاء)،
 *      wpml_translations وrank_math_seo وultimate_member_profile
 *      (تنقل + أجزاؤها في الدفعة 8)، ai (أزرار posts.php داخلها
 *      البوابة). القرار الفعلي يبقى مركبًا: الميزة + registry +
 *      capability + ownership — البوابة هنا طبقة المالك فوق شروط
 *      المصدر بلا إزالة أي شرط قائم. استهلاك enqueue وbody classes
 *      تابع لـhossam_is_dashboard() (setup.php) عبر predicate
 *      الـController نفسه — §7.5. المتبقي: أجزاء الدفعة 8 تربط بواباتها
 *      عند ترحيلها (نمط ربط نقاط الاستهلاك المتدرج نفسه).
 *   3. الشعار: لا مسار ملف ثابت ولا get_stylesheet_directory_uri —
 *      الحل من Branding (Attachment ID عبر Settings Repository مع
 *      validation PNG/JPEG/WebP) وfallback نصي آمن HAL عند غياب/حذف
 *      المرفق (قرار المالك §2.3؛ نص HAL الموثق في رأس Settings
 *      Repository، الدفعة 3).
 *   4. hossam_is_dashboard() في core/setup.php صارت تستخدم predicate
 *      الـController (صفحة المملكة ID + ترجمات WPML) بدل
 *      is_page('dashboard')/is_page_template('page-dashboard.php')
 *      — §7.5: لا اعتماد متبقٍ على slug عام أو template slug.
 *
 * بقي كما هو حرفيًا: Auth Guard مع exit، بيانات المستخدم، role
 * booleans، حسابات الإحصائيات (WP_Query/$wpdb)، دوال المساعدة
 * (_dash_status_badge وأخواتها)، no-cache headers، عداد inbox غير
 * المقروء، wp_head()/wp_footer()/wp_body_open() مباشرة بدل
 * header-dashboard.php/footer-dashboard.php (المصدر كذلك — الملفان
 * غير مستهلكين في المصدر ولا يشحنان، انظر سجل الدفعة 7).
 *
 * دلتا الدفعة 8 (§19 — عقد members.php): المفتاح 45
 * 'role_display_map' أُضيف إلى $template_args صريحة — members.php
 * يستهلكه لجدول Roles وكان يصل عبر get_defined_vars() في المصدر
 * (نقص اكتمال دلتا الدفعة 7 كُشف بالمراجعة المستقلة للدفعة 8، V-1
 * 2026-09-17). باقي المفاتيح الأربعة والأربعون كما هي.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
		 exit;
}

// B7-04 (معمارية §18): لا Template Header — التسجيل عبر Controller فقط.

// ══════════════════════════════════════════════════════════════
// 1. AUTHENTICATION GUARD — أول شيء قبل أي إخراج
// ══════════════════════════════════════════════════════════════
if ( ! is_user_logged_in() ) {
    wp_redirect( hossam_login_url() );
    exit;
}

// ══════════════════════════════════════════════════════════════
// 2. CURRENT USER DATA
// ══════════════════════════════════════════════════════════════
$current_user  = wp_get_current_user();
$user_id       = get_current_user_id();
$_dash_base    = hossam_dashboard_url();
$user_name     = $current_user->display_name;
$user_fname    = $current_user->first_name ?: $current_user->display_name;
$user_email    = $current_user->user_email;
$user_roles    = (array) $current_user->roles;

// صورة المستخدم الحقيقية من WordPress / Ultimate Member
$user_avatar_url = get_avatar_url( $user_id, [
    'size'    => 80,
    'default' => 'identicon',
    'rating'  => 'g',
] );

// ══════════════════════════════════════════════════════════════
// 2.B FEATURE GATES — بوابة B3-08 (الدفعة 7، template gate):
// الميزة المعطلة من المالك لا تُستهلك في التنقل/الأجزاء/quick actions.
// القرار الفعلي يبقى مركبًا (الميزة + registry + capability +
// ownership) — هذه طبقة المالك فوق شروط المصدر بلا إزالة أي شرط.
// ══════════════════════════════════════════════════════════════
$hal_feature_gate = array(
    'posts'                  => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ),
    'files'                  => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'files' ),
    'inbox'                  => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'inbox' ),
    'members'                => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'members' ),
    'amelia'                 => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'amelia' ),
    'finance'                => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'finance' ),
    'store'                  => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'store' ),
    'wpml_translations'      => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'wpml_translations' ),
    'rank_math_seo'          => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rank_math_seo' ),
    'ultimate_member_profile' => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ultimate_member_profile' ),
    'ai'                     => HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ai' ),
);

// ══════════════════════════════════════════════════════════════
// 3. ROLE BOOLEANS — استخدمها في كل if() في الصفحة
// ══════════════════════════════════════════════════════════════
$is_admin      = current_user_can( 'manage_options' );
$is_editor     = current_user_can( 'edit_others_posts' );
$is_author     = current_user_can( 'publish_posts' );
$is_writer     = current_user_can( 'edit_posts' );
$can_upload    = current_user_can( 'upload_files' ) && $hal_feature_gate['files'];
$can_delete    = current_user_can( 'edit_posts' );
$is_amelia_mgr = current_user_can( 'view_amelia_calendar_all' );
$is_amelia_emp = ! $is_amelia_mgr && current_user_can( 'view_amelia_calendar' );

// ══════════════════════════════════════════════════════════════
// 7. POST_ID/PANEL من URL (لصفحتي التعديل والحذف)
// ══════════════════════════════════════════════════════════════
$url_panel = isset( $_GET['panel'] ) && is_string( $_GET['panel'] )
    ? sanitize_key( wp_unslash( $_GET['panel'] ) )
    : '';
$url_post_id = isset( $_GET['post_id'] ) && is_scalar( $_GET['post_id'] )
    ? absint( $_GET['post_id'] )
    : 0;

$direct_post_panel   = in_array( $url_panel, [ 'edit-article', 'delete-article' ], true );
$can_edit_url_post   = $direct_post_panel
    && $url_post_id > 0
    && current_user_can( 'edit_post', $url_post_id );
$show_edit_post_link = $hal_feature_gate['posts'] && ( $is_writer || ( 'edit-article' === $url_panel && $can_edit_url_post ) );
$show_delete_post_link = $hal_feature_gate['posts'] && ( $is_writer || ( 'delete-article' === $url_panel && $can_edit_url_post ) );

$amelia_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'amelia' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$woo_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'woocommerce' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$wpml_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'wpml' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];

$sees_calendar = 'unavailable' !== $amelia_integration['status']
    && ( $is_admin || $is_amelia_mgr || $is_amelia_emp )
    && $hal_feature_gate['amelia'];
$sees_content  = ( $is_writer || $can_edit_url_post ) && $hal_feature_gate['posts'];
// Finance always has a safe current-customer scope for an authenticated user;
// its template renders the registry state when Woo/order APIs are unavailable.
// B3-08 (الدفعة 7): الاستهلاك متوقف أيضًا على بوابة المالك.
$_show_finance = $hal_feature_gate['finance'];
$sees_store = ( current_user_can( 'edit_products' )
    || current_user_can( 'manage_woocommerce' )
    || current_user_can( 'manage_options' ) )
    && $hal_feature_gate['store'];

// بوابات المميزات بلا شروط قدرة في المصدر (البنود غير المشروطة) —
// طبقة المالك وحدها فوق سلوك المصدر الحرفي.
$show_seo           = $is_writer && $hal_feature_gate['rank_math_seo'];
$show_translations  = $hal_feature_gate['wpml_translations'];
$show_profile       = $hal_feature_gate['ultimate_member_profile'];
$show_inbox         = $hal_feature_gate['inbox'];
$show_members       = $hal_feature_gate['members'];

// عنوان الدور للعرض
$role_display_map = [
    'administrator'   => hossam_t('Administrator'),
    'editor'          => hossam_t('Editor'),
    'author'          => hossam_t('Author'),
    'contributor'     => hossam_t('Contributor'),
    'amelia_manager'  => hossam_t('Manager'),
    'amelia_employee' => hossam_t('Employee'),
];
$display_role = hossam_t('Member');
foreach ( [ 'administrator', 'editor', 'author', 'amelia_manager', 'amelia_employee', 'contributor' ] as $_role_priority ) {
    if ( in_array( $_role_priority, $user_roles, true ) ) {
        $display_role = $role_display_map[ $_role_priority ];
        break;
    }
}

// ══════════════════════════════════════════════════════════════
// 4. STAT COUNTS — WP_Query حقيقي (B7-03: لا استعلامات مع تعطيل posts)
// 5. RECENT ACTIVITY — آخر 5 مقالات (B7-03: same gate)
// ══════════════════════════════════════════════════════════════
// 4. STAT COUNTS — WP_Query حقيقي (B7-03: تُحسب فقط مع تمكين posts)
// 5. RECENT ACTIVITY — آخر 5 مقالات (B7-03: same gate)
// 6. ALL ARTICLES TABLE DATA (B7-03: same gate)
// When posts is disabled none of the queries run: stats stay zero and
// both query handles stay null (overview hides its activity card on
// null; posts.php renders only behind $sees_content which already
// requires the posts gate).
// ══════════════════════════════════════════════════════════════
$author_filter = ! current_user_can( 'edit_others_posts' );

$stat_all = $stat_pending = $stat_published = $stat_draft = 0;
$activity_query = null;
$articles_query = null;
if ( $sees_content ) {
$_stat_sql  = "SELECT post_status, COUNT(*) cnt FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','future')" . ( $author_filter ? ' AND post_author = %d' : '' ) . ' GROUP BY post_status';
$_stat_args = $author_filter ? [ 'post', $user_id ] : [ 'post' ];
foreach ( $wpdb->get_results( $wpdb->prepare( $_stat_sql, ...$_stat_args ) ) as $_stat_row ) {
    $stat_all += (int) $_stat_row->cnt;
    if ( 'publish' === $_stat_row->post_status ) {
        $stat_published = (int) $_stat_row->cnt;
    } elseif ( 'draft' === $_stat_row->post_status ) {
        $stat_draft = (int) $_stat_row->cnt;
    } elseif ( 'pending' === $_stat_row->post_status ) {
        $stat_pending = (int) $_stat_row->cnt;
    }
}

$activity_args = [
    'post_type'      => 'post',
    'post_status'    => ['publish','draft','pending'],
    'posts_per_page' => 5,
    'orderby'        => 'modified',
    'order'          => 'DESC',
    'no_found_rows'  => true,
];
if ( $author_filter ) {
    $activity_args['author'] = $user_id;
}
if ( 'unavailable' !== $wpml_integration['status']
    && ! empty( $wpml_integration['capabilities']['languages'] )
    && ! $author_filter ) {
    $activity_args['lang'] = 'all';
}
$activity_query = new WP_Query( $activity_args );

$tbl_args = [
    'post_type'      => 'post',
    'post_status'    => ['publish','draft','pending','future'],
    'posts_per_page' => 20,
    'paged'          => max( 1, intval( $_GET['paged'] ?? 1 ) ),
    'orderby'        => 'modified',
    'order'          => 'DESC',
    'update_post_meta_cache' => true,
];

// فلتر التصنيف من URL
if ( isset( $_GET['cat'] ) && is_string( $_GET['cat'] ) && '' !== $_GET['cat'] ) {
    $tbl_args['category_name'] = sanitize_text_field( wp_unslash( $_GET['cat'] ) );
}
// فلتر الحالة من URL
$_allowed_statuses = ['publish', 'draft', 'pending', 'future'];
$_s_raw = isset( $_GET['status'] ) && is_string( $_GET['status'] )
    ? sanitize_key( wp_unslash( $_GET['status'] ) )
    : 'all';
if ( ! in_array( $_s_raw, $_allowed_statuses, true ) ) {
    $_s_raw = 'all';
}
if ( $_s_raw !== 'all' ) {
    $tbl_args['post_status'] = $_s_raw;
}
// Author: author يرى مقالاته فقط، editor/admin يرى الكل
if ( $author_filter ) {
    $tbl_args['author'] = $user_id;
}

if ( 'unavailable' !== $wpml_integration['status']
    && ! empty( $wpml_integration['capabilities']['languages'] )
    && ! $author_filter ) {
    $tbl_args['lang'] = 'all';
}
$articles_query = new WP_Query( $tbl_args );
}

// دالة مساعدة: حالة المقال → badge class + نص
if ( ! function_exists('_dash_status_badge') ) {
    function _dash_status_badge( $status ) {
        $map = [
            'publish' => ['pill-pub',     hossam_t('Published')],
            'draft'   => ['pill-draft',   hossam_t('Draft')],
            'pending' => ['pill-review',  hossam_t('Pending Review')],
            'future'  => ['pill-review',  hossam_t('Scheduled')],
            'trash'   => ['pill-pending', hossam_t('Trashed')],
        ];
        return $map[$status] ?? ['pill-draft', ucfirst($status)];
    }
}

// دالة مساعدة: لون نقطة النشاط
if ( ! function_exists('_dash_activity_dot') ) {
    function _dash_activity_dot( $status ) {
        return [
            'publish' => 'gold',
            'draft'   => 'blue',
            'pending' => 'warn',
            'future'  => 'blue',
        ][$status] ?? 'blue';
    }
}

// دالة مساعدة: نص النشاط
if ( ! function_exists('_dash_activity_text') ) {
    function _dash_activity_text( $status ) {
        return [
            'publish' => hossam_t('Published'),
            'draft'   => hossam_t('Draft saved'),
            'pending' => hossam_t('Submitted for review'),
            'future'  => hossam_t('Scheduled'),
        ][$status] ?? hossam_t('Updated');
    }
}

// ══════════════════════════════════════════════════════════════
// 8. NO-CACHE HEADER — منع LiteSpeed من التخزين المؤقت
// ══════════════════════════════════════════════════════════════
if ( function_exists('litespeed_no_cache') ) {
    litespeed_no_cache();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-LiteSpeed-Cache-Control: no-cache');

// ══════════════════════════════════════════════════════════════
// 9. LOGO URL — الدفعة 7: من Branding (Attachment ID مع validation
// PNG/JPEG/WebP عبر Settings Repository) بلا مسار ملف ثابت ولا
// stylesheet؛ غياب/حذف المرفق = fallback نصي آمن (قرار المالك §2.3).
// ══════════════════════════════════════════════════════════════
$logo_url = '';
$_branding_attachment_id = HAL_Frontend_Dashboard_Settings_Repository::get_branding_attachment_id();
if ( $_branding_attachment_id > 0 ) {
    $_branding_url = wp_get_attachment_url( $_branding_attachment_id );
    if ( is_string( $_branding_url ) && '' !== $_branding_url ) {
        $logo_url = $_branding_url;
    }
}

// م19 — Inbox unread count — PHP-embedded, no AJAX (B7-03: لا استعلام
// مع تعطيل inbox؛ $unread_inbox يبقى null فتعرض الشارات unavailable).
global $wpdb;
$_tbl_inbox   = $wpdb->prefix . 'hossam_messages';
$unread_inbox = null;
if ( $hal_feature_gate['inbox'] ) {
$_inbox_table = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $_tbl_inbox ) ) );
if ( $wpdb->last_error ) {
    error_log( 'Hossam Dashboard inbox count table check failed.' );
} elseif ( $_inbox_table !== $_tbl_inbox ) {
    error_log( 'Hossam Dashboard inbox table is missing.' );
} else {
    $_unread_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$_tbl_inbox} WHERE receiver_id = %d AND read_at IS NULL",
        $user_id
    ) );
    if ( $wpdb->last_error ) {
        error_log( 'Hossam Dashboard inbox unread count failed.' );
    } else {
        $unread_inbox = (int) $_unread_count;
    }
}
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?> data-theme="light">
<head>
<meta charset="<?php bloginfo('charset'); ?>"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="robots" content="noindex,nofollow"/>
<title><?php echo esc_html($user_name); ?> — Dashboard · Hossam Adel Law Firm</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})()</script>
<?php
// wp_head() مستدعى مباشرةً هنا بدلاً من get_header('dashboard') لأن هذه الصفحة
// تُعيد بناء الـ <head> بالكامل بشكل مخصص (viewport, robots, fonts, theme-script)
// دون الاعتماد على header-dashboard.php، مما يضمن تحكماً كاملاً في ترتيب الأصول
// وإلغاء أي عناصر قد يُدرجها القالب الرئيسي وهي غير مطلوبة في لوحة التحكم.
wp_head();
?>
</head>
<body <?php body_class('dashboard-page no-sidebar'); ?>>
<?php wp_body_open(); ?>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toast-container"></div>

<!-- MOBILE OVERLAY -->
<div class="overlay" id="overlay" onclick="closeMobile()"></div>

<div class="app">
<div class="app-body">

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">

  <!-- Brand: Logo + Title — الدفعة 7: من Branding attachment أو
       fallback نصي آمن HAL (قرار المالك §2.3) بلا مسار ملف ثابت -->
  <div class="sb-brand">
    <?php if ( '' !== $logo_url ) : ?>
    <img src="<?php echo esc_url($logo_url); ?>"
         alt="Hossam Adel Law Firm"
         class="sb-logo-img"
         width="34" height="34"
         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
    <div class="sb-logo" style="display:none;width:34px;height:34px;background:var(--gold);border-radius:6px;align-items:center;justify-content:center;font-family:'Cormorant Garamond',serif;font-weight:700;font-size:15px;color:var(--navy);flex-shrink:0;">HAL</div>
    <?php else : ?>
    <div class="sb-logo" style="display:flex;width:34px;height:34px;background:var(--gold);border-radius:6px;align-items:center;justify-content:center;font-family:'Cormorant Garamond',serif;font-weight:700;font-size:15px;color:var(--navy);flex-shrink:0;">HAL</div>
    <?php endif; ?>
    <div class="sb-title">
      Hossam Adel
      <span>Law Firm Portal</span>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="sb-nav" id="sb-nav">

    <div class="sb-label"><?php echo esc_html( hossam_t( 'Workspace' ) ); ?></div>

    <!-- Overview -->
    <div class="sb-item active" data-panel="overview" onclick="nav(this,'overview')" title="Overview">
      <div class="icon" id="icon-overview"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Overview' ) ); ?></span>
    </div>

    <!-- Articles group — فقط لمن يملك صلاحية الكتابة (+ بوابة المالك B3-08) -->
    <?php if ( $sees_content ) : ?>
    <div class="sb-item" onclick="toggleSub(this,'sub-articles')" title="Articles">
      <div class="icon" id="icon-articles"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Articles' ) ); ?></span>
      <svg class="chev" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 12l4-4-4-4"/></svg>
    </div>
    <div class="sb-sub" id="sub-articles">
      <?php if ( $is_writer ) : ?>
      <div class="sb-item" data-panel="write" onclick="nav(this,'write')" title="Write New Article">
        <div class="icon" id="icon-write"></div>
        <span class="label"><?php echo esc_html( hossam_t( 'Write New Article' ) ); ?></span>
      </div>
      <div class="sb-item" data-panel="all-articles" onclick="nav(this,'all-articles')" title="All My Articles">
        <div class="icon" id="icon-all-articles"></div>
        <span class="label"><?php echo esc_html( hossam_t( 'All My Articles' ) ); ?></span>
        <span class="badge" id="articles-badge"><?php echo $stat_all; ?></span>
      </div>
      <?php endif; ?>
      <?php if ( $show_edit_post_link ) : ?>
      <div class="sb-item" data-panel="edit-article" onclick="nav(this,'edit-article')" title="Edit Article">
        <div class="icon" id="icon-edit-article"></div>
        <span class="label"><?php echo esc_html( hossam_t( 'Edit Article' ) ); ?></span>
      </div>
      <?php endif; ?>
      <?php if ( $show_delete_post_link ) : ?>
      <div class="sb-item" data-panel="delete-article" onclick="nav(this,'delete-article')" title="Delete Article">
        <div class="icon" id="icon-delete-article"></div>
        <span class="label"><?php echo esc_html( hossam_t( 'Delete Article' ) ); ?></span>
      </div>
      <?php endif; ?>
      <?php if ( $can_delete ) : ?>
      <div class="sb-item" data-panel="trash-articles" onclick="nav(this,'trash-articles')" title="Trash">
        <div class="icon" id="icon-trash-articles"></div>
        <span class="label"><?php echo esc_html( hossam_t( 'Trash' ) ); ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Media & Uploads — (+ بوابة المالك B3-08 عبر $can_upload) -->
    <?php if ( $can_upload ) : ?>
    <div class="sb-item" data-panel="media" onclick="nav(this,'media')" title="Files & Documents">
      <div class="icon" id="icon-media"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Files & Documents' ) ); ?></span>
    </div>
    <?php endif; ?>

    <!-- Appointments — أدوار Amelia فقط + Admin (+ بوابة المالك B3-08) -->
    <?php if ( $sees_calendar ) : ?>
    <div class="sb-label"><?php echo esc_html( hossam_t( 'Appointments' ) ); ?></div>
    <div class="sb-item" data-panel="appointments" onclick="nav(this,'appointments')" title="My Schedule" id="nav-appointments">
      <div class="icon" id="icon-appointments"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'My Schedule' ) ); ?></span>
    </div>
    <?php endif; ?>

    <div class="sb-label"><?php echo esc_html( hossam_t( 'Content Tools' ) ); ?></div>

    <!-- SEO — بدون اسم البرنامج (+ بوابة المالك B3-08) -->
    <?php if ( $show_seo ) : ?>
    <div class="sb-item" data-panel="seo" onclick="nav(this,'seo')" title="SEO Settings">
      <div class="icon" id="icon-seo"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'SEO Settings' ) ); ?></span>
    </div>
    <?php endif; ?>

    <!-- Translation — بدون اسم البرنامج (+ بوابة المالك B3-08) -->
    <?php if ( $show_translations ) : ?>
    <div class="sb-item" data-panel="translation" onclick="nav(this,'translation')" title="Languages & Translations">
      <div class="icon" id="icon-translation"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Languages & Translations' ) ); ?></span>
    </div>
    <?php endif; ?>

    <div class="sb-label"><?php echo esc_html( hossam_t( 'Account' ) ); ?></div>

    <!-- Profile (+ بوابة المالك B3-08) -->
    <?php if ( $show_profile ) : ?>
    <div class="sb-item" data-panel="profile" onclick="nav(this,'profile')" title="My Profile">
      <div class="icon" id="icon-profile"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'My Profile' ) ); ?></span>
    </div>
    <?php endif; ?>

    <!-- Inbox (+ بوابة المالك B3-08) -->
    <?php if ( $show_inbox ) : ?>
    <div class="sb-item" data-panel="inbox" onclick="nav(this,'inbox')" title="My Inbox">
      <div class="icon" id="icon-inbox"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'My Inbox' ) ); ?></span>
      <span class="badge danger" id="inbox-badge" data-state="<?php echo null === $unread_inbox ? 'unavailable' : 'ok'; ?>"<?php if ( null === $unread_inbox ) : ?> title="<?php echo esc_attr( hossam_t( 'Inbox status unavailable' ) ); ?>"<?php endif; ?>><?php echo null === $unread_inbox ? '' : (int) $unread_inbox; ?></span>
    </div>
    <?php endif; ?>

    <!-- Finance — (+ بوابة المالك B3-08) -->
    <?php if ( $_show_finance ) : ?>
    <div class="sb-item" data-panel="finance" onclick="nav(this,'finance')" title="Finance">
      <div class="icon" id="icon-finance"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Finance' ) ); ?></span>
    </div>
    <?php endif; ?>

    <!-- Store — (+ بوابة المالك B3-08) -->
    <?php if ( $sees_store ) : ?>
    <div class="sb-item" data-panel="store" onclick="nav(this,'store')" title="Store">
      <div class="icon" id="icon-store"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Store' ) ); ?></span>
    </div>
    <?php endif; ?>

    <!-- Admin Area — Admin فقط (+ بوابة المالك B3-08 لعنصر Members) -->
    <?php if ( $is_admin ) : ?>
    <div class="sb-label sb-label-admin"><?php echo esc_html( hossam_t( 'Admin Only' ) ); ?></div>
    <div class="sb-item sb-item-admin" data-panel="admin" onclick="nav(this,'admin')" title="Administration" id="nav-admin">
      <div class="icon" style="color:var(--gold)" id="icon-admin"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Administration' ) ); ?></span>
    </div>
    <?php if ( $show_members ) : ?>
    <div class="sb-item sb-item-admin" data-panel="members" onclick="nav(this,'members')" title="Members" id="nav-members">
      <div class="icon" style="color:var(--gold)" id="icon-members"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Members' ) ); ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    $nav_items = [];
    $locs      = get_nav_menu_locations();
    if ( ! empty( $locs['primary'] ) ) {
        $items = wp_get_nav_menu_items( $locs['primary'] );
        if ( $items && ! is_wp_error( $items ) ) {
            foreach ( $items as $it ) {
                if ( ! $it->menu_item_parent ) {
                    $nav_items[] = [
                        'url'   => hossam_wpml_permalink( (string) $it->url ),
                        'label' => $it->title,
                        'icon'  => '',
                    ];
                }
            }
        }
    }
    if ( empty( $nav_items ) ) {
        $section_defs = [
            'home'     => [ 'label' => hossam_t('Home'),          'candidates' => [] ],
            'about'    => [ 'label' => hossam_t('About'),         'candidates' => ['about','about-us'] ],
            'services' => [ 'label' => hossam_t('Services'),      'candidates' => ['services','our-services'] ],
            'blog'     => [ 'label' => hossam_t('Blog'),          'candidates' => [] ],
            'contact'  => [ 'label' => hossam_t('Contact'),       'candidates' => ['contact','contact-us'] ],
            'privacy'  => [ 'label' => hossam_t('Privacy Policy'),'candidates' => [] ],
            'terms'    => [ 'label' => hossam_t('Terms'),         'candidates' => ['terms','terms-conditions'] ],
            'shop'     => [ 'label' => hossam_t('Shop'),          'candidates' => [] ],
        ];
        $nav_items = [];
        foreach ( $section_defs as $key => $def ) {
            $result = hossam_resolve_site_section( $key, $def['candidates'] );
            if ( $result ) { $nav_items[] = [ 'url' => $result['url'], 'label' => $def['label'], 'icon' => '' ]; }
        }
    }
    $site_sections = $nav_items;
    ?>
    <div class="sb-label"><?php echo esc_html( hossam_t( 'Site Sections' ) ); ?></div>
    <?php foreach ( $site_sections as $s ) : ?>
    <a href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" class="sb-item"
       style="text-decoration:none" title="<?php echo esc_attr( $s['label'] ); ?>">
      <div class="icon">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
          <polyline points="15 3 21 3 21 9"/>
          <line x1="10" y1="14" x2="21" y2="3"/>
        </svg>
      </div>
      <span class="label"><?php echo esc_html( $s['label'] ); ?></span>
    </a>
    <?php endforeach; ?>

  </nav>

  <!-- Logout -->
  <div class="sb-logout">
    <a href="<?php echo esc_url( wp_logout_url( hossam_login_url() ) ); ?>"
       class="sb-item" style="color:rgba(255,255,255,.35)" title="Sign Out">
      <div class="icon" id="icon-logout"></div>
      <span class="label"><?php echo esc_html( hossam_t( 'Sign Out' ) ); ?></span>
    </a>
  </div>

  <?php if ( 'unavailable' !== $wpml_integration['status'] && shortcode_exists( 'wpml_language_switcher' ) ) : ?>
  <div class="sb-lang-mobile">
    <?php echo do_shortcode(
        "[wpml_language_switcher type='footer' flags=1 native=0 translated=0]"
    ); ?>
  </div>
  <?php endif; ?>

  <!-- Collapse toggle -->
  <div class="sb-toggle" onclick="toggleSidebar()" title="Collapse sidebar">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <path d="M10 12L6 8l4-4"/>
    </svg>
  </div>

  <!-- Theme Toggle -->
  <button class="theme-btn" onclick="toggleTheme()" title="Toggle dark mode">
    <svg id="theme-icon-svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
  </button>
</aside>

<!-- ══════════════════════════════════════════════════════════
     MAIN AREA
══════════════════════════════════════════════════════════ -->
<div class="main">

  <!-- HEADER -->
  <header class="header">
    <button class="hdr-menu" onclick="openMobile()">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
    </button>
    <a href="<?php echo esc_url( home_url() ); ?>" class="hdr-home" target="_blank" title="Main Site">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </a>

    <div class="hdr-clock" id="hdr-clock">--:-- --</div>

    <div class="hdr-search">
      <svg class="s-icon" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="6.5" cy="6.5" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
      <input type="text" placeholder="<?php echo esc_attr( hossam_t( 'Search articles…' ) ); ?>" id="search-input" oninput="liveSearch(this.value)" autocomplete="off"/>
    </div>

    <!-- Inbox (+ بوابة المالك B3-08) -->
    <?php if ( $show_inbox ) : ?>
    <div class="hdr-inbox" onclick="nav(this,'inbox')" title="<?php echo esc_attr( hossam_t( 'My Inbox' ) ); ?>" style="cursor:pointer;position:relative">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      <span class="notif-badge" id="hdr-inbox-badge" data-state="<?php echo null === $unread_inbox ? 'unavailable' : 'ok'; ?>"<?php if ( null === $unread_inbox ) : ?> title="<?php echo esc_attr( hossam_t( 'Inbox status unavailable' ) ); ?>"<?php endif; ?>><?php echo null === $unread_inbox ? '' : (int) $unread_inbox; ?></span>
    </div>
    <?php endif; ?>

    <!-- Notification Bell -->
    <div class="notif-wrap" tabindex="0">
      <div class="notif-btn" id="notif-bell" style="position:relative">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="notif-badge hidden" id="notif-badge">0</span>
      </div>
      <div class="notif-dd" id="notif-dd">
        <div class="notif-hdr">
          <strong><?php echo esc_html( hossam_t( 'Notifications' ) ); ?></strong>
          <button onclick="clearNotifs()" style="font-size:10px;color:var(--txt-3);background:none;border:none;cursor:pointer"><?php echo esc_html( hossam_t( 'Mark all read' ) ); ?></button>
        </div>
        <div id="notif-list">
          <div class="notif-empty"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
        </div>
      </div>
    </div>

    <?php if ( 'unavailable' !== $wpml_integration['status'] && ! empty( $wpml_integration['capabilities']['languages'] ) && shortcode_exists( 'wpml_language_switcher' ) ) : ?>
    <?php
    $_cur_code  = hossam_wpml_get_current_language();
    $_cur_flag  = '';
    $_cur_label = strtoupper( $_cur_code ?: 'EN' );
    if ( $_cur_code ) {
        $_langs = hossam_wpml_get_active_languages();
        if ( is_array( $_langs ) && isset( $_langs[ $_cur_code ] ) ) {
            $_cur_flag  = $_langs[ $_cur_code ]['country_flag_url'] ?? '';
            $_cur_label = strtoupper( $_cur_code );
        }
    }
    ?>
    <div class="hdr-lang" tabindex="0" role="navigation" aria-label="Language selector">
      <button class="hdr-lang-trigger" type="button"
              aria-haspopup="listbox" aria-expanded="false">
        <?php if ( $_cur_flag ) : ?>
        <img src="<?php echo esc_url( $_cur_flag ); ?>" width="16" height="16"
             alt="<?php echo esc_attr( $_cur_label ); ?>">
        <?php else : ?>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        <?php endif; ?>
        <span class="hdr-lang-cur"><?php echo esc_html( $_cur_label ); ?></span>
        <svg class="hdr-lang-chevron" width="10" height="10" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M6 9l6 6 6-6"/>
        </svg>
      </button>
      <div class="hdr-lang-dd" role="listbox">
        <?php echo do_shortcode(
            "[wpml_language_switcher type='footer' flags=1 native=1 translated=0]"
        ); ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- User Pill — صورة حقيقية من WordPress -->
    <div class="user-pill" tabindex="0">
      <div class="user-avatar" title="<?php echo esc_attr( hossam_t( 'Welcome back' ) ); ?>">
        <?php
          $avatar_initials = strtoupper( substr( $user_name, 0, 2 ) );
          if ( empty( $avatar_initials ) ) {
              $avatar_initials = '??';
          }
        ?>
        <img src="<?php echo esc_url($user_avatar_url); ?>"
             alt="<?php echo esc_attr($user_name); ?>"
             width="28" height="28"
             style="border-radius:50%;object-fit:cover;display:block;"
             onerror="this.style.display='none';this.parentElement.textContent='<?php echo esc_js( $avatar_initials ); ?>'"/>
      </div>
      <div>
        <div class="user-name"><?php echo esc_html($user_fname); ?></div>
        <div class="role-chip"><?php echo esc_html($display_role); ?></div>
      </div>
      <!-- Dropdown -->
      <div class="user-dd">
        <?php if ( $show_profile ) : ?>
        <div class="dd-item" onclick="nav(document.querySelector('[data-panel=profile]'),'profile')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
          <?php echo esc_html( hossam_t( 'My Profile' ) ); ?>
        </div>
        <?php endif; ?>
        <?php if ( $show_inbox ) : ?>
        <div class="dd-item" onclick="nav(document.querySelector('[data-panel=inbox]'),'inbox')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          <?php echo esc_html( hossam_t( 'My Inbox' ) ); ?>
        </div>
        <?php endif; ?>
        <div class="dd-sep"></div>
        <a href="<?php echo esc_url( wp_logout_url( hossam_login_url() ) ); ?>" class="dd-item danger">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <?php echo esc_html( hossam_t( 'Sign Out' ) ); ?>
        </a>
      </div>
    </div>
  </header>

  <!-- ══════════════════════════════════════════════
       CONTENT PANELS — محمل أجزاء Runtime حصرًا (§18) بـargs صريحة،
       بنفس ترتيب المصدر ونفس شروطه + بوابات B3-08 (2.B أعلاه)
  ══════════════════════════════════════════════ -->
  <div class="content" id="content-area">

    <?php
    $template_args = [
        'current_user'         => $current_user,
        'user_id'              => $user_id,
        '_dash_base'           => $_dash_base,
        'user_name'            => $user_name,
        'user_fname'           => $user_fname,
        'user_email'           => $user_email,
        'user_roles'           => $user_roles,
        'user_avatar_url'      => $user_avatar_url,
        'is_admin'             => $is_admin,
        'is_editor'            => $is_editor,
        'is_author'            => $is_author,
        'is_writer'            => $is_writer,
        'can_upload'           => $can_upload,
        'can_delete'           => $can_delete,
        'is_amelia_mgr'        => $is_amelia_mgr,
        'is_amelia_emp'        => $is_amelia_emp,
        'url_panel'            => $url_panel,
        'url_post_id'          => $url_post_id,
        'direct_post_panel'    => $direct_post_panel,
        'can_edit_url_post'    => $can_edit_url_post,
        'show_edit_post_link'  => $show_edit_post_link,
        'show_delete_post_link' => $show_delete_post_link,
        'amelia_integration'   => $amelia_integration,
        'woo_integration'      => $woo_integration,
        'wpml_integration'     => $wpml_integration,
        'sees_calendar'        => $sees_calendar,
        'sees_content'         => $sees_content,
        '_show_finance'        => $_show_finance,
        'sees_store'           => $sees_store,
        'display_role'         => $display_role,
        'author_filter'        => $author_filter,
        'stat_all'             => $stat_all,
        'stat_pending'         => $stat_pending,
        'stat_published'       => $stat_published,
        'stat_draft'           => $stat_draft,
        'activity_query'       => $activity_query,
        'articles_query'       => $articles_query,
        'unread_inbox'         => $unread_inbox,
        'logo_url'             => $logo_url,
        'show_seo'             => $show_seo,
        'show_translations'    => $show_translations,
        'show_profile'         => $show_profile,
        'show_inbox'           => $show_inbox,
        'show_members'         => $show_members,
        /* الدفعة 8 (V-1 من المراجعة المستقلة 2026-09-17): members.php
         * يستهلك $role_display_map (جدول Roles) — كانت تصل عبر
         * get_defined_vars() في المصدر؛ تُمرَّر الآن صريحة (45 مفتاحًا). */
        'role_display_map'     => $role_display_map,
    ];
    HAL_Frontend_Dashboard_Template_Controller::render_part( 'overview', $template_args );

    if ( $sees_content ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'posts', $template_args );
    }

    if ( $can_upload ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'files', $template_args );
    }

    if ( $sees_calendar ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'bookings', $template_args );
    }

    if ( $show_seo ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'seo', $template_args );
    }

    if ( $show_translations ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'translations', $template_args );
    }
    if ( $show_profile ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'profile', $template_args );
    }
    if ( $show_inbox ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'inbox', $template_args );
    }

    if ( $_show_finance ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'finance', $template_args );
    }

    if ( $sees_store ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'store', $template_args );
    }

    if ( $is_admin ) {
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'admin', $template_args );
        HAL_Frontend_Dashboard_Template_Controller::render_part( 'members', $template_args );
    }
    ?>
  </div><!-- /content -->
</div><!-- /main -->

</div><!-- /app-body -->
</div><!-- /app -->

<?php wp_footer(); ?>
</body>
</html>
