<?php
/**
 * Runtime detection of optional dependency plugins (Events Pro, ECP, Event Tickets Plus).
 *
 * No new dependencies — only class_exists()/function_exists()/defined() checks, so this runs
 * on a bare QA site with just Event Tickets + The Events Calendar active.
 */

namespace TEC\DataGenerator;

class Plugin_Availability {

	const ALLOWED_EVENT_TYPES  = [ 'single', 'recurring', 'virtual' ];
	const ALLOWED_TICKET_TYPES = [ 'rsvp', 'paid', 'none' ];

	const STATUS_DEFINITIONS = [
		[
			'name' => 'The Events Calendar',
			'file' => 'the-events-calendar/the-events-calendar.php',
		],
		[
			'name' => 'Event Tickets',
			'file' => 'event-tickets/event-tickets.php',
		],
		[
			'name' => 'Events Calendar Pro',
			'file' => 'events-pro/events-calendar-pro.php',
		],
		[
			'name' => 'Event Tickets Plus',
			'file' => 'event-tickets-plus/event-tickets-plus.php',
		],
	];

	public static function has_event_tickets(): bool {
		return class_exists( 'Tribe__Tickets__RSVP' ) || class_exists( 'Tribe__Tickets__Main' );
	}

	public static function has_events_pro(): bool {
		return class_exists( 'Tribe__Events__Pro__Main' )
			|| function_exists( 'tribe_events_pro_register_provider' )
			|| defined( 'EVENTS_CALENDAR_PRO_VERSION' );
	}

	public static function has_ecp(): bool {
		return class_exists( 'TEC\Events_Pro\Plugin' )
			|| function_exists( 'tec_events_pro_register_provider' )
			|| function_exists( 'tec_pro_register_provider' )
			|| self::has_events_pro();
	}

	public static function has_event_tickets_plus(): bool {
		return class_exists( 'Tribe__Tickets__Plus__Main' );
	}

	/**
	 * True when the site's RSVP feature is already running in V2 (Tickets Commerce-backed) mode,
	 * i.e. the `rsvp-to-tc` migration has completed.
	 *
	 * This tool always writes the legacy V1 shape (see Generator::with_v1_rsvp_repositories()),
	 * and Event Tickets' admin reads whichever repository is bound — so on a V2 site the data it
	 * generates is real and migratable but invisible in the Tickets/Attendees UI until the site is
	 * reverted. Callers use this to warn instead of leaving QA staring at an empty attendee list.
	 */
	public static function has_rsvp_v2(): bool {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\\Tribe__Tickets__Repositories__Ticket__RSVP' ) ) {
			return false;
		}

		try {
			return ! ( tribe( 'tickets.ticket-repository.rsvp' ) instanceof \Tribe__Tickets__Repositories__Ticket__RSVP );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public static function has_events_pro_or_ecp(): bool {
		return self::has_events_pro() || self::has_ecp();
	}

	/**
	 * Name, status, and version for the plugins shown in the admin page's status table.
	 *
	 * @return array<int,array{name:string,status:string,status_label:string,version:string}>
	 */
	public static function get_statuses(): array {
		if ( ! function_exists( 'get_plugin_data' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$statuses = [];

		foreach ( self::STATUS_DEFINITIONS as $definition ) {
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $definition['file'] ) ) {
				$statuses[] = [
					'name'         => $definition['name'],
					'status'       => 'not-installed',
					'status_label' => __( 'Not installed', 'tec-data-generator' ),
					'version'      => '',
				];

				continue;
			}

			$data    = function_exists( 'get_plugin_data' )
				? get_plugin_data( WP_PLUGIN_DIR . '/' . $definition['file'], false, false )
				: [];
			$active  = function_exists( 'is_plugin_active' )
				? is_plugin_active( $definition['file'] )
				: in_array( $definition['file'], (array) get_option( 'active_plugins', [] ), true );

			$statuses[] = [
				'name'         => $definition['name'],
				'status'       => $active ? 'active' : 'inactive',
				'status_label' => $active
					? __( 'Active', 'tec-data-generator' )
					: __( 'Inactive', 'tec-data-generator' ),
				'version'      => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			];
		}

		return $statuses;
	}
}
