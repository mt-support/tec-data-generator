<?php
/**
 * Plugin Name: RSVP Migration Load Generator
 * Description: Generates bulk legacy V1 RSVP test data (events/pages/posts + RSVP tickets + attendees) to stress-test the rsvp-to-tc migration. QA tool only - not for production use.
 * Version: 1.0.0
 * Author: Victor Larodiel
 * Requires Plugins: event-tickets, the-events-calendar
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSVP_LOADGEN_FILE', __FILE__ );
define( 'RSVP_LOADGEN_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/class-data.php';
require_once __DIR__ . '/includes/class-generator.php';
require_once __DIR__ . '/includes/class-cleanup.php';
require_once __DIR__ . '/includes/class-migration.php';
require_once __DIR__ . '/includes/class-scenario-job.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-ajax.php';

// Ensure tickets can attach to Event, Page, and Post content (default is Event + Page only).
// Non-destructive: only filters the runtime value, doesn't rewrite the stored site option.
add_filter( 'tribe_tickets_post_types', function ( $post_types ) {
	return array_unique( array_merge( (array) $post_types, [ 'tribe_events', 'page', 'post' ] ) );
} );

( new \RSVP_Loadgen\Admin() )->hook();
( new \RSVP_Loadgen\Ajax() )->hook();
// Registered on every request (not just wp-admin) so Action Scheduler's own async/cron
// requests can find this callback when they process a scheduled scenario chunk.
( new \RSVP_Loadgen\Scenario_Job() )->hook();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';

	WP_CLI::add_command( 'rsvp-loadgen', \RSVP_Loadgen\CLI::class );
}
