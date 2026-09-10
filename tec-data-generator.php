<?php
/**
 * Plugin Name: TEC Data Generator
 * Description: Generates bulk test data (Events, Pages, Posts, Venues, Organizers, tickets, attendees) for stress-testing Event Tickets.
 * Version: 1.2.0
 * Author: Victor Larodiel
 * Requires Plugins: event-tickets, the-events-calendar
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TEC_DATA_GENERATOR_FILE', __FILE__ );
define( 'TEC_DATA_GENERATOR_VERSION', '1.2.0' );

require_once __DIR__ . '/includes/class-data.php';
require_once __DIR__ . '/includes/class-plugin-availability.php';
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

// Guard: Event Tickets REST endpoint may try to access undefined 'link' field
// when serializing generated posts. Add a filter to ensure it's always set.
add_filter( 'rest_prepare_tribe_events', function ( $response, $post ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = $response->get_data();
	if ( is_array( $data ) && ! isset( $data['link'] ) ) {
		$data['link'] = get_permalink( $post ) ?: '';
		$response->set_data( $data );
	}
	return $response;
}, 10, 2 );

add_filter( 'rest_prepare_page', function ( $response, $post ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = $response->get_data();
	if ( is_array( $data ) && ! isset( $data['link'] ) ) {
		$data['link'] = get_permalink( $post ) ?: '';
		$response->set_data( $data );
	}
	return $response;
}, 10, 2 );

add_filter( 'rest_prepare_post', function ( $response, $post ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = $response->get_data();
	if ( is_array( $data ) && ! isset( $data['link'] ) ) {
		$data['link'] = get_permalink( $post ) ?: '';
		$response->set_data( $data );
	}
	return $response;
}, 10, 2 );

// On RSVP V2 sites, show V1 generated tickets in the WordPress admin alongside V2 tickets.
// The issue: RSVP V2 uses Tickets Commerce backend, so the repository queries for tec_tc_ticket posts with _type='tc-rsvp'.
// But we create tribe_rsvp_tickets (V1 post type) via with_v1_rsvp_repositories(), so they're invisible to the admin.
// Solution: Hook the REST API response to include V1 generated tickets in the ticket list.
add_filter( 'rest_prepare_tribe_rsvp_tickets', function ( $response, $post ) {
	// This isn't the right place either - we need to filter list requests, not individual posts.
	return $response;
}, 10, 2 );

// Simpler: hook post_class so V1 generated tickets at least show in list tables if they appear there.
add_filter( 'tribe_tickets_has_tickets_for_event', function ( $has_tickets, $post_id ) {
	if ( $has_tickets ) {
		return $has_tickets;
	}

	// Check if there are V1 generated RSVP tickets (may not be visible in the V2 repository query).
	$v1_ticket_count = (int) $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare(
			"SELECT COUNT(*) FROM $GLOBALS[wpdb]->posts p
			 JOIN $GLOBALS[wpdb]->postmeta pm ON p.ID = pm.post_id
			 WHERE p.post_type = 'tribe_rsvp_tickets'
			 AND p.post_status = 'publish'
			 AND pm.meta_key = '_tribe_rsvp_for_event'
			 AND pm.meta_value = %d
			 AND p.ID IN (
			   SELECT post_id FROM $GLOBALS[wpdb]->postmeta WHERE meta_key = '_tec_data_generator_generated'
			 )",
			(int) $post_id
		)
	);

	return $v1_ticket_count > 0;
}, 10, 2 );

( new \TEC\DataGenerator\Admin() )->hook();
( new \TEC\DataGenerator\Ajax() )->hook();
// Registered on every request (not just wp-admin) so Action Scheduler's own async/cron
// requests can find this callback when they process a scheduled scenario chunk.
( new \TEC\DataGenerator\Scenario_Job() )->hook();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';

	WP_CLI::add_command( 'tec-data-generator', \TEC\DataGenerator\CLI::class );
}
