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

	public static function has_events_pro_or_ecp(): bool {
		return self::has_events_pro() || self::has_ecp();
	}
}
