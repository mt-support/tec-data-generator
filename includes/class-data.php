<?php
/**
 * Marker meta constants and built-in fake-data helpers (no Faker dependency).
 */

namespace TEC\DataGenerator;

class Data {

	const GENERATED_META_KEY = '_tec_data_generator_generated';
	const RUN_ID_META_KEY    = '_tec_data_generator_run_id';
	const POST_KIND_META_KEY = '_tec_data_generator_post_kind';
	const EVENT_TYPE_META_KEY = '_tec_data_generator_event_type';
	const EDITOR_META_KEY = '_tec_data_generator_editor';

	const FIRST_NAMES = [
		'James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda', 'William', 'Elizabeth',
		'David', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen',
		'Christopher', 'Nancy', 'Daniel', 'Lisa', 'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra',
		'Donald', 'Ashley', 'Steven', 'Kimberly', 'Andrew', 'Emily', 'Paul', 'Donna', 'Joshua', 'Michelle',
	];

	const LAST_NAMES = [
		'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
		'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
		'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
	];

	const EMAIL_DOMAINS = [ 'example.com', 'example.org', 'example.net', 'loadgen.test' ];

	const EVENT_TITLE_VERBS = [ 'Discussing', 'Talking', 'Dissecting', 'Analyzing', 'Exploring', 'Unpacking' ];

	const EVENT_TITLE_TOPICS = [
		'Modern Web Design', 'Growth Strategies', 'Remote Team Culture', 'Sustainable Business',
		'Creative Leadership', 'Data-Driven Marketing', 'Community Building', 'Product Innovation',
	];

	const ORGANIZER_COMPANY_SUFFIXES = [ '& Co', 'Productions', 'Events Group', 'Presents', 'Live' ];

	const VENUE_NAME_SUFFIXES = [ 'Hall', 'Room', 'Cafe', 'Arena', 'Theater' ];

	/**
	 * Fixed, real city/street combinations (not randomly generated) — ported directly from the
	 * source repo, since this data was already hardcoded there, not Faker output.
	 */
	const VENUE_ADDRESSES = [
		[ 'address' => '155 Broadway', 'city' => 'New York City', 'state' => 'New York', 'country' => 'United States' ],
		[ 'address' => '200 W Madison St', 'city' => 'Chicago', 'state' => 'Illinois', 'country' => 'United States' ],
		[ 'address' => '1750 Sunset Blvd', 'city' => 'Los Angeles', 'state' => 'California', 'country' => 'United States' ],
		[ 'address' => '9000 Wilshire Blvd', 'city' => 'Beverly Hills', 'state' => 'California', 'country' => 'United States' ],
		[ 'address' => '1500 Lombard St', 'city' => 'San Francisco', 'state' => 'California', 'country' => 'United States' ],
	];

	/**
	 * Pre-defined `scenario` presets. Each range is resolved to a concrete value via `wp_rand()`
	 * at generation time (units, orphan rate), or varied per-unit/per-ticket the same way
	 * `generate`'s own min/max options already are (rsvps_min/max, and --min/max-attendees).
	 */
	const SCENARIOS = [
		'usual' => [
			'units_min'  => 25,
			'units_max'  => 250,
			'rsvps_min'  => 1,
			'rsvps_max'  => 3,
			'orphan_min' => 5,
			'orphan_max' => 20,
		],
		'edge'  => [
			'units_min'  => 7000,
			'units_max'  => 11000,
			'rsvps_min'  => 1,
			'rsvps_max'  => 9,
			'orphan_min' => 5,
			'orphan_max' => 20,
		],
	];

