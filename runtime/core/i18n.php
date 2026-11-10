<?php
/**
 * runtime/core/i18n.php
 * ══════════════════════════════════════════════════════════════
 * Role: everything WPML String Translation related — the helper function,
 * string registration, and the single source of the i18n array sent to JS.
 *
 * Source: a verbatim 1:1 move from legacy mu-plugins/hossam-dashboard/core/i18n.php
 * (batch 2 of HAL Frontend Dashboard) — with no change to the keys, values or
 * registration texts at that time:
 *   - hossam_t() as it was.
 *   - WPML string registration via icl_register_string() under the context
 *     name 'hossam-dashboard' (init hook, priority 5) with the md5 hash logic
 *     preventing unnecessary re-registration — as it was.
 *   - hossam_get_i18n_strings() complete with all keys (the originals + the
 *     twenty-eight T-31 keys + the batch additions) without any change then.
 *
 * Text-domain decision (documented for its owner presentations): WPML
 * registration via icl_register_string() does not depend on a text domain at
 * all. In this release the text domain of the interface strings was unified —
 * the remaining __() calls in runtime now use 'hal-frontend-dashboard' and the
 * child-theme domain no longer appears anywhere in runtime; the changed
 * keys/values above are re-registered through the same hash-gated mechanism.
 *
 * [Delta tag — H4 §2.4-D1 | docblock only — zero behavior change] (OEB/7):
 * the String Translation integration was existing and is the plugin's own
 * mechanism (icl_register_string :218 / icl_t :33); the text domain was
 * then settled (WPML registration does not depend on it; the 109 __()
 * strings sat on the child-theme domain deliberately at that time); the
 * future recipe is documented in H4 §2.1-6 and opens by owner decision only.
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
        // Missing-module keys (T-31) — the literals match the fallback values
        // in modules/*.js verbatim.
        // (The Status/Name/Email/Role/Product/Price/Stock texts are already
        // registered above.)
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
        // Historic second-batch edits texts — the general health table
        // (admin.php), the SEO edit form (the seo.php template), and the
        // delete confirmations/pages.
        'Integrations Health', 'Version', 'Capabilities', 'Reason',
        'Edit SEO', 'Focus Keyword', 'Meta Description', 'Robots',
        'Save SEO', 'SEO settings saved.', 'Cancel',
        'Delete this file permanently?', 'Load more',
        // Native forms and fallback for the historic batch — Core-first (Posts + Upload)
        'Content', 'Categories', 'Save Article',
        'Move this article to trash?', 'Moved to trash.',
        'Move “%s” to trash?', 'Move to Trash',
        'Opening the legacy form due to a technical issue.',
        'File', 'File uploaded.',
        'Allowed types: images, PDF, and Office documents.',
        'Legacy uploader unavailable.',
        // v1.0.5 (A2+B): excerpt + featured image + the keep-categories disclosure.
        'Excerpt', 'Featured Image', 'Set featured image',
        'Leave all unchecked to keep the current categories.',
        // v1.0.6 §11 (P2): only strings actually rendered now (P1) — no slug,
        // no Gutenberg/WordPress copies. 'View'/'Edit' already registered above (:74).
        'Remove featured image', 'Permalink', 'Temporary — final on publish', 'Preview',
        // v1.0.6 §9 (§11): HAL-owned rich-editor strings only — failure notice
        // + upload-failure message. WordPress/Gutenberg UI is translated by
        // its own packages; nothing is copied here.
        'Rich editor unavailable — using the plain text field.', 'Media upload failed.',
        // v1.0.6 §14: HAL-owned translation status/permission strings only —
        // rendered by the translations panel (translations.php) and the
        // translate endpoint (ajax/posts.php). No WPML job-system copies.
        'Article translations', 'Original', 'Not translated',
        'Translations are unavailable.',
        'You do not have permission to create this translation.',
        'You do not have permission to edit this translation.',
        'This translation does not belong to the requested article.',
        // v1.0.7 REC-5 (5.1): every remaining literal hossam_t() string in
        // runtime (templates + ajax) — the observed gap list (118 strings — a
        // PHP 8.4 inventory).
        'AI Writing Assistant', 'API', 'API Key', 'About',
        'Account settings are unavailable.', 'Active', 'Active languages on this site:', 'Additional links',
        'Advanced SEO Dashboard', 'All Members', 'All Posts', 'All articles have a focus keyword set.',
        'All users & roles', 'Allowed types', 'Amelia Integration Health', 'Appointments',
        'Appointments Backend', 'Arabic content is displayed right-to-left automatically when Arabic is the active language.', 'Article could not be moved to trash.', 'Article could not be restored.',
        'Article forms management', 'Blog', 'Cache Settings', 'Cells reflect default WordPress capabilities per role. Specific-article Edit/Delete/Trash always re-check edit_post or delete_post for that resource, so direct capability grants follow the capability itself rather than the role name. Store Products requires edit_products, Store Orders require manage_woocommerce or manage_options, and calendar sections also require their integration to be available.',
        'Checked routes', 'Choose at least one valid robots directive.', 'Configured', 'Contact',
        'Contact your editor to request new translations or to update existing ones.', 'Could not load appointments.', 'Deleted', 'Edit %s',
        'File could not be deleted.', 'File could not be restored.', 'Form Builder', 'Full SEO analytics',
        'Full administration panel', 'Full booking management', 'Full content management', 'Go to My Articles',
        'Grammar Check', 'Home', 'Inactive', 'Inbox status unavailable',
        'Invalid article data.', 'Invalid article.', 'Invalid categories.', 'Invalid description.',
        'Invalid featured image.', 'Invalid focus keyword.', 'Invalid robots directive.', 'Invalid title.',
        'Invoices', 'Language Manager', 'Language switcher is unavailable.', 'Languages & Translations',
        'Last checked', 'Last error', 'MU Plugin Dashboard', 'Manage staff accounts and roles.',
        'Members could not be loaded.', 'Missing', 'Missing Focus Keywords', 'No file types are currently enabled for upload.',
        'No noindex articles.', 'Noindex Articles', 'Open Amelia settings', 'Orders',
        'Orders could not be loaded.', 'PHP hooks and shortcodes managed via hossam-dashboard.php', 'Password change is unavailable.', 'Payment Methods',
        'Payment methods could not be loaded.', 'Performance & cache exclusions', 'Plugin', 'Privacy Policy',
        'Products', 'Products could not be loaded.', 'Profile form is unavailable.', 'Reachable',
        'Restore recently deleted articles.', 'Roles', 'SEO Dashboard', 'SEO Health Overview',
        'SEO Meta Description', 'SEO Settings', 'SEO editing is read-only until Rank Math fields are verified.', 'SEO settings could not be saved.',
        'Saved Cards', 'Saved payment tokens could not be loaded.', 'Score Distribution', 'Security',
        'Select an article from %s and click Delete to load the confirmation form here.', 'Select an article from %s and click Edit to load the form here.', 'Services', 'Settings',
        'Shop', 'Site Settings', 'Suggest Improvements', 'Suggest SEO',
        'Suggest Translation', 'Summary could not be loaded.', 'Target language code', 'Terms',
        'To translate an article, find it in All My Articles and use the translation option next to each article.', 'Translate to %s', 'Translation contract is unavailable.', 'Translation could not be linked.',
        'Translation editing is managed through the site administration area.', 'Translation management', 'Trash is empty.', 'Ultimate Member status',
        'Unreachable', 'Upload', 'User Management', 'WooCommerce status',
        'You do not have permission to view appointments.', 'You do not have permission to view products.',
        // v1.0.7 REC-5 (5.2): texts of the new JS keys with no registered
        // counterpart (the rest of them registered above within the 5.1 block
        // verbatim; loadFailed excluded from the map by owner approval — it
        // stays fallback-only with no new text).
        'AI is working on it…', 'Could not start the AI request.', 'The AI request took too long. Please try again.',
        'AI request failed.', 'No suggestion returned.', 'AI suggestion', 'Apply', 'Dismiss',
        'Invalid server response', 'Appointment integration is unavailable.', 'Request failed',
        // v1.1.0 OEB/7 (H1 §6-8): the three inherited OEB keys — the manual
        // update button/dialog/toast (binding: the assets-test contract
        // forbids any new inline fallback beyond them). Registered
        // automatically through the existing hossam_i18n_v gate above; the
        // final wording accepted via T-1.
        'Manual update', 'Checking for updates…', 'Manual update started.',
        // v1.1.2: the AI assistant card tag and description (posts.php :241/:243)
        // move from unregistered Arabic __() strings to registered English
        // hossam_t() sources — same hash-gated mechanism as the batches above.
        'Optional',
        'Optional assistant for proofreading, translation and SEO — results appear inside this panel.',
        // v1.1.3: the H1 rejection source moves to the unified domain and
        // registers here in the same batch.
        'The H1 tag is not allowed in the article content — use a level-2 heading instead.',
        // v1.1.3 W6 (the editor build): the side-column display holders, the
        // Languages & Translation card, and the simplified SEO group —
        // English sources registered through the same hash-gated mechanism.
        'Publishing',
        'The permalink is set after the first save.',
        'Metadata',
        'No cover selected yet.',
        'Write a short excerpt in the form — it feeds archives and search results.',
        'Tags',
        'No tags yet.',
        'Tags power related-article linking — keep them short and specific.',
        'Languages & Translation',
        'Translation creates a linked draft in the target language — edit it and publish it like any article.',
        'Open translation',
        'SEO Title',
        'Good',
        'Average',
        'Weak',
        'Not calculated yet.',
        'Raise the score by completing the fields below — Rank Math calculates it automatically.',
        'The keyword your client searches for — e.g. corporate lawyer in Cairo.',
        'The title shown in search results — it may differ from the article title.',
        'The summary line shown under the title in search results.',
        'Do not show this page in search results',
        'Do not follow links on this page',
        'Do not store a cached copy of this page',
        'Do not show images from this page in image search',
        // v1.1.3 W7 (the professional toolbar): the ten-group toolbar,
        // the More/History groups, the bottom action bar and the prompts -
        // every label JS-consumed through the t() channel (the triple
        // ledger: literal here + map key below + matching fallback).
        'Heading level',
        'Paragraph (Normal Text)',
        'Section Heading (H2)',
        'Subsection Heading (H3)',
        'Minor Detail (H4)',
        'Font family',
        'Modern Sans (Inter)',
        'Legal Serif (Merriweather)',
        'Arabic Corporate (Cairo)',
        'Classical Serif (Cormorant)',
        'Font size',
        '13px (Small)',
        '15px (Body)',
        '18px (Medium)',
        '22px (Large)',
        'Line spacing',
        '↕ 1.4 (Compact)',
        '↕ 1.6 (Normal)',
        '↕ 1.8 (Relaxed)',
        '↕ 2.0 (Double)',
        'Bold (Ctrl+B)',
        'Italic (Ctrl+I)',
        'Underline (Ctrl+U)',
        'Strikethrough',
        'Subscript (X₂)',
        'Superscript (X²)',
        'Clear formatting',
        'Text color',
        'Suggested colors:',
        'Free color picker:',
        'Highlight',
        'Change case',
        'Sentence case.',
        'lowercase',
        'UPPERCASE',
        'Title Case',
        'Grow font',
        'Shrink font',
        'Align left',
        'Center align',
        'Align right',
        'Justify full',
        'Switch paragraph direction',
        'Bullet list',
        'Numbered list',
        'Multilevel list',
        'Checklist',
        'Decrease indent',
        'Increase indent',
        'Legal statutory clause',
        'Legal callout / notice box',
        'Insert footnote and citation',
        'Insert legal comparison table',
        'Insert collapsible section',
        'Insert reference link',
        'Insert image or document',
        'Insert legal symbol',
        'Divider line',
        'More',
        'More tools',
        'Undo (Ctrl+Z)',
        'Redo (Ctrl+Y)',
        'Focus mode',
        'Link URL:',
        'Legal symbol:',
        'Footnote text:',
        'Publish Now',
        'Save as Draft',
        'Discard Changes',
        // v1.1.3 W7 (the gate-verdict family item): the nine swatch color
        // names - hover tooltips are real UI strings; the hex is the data,
        // the name is the display (the i18n contract line above).
        'Navy',
        'Gold',
        'Red',
        'Blue',
        'Green',
        'Gray',
        'Black',
        'Yellow',
        'Gold Pale',
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

            // Missing-module keys (historic T-31). Every value here matches
            // the fallback text in the actual call inside modules/*.js
            // verbatim so no displayed text changes before live translation.
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

            // The edits keys (the historic second batch).
            // Values match the fallback values in the actual calls verbatim.
            'confirmDeleteFile'   => hossam_t('Delete this file permanently?'), // uploads.js
            'loadMore'            => hossam_t('Load more'),                     // uploads.js
            'editSeo'             => hossam_t('Edit SEO'),                      // seo.php template (inline)
            'metaDescription'     => hossam_t('Meta Description'),              // seo.php template (inline)
            'robots'              => hossam_t('Robots'),                        // seo.php template (inline)
            'saveSeo'             => hossam_t('Save SEO'),                      // seo.php template (inline)
            'seoSaved'            => hossam_t('SEO settings saved.'),           // seo.php template (inline)

            // The historic batch — Core-first (Posts + Upload)
            'content'             => hossam_t('Content'),                       // posts.js + posts.php template
            'categories'          => hossam_t('Categories'),                    // posts.php template
            'saveArticle'         => hossam_t('Save Article'),                  // posts.php template
            'draftSaved'          => hossam_t('Draft saved'),                   // posts.js
            'submittedReview'     => hossam_t('Submitted for review'),          // posts.js
            'published'           => hossam_t('Published'),                     // posts.js
            'updated'             => hossam_t('Updated'),                        // posts.js
            'confirmTrashArticle' => hossam_t('Move this article to trash?'),   // posts.js
            'movedToTrash'        => hossam_t('Moved to trash.'),               // posts.js
            'fallbackNotice'      => hossam_t('Opening the legacy form due to a technical issue.'), // posts.js + uploads.js
            'fileLabel'           => hossam_t('File'),                          // files.php template
            'fileUploaded'        => hossam_t('File uploaded.'),                // uploads.js
            'moveToTrashBtn'      => hossam_t('Move to Trash'),                 // posts.php template (button)
            // v1.0.5 (A2+B) — values match the fallback texts in the actual calls verbatim.
            'excerptLabel'        => hossam_t('Excerpt'),                        // posts.php template
            'featuredImage'       => hossam_t('Featured Image'),                // posts.js + posts.php template
            'setFeaturedImage'    => hossam_t('Set featured image'),            // posts.js + posts.php template
            // v1.0.6 §11: JS keys match posts.js fallbacks literally (P1-visible only).
            'removeFeaturedImage' => hossam_t('Remove featured image'),   // posts.js
            'viewLink'            => hossam_t('View'),                     // posts.js
            'previewLink'         => hossam_t('Preview'),                  // posts.js
            'editLink'            => hossam_t('Edit'),                     // posts.js
            // v1.0.6 §8.6: posts.js success-card deep link to the SEO panel
            // ('SEO' already registered in the strings list above — no slug).
            'seoLink'             => hossam_t('SEO'),                      // posts.js
            // v1.0.6 §9 (§11): HAL-owned rich-editor keys — fallbacks in
            // posts.js match these values literally.
            'richEditorUnavailable' => hossam_t('Rich editor unavailable — using the plain text field.'), // posts.js
            'richUploadFailed'  => hossam_t('Media upload failed.'), // posts.js
            'keepCategoriesNote'  => hossam_t('Leave all unchecked to keep the current categories.'), // posts.php template
            // v1.0.7 REC-5 (5.2): the fourteen defined t() keys — values match
            // the fallback texts in the JS files verbatim (and the new ones
            // registered above).
            // loadFailed excluded by owner decision — stays fallback-only (see
            // the risks).
            // JS files are never touched.
            'aiThinking'             => hossam_t('AI is working on it…'),                        // ai.js:58
            'aiSubmitFailed'         => hossam_t('Could not start the AI request.'),             // ai.js:77
            'aiTimeout'              => hossam_t('The AI request took too long. Please try again.'), // ai.js:124
            'aiFailed'               => hossam_t('AI request failed.'),                          // ai.js:146
            'aiNoResult'             => hossam_t('No suggestion returned.'),                     // ai.js:164
            'aiSuggestion'           => hossam_t('AI suggestion'),                               // ai.js:170
            'aiApply'                => hossam_t('Apply'),                                       // ai.js:173
            'aiDismiss'              => hossam_t('Dismiss'),                                     // ai.js:174
            'contractError'          => hossam_t('Invalid server response'),                     // bookings.js:42,59; inbox.js:49,80,160; members.js:52,54
            'forbidden'              => hossam_t('You do not have permission to view appointments.'), // bookings.js:52
            'integrationUnavailable' => hossam_t('Appointment integration is unavailable.'),     // bookings.js:53
            'requestFailed'          => hossam_t('Request failed'),                              // inbox.js:51; members.js:53
            'allFocusKeywords'       => hossam_t('All articles have a focus keyword set.'),      // seo.php:306 (inline JS)
            'noNoindexArticles'      => hossam_t('No noindex articles.'),                        // seo.php:307 (inline JS)

            // v1.1.0 OEB/7 (H1 §6-8): the three inherited OEB keys — the manual
            // update button/dialog/toast. The literal sources registered above
            // in the strings list (final wording accepted via T-1).
            'oeb_open'               => hossam_t('Manual update'),                    // the manual-update dialog open button
            'oeb_dialog_status'      => hossam_t('Checking for updates…'),            // the manual-update dialog status line
            'oeb_manual_update'      => hossam_t('Manual update started.'),           // the manual-update started toast

            // v1.1.3 W7: the professional-toolbar keys - values match the
            // posts.js t() fallbacks verbatim (the triple ledger).
            'w7HeadingLabel' => hossam_t('Heading level'),
            'w7OptParagraph' => hossam_t('Paragraph (Normal Text)'),
            'w7OptH2' => hossam_t('Section Heading (H2)'),
            'w7OptH3' => hossam_t('Subsection Heading (H3)'),
            'w7OptH4' => hossam_t('Minor Detail (H4)'),
            'w7FontLabel' => hossam_t('Font family'),
            'w7FontInter' => hossam_t('Modern Sans (Inter)'),
            'w7FontSerif' => hossam_t('Legal Serif (Merriweather)'),
            'w7FontCairo' => hossam_t('Arabic Corporate (Cairo)'),
            'w7FontCormorant' => hossam_t('Classical Serif (Cormorant)'),
            'w7SizeLabel' => hossam_t('Font size'),
            'w7SizeSmall' => hossam_t('13px (Small)'),
            'w7SizeBody' => hossam_t('15px (Body)'),
            'w7SizeMedium' => hossam_t('18px (Medium)'),
            'w7SizeLarge' => hossam_t('22px (Large)'),
            'w7LineHeightLabel' => hossam_t('Line spacing'),
            'w7LhCompact' => hossam_t('↕ 1.4 (Compact)'),
            'w7LhNormal' => hossam_t('↕ 1.6 (Normal)'),
            'w7LhRelaxed' => hossam_t('↕ 1.8 (Relaxed)'),
            'w7LhDouble' => hossam_t('↕ 2.0 (Double)'),
            'w7Bold' => hossam_t('Bold (Ctrl+B)'),
            'w7Italic' => hossam_t('Italic (Ctrl+I)'),
            'w7Underline' => hossam_t('Underline (Ctrl+U)'),
            'w7Strike' => hossam_t('Strikethrough'),
            'w7Subscript' => hossam_t('Subscript (X₂)'),
            'w7Superscript' => hossam_t('Superscript (X²)'),
            'w7ClearFormat' => hossam_t('Clear formatting'),
            'w7TextColor' => hossam_t('Text color'),
            'w7Suggested' => hossam_t('Suggested colors:'),
            'w7FreePicker' => hossam_t('Free color picker:'),
            'w7Highlight' => hossam_t('Highlight'),
            'w7Case' => hossam_t('Change case'),
            'w7CaseSentence' => hossam_t('Sentence case.'),
            'w7CaseLower' => hossam_t('lowercase'),
            'w7CaseUpper' => hossam_t('UPPERCASE'),
            'w7CaseTitle' => hossam_t('Title Case'),
            'w7Grow' => hossam_t('Grow font'),
            'w7Shrink' => hossam_t('Shrink font'),
            'w7AlignLeft' => hossam_t('Align left'),
            'w7AlignCenter' => hossam_t('Center align'),
            'w7AlignRight' => hossam_t('Align right'),
            'w7Justify' => hossam_t('Justify full'),
            'w7Direction' => hossam_t('Switch paragraph direction'),
            'w7BulletList' => hossam_t('Bullet list'),
            'w7NumberedList' => hossam_t('Numbered list'),
            'w7Multilevel' => hossam_t('Multilevel list'),
            'w7Checklist' => hossam_t('Checklist'),
            'w7Outdent' => hossam_t('Decrease indent'),
            'w7Indent' => hossam_t('Increase indent'),
            'w7LegalQuote' => hossam_t('Legal statutory clause'),
            'w7Callout' => hossam_t('Legal callout / notice box'),
            'w7Footnote' => hossam_t('Insert footnote and citation'),
            'w7ComparisonTable' => hossam_t('Insert legal comparison table'),
            'w7Collapsible' => hossam_t('Insert collapsible section'),
            'w7RefLink' => hossam_t('Insert reference link'),
            'w7Media' => hossam_t('Insert image or document'),
            'w7LegalSymbol' => hossam_t('Insert legal symbol'),
            'w7Divider' => hossam_t('Divider line'),
            'w7More' => hossam_t('More'),
            'w7MoreTitle' => hossam_t('More tools'),
            'w7Undo' => hossam_t('Undo (Ctrl+Z)'),
            'w7Redo' => hossam_t('Redo (Ctrl+Y)'),
            'w7Zen' => hossam_t('Focus mode'),
            'w7PromptLink' => hossam_t('Link URL:'),
            'w7PromptSymbol' => hossam_t('Legal symbol:'),
            'w7PromptFootnote' => hossam_t('Footnote text:'),
            'w7PublishNow' => hossam_t('Publish Now'),
            'w7SaveDraft' => hossam_t('Save as Draft'),
            'w7Discard' => hossam_t('Discard Changes'),

            // v1.1.3 W7: the nine swatch color-name keys - values match the
            // posts.js swatch-row fallbacks verbatim.
            'w7ColorNavy' => hossam_t('Navy'),
            'w7ColorGold' => hossam_t('Gold'),
            'w7ColorRed' => hossam_t('Red'),
            'w7ColorBlue' => hossam_t('Blue'),
            'w7ColorGreen' => hossam_t('Green'),
            'w7ColorGray' => hossam_t('Gray'),
            'w7ColorBlack' => hossam_t('Black'),
            'w7ColorYellow' => hossam_t('Yellow'),
            'w7ColorGoldPale' => hossam_t('Gold Pale'),
        ];
    }
}
