<?php
/**
 * Creates legacy V1 RSVP tickets (with attendees, grouped into orders) on freshly
 * created Event/Page/Post content, using the same production code paths real
 * user-created data goes through.
 */

namespace RSVP_Loadgen;

class Generator {

	const PAID_TICKET_TYPES = [ 'Standard', 'General', 'Basic', 'Student', 'Early Bird', 'VIP', 'Platinum' ];

	/**
	 * @var string
	 */
	private $run_id;

	/**
	 * @var array
	 */
	private $options = [];

	/**
	 * @var int[]
	 */
	private $venue_pool = [];

	/**
	 * @var int[]
	 */
	private $organizer_pool = [];

	public function __construct( string $run_id ) {
		$this->run_id = $run_id;
	}

	/**
	 * Generates $count post+ticket+attendees units, starting at global sequence $offset.
	 *
	 * @param int   $count   How many units to generate in this call.
	 * @param int   $offset  Global sequence offset (keeps the post-type round robin and
	 *                       name/email uniqueness stable across multiple chunked calls).
	 * @param array $options {
	 *     @type int $min_attendees      Minimum attendees per ticket. Default 1.
	 *     @type int $max_attendees      Maximum attendees per ticket. Default 20.
	 *     @type int $min_rsvps_per_unit Minimum RSVP tickets per post. Default 1.
	 *     @type int $max_rsvps_per_unit Maximum RSVP tickets per post. Default 1.
	 *     @type bool $with_venues        Attach a random generated Venue to each Event unit. Default false.
	 *     @type bool $with_organizers    Attach a random generated Organizer to each Event unit. Default false.
	 * }
	 *
	 * @return array{created:int,post_ids:int[],ticket_ids:int[],attendee_ids:int[]}
	 */
	public function generate_batch( int $count, int $offset, array $options = [] ): array {
		$this->options = $options;

		return $this->run_with_performance_guards( function () use ( $count, $offset ) {
			$post_ids     = [];
			$ticket_ids   = [];
			$attendee_ids = [];

			for ( $i = 0; $i < $count; $i++ ) {
				$seq       = $offset + $i;
				$post_type = $this->post_type_for_sequence( $seq );

				$post_id = 'tribe_events' === $post_type
					? $this->create_event_post( $seq, $this->pick_venue(), $this->pick_organizer() )
					: $this->create_page_or_post( $post_type, $seq );

				if ( ! $post_id ) {
					continue;
				}
				$post_ids[] = $post_id;

				$min_rsvps  = max( 1, (int) ( $this->options['min_rsvps_per_unit'] ?? 1 ) );
				$max_rsvps  = max( $min_rsvps, (int) ( $this->options['max_rsvps_per_unit'] ?? 1 ) );
				$rsvp_count = wp_rand( $min_rsvps, $max_rsvps );

				for ( $r = 0; $r < $rsvp_count; $r++ ) {
					$ticket_id = $this->create_rsvp_ticket( $post_id, $seq, $r );

					if ( ! $ticket_id ) {
						continue;
					}
					$ticket_ids[] = $ticket_id;

					$attendee_ids = array_merge(
						$attendee_ids,
						$this->create_attendees_for_ticket( $ticket_id, $post_id )
					);
				}
			}

			return [
				'created'      => count( $post_ids ),
				'post_ids'     => $post_ids,
				'ticket_ids'   => $ticket_ids,
				'attendee_ids' => $attendee_ids,
			];
		} );
	}

	/**
	 * Adds RSVP tickets to an existing event, outside the normal generate_batch() unit flow —
	 * backs the standalone `add-rsvp` CLI/AJAX command.
	 *
	 * @param array{capacity?:int,stock?:int,unlimited_capacity?:bool} $options
	 *
	 * @return int[] Created ticket IDs.
	 */
	public function add_rsvp_tickets( int $event_id, int $quantity, array $options = [] ): array {
		$ticket_ids = [];

		for ( $i = 0; $i < $quantity; $i++ ) {
			$ticket_id = $this->create_adhoc_rsvp_ticket( $event_id, $options );

			if ( $ticket_id ) {
				$ticket_ids[] = $ticket_id;
			}
		}

		return $ticket_ids;
	}

