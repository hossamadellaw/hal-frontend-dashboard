<?php
/**
 * runtime/core/i18n.php
 * ══════════════════════════════════════════════════════════════
 * الدور: كل ما يخص الترجمة عبر WPML String Translation — الدالة
 * المساعدة، تسجيل النصوص، ومصدر واحد لمصفوفة i18n المُرسَلة لـJS.
 *
 * المصدر: نقل حرفي 1:1 من legacy mu-plugins/hossam-dashboard/core/i18n.php
 * (الدفعة 2 من HAL Frontend Dashboard) — بلا أي تغيير في المفاتيح أو
 * القيم أو نصوص التسجيل:
 *   - hossam_t() كما هي.
 *   - تسجيل نصوص WPML عبر icl_register_string() باسم context
 *     'hossam-dashboard' (hook init، priority 5) مع منطق الـmd5 hash
 *     لمنع إعادة التسجيل غير الضرورية — كما هو.
 *   - hossam_get_i18n_strings() كاملة بكل المفاتيح (الأصلية + مفاتيح
 *     T-31 الثمانية والعشرين + إضافات الدفعات) دون أي تغيير.
 *
 * قرار text domain مؤجَّل (موثَّق لعروضه على المالك): تسجيل WPML عبر
 * icl_register_string() لا يعتمد على text domain أصلًا، والقيم الحرفية
 * للنصوص لا تتغير في هذه الدفعة. استبدال text domain لسلاسل الواجهة
 * (__('...','astra-child') المتبقية في runtime) مؤجَّل إلى دفعة القوالب
 * حيث تُقرَّر الواجهة الجديدة — لا يتغير أي نص معروض هنا.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists('hossam_t') ) {
    function hossam_t( string $string, string $ctx = 'hossam-dashboard' ): string {
        if ( function_exists('icl_t') ) {
            return icl_t( $ctx, $string, $string );
        }
        return $string;
    }
}

add_action( 'init', function(): void {
    if ( ! function_exists('icl_register_string') ) return;

    $strings = [
        'Welcome back', 'Good morning', 'Good afternoon', 'Good evening',
        'Overview', 'Articles', 'Write New Article', 'All My Articles',
        'Edit Article', 'Delete Article', 'Files & Documents', 'My Schedule',
        'Content Optimization', 'Language Settings', 'My Profile', 'My Inbox',
        'Finance', 'Administration', 'Sign Out',
        'Workspace', 'Scheduling', 'Content Tools', 'Account',
        'Site Sections', 'Admin Only',
        'Dashboard Overview', 'My Profile & Account', 'Finance & Payments',
        'Site Administration',
        'Your content workspace — all data is live from the system.',
        'Create a new article and submit it for publication.',
        'All articles — all authors.', 'All posts authored by you.',
        'Edit an existing post. Changes are saved immediately upon submission.',
        'Move a post to trash — requires confirmation. Action cannot be undone from this interface.',
        'Upload and manage files attached to your work.',
        'Manage your bookings and employee calendar.',
        'Improve your articles for better search engine visibility.',
        'Manage content languages and translation settings.',
        'Manage your user profile, password, and account settings.',
        'Full administrator access — all links open the site administration panel.',
        'Dashboard',
        'Administrator', 'Editor', 'Author', 'Contributor',
        'Manager', 'Employee', 'Member',
        'Published', 'Draft', 'Pending Review', 'Scheduled', 'Trashed',
        'Status',
        'Draft saved', 'Submitted for review', 'Updated',
        'No activity yet. Start by writing your first article.',
        'All Articles', 'My Articles', "Today's Schedule",
        'All statuses', 'Awaiting editor', 'Live on site', 'In progress', 'View My Schedule',
        'All Statuses', 'All Categories',
        'Title', 'Category', 'Lang', 'Modified', 'Actions', 'SEO',
        '+ Write New', 'Edit', 'Delete', 'View',
        'Recent Activity', 'Upcoming Appointments', 'Payment History',
        'Switch Interface Language', 'Translation Guidelines',
        'Profile', 'Account Settings & Security', 'Role Access Matrix',
        'Quick Actions', 'Upload File',
        'Appointment module not configured.',
        'Administrator access only',
        'Account settings available in profile form above.',
        'Password change available in profile form above.',
        'Notifications', 'Mark all read',
        'No notifications at this time.', 'No notifications.',
        'Failed to mark read', 'All marked as read', 'Network error',
        'Search articles…',
        'Loading messages…', 'No messages yet.', 'New', 'From: User #',
        'Fill all fields', 'Message sent', 'Send failed',
        'Send Message', 'To — User ID', 'User ID', 'Send',
        'Loading…', 'No payment records yet.',
        'Payment module not available.', 'WooCommerce not active',
        '#', 'Date', 'Total',
        'Showing {x} of {y} articles', 'Showing {x} articles',
        'No articles found.', 'No articles match the selected filters.',
        'Your articles go to review before publishing.',
        'To enable article writing, Frontend Admin plugin must be activated.',
        'Article not found.',
        'Frontend Admin plugin must be activated to edit articles.',
        'Frontend Admin plugin must be activated to delete articles.',
        'File uploads are available via Media Library:',
        'You do not have permission to upload files.',
        'Please contact the administrator to link your account.',
        'Please contact the administrator to link your account to the appointments system.',
        'Amelia Mgr', 'Amelia Emp',
        'Write Article', 'Profile / Account',
        'Full', 'Own', 'Pending', 'All',
        'New article saved: %s', 'New booking from: %s',
        'Method', 'Items', 'View Invoice', 'Previous', 'Next',
        'Section',
        'No upcoming appointments.',
        'Retry',
        'No keyword set',
        'Moves to trash only — not permanent',
        'You do not have permission to edit this article.',
        'You do not have permission to delete this article.',
        'Amelia plugin not active.',
        'Open Media Library',
        'Messages from visitors and the administration team.',
        'Members', 'Store', 'Name', 'Email', 'Role',
        'Product', 'Price', 'Stock', 'SKU',
        'Total Revenue', 'Total Orders', 'Average Order', 'Pending Orders',
        'Restore Article', 'Restore File', 'Article restored', 'File restored',
        'Delete Permanently', 'My Files',
        'SEO Health', 'SEO Score',
        'Order %s status changed to: %s',
        'Translating…', 'Try again', 'Could not create translation.', 'Unable to load schedule.',
        'WPML not active.', 'Invalid language.',
        // مفاتيح الوحدات الناقصة (T-31) — القيم الحرفية مطابقة لقيم
        // fallback فى modules/*.js حرفيًا.
        // (النصوص: Status/Name/Email/Role/Product/Price/Stock مسجَّلة بالفعل أعلاه)
        'Delete failed', 'Disabled', 'Enabled', 'File deleted',
        'This Month',
        'No appointments yet.',
        'No files yet.',
        'No invoices yet.',
        'No members yet.',
        'No payment methods yet.',
        'No products yet.',
        'No saved cards yet.',
        'Trash',
        'Card', 'Expires', 'Ending in',
        'Restore', 'Restore failed',
        'Translation created', 'Translation failed',
        // نصوص تعديلات الدفعة الثانية التاريخية — جدول الصحة العام
        // (admin.php)، نموذج تحرير SEO (قالب seo.php)، وتأكيد الحذف/الصفحات.
        'Integrations Health', 'Version', 'Capabilities', 'Reason',
        'Edit SEO', 'Focus Keyword', 'Meta Description', 'Robots',
        'Save SEO', 'SEO settings saved.', 'Cancel',
        'Delete this file permanently?', 'Load more',
        // نماذج أصلية وfallback للدفعة التاريخية — Core-first (Posts + Upload)
        'Content', 'Categories', 'Save Article',
        'Move this article to trash?', 'Moved to trash.',
        'Move “%s” to trash?', 'Move to Trash',
        'Opening the legacy form due to a technical issue.',
        'File', 'File uploaded.',
        'Allowed types: images, PDF, and Office documents.',
        'Legacy uploader unavailable.',
    ];
    $hash = substr( md5( serialize($strings) ), 0, 12 );
    if ( get_option('hossam_i18n_v') === $hash ) return;
    foreach ( $strings as $s ) {
        icl_register_string( 'hossam-dashboard', $s, $s );
    }
    update_option( 'hossam_i18n_v', $hash );
}, 5 );

if ( ! function_exists( 'hossam_get_i18n_strings' ) ) {
    /**
     * @return array<string,string>
     */
    function hossam_get_i18n_strings(): array {
        return [
            'goodMorning'         => hossam_t('Good morning'),
            'goodAfternoon'       => hossam_t('Good afternoon'),
            'goodEvening'         => hossam_t('Good evening'),
            'panelOverview'       => hossam_t('Dashboard Overview'),
            'panelWrite'          => hossam_t('Write New Article'),
            'panelAllArticles'    => hossam_t('All My Articles'),
            'panelEditArticle'    => hossam_t('Edit Article'),
            'panelDeleteArticle'  => hossam_t('Delete Article'),
            'panelMedia'          => hossam_t('Files & Documents'),
            'panelAppointments'   => hossam_t('My Schedule'),
            'panelSeo'            => hossam_t('Content Optimization'),
            'panelTranslation'    => hossam_t('Language Settings'),
            'panelProfile'        => hossam_t('My Profile'),
            'panelAdmin'          => hossam_t('Administration'),
            'panelInbox'          => hossam_t('My Inbox'),
            'panelFinance'        => hossam_t('Finance & Payments'),
            'notifEmpty'          => hossam_t('No notifications at this time.'),
            'notifEmptyAlt'       => hossam_t('No notifications.'),
            'markAllFail'         => hossam_t('Failed to mark read'),
            'allMarkedRead'       => hossam_t('All marked as read'),
            'networkError'        => hossam_t('Network error'),
            'noMessages'          => hossam_t('No messages yet.'),
            'inboxNew'            => hossam_t('New'),
            'inboxFrom'           => hossam_t('From: User #'),
            'fillAllFields'       => hossam_t('Fill all fields'),
            'messageSent'         => hossam_t('Message sent'),
            'sendFailed'          => hossam_t('Send failed'),
            'noPayments'          => hossam_t('No payment records yet.'),
            'paymentUnavailable'  => hossam_t('Payment module not available.'),
            'financeColId'        => hossam_t('#'),
            'financeColDate'      => hossam_t('Date'),
            'financeColTotal'     => hossam_t('Total'),
            'financeColStatus'    => hossam_t('Status'),
            'financeColMethod'    => hossam_t('Method'),
            'financeColItems'     => hossam_t('Items'),
            'viewInvoice'         => hossam_t('View Invoice'),
            'prevPage'            => hossam_t('Previous'),
            'nextPage'            => hossam_t('Next'),
            'filterAll'           => hossam_t('All Statuses'),
            'showingXofY'         => hossam_t('Showing {x} of {y} articles'),
            'showingX'            => hossam_t('Showing {x} articles'),
            'noArticles'          => hossam_t('No articles match the selected filters.'),
            'retry'               => hossam_t('Retry'),
            'financeLoading'      => hossam_t('Loading…'),
            'panelMembers'        => hossam_t('Members'),
            'panelStore'          => hossam_t('Store'),
            'membersColName'      => hossam_t('Name'),
            'membersColEmail'     => hossam_t('Email'),
            'membersColRole'      => hossam_t('Role'),
            'productsColProduct'  => hossam_t('Product'),
            'productsColPrice'    => hossam_t('Price'),
            'productsColStock'    => hossam_t('Stock'),
            'productsColSku'      => hossam_t('SKU'),
            'kpiTotalRevenue'     => hossam_t('Total Revenue'),
            'kpiTotalOrders'      => hossam_t('Total Orders'),
            'kpiAverageOrder'     => hossam_t('Average Order'),
            'kpiPendingOrders'    => hossam_t('Pending Orders'),
            'restoreArticle'      => hossam_t('Restore Article'),
            'restoreFile'         => hossam_t('Restore File'),
            'articleRestored'     => hossam_t('Article restored'),
            'fileRestored'        => hossam_t('File restored'),
            'deletePermanently'   => hossam_t('Delete Permanently'),
            'myFiles'             => hossam_t('My Files'),
            'seoHealth'           => hossam_t('SEO Health'),
            'seoScore'            => hossam_t('SEO Score'),
            'translating'         => hossam_t('Translating…'),
            'tryAgain'            => hossam_t('Try again'),
            'translateFailed'     => hossam_t('Could not create translation.'),
            'scheduleLoadFailed'  => hossam_t('Unable to load schedule.'),

            // مفاتيح الوحدات الناقصة (T-31 التاريخي). كل قيمة هنا
            // مطابقة حرفيًا لنص fallback فى الاستدعاء الفعلي داخل
            // modules/*.js حتى لا يتغير أي نص معروض قبل الترجمة الحية.
            'deleteFailed'        => hossam_t('Delete failed'),        // uploads.js
            'disabled'            => hossam_t('Disabled'),             // finance.js
            'enabled'             => hossam_t('Enabled'),              // finance.js
            'fileDeleted'         => hossam_t('File deleted'),         // uploads.js
            'kpiMonthRevenue'     => hossam_t('This Month'),           // finance.js
            'memberEmail'         => hossam_t('Email'),                // members.js
            'memberName'          => hossam_t('Name'),                 // members.js
            'memberRole'          => hossam_t('Role'),                 // members.js
            'noAppointments'      => hossam_t('No appointments yet.'), // bookings.js
            'noFiles'             => hossam_t('No files yet.'),        // uploads.js
            'noInvoices'          => hossam_t('No invoices yet.'),     // finance.js
            'noMembers'           => hossam_t('No members yet.'),      // members.js
            'noPaymentMethods'    => hossam_t('No payment methods yet.'), // finance.js
            'noProducts'          => hossam_t('No products yet.'),     // store.js
            'noSavedTokens'       => hossam_t('No saved cards yet.'),  // finance.js
            'panelTrash'          => hossam_t('Trash'),                // dashboard.js
            'pmStatus'            => hossam_t('Status'),               // finance.js
            'pmTitle'             => hossam_t('Method'),               // finance.js
            'productName'         => hossam_t('Product'),              // store.js
            'productPrice'        => hossam_t('Price'),                // store.js
            'productStock'        => hossam_t('Stock'),                // store.js
            'restore'             => hossam_t('Restore'),              // uploads.js
            'restoreFailed'       => hossam_t('Restore failed'),       // posts.js + uploads.js
            'tokenBrand'          => hossam_t('Card'),                 // finance.js
            'tokenExpiry'         => hossam_t('Expires'),              // finance.js
            'tokenLast4'          => hossam_t('Ending in'),            // finance.js
            'translationCreated'  => hossam_t('Translation created'),  // posts.js
            'translationFailed'   => hossam_t('Translation failed'),   // posts.js

            // مفاتيح التعديلات (الدفعة الثانية التاريخية).
            // القيم مطابقة حرفيًا لقيم fallback فى الاستدعاءات الفعلية.
            'confirmDeleteFile'   => hossam_t('Delete this file permanently?'), // uploads.js
            'loadMore'            => hossam_t('Load more'),                     // uploads.js
            'editSeo'             => hossam_t('Edit SEO'),                      // قالب seo.php (inline)
            'metaDescription'     => hossam_t('Meta Description'),              // قالب seo.php (inline)
            'robots'              => hossam_t('Robots'),                        // قالب seo.php (inline)
            'saveSeo'             => hossam_t('Save SEO'),                      // قالب seo.php (inline)
            'seoSaved'            => hossam_t('SEO settings saved.'),           // قالب seo.php (inline)

            // الدفعة التاريخية — Core-first (Posts + Upload)
            'content'             => hossam_t('Content'),                       // posts.js + قالب posts.php
            'categories'          => hossam_t('Categories'),                    // قالب posts.php
            'saveArticle'         => hossam_t('Save Article'),                  // قالب posts.php
            'draftSaved'          => hossam_t('Draft saved'),                   // posts.js
            'submittedReview'     => hossam_t('Submitted for review'),          // posts.js
            'published'           => hossam_t('Published'),                     // posts.js
            'updated'             => hossam_t('Updated'),                        // posts.js
            'confirmTrashArticle' => hossam_t('Move this article to trash?'),   // posts.js
            'movedToTrash'        => hossam_t('Moved to trash.'),               // posts.js
            'fallbackNotice'      => hossam_t('Opening the legacy form due to a technical issue.'), // posts.js + uploads.js
            'fileLabel'           => hossam_t('File'),                          // قالب files.php
            'fileUploaded'        => hossam_t('File uploaded.'),                // uploads.js
            'moveToTrashBtn'      => hossam_t('Move to Trash'),                 // قالب posts.php (زر)
        ];
    }
}