	/**
	 * Returns a random [ full_name, email ] pair. Emails are always unique for a given
	 * (unique_id, sub_seq) combination via a "+tag" suffix, even under concurrent AJAX chunks.
	 *
	 * @param int $unique_id A value unique per RSVP ticket (its ticket post ID) — NOT the unit's
	 *                        sequence number, since one unit can now have multiple tickets.
	 * @param int $sub_seq   Attendee index within that ticket.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function random_person( int $unique_id, int $sub_seq = 0 ): array {
		$first = self::FIRST_NAMES[ array_rand( self::FIRST_NAMES ) ];
		$last  = self::LAST_NAMES[ array_rand( self::LAST_NAMES ) ];
		$full  = "{$first} {$last}";

		$email = sprintf(
			'%s.%s+%d_%d@%s',
			strtolower( $first ),
			strtolower( $last ),
			$unique_id,
			$sub_seq,
			self::EMAIL_DOMAINS[ array_rand( self::EMAIL_DOMAINS ) ]
		);

		return [ $full, $email ];
	}

	/**
	 * Returns a random US-style phone number. Not meant to be dialable — purely for QA display.
	 */
	public static function random_phone(): string {
		return sprintf( '(%03d) 555-%04d', wp_rand( 200, 999 ), wp_rand( 0, 9999 ) );
	}

	/**
	 * Returns a varied, realistic-looking event title (no numeric suffix — callers append their
	 * own sequence number for traceability).
	 */
	public static function random_event_title(): string {
		$verb  = self::EVENT_TITLE_VERBS[ array_rand( self::EVENT_TITLE_VERBS ) ];
		$topic = self::EVENT_TITLE_TOPICS[ array_rand( self::EVENT_TITLE_TOPICS ) ];

		return "{$verb} {$topic}";
	}

	/**
	 * Returns a short HTML event description, optionally mentioning the attached organizer/venue.
	 */
	public static function random_event_description( string $organizer_name = '', string $venue_name = '', string $venue_city = '' ): string {
		$organizer_name = $organizer_name ?: 'a Premium Organizer';
		$venue_name     = $venue_name ?: 'The Venue';
		$venue_city     = $venue_city ?: 'your city';

		return sprintf(
			'<p>%1$s hosts an event by %2$s coming to %3$s! Join us for an unforgettable experience with networking, discussion, and community.</p>',
			$venue_name,
			$organizer_name,
			$venue_city
		);
	}

	/**
	 * @return array{name:string,phone:string,website:string,email:string,bio:string}
	 */
	public static function random_organizer(): array {
		$first  = self::FIRST_NAMES[ array_rand( self::FIRST_NAMES ) ];
		$last   = self::LAST_NAMES[ array_rand( self::LAST_NAMES ) ];
		$suffix = self::ORGANIZER_COMPANY_SUFFIXES[ array_rand( self::ORGANIZER_COMPANY_SUFFIXES ) ];
		$name   = "{$last} {$suffix}";
		$host   = strtolower( str_replace( [ ' ', '&' ], [ '-', 'and' ], $name ) ) . '-qa.loadgen.test';

		return [
			'name'    => $name,
			'phone'   => self::random_phone(),
			'website' => "https://{$host}",
			'email'   => strtolower( $first ) . "@{$host}",
			'bio'     => "{$first} {$last} and the team at {$name} craft memorable event experiences for the community.",
		];
	}

	/**
	 * @return array{name:string,address:string,city:string,state:string,country:string,phone:string,website:string,description:string}
	 */
	public static function random_venue(): array {
		$suffix  = self::VENUE_NAME_SUFFIXES[ array_rand( self::VENUE_NAME_SUFFIXES ) ];
		$last    = self::LAST_NAMES[ array_rand( self::LAST_NAMES ) ];
		$name    = "The {$last} {$suffix}";
		$address = self::VENUE_ADDRESSES[ array_rand( self::VENUE_ADDRESSES ) ];
		$host    = strtolower( str_replace( ' ', '-', $name ) ) . '-qa.loadgen.test';

		return [
			'name'        => $name,
			'address'     => $address['address'],
			'city'        => $address['city'],
			'state'       => $address['state'],
			'country'     => $address['country'],
			'phone'       => self::random_phone(),
			'website'     => "https://{$host}",
			'description' => "{$name} is a multi-purpose space in {$address['city']}, {$address['state']}, hosting events of every size.",
		];
	}

	/**
	 * Generates a new run ID, used to tag a batch of generated data and to scope cleanup.
	 *
	 * @return string
	 */
	public static function new_run_id(): string {
		return 'run_' . gmdate( 'Ymd_His' ) . '_' . substr( wp_generate_password( 12, false, false ), 0, 6 );
	}
}