	private function create_adhoc_rsvp_ticket( int $event_id, array $options ): int {
		$unlimited = ! empty( $options['unlimited_capacity'] );
		$capacity  = $unlimited ? '' : ( $options['capacity'] ?? wp_rand( 20, 200 ) );
		$stock     = $unlimited ? '' : ( $options['stock'] ?? $capacity );

		$data = [
			'ticket_name'             => 'RSVP ' . uniqid(),
			'ticket_description'      => 'Generated RSVP ticket added to existing event.',
			'ticket_show_description' => 1,
			'ticket_price'            => 0,
			'tribe-ticket'            => [
				'mode'     => \Tribe__Tickets__Global_Stock::OWN_STOCK_MODE,
				'capacity' => $capacity,
				'stock'    => $stock,
			],
		];

		$ticket_id = tribe( 'tickets.rsvp' )->ticket_add( $event_id, $data );

		if ( ! $ticket_id ) {
			return 0;
		}

		$this->tag_generated( (int) $ticket_id, 'ticket' );

		return (int) $ticket_id;
	}

	/**
	 * Adds paid tickets (via whatever provider the event already uses — Tickets Commerce, PayPal,
	 * etc.) to an existing event. This is the one deliberate exception to this plugin's "V1 RSVP
	 * shape only" default: it exists solely for this standalone command, `generate`/`scenario`
	 * never call it.
	 *
	 * @param array{capacity?:int,stock?:int,unlimited_capacity?:bool,shared_capacity?:bool} $options
	 *
	 * @return int[] Created ticket IDs. Empty if the event's ticket provider is RSVP.
	 */
	public function add_paid_tickets( int $event_id, int $quantity, array $options = [] ): array {
		$provider = \Tribe__Tickets__Tickets::get_event_ticket_provider( $event_id );

		if ( is_string( $provider ) ) {
			$provider = new $provider();
		}

		if ( ! $provider || \Tribe__Tickets__RSVP::class === get_class( $provider ) ) {
			return [];
		}

		$ticket_ids = [];

		for ( $i = 0; $i < $quantity; $i++ ) {
			$ticket_id = $this->create_paid_ticket( $provider, $event_id, $options );

			if ( $ticket_id ) {
				$ticket_ids[] = $ticket_id;
			}
		}

		return $ticket_ids;
	}

	private function create_paid_ticket( $provider, int $event_id, array $options ): int {
		$type  = self::PAID_TICKET_TYPES[ array_rand( self::PAID_TICKET_TYPES ) ];
		$price = wp_rand( 10, 150 );

		$unlimited = ! empty( $options['unlimited_capacity'] );
		$capacity  = $unlimited ? '' : ( $options['capacity'] ?? wp_rand( 20, 200 ) );
		$stock     = $unlimited ? '' : ( $options['stock'] ?? $capacity );

		$stock_data = [
			'capacity' => $capacity,
			'stock'    => $stock,
		];

		if ( ! empty( $options['shared_capacity'] ) ) {
			$stock_data['mode'] = \Tribe__Tickets__Global_Stock::GLOBAL_STOCK_MODE;
		}

		$data = [
			'ticket_name'             => "{$type} Ticket",
			'ticket_price'            => $price,
			'ticket_description'      => "Generated {$type} ticket added to existing event.",
			'ticket_show_description' => 1,
			'tribe-ticket'            => $stock_data,
		];

		$ticket_id = $provider->ticket_add( $event_id, $data );

		if ( ! $ticket_id ) {
			return 0;
		}

		$this->tag_generated( (int) $ticket_id, 'paid_ticket' );

		return (int) $ticket_id;
	}

	/**
	 * Adds attendees to an existing ticket (RSVP or paid) — backs the standalone `add-attendees`
	 * CLI/AJAX command. Branches by ticket post type because `Tribe__Tickets__RSVP` does not
	 * override the base `create_attendee()` method: the generic `tribe( 'tickets.attendees' )`
	 * dispatcher would route an RSVP ticket through a different, ORM-based code path than
	 * `Tribe__Tickets__RSVP::create_attendee_for_ticket()` — calling the RSVP method directly is
	 * the only way to guarantee the V1 meta shape this plugin depends on.
	 *
	 * @return int[] Created attendee IDs.
	 */
	public function add_attendees( int $ticket_id, int $quantity ): array {
		$attendee_ids = [];
		$ticket_post  = get_post( $ticket_id );

		if ( ! $ticket_post ) {
			return [];
		}

		$is_rsvp = 'tribe_rsvp_tickets' === $ticket_post->post_type;

		for ( $i = 0; $i < $quantity; $i++ ) {
			[ $full_name, $email ] = Data::random_person( $ticket_id, $i );
			$data = [
				'full_name' => $full_name,
				'email'     => $email,
			];

			$attendee_id = 0;

			try {
				if ( $is_rsvp ) {
					$attendee_id = (int) tribe( 'tickets.rsvp' )->create_attendee_for_ticket( $ticket_post, $data );
				} else {
					$attendee = tribe( 'tickets.attendees' )->create_attendee( $ticket_id, $data );
					$attendee_id = $attendee instanceof \WP_Post ? $attendee->ID : 0;
				}
			} catch ( \Exception $e ) {
				continue;
			}

			if ( ! $attendee_id ) {
				continue;
			}

			$this->tag_generated( $attendee_id, 'attendee' );
			$attendee_ids[] = $attendee_id;
		}

		return $attendee_ids;
	}

