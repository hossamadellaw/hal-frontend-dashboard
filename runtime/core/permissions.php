<?php
/**
 * runtime/core/permissions.php
 * ══════════════════════════════════════════════════════════════
 * Role: registering custom Capabilities for the Amelia roles.
 *
 * Source: verbatim 1:1 port from legacy mu-plugins/hossam-dashboard/core/
 * permissions.php (batch 2 of HAL Frontend Dashboard) — a port without
 * privilege expansion: no new capability and no new role is added here.
 *
 * Contract carried over as-is (summarized from the source):
 *   - Registers view_amelia_calendar / view_amelia_calendar_all via the init hook.
 *   - amelia_manager takes both capabilities together; amelia_employee takes
 *     view_amelia_calendar only.
 *   - add_cap() on the actually existing role via get_role() only — no
 *     add_role() (both roles are assumed to already exist in the live
 *     WordPress database). get_role() safely returns null if the role is
 *     not present, with no fatal.
 *   - has_cap() guard before every add_cap() — add_cap() actually performs an
 *     update_option() write every single time, and without the guard it would
 *     write to the database on every init request.
 *   - The two capabilities are consumed by adapters/amelia.php and ajax/appointments.php
 *     (outside this batch) alongside manage_options.
 * ══════════════════════════════════════════════════════════════
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function (): void {
	$manager = get_role( 'amelia_manager' );
	if ( $manager ) {
		if ( ! $manager->has_cap( 'view_amelia_calendar' ) ) {
			$manager->add_cap( 'view_amelia_calendar' );
		}
		if ( ! $manager->has_cap( 'view_amelia_calendar_all' ) ) {
			$manager->add_cap( 'view_amelia_calendar_all' );
		}
	}

	$employee = get_role( 'amelia_employee' );
	if ( $employee && ! $employee->has_cap( 'view_amelia_calendar' ) ) {
		$employee->add_cap( 'view_amelia_calendar' );
	}
} );
