<?php
/**
 * runtime/templates/dashboard/members.php — Members panel (batch 8)
 * ══════════════════════════════════════════════════════════════
 * Source: legacy theme/template-parts/dashboard/members.php (byte-level
 * source fingerprint pinned in the batch 8 inventory — snapshot 5CD55D5D…).
 *
 * The sole authorized delta (owner decision B3-08 — option a; contract
 * §19 row members.php «double manage_options guard»): two internal guards
 * after the ABSPATH guard — current_user_can('manage_options') (the real
 * capability, not the passed boolean) then the owner gate
 * is_feature_enabled('members') — each with an early return; the source
 * condition if ( $is_admin ) stays in the body as-is (the shell layer),
 * so the double guard is completed. The Roles table from
 * $role_display_map and the two tabs remain as they are.
 *
 * Explicit args: it consumes $is_admin (the source condition) and
 * $role_display_map (the Roles table) from the shell — it is called from
 * render_part with the documented args list.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
if ( ! HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'members' ) ) {
	return;
}
?>
    <!-- ══ PANEL: MEMBERS (Admin Only) ══ -->
    <?php if ( $is_admin ) : ?>
    <div class="panel" id="panel-members">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'Members' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'Members' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Manage staff accounts and roles.' ) ); ?></p>
      </div>

      <div class="tab-group" data-tab-group="members">
        <div class="tab-bar mb-14">
          <button class="tab-btn active" data-tab="all" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'All Members' ) ); ?></button>
          <button class="tab-btn" data-tab="roles" onclick="switchTab(this)"><?php echo esc_html( hossam_t( 'Roles' ) ); ?></button>
        </div>

        <div class="tab-content active" data-tab-content="all">
          <div class="card">
            <div id="members-table-wrap">
              <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
            </div>
          </div>
        </div>

        <div class="tab-content" data-tab-content="roles" style="display:none">
          <div class="card">
            <table class="role-matrix">
              <tbody>
                <?php foreach ( $role_display_map as $role_slug => $role_label ) : ?>
                <tr><td><?php echo esc_html( $role_slug ); ?></td><td><?php echo esc_html( $role_label ); ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- /panel-members -->
    <?php endif; ?>