	/**
	 * Round-robins post types by global sequence, guaranteeing an even 3-way split
	 * regardless of how many chunked calls it takes to reach the total count.
	 */
	private function post_type_for_sequence( int $seq ): string {
		static $types = [ 'tribe_events', 'page', 'post' ];

		return $types[ $seq % 3 ];
	}

	/**
	 * Creates a `tribe_events` post via the-events-calendar's own repository ORM, so it has valid
	 * start/end dates and behaves like a real event on the front end.
	 */
	private function create_event_post( int $seq, int $venue_id = 0, int $organizer_id = 0 ): int {
		if ( ! function_exists( 'tribe_events' ) ) {
			return 0;
		}

		$start = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( "+{$seq} hours" );
		$end   = $start->modify( '+2 hours' );

		$organizer_name = $organizer_id ? get_the_title( $organizer_id ) : '';
		$venue_name     = $venue_id ? get_the_title( $venue_id ) : '';
		$venue_city     = $venue_id ? (string) get_post_meta( $venue_id, '_VenueCity', true ) : '';

		$args = [
			'title'      => Data::random_event_title() . " (#{$seq})",
			'status'     => 'publish',
			'start_date' => $start->format( 'Y-m-d H:i:s' ),
			'end_date'   => $end->format( 'Y-m-d H:i:s' ),
			'content'    => Data::random_event_description( $organizer_name, $venue_name, $venue_city ),
		];

		if ( $venue_id ) {
			$args['venue'] = $venue_id;
		}

		if ( $organizer_id ) {
			$args['organizer'] = $organizer_id;
		}

		$event = tribe_events()->set_args( $args )->create();

		$post_id = $event instanceof \WP_Post ? $event->ID : 0;

		if ( $post_id ) {
			$this->tag_generated( $post_id, 'event' );
		}

		return $post_id;
	}

