<?php
/**
 * runtime/templates/dashboard/overview.php — بانل النظرة العامة (الدفعة 7)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/overview.php (بصمة
 * المصدر بالبايت مثبتة في inventory الدفعة 7 — snapshot 67044A2F…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ):
 *   1. إعادة حساب $sees_calendar المحلي أضيفت عليها بوابة المالك
 *      is_feature_enabled('amelia') — شروط المصدر (القدرات + حالة
 *      التكامل) باقية كما هي.
 *   2. زر My Profile في _hossam_dashboard_render_quick_actions بقي
 *      غير مشروط في المصدر — أضيفت عليه بوابة المالك
 *      is_feature_enabled('ultimate_member_profile').
 * الأزرار الأخرى تستهلك بوابة المالك ضمنيًا عبر args الـshell:
 * $sees_content (posts) و$can_upload (files) والشرط المحلي
 * $sees_calendar (amelia).
 *
 * args صريحة: تستقبل متغيراتها من الـshell عبر محمل أجزاء Runtime
 * (render_part) — قائمة args الموثقة في runtime/templates/dashboard.php.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$overview_amelia = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'amelia' )
    : [ 'status' => 'unavailable' ];
$sees_calendar = 'unavailable' !== $overview_amelia['status'] && (
    current_user_can( 'manage_options' )
    || current_user_can( 'view_amelia_calendar_all' )
    || current_user_can( 'view_amelia_calendar' )
    )
    && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'amelia' );
?>
    <!-- ══ PANEL: OVERVIEW ══ -->
    <div class="panel active" id="panel-overview">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Overview' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Dashboard Overview' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Your content workspace — all data is live from the system.' ) ); ?></p>
      </div>

      <!-- Stat Cards -->
      <div class="grid g4 mb-16">

        <!-- Total Articles -->
        <?php if ( $sees_content ) : ?>
        <div class="stat-card sc-gold">
          <div class="stat-icon si-gold">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          </div>
          <div class="stat-label"><?php echo esc_html( $is_editor||$is_admin ? hossam_t('All Articles') : hossam_t('My Articles') ); ?></div>
          <div class="stat-val"><?php echo $stat_all; ?></div>
          <div class="stat-sub"><?php echo esc_html( hossam_t( 'All statuses' ) ); ?></div>
        </div>
        <?php endif; ?>

        <!-- Pending Review -->
        <?php if ( $sees_content ) : ?>
        <div class="stat-card sc-warn">
          <div class="stat-icon si-warn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </div>
          <div class="stat-label"><?php echo esc_html( hossam_t( 'Pending Review' ) ); ?></div>
          <div class="stat-val"><?php echo $stat_pending; ?></div>
          <div class="stat-sub"><?php echo esc_html( hossam_t( 'Awaiting editor' ) ); ?></div>
        </div>
        <?php endif; ?>

        <!-- Published -->
        <?php if ( $sees_content ) : ?>
        <div class="stat-card sc-green">
          <div class="stat-icon si-green">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>
          </div>
          <div class="stat-label"><?php echo esc_html( hossam_t( 'Published' ) ); ?></div>
          <div class="stat-val"><?php echo $stat_published; ?></div>
          <div class="stat-sub"><?php echo esc_html( hossam_t( 'Live on site' ) ); ?></div>
        </div>
        <?php endif; ?>

        <!-- Drafts -->
        <?php if ( $sees_content ) : ?>
        <div class="stat-card sc-blue">
          <div class="stat-icon si-blue">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </div>
          <div class="stat-label"><?php echo esc_html( hossam_t( 'Draft' ) ); ?></div>
          <div class="stat-val"><?php echo $stat_draft; ?></div>
          <div class="stat-sub"><?php echo esc_html( hossam_t( 'In progress' ) ); ?></div>
        </div>
        <?php endif; ?>

        <!-- Appointments placeholder — Phase 2 -->
        <?php if ( $sees_calendar && !$sees_content ) : ?>
        <div class="stat-card sc-blue">
          <div class="stat-icon si-blue">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </div>
          <div class="stat-label"><?php echo esc_html( hossam_t( "Today's Schedule" ) ); ?></div>
          <div class="stat-val">—</div>
          <div class="stat-sub"><?php echo esc_html( hossam_t( 'View My Schedule' ) ); ?></div>
        </div>
        <?php endif; ?>

      </div><!-- /stat cards -->

      <div class="grid g2 mb-16">

        <!-- Recent Activity — WP_Query حقيقي (B7-03: تُعرض فقط مع تمكين
             posts؛ الـshell لا يشغّل الاستعلام أصلًا عند التعطيل) -->
        <?php if ( $sees_content && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'posts' ) && null !== $activity_query ) : ?>
        <div class="card">
          <div class="flex items-center gap-8 mb-12">
            <div class="card-title"><?php echo esc_html( hossam_t( 'Recent Activity' ) ); ?></div>
          </div>
          <?php if ( $activity_query->have_posts() ) : ?>
            <?php while ( $activity_query->have_posts() ) : $activity_query->the_post(); ?>
              <?php
                $pstatus  = get_post_status();
                $dot_cls  = _dash_activity_dot($pstatus);
                $act_txt  = _dash_activity_text($pstatus);
                $modified = get_the_modified_date('M j, Y');
              ?>
              <div class="activity-item">
                <div class="act-dot <?php echo $dot_cls; ?>"></div>
                <div class="act-txt"><?php echo esc_html($act_txt); ?>: <span class="act-post"><?php echo esc_html(get_the_title()); ?></span></div>
                <div class="act-time"><?php echo esc_html($modified); ?></div>
              </div>
            <?php endwhile; wp_reset_postdata(); ?>
          <?php else : ?>
            <div class="notif-empty" style="padding:20px 0;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="display:block;margin:0 auto 8px;opacity:.3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
              <?php echo esc_html( hossam_t( 'No activity yet. Start by writing your first article.' ) ); ?>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ( $sees_calendar ) : ?>
        <div class="card">
        <div class="card-title"><?php echo esc_html( hossam_t( 'Upcoming Appointments' ) ); ?></div>
          <div id="appts-wrap">
            <div class="skel-row"><div class="skel-line" style="width:80%"></div></div>
          </div>
          <script>
          document.addEventListener('DOMContentLoaded', function(){
            const esc=s=>{const d=document.createElement('div');d.textContent=String(s);return d.innerHTML;};
            const wrap = document.getElementById('appts-wrap');
            if(!wrap||!window.hossamAjax) return;
            const fd = new FormData();
            fd.append('action','hossam_get_upcoming_appointments');
            fd.append('nonce',hossamAjax.nonce);
            const load=()=>fetch(hossamAjax.ajaxurl,{method:'POST',body:fd})
              .then(async r=>({response:r,data:JSON.parse(await r.text())}))
              .then(({response:r,data:d})=>{
                if(!r.ok||!d||d.success!==true){
                  const denied=r.status===401||r.status===403;
                  wrap.innerHTML='<div class="ph-notice">'+(denied
                    ? '<?php echo esc_js( hossam_t( 'You do not have permission to view appointments.' ) ); ?>'
                    : '<?php echo esc_js( hossam_t( 'Appointment module not configured.' ) ); ?>')+'</div>';
                  return;
                }
                if(!Array.isArray(d.data)) throw new Error('invalid_contract');
                if(!d.data.length){
                  wrap.innerHTML='<div class="ph-notice"><?php echo esc_js( hossam_t( 'No upcoming appointments.' ) ); ?></div>';
                  return;
                }
                wrap.innerHTML=d.data.map(a=>
                  `<div class="activity-item">
                    <div class="act-dot gold"></div>
                    <div class="act-txt">${esc(a.service_name||'')} — ${esc(a.bookingStart||'')}</div>
                  </div>`
                ).join('');
              })
              .catch(()=>{
                wrap.innerHTML='<div class="ph-notice" style="color:var(--danger)"><?php echo esc_js( hossam_t( 'Could not load appointments.' ) ); ?> <button type="button" id="overview-appts-retry" class="btn btn-sm"><?php echo esc_js( hossam_t( 'Retry' ) ); ?></button></div>';
                const retry=document.getElementById('overview-appts-retry');
                if(retry) retry.addEventListener('click',load,{once:true});
              });
            load();
          });
          </script>
        </div>
        <?php endif; ?>

        <!-- م26 — quick-actions helper -->
        <?php
        if ( ! function_exists( '_hossam_dashboard_render_quick_actions' ) ) {
            function _hossam_dashboard_render_quick_actions( bool $sees_content, string $extra_btn = '' ): void { ?>
                <?php if ( $sees_content ) : ?>
                <button class="qa-btn qa-primary"
                        onclick="nav(document.querySelector('[data-panel=write]'),'write')">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  <?php echo esc_html( hossam_t( 'Write New Article' ) ); ?>
                </button>
                <?php endif; ?>
                <?php echo $extra_btn; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php if ( HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'ultimate_member_profile' ) ) : ?>
                <button class="qa-btn"
                        onclick="nav(document.querySelector('[data-panel=profile]'),'profile')">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                  <?php echo esc_html( hossam_t( 'My Profile' ) ); ?>
                </button>
                <?php endif; ?>
            <?php }
        }
        ?>

        <!-- Quick Actions card when no calendar -->
        <?php if ( ! $sees_calendar ) : ?>
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Quick Actions' ) ); ?></div>
          <div class="quick-actions">
            <?php
            ob_start();
            if ( $sees_content ) : ?>
            <button class="qa-btn" onclick="nav(document.querySelector('[data-panel=all-articles]'),'all-articles')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
              <?php echo esc_html( hossam_t( 'My Articles' ) ); ?>
            </button>
            <?php endif;
            if ( $can_upload ) : ?>
            <button class="qa-btn" onclick="nav(document.querySelector('[data-panel=media]'),'media')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <?php echo esc_html( hossam_t( 'Upload File' ) ); ?>
            </button>
            <?php endif;
            $_extra1 = ob_get_clean();
            _hossam_dashboard_render_quick_actions( $sees_content, $_extra1 );
            ?>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <!-- Quick Actions row (when calendar is visible) -->
      <?php if ( $sees_calendar ) : ?>
      <div class="card">
        <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Quick Actions' ) ); ?></div>
        <div class="quick-actions">
          <?php
          ob_start(); ?>
          <button class="qa-btn" onclick="nav(document.querySelector('[data-panel=appointments]'),'appointments')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?php echo esc_html( hossam_t( 'My Schedule' ) ); ?>
          </button>
          <?php
          $_extra2 = ob_get_clean();
          _hossam_dashboard_render_quick_actions( $sees_content, $_extra2 );
          ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /panel-overview -->
