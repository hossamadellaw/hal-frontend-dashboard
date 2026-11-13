<?php
/**
 * runtime/templates/dashboard/bookings.php — Appointments panel/Amelia (batch 7)
 * ══════════════════════════════════════════════════════════════
 * Source: legacy theme/template-parts/dashboard/bookings.php (byte-level
 * source fingerprint pinned in the batch 7 inventory — snapshot 67044A2F…).
 *
 * The sole authorized delta (owner decision B3-08 — option a): the
 * $bookings_allowed condition had the owner gate is_feature_enabled('amelia')
 * added to it — the source conditions (the three capabilities), the registry
 * state and the dedicated hossam_lazy_appointments nonce remain as-is per scope.
 *
 * Explicit args: the panel relies only on user capabilities and the registry
 * (no variables from the shell) — it is called from render_part with the
 * documented args list.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bookings_allowed = ( current_user_can( 'manage_options' )
    || current_user_can( 'view_amelia_calendar_all' )
    || current_user_can( 'view_amelia_calendar' ) )
    && HAL_Frontend_Dashboard_Settings_Repository::is_feature_enabled( 'amelia' );
if ( ! $bookings_allowed ) {
    return;
}

$bookings_integration = function_exists( 'hossam_get_integration_decision' )
    ? hossam_get_integration_decision( 'amelia' )
    : [ 'status' => 'unavailable', 'capabilities' => [] ];
$bookings_ready = 'unavailable' !== $bookings_integration['status']
    && ! empty( $bookings_integration['capabilities']['bookings_shortcode'] )
    && shortcode_exists( 'ameliaemployeepanel' );
?>
    <!-- ══ PANEL: APPOINTMENTS ══ -->
    <div class="panel" id="panel-appointments">
      <div class="page-hdr">
        <div class="page-breadcrumb"><span><?php echo esc_html( hossam_t( 'Dashboard' ) ); ?></span><span>›</span><span class="crumb-active"><?php echo esc_html( hossam_t( 'My Schedule' ) ); ?></span></div>
        <h1><?php echo esc_html( hossam_t( 'My Schedule' ) ); ?></h1>
        <p><?php echo esc_html( hossam_t( 'Manage your bookings and employee calendar.' ) ); ?></p>
      </div>
      <?php if ( $bookings_ready ) : ?>
      <div class="card" id="appointments-panel-wrap"
           data-lazy-panel="appointments"
           data-lazy-nonce="<?php echo esc_attr( wp_create_nonce( 'hossam_lazy_appointments' ) ); ?>">
        <div class="ph-notice"><?php echo esc_html( hossam_t( 'Loading…' ) ); ?></div>
      </div>
      <?php else : ?>
      <div class="card"><div class="ph-notice"><?php echo esc_html( hossam_t( 'Appointment module not configured.' ) ); ?></div></div>
      <?php endif; ?>
    </div><!-- /panel-appointments -->
