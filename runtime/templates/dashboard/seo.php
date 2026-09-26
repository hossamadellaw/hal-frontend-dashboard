<?php
/**
 * runtime/templates/dashboard/seo.php — بانل SEO (الدفعة 8)
 * ══════════════════════════════════════════════════════════════
 * المصدر: legacy theme/template-parts/dashboard/seo.php (بصمة المصدر
 * بالبايت مثبتة في inventory الدفعة 8 — snapshot 5CD55D5D…).
 *
 * الدلتا المصرَّح بها الوحيدة (قرار المالك B3-08 — الخيار أ
 * §15.14/§15.16/§15.19/§15.20): بوابة المالك is_feature_enabled(
 * 'rank_math_seo') قبل أول إخراج — return مبكر (تعليق HTML للبانل
 * في المصدر يسبق شرطه، فامتداد الشرط وحده لا يمنع الإخراج)؛ شرط
 * قدرة المصدر current_user_can('edit_posts') باقٍ حرفيًا كما هو،
 * وpagination ($_seo_page عبر absint/max) وresource permission
 * (edit_post لكل مقال داخل الحلقة) وregistry states
 * ($hossam_rm_can_read/$hossam_rm_can_write) وحالة القراءة فقط
 * باقية كما هي (عقد §19).
 *
 * args صريحة: يستهلك من الـshell $author_filter و$user_id و
 * $_dash_base (روابط pagination) — بقية المتغيرات حسابات محلية
 * داخل البانل؛ يُستدعى من render_part بقائمة args الموثقة.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


$hossam_rm_decision = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'rank_math' )
    : [ 'status' => 'unavailable', 'capabilities' => [], 'reason' => 'Rank Math is unavailable.' ];
$hossam_rm_caps      = is_array( $hossam_rm_decision['capabilities'] ?? null ) ? $hossam_rm_decision['capabilities'] : [];
$hossam_rm_can_read  = in_array( $hossam_rm_decision['status'], [ 'limited', 'available' ], true )
    && ! empty( $hossam_rm_caps['plugin_contract'] );
$hossam_rm_can_write = 'available' === $hossam_rm_decision['status']
    && ! empty( $hossam_rm_caps['plugin_contract'] )
    && ! empty( $hossam_rm_caps['verified_fields'] );

if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'rank_math_seo' ) ) {
    return;
}
?>
    <!-- ══ PANEL: SEO / SEO SETTINGS ══ -->
    <?php if ( current_user_can( 'edit_posts' ) ) : ?>
    <div class="panel" id="panel-seo">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'SEO Settings' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'SEO Settings' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Improve your articles for better search engine visibility.' ) ); ?></p>
      </div>

      <?php
      // البند التمهيدى 3 (الدفعة الثانية): قرار التكامل من سجل القدرات —
      // عند غياب Rank Math: إشعار آمن وبلا بيانات زائفة؛ بقية اللوحة تعمل.
      if ( ! $hossam_rm_can_write ) : ?>
      <div class="ph-notice mb-14"><?php echo esc_html( $hossam_rm_decision['reason'] ?: hossam_t( 'SEO editing is read-only until Rank Math fields are verified.' ) ); ?></div>
      <?php endif; ?>

      <?php
      $_seo_page_raw = $_GET['seo_page'] ?? 1;
      $_seo_page     = is_scalar( $_seo_page_raw ) ? max( 1, absint( $_seo_page_raw ) ) : 1;
      $_seo_query_args = [
          'post_type'              => 'post',
          'post_status'            => [ 'publish', 'draft', 'pending', 'future' ],
          'posts_per_page'         => 50,
          'paged'                  => $_seo_page,
          'orderby'                => 'date',
          'order'                  => 'DESC',
          'no_found_rows'          => false,
          'update_post_term_cache' => false,
      ];
      if ( $author_filter ) {
          $_seo_query_args['author'] = $user_id;
      }
      $_seo_health_query = new WP_Query( $_seo_query_args );
      $_seo_missing_kw   = [];
      $_seo_distribution = [ '0-50' => 0, '51-79' => 0, '80-100' => 0 ];
      $_seo_noindex      = [];
      $_seo_visible      = 0;
      ?>

      <div class="card mb-14">
        <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'SEO Health Overview' ) ); ?></div>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th><?php echo esc_html( hossam_t( 'Title' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'SEO' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'Focus Keyword' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'SEO Meta Description' ) ); ?></th>
                <th><?php echo esc_html( hossam_t( 'Actions' ) ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ( $_seo_health_query->have_posts() ) : ?>
                <?php while ( $_seo_health_query->have_posts() ) : $_seo_health_query->the_post(); ?>
                  <?php
                    $_seo_post_id    = get_the_ID();
                    if ( ! current_user_can( 'edit_post', $_seo_post_id ) ) {
                        continue;
                    }
                    $_seo_visible++;
                    $_seo_rm_data    = $hossam_rm_can_read
                        ? hossam_get_rankmath_seo_data( $_seo_post_id )
                        : [ 'seo_score' => 0, 'focus_keyword' => '', 'description' => '', 'robots' => [] ];
                    $_seo_score      = (int) $_seo_rm_data['seo_score'];
                    $_seo_focus_kw   = (string) $_seo_rm_data['focus_keyword'];
                    $_seo_meta_desc  = (string) $_seo_rm_data['description'];
                    $_seo_robots     = $_seo_rm_data['robots'];
                    $_seo_is_noindex = is_array( $_seo_robots )
                        ? in_array( 'noindex', $_seo_robots, true )
                        : str_contains( (string) $_seo_robots, 'noindex' );
                    $_seo_rm_cls     = $_seo_score >= 80 ? 'rm-good' : ( $_seo_score >= 51 ? 'rm-avg' : ( $_seo_score > 0 ? 'rm-bad' : 'rm-none' ) );

                    if ( $hossam_rm_can_read && '' === $_seo_focus_kw ) {
                        $_seo_missing_kw[] = get_the_title();
                    }
                    if ( $hossam_rm_can_read && $_seo_score >= 80 ) {
                        $_seo_distribution['80-100']++;
                    } elseif ( $hossam_rm_can_read && $_seo_score >= 51 ) {
                        $_seo_distribution['51-79']++;
                    } elseif ( $hossam_rm_can_read ) {
                        $_seo_distribution['0-50']++;
                    }
                    if ( $_seo_is_noindex ) {
                        $_seo_noindex[] = get_the_title();
                    }
                  ?>
                  <tr
                    data-seo-id="<?php echo esc_attr( $_seo_post_id ); ?>"
                    data-seo-title="<?php echo esc_attr( get_the_title() ); ?>"
                    data-seo-score="<?php echo esc_attr( $_seo_score ); ?>"
                    data-seo-kw="<?php echo esc_attr( $_seo_focus_kw ); ?>"
                    data-seo-desc="<?php echo esc_attr( $_seo_meta_desc ); ?>"
                    data-seo-robots="<?php echo esc_attr( is_array( $_seo_robots ) ? implode( ',', array_map( 'sanitize_key', $_seo_robots ) ) : '' ); ?>"
                  >
                    <td><span class="td-title"><?php echo esc_html( get_the_title() ); ?></span></td>
                    <td><span class="rm-score <?php echo esc_attr( $_seo_rm_cls ); ?>"><?php echo $_seo_score > 0 ? esc_html( $_seo_score . '%' ) : '—'; ?></span></td>
                    <td><span class="td-muted"><?php echo esc_html( $_seo_focus_kw ?: '—' ); ?></span></td>
                    <td><span class="td-muted"><?php echo esc_html( $_seo_meta_desc ?: '—' ); ?></span></td>
                    <td>
                      <?php if ( $hossam_rm_can_write ) : ?>
                      <button type="button" class="btn btn-sm btn-ghost" onclick="hossamSeoOpen(this)"><?php echo esc_html( hossam_t( 'Edit SEO' ) ); ?></button>
                      <?php else : ?>
                      <span class="td-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endwhile; wp_reset_postdata(); ?>
                <?php if ( 0 === $_seo_visible ) : ?>
                  <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--txt-3);"><?php echo esc_html( hossam_t( 'No articles found.' ) ); ?></td></tr>
                <?php endif; ?>
              <?php else : ?>
                <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--txt-3);"><?php echo esc_html( hossam_t( 'No articles found.' ) ); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ( $_seo_health_query->max_num_pages > 1 ) : ?>
        <div class="fin-pagination mt-12">
          <?php if ( $_seo_page > 1 ) : ?>
          <a class="btn btn-sm" href="<?php echo esc_url( add_query_arg( [ 'panel' => 'seo', 'seo_page' => $_seo_page - 1 ], $_dash_base ) ); ?>"><?php echo esc_html( hossam_t( 'Previous' ) ); ?></a>
          <?php endif; ?>
          <?php if ( $_seo_page < $_seo_health_query->max_num_pages ) : ?>
          <a class="btn btn-sm" href="<?php echo esc_url( add_query_arg( [ 'panel' => 'seo', 'seo_page' => $_seo_page + 1 ], $_dash_base ) ); ?>"><?php echo esc_html( hossam_t( 'Next' ) ); ?></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ( $hossam_rm_can_read ) : ?>
      <div class="grid g2 mb-14">
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Missing Focus Keywords' ) ); ?></div>
          <div id="hossam-seo-missing-list">
          <?php if ( $_seo_missing_kw ) : ?>
            <?php foreach ( $_seo_missing_kw as $_seo_mk_title ) : ?>
            <div class="activity-item"><div class="act-dot warn"></div><div class="act-txt"><?php echo esc_html( $_seo_mk_title ); ?></div></div>
            <?php endforeach; ?>
          <?php else : ?>
            <div class="notif-empty"><?php echo esc_html( hossam_t( 'All articles have a focus keyword set.' ) ); ?></div>
          <?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Score Distribution' ) ); ?></div>
          <div class="activity-item"><div class="act-dot green"></div><div class="act-txt">80-100</div><div class="act-time" id="hossam-seo-dist-good"><?php echo esc_html( $_seo_distribution['80-100'] ); ?></div></div>
          <div class="activity-item"><div class="act-dot gold"></div><div class="act-txt">51-79</div><div class="act-time" id="hossam-seo-dist-avg"><?php echo esc_html( $_seo_distribution['51-79'] ); ?></div></div>
          <div class="activity-item"><div class="act-dot warn"></div><div class="act-txt">0-50</div><div class="act-time" id="hossam-seo-dist-low"><?php echo esc_html( $_seo_distribution['0-50'] ); ?></div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Noindex Articles' ) ); ?></div>
        <div id="hossam-seo-noindex-list">
        <?php if ( $_seo_noindex ) : ?>
          <?php foreach ( $_seo_noindex as $_seo_ni_title ) : ?>
          <div class="activity-item"><div class="act-dot warn"></div><div class="act-txt"><?php echo esc_html( $_seo_ni_title ); ?></div></div>
          <?php endforeach; ?>
        <?php else : ?>
          <div class="notif-empty"><?php echo esc_html( hossam_t( 'No noindex articles.' ) ); ?></div>
        <?php endif; ?>
        </div>
        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=rank-math')); ?>" target="_blank" class="btn btn-outline mt-12">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          <?php echo esc_html( hossam_t( 'Advanced SEO Dashboard' ) ); ?>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- محرر SEO المضمَّن — يفتح من زر Edit SEO؛ الحفظ عبر wp_ajax_hossam_save_seo فقط -->
      <?php if ( $hossam_rm_can_write ) : ?>
      <div class="card mb-14" id="hossam-seo-editor" style="display:none">
        <div class="card-title mb-12"><?php echo esc_html( hossam_t( 'Edit SEO' ) ); ?> — <span id="hossam-seo-post-label"></span></div>
        <form onsubmit="return hossamSeoSave(event)">
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-seo-kw"><?php echo esc_html( hossam_t( 'Focus Keyword' ) ); ?></label>
            <input type="text" class="form-input" id="hossam-seo-kw" maxlength="255" />
          </div>
          <div class="form-group mb-8">
            <label class="form-label" for="hossam-seo-desc"><?php echo esc_html( hossam_t( 'Meta Description' ) ); ?></label>
            <textarea class="form-input" id="hossam-seo-desc" rows="3"></textarea>
          </div>
          <div class="form-group mb-8">
            <span class="form-label"><?php echo esc_html( hossam_t( 'Robots' ) ); ?></span>
            <div class="flex gap-8" style="flex-wrap:wrap">
              <?php foreach ( function_exists( 'hossam_get_allowed_rank_math_robots' ) ? hossam_get_allowed_rank_math_robots() : [] as $_seo_rb_option ) : ?>
              <label style="display:inline-flex;align-items:center;gap:4px">
                <input type="checkbox" name="robots" value="<?php echo esc_attr( sanitize_key( $_seo_rb_option ) ); ?>" />
                <?php echo esc_html( sanitize_key( $_seo_rb_option ) ); ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-actions mt-12">
            <button type="submit" id="hossam-seo-save" class="btn btn-primary"><?php echo esc_html( hossam_t( 'Save SEO' ) ); ?></button>
            <button type="button" class="btn btn-outline" onclick="(function(b){b.style.display='none';})(document.getElementById('hossam-seo-editor'))"><?php echo esc_html( hossam_t( 'Cancel' ) ); ?></button>
          </div>
        </form>
      </div>

      <script>
      function hossamSeoOpen(btn){
        var tr=btn.closest("tr");
        var box=document.getElementById("hossam-seo-editor");
        if(!box||!tr) return;
        box.style.display="block";
        box.dataset.postId=tr.dataset.seoId||"";
        var titleEl=tr.querySelector(".td-title");
        document.getElementById("hossam-seo-post-label").textContent=titleEl?titleEl.textContent:("#"+(tr.dataset.seoId||""));
        document.getElementById("hossam-seo-kw").value=tr.dataset.seoKw||"";
        document.getElementById("hossam-seo-desc").value=tr.dataset.seoDesc||"";
        var robots=(tr.dataset.seoRobots||"").split(",").filter(Boolean);
        box.querySelectorAll("input[name=\"robots\"]").forEach(function(cb){cb.checked=robots.indexOf(cb.value)>-1;});
        try{box.scrollIntoView({behavior:"smooth",block:"center"});}catch(e){}
      }
      async function hossamSeoSave(ev){
        ev.preventDefault();
        var box=document.getElementById("hossam-seo-editor");
        if(!box||!window.hossamAjax) return false;
        var fd=new FormData();
        fd.append("action","hossam_save_seo");
        fd.append("nonce",hossamAjax.nonce);
        fd.append("post_id",box.dataset.postId||"0");
        fd.append("focus_keyword",document.getElementById("hossam-seo-kw").value);
        fd.append("description",document.getElementById("hossam-seo-desc").value);
        box.querySelectorAll("input[name=\"robots\"]:checked").forEach(function(cb){fd.append("robots[]",cb.value);});
        var btn=document.getElementById("hossam-seo-save");
        if(btn){btn.disabled=true;}
        try{
          var r=await fetch(hossamAjax.ajaxurl,{method:"POST",body:fd});
          var d=await r.json();
          if(d&&d.success&&d.data&&d.data.fields){
            showToast(t("seoSaved","SEO settings saved."),"success");
            var row=document.querySelector("tr[data-seo-id=\""+box.dataset.postId+"\"]");
            var fields=d.data.fields;
            if(row){
              row.dataset.seoKw=fields.focus_keyword||"";
              row.dataset.seoDesc=fields.description||"";
              row.dataset.seoRobots=(fields.robots&&Array.isArray(fields.robots))?fields.robots.join(","):"";
              var tds=row.querySelectorAll(".td-muted");
              if(tds[0])tds[0].textContent=row.dataset.seoKw||"—";
              if(tds[1])tds[1].textContent=row.dataset.seoDesc||"—";
            }
            hossamSeoRefreshAggregates();
            box.style.display="none";
          }else{
            showToast((d&&d.data&&d.data.message)||t("networkError","Network error"),"error");
          }
        }catch(e){showToast(t("networkError","Network error"),"error");}
        finally{if(btn){btn.disabled=false;}}
        return false;
      }
      function hossamSeoReplaceList(id,values,emptyText){
        var list=document.getElementById(id);if(!list)return;
        list.replaceChildren();
        if(!values.length){var empty=document.createElement("div");empty.className="notif-empty";empty.textContent=emptyText;list.appendChild(empty);return;}
        values.forEach(function(value){var item=document.createElement("div");item.className="activity-item";var dot=document.createElement("div");dot.className="act-dot warn";var text=document.createElement("div");text.className="act-txt";text.textContent=value;item.append(dot,text);list.appendChild(item);});
      }
      function hossamSeoRefreshAggregates(){
        var missing=[],noindex=[],dist={good:0,avg:0,low:0};
        document.querySelectorAll("tr[data-seo-id]").forEach(function(row){var score=parseInt(row.dataset.seoScore||"0",10)||0;var title=row.dataset.seoTitle||"";if(!row.dataset.seoKw)missing.push(title);if((row.dataset.seoRobots||"").split(",").indexOf("noindex")>-1)noindex.push(title);if(score>=80)dist.good++;else if(score>=51)dist.avg++;else dist.low++;});
        hossamSeoReplaceList("hossam-seo-missing-list",missing,t("allFocusKeywords","All articles have a focus keyword set."));
        hossamSeoReplaceList("hossam-seo-noindex-list",noindex,t("noNoindexArticles","No noindex articles."));
        [["hossam-seo-dist-good",dist.good],["hossam-seo-dist-avg",dist.avg],["hossam-seo-dist-low",dist.low]].forEach(function(pair){var el=document.getElementById(pair[0]);if(el)el.textContent=pair[1];});
      }
      </script>
      <?php endif; ?>
    </div><!-- /panel-seo -->
    <?php endif; ?>
