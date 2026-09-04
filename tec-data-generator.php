<?php
/**
 * Plugin Name: TEC Data Generator
 * Description: Generates bulk test data (Events, Pages, Posts, Venues, Organizers, tickets, attendees) for stress-testing Event Tickets.
 * Version: 1.1.0
 * Author: Victor Larodiel
 * Requires Plugins: event-tickets, the-events-calendar
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TEC_DATA_GENERATOR_FILE', __FILE__ );
define( 'TEC_DATA_GENERATOR_VERSION', '1.1.0' );

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

( new \TEC\DataGenerator\Admin() )->hook();
( new \TEC\DataGenerator\Ajax() )->hook();
// Registered on every request (not just wp-admin) so Action Scheduler's own async/cron
// requests can find this callback when they process a scheduled scenario chunk.
( new \TEC\DataGenerator\Scenario_Job() )->hook();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';

	WP_CLI::add_command( 'tec-data-generator', \TEC\DataGenerator\CLI::class );
}