	/**
	 * Creates a plain `page` or `post` container post.
	 */
	private function create_page_or_post( string $post_type, int $seq ): int {
		$post_id = wp_insert_post( [
			'post_type'    => $post_type,
			'post_title'   => sprintf( 'Loadgen %s %d', ucfirst( $post_type ), $seq ),
			'post_status'  => 'publish',
			'post_content' => 'Generated by RSVP Migration Load Generator for migration stress testing.',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$this->tag_generated( $post_id, $post_type );

		return (int) $post_id;
	}

	/**
	 * Creates a `tribe_venues` post via The Events Calendar's own repository ORM.
	 */
	private function create_venue( int $seq ): int {
		if ( ! function_exists( 'tribe_venues' ) ) {
			return 0;
		}

		$venue = Data::random_venue();

		$post = tribe_venues()->set_args( [
			'venue'        => $venue['name'],
			'address'      => $venue['address'],
			'city'         => $venue['city'],
			'state'        => $venue['state'],
			'country'      => $venue['country'],
			'phone'        => $venue['phone'],
			'website'      => $venue['website'],
			'post_content' => $venue['description'],
			'post_status'  => 'publish',
		] )->create();

		$post_id = $post instanceof \WP_Post ? $post->ID : 0;

		if ( $post_id ) {
			$this->tag_generated( $post_id, 'venue' );
		}

		return $post_id;
	}

	/**
	 * Creates a `tribe_organizer` post via The Events Calendar's own repository ORM.
	 */
	private function create_organizer( int $seq ): int {
		if ( ! function_exists( 'tribe_organizers' ) ) {
			return 0;
		}

		$organizer = Data::random_organizer();

		$post = tribe_organizers()->set_args( [
			'organizer'    => $organizer['name'],
			'phone'        => $organizer['phone'],
			'website'      => $organizer['website'],
			'email'        => $organizer['email'],
			'post_content' => $organizer['bio'],
			'post_status'  => 'publish',
		] )->create();

		$post_id = $post instanceof \WP_Post ? $post->ID : 0;

		if ( $post_id ) {
			$this->tag_generated( $post_id, 'organizer' );
		}

		return $post_id;
	}

	/**
	 * Lazily creates a small pool of Venues (once per run — reusing any this run already created
	 * on an earlier chunk, since the caller may construct a fresh Generator instance per chunk) and
	 * returns a random one from it, or 0 if `with_venues` wasn't requested for this run.
	 */
	private function pick_venue(): int {
		if ( empty( $this->options['with_venues'] ) ) {
			return 0;
		}

		if ( empty( $this->venue_pool ) ) {
			$this->venue_pool = $this->find_run_posts( 'tribe_venue' );
		}

		if ( empty( $this->venue_pool ) ) {
			for ( $i = 0; $i < 5; $i++ ) {
				$id = $this->create_venue( $i );

				if ( $id ) {
					$this->venue_pool[] = $id;
				}
			}
		}

		return $this->venue_pool ? $this->venue_pool[ array_rand( $this->venue_pool ) ] : 0;
	}

	/**
	 * Lazily creates a small pool of Organizers (once per run — reusing any this run already
	 * created on an earlier chunk) and returns a random one from it, or 0 if `with_organizers`
	 * wasn't requested for this run.
	 */
	private function pick_organizer(): int {
		if ( empty( $this->options['with_organizers'] ) ) {
			return 0;
		}

		if ( empty( $this->organizer_pool ) ) {
			$this->organizer_pool = $this->find_run_posts( 'tribe_organizer' );
		}

		if ( empty( $this->organizer_pool ) ) {
			for ( $i = 0; $i < 5; $i++ ) {
				$id = $this->create_organizer( $i );

				if ( $id ) {
					$this->organizer_pool[] = $id;
				}
			}
		}

		return $this->organizer_pool ? $this->organizer_pool[ array_rand( $this->organizer_pool ) ] : 0;
	}

	/**
	 * Finds posts of the given type already tagged with this Generator instance's run ID —
	 * used to let a pool (venues/organizers) persist across multiple Generator instances that
	 * share the same run, e.g. one per chunked AJAX/background-job request.
	 *
	 * @return int[]
	 */
	private function find_run_posts( string $post_type ): array {
		return get_posts( [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_query'       => [
				[
					'key'   => Data::RUN_ID_META_KEY,
					'value' => $this->run_id,
				],
			],
			'no_found_rows'    => true,
			'suppress_filters' => true,
		] );
	}

	/**
	 * Creates a legacy V1 RSVP ticket via the real production code path
	 * (`Tribe__Tickets__Tickets::ticket_add()` → `Tribe__Tickets__RSVP::save_ticket()`),
	 * so generated tickets are indistinguishable from real user-created ones.
	 *
	 * @param int $index RSVP index within this unit (0 for the first/only ticket on the post),
	 *                    so multiple tickets on one event get distinguishable titles.
	 */
	private function create_rsvp_ticket( int $post_id, int $seq, int $index = 0 ): int {
		/** @var \Tribe__Tickets__RSVP $rsvp */
		$rsvp = tribe( 'tickets.rsvp' );

		$capacity = wp_rand( 20, 200 );

		$data = [
			'ticket_name'             => 0 === $index ? "Loadgen RSVP {$seq}" : "Loadgen RSVP {$seq}-{$index}",
			'ticket_description'      => 'Generated RSVP ticket for migration load testing.',
			'ticket_show_description' => 1,
			'ticket_price'            => 0,
			'ticket_start_date'       => gmdate( 'Y-m-d', strtotime( '-1 month' ) ),
			'ticket_start_time'       => '08:00:00',
			'ticket_end_date'         => gmdate( 'Y-m-d', strtotime( '+6 months' ) ),
			'ticket_end_time'         => '20:00:00',
			'tribe-ticket'            => [
				'mode'     => \Tribe__Tickets__Global_Stock::OWN_STOCK_MODE,
				'capacity' => $capacity,
			],
		];

		$ticket_id = $rsvp->ticket_add( $post_id, $data );

		if ( ! $ticket_id ) {
			return 0;
		}

		$this->tag_generated( (int) $ticket_id, 'ticket' );

		return (int) $ticket_id;
	}

	/**
	 * Creates a random number of attendees for a ticket, spread across a random number
	 * of `_tribe_rsvp_order` groups, to stress the migration's order-grouping logic.
	 *
	 * @return int[] Attendee post IDs.
	 */
	private function create_attendees_for_ticket( int $ticket_id, int $post_id ): array {
		$capacity      = (int) get_post_meta( $ticket_id, '_tribe_ticket_capacity', true );
		$min_attendees = max( 1, (int) ( $this->options['min_attendees'] ?? 1 ) );
		$max_attendees = max( $min_attendees, (int) ( $this->options['max_attendees'] ?? 20 ) );

		if ( $capacity > 0 ) {
			$max_attendees = min( $max_attendees, max( $min_attendees, $capacity ) );
		}

		$attendee_count = wp_rand( $min_attendees, $max_attendees );
		$order_count    = wp_rand( 1, $attendee_count );

		$order_hashes = [];
		for ( $o = 0; $o < $order_count; $o++ ) {
			$order_hashes[] = md5( uniqid( "loadgen-{$ticket_id}-{$o}", true ) );
		}

		// Guarantee every order group gets at least one attendee, then randomly
		// distribute the remainder across groups.
		$assignments = $order_hashes;
		for ( $i = count( $order_hashes ); $i < $attendee_count; $i++ ) {
			$assignments[] = $order_hashes[ array_rand( $order_hashes ) ];
		}
		shuffle( $assignments );

		$attendee_ids = [];
		$total_sales  = 0;

		foreach ( $assignments as $i => $order_hash ) {
			$status = wp_rand( 1, 100 ) <= 90 ? 'yes' : 'no';

			$attendee_id = $this->create_single_attendee( $ticket_id, $post_id, $order_hash, $status, $i );

			if ( $attendee_id ) {
				$attendee_ids[] = $attendee_id;

				if ( 'yes' === $status ) {
					$total_sales++;
				}
			}
		}

		update_post_meta( $ticket_id, 'total_sales', $total_sales );

		return $attendee_ids;
	}

	/**
	 * Creates a single `tribe_rsvp_attendees` post with the meta shape the
	 * `rsvp-to-tc` migration expects.
	 */
	private function create_single_attendee( int $ticket_id, int $post_id, string $order_hash, string $status, int $sub_seq ): int {
		// Keyed off $ticket_id (always unique) rather than the unit's $seq, since a unit can now
		// have multiple tickets sharing the same $seq — keying off $seq alone would collide emails.
		[ $full_name, $email ] = Data::random_person( $ticket_id, $sub_seq );

		$attendee_id = wp_insert_post( [
			'post_type'   => \Tribe__Tickets__RSVP::ATTENDEE_OBJECT,
			'post_title'  => "Loadgen Attendee {$full_name}",
			'post_status' => 'publish',
			'meta_input'  => [
				'_tribe_rsvp_event'               => $post_id,
				'_tribe_rsvp_product'             => $ticket_id,
				'_tribe_rsvp_security_code'       => md5( uniqid( (string) $ticket_id, true ) ),
				'_tribe_rsvp_full_name'           => $full_name,
				'_tribe_rsvp_email'               => $email,
				\Tribe__Tickets__RSVP::ATTENDEE_RSVP_KEY    => $status,
				\Tribe__Tickets__RSVP::ATTENDEE_OPTOUT_KEY  => false,
				\Tribe__Tickets__RSVP::ATTENDEE_TICKET_SENT => true,
				'_tribe_rsvp_checkedin'           => false,
				'_paid_price'                     => 0,
			],
		], true );

		if ( is_wp_error( $attendee_id ) ) {
			return 0;
		}

		// Grouping meta: multiple attendees sharing the same $order_hash become one
		// migrated Order, per RSVP_To_Tickets_Commerce::group_attendees_by_order_hash().
		update_post_meta( $attendee_id, '_tribe_rsvp_order', $order_hash );

		$this->tag_generated( (int) $attendee_id, 'attendee' );

		return (int) $attendee_id;
	}

	/**
	 * Tags a post as generated by this tool, so cleanup can find (only) it later.
	 */
	private function tag_generated( int $post_id, string $kind ): void {
		update_post_meta( $post_id, Data::GENERATED_META_KEY, 1 );
		update_post_meta( $post_id, Data::RUN_ID_META_KEY, $this->run_id );
		update_post_meta( $post_id, Data::POST_KIND_META_KEY, $kind );
	}

	/**
	 * Wraps a bulk-insert callback with WordPress's own bulk-import performance guards.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	private function run_with_performance_guards( callable $callback ) {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_invalidation( true );

		try {
			return $callback();
		} finally {
			wp_suspend_cache_invalidation( false );
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
			wp_cache_flush();
		}
	}
}
