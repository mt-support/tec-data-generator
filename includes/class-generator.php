<?php
/**
 * Creates legacy V1 RSVP tickets (with attendees, grouped into orders) on freshly
 * created Event/Page/Post content, using the same production code paths real
 * user-created data goes through.
 */

namespace TEC\DataGenerator;

class Generator {

	const PAID_TICKET_TYPES = [ 'Standard', 'General', 'Basic', 'Student', 'Early Bird', 'VIP', 'Platinum' ];

	/**
	 * Virtual meeting platforms cycled randomly across generated virtual events.
	 *
	 * @var array<string,array{label:string,url:string}>
	 */
	const VIRTUAL_PLATFORMS = [
		'zoom'        => [ 'label' => 'Zoom', 'url' => 'https://zoom.us/j/%s' ],
		'google_meet' => [ 'label' => 'Google Meet', 'url' => 'https://meet.google.com/%s' ],
		'teams'       => [ 'label' => 'Microsoft Teams', 'url' => 'https://teams.microsoft.com/l/meetup-join/%s' ],
	];

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
	 *     @type string[] $event_types    Event subtypes for tribe_events units: single, recurring, virtual.
	 *                                    Default [ 'single' ] (pre-existing behavior).
	 *     @type string $ticket_type      Ticket attachment mode: rsvp, paid, or none. Default 'rsvp'.
	 *     @type string $container        Container filter: mixed (even Event/Page/Post split, default),
	 *                                    event (tribe_events only), or page (pages only).
	 *     @type string $editor           Container editor: classic (plain post_content, default) or
	 *                                    block (Gutenberg paragraph/heading markup). Tickets always use
	 *                                    the production ticket_add() path regardless of editor.
	 * }
	 *
	 * @throws \Exception When a requested event/ticket type needs a plugin that isn't active.
	 *
	 * @return array{created:int,post_ids:int[],ticket_ids:int[],attendee_ids:int[]}
	 */
	public function generate_batch( int $count, int $offset, array $options = [] ): array {
		$this->options = $options;

		// Fail fast with a clear message before creating anything, so a bad flag
		// combination never leaves half a run behind.
		$this->options['event_types'] = $this->normalize_event_types( $this->options['event_types'] ?? [ 'single' ] );
		$this->options['ticket_type'] = $this->options['ticket_type'] ?? 'rsvp';
		$this->options['container'] = $this->normalize_container( $this->options['container'] ?? 'mixed' );
		$this->options['editor'] = $this->normalize_editor( $this->options['editor'] ?? 'classic' );
		$this->validate_options();

		return $this->run_with_performance_guards( function () use ( $count, $offset ) {
			$post_ids     = [];
			$ticket_ids   = [];
			$attendee_ids = [];

			for ( $i = 0; $i < $count; $i++ ) {
				$seq       = $offset + $i;
				$post_type = $this->post_type_for_sequence( $seq );

				try {
					$post_id = 'tribe_events' === $post_type
						? $this->create_event_by_type( $seq, $this->event_type_for_sequence( $seq, $this->options['event_types'] ) )
						: $this->create_page_or_post( $post_type, $seq );
				} catch ( \Exception $e ) {
					// A dependency may have been deactivated mid-run (chunked AJAX/background
					// jobs span many requests) — skip this unit with a warning instead of
					// aborting the whole run.
					error_log( sprintf( '[tec-data-generator] Skipping unit %d: %s', $seq, $e->getMessage() ) );
					continue;
				}

				if ( ! $post_id ) {
					continue;
				}
				$post_ids[] = $post_id;

				$result = $this->attach_tickets( $post_id, $seq );

				$ticket_ids   = array_merge( $ticket_ids, $result['ticket_ids'] );
				$attendee_ids = array_merge( $attendee_ids, $result['attendee_ids'] );
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

		$ticket_id = $this->with_v1_rsvp_repositories( function () use ( $event_id, $data ) {
			return tribe( 'tickets.rsvp' )->ticket_add( $event_id, $data );
		} );

		if ( ! $ticket_id ) {
			return 0;
		}

		$this->tag_generated( (int) $ticket_id, 'ticket' );

		// Ensure the event's default ticket provider is set so the admin UI displays the tickets metabox.
		update_post_meta( $event_id, '_tribe_default_ticket_provider', \Tribe__Tickets__RSVP::class );

		// On V2 sites, also create the V2 representation so the WordPress admin can see it via Tickets Commerce repository.
		if ( function_exists( 'tribe' ) && class_exists( 'Tribe__Tickets__RSVP' ) ) {
			// Call without the V1 wrapper to use the current provider binding (which will be V2 on RSVP V2 sites).
			$v2_ticket_id = tribe( 'tickets.rsvp' )->ticket_add( $event_id, $data );
			if ( $v2_ticket_id ) {
				$this->tag_generated( (int) $v2_ticket_id, 'ticket' );
				// set _type explicitly for V2 tickets so the repository query can find them
				update_post_meta( (int) $v2_ticket_id, '_type', 'tc-rsvp' );
			}
		}

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
					$attendee_id = (int) $this->with_v1_rsvp_repositories( function () use ( $ticket_post, $data ) {
						return tribe( 'tickets.rsvp' )->create_attendee_for_ticket( $ticket_post, $data );
					} );
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
	 * Generates series with events. Each series contains N events (default 5) with different
	 * venues/organizers, optionally with tickets.
	 *
	 * @param int   $count              Number of series to create.
	 * @param int   $offset             Global sequence offset for name uniqueness.
	 * @param int   $events_per_series  Events per series (default 5).
	 * @param array $options            {
	 *     @type int $min_attendees      Minimum attendees per ticket. Default 1.
	 *     @type int $max_attendees      Maximum attendees per ticket. Default 20.
	 *     @type bool $with_venues       Attach different venue to each event. Default false.
	 *     @type bool $with_organizers   Attach different organizer to each event. Default false.
	 *     @type string $ticket_type     Ticket mode: rsvp, paid, or none. Default 'rsvp'.
	 *     @type string $editor          Container editor: classic or block. Default 'classic'.
	 * }
	 *
	 * @return array{created:int,series_ids:int[],post_ids:int[],ticket_ids:int[],attendee_ids:int[]}
	 */
	public function generate_series( int $count, int $offset, int $events_per_series = 5, array $options = [] ): array {
		$this->options = $options;
		$this->options['ticket_type'] = $this->options['ticket_type'] ?? 'rsvp';
		$this->options['editor'] = $this->normalize_editor( $this->options['editor'] ?? 'classic' );
		$this->options['event_types'] = [ 'single' ];

		return $this->run_with_performance_guards( function () use ( $count, $offset, $events_per_series ) {
			$series_ids   = [];
			$post_ids     = [];
			$ticket_ids   = [];
			$attendee_ids = [];

			for ( $s = 0; $s < $count; $s++ ) {
				$series_seq  = $offset + $s;
				$series_id   = $this->create_series( $series_seq );

				if ( ! $series_id ) {
					continue;
				}

				$series_ids[] = $series_id;

				for ( $e = 0; $e < $events_per_series; $e++ ) {
					$event_seq = ( $series_seq * 1000 ) + $e;
					$venue_id  = ! empty( $this->options['with_venues'] ) ? $this->pick_venue() : 0;
					$org_id    = ! empty( $this->options['with_organizers'] ) ? $this->pick_organizer() : 0;

					try {
						$event_id = $this->create_single_event( $event_seq, $venue_id, $org_id );

						if ( $event_id ) {
							$post_ids[] = $event_id;
							$this->link_event_to_series( $event_id, $series_id );

							$attached = $this->attach_tickets( $event_id, $event_seq );
							$ticket_ids   = array_merge( $ticket_ids, $attached['ticket_ids'] );
							$attendee_ids = array_merge( $attendee_ids, $attached['attendee_ids'] );
						}
					} catch ( \Exception $e ) {
						continue;
					}
				}
			}

			return [
				'created'      => count( $series_ids ),
				'series_ids'   => $series_ids,
				'post_ids'     => $post_ids,
				'ticket_ids'   => $ticket_ids,
				'attendee_ids' => $attendee_ids,
			];
		} );
	}

	/**
	 * Creates a tribe_event_series post using the Events Pro API.
	 */
	private function create_series( int $seq ): int {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\TEC\Events_Pro\Custom_Tables\V1\Models\Series' ) ) {
			return 0;
		}

		$series_class = '\TEC\Events_Pro\Custom_Tables\V1\Models\Series';
		$title        = Data::random_event_title() . " Series (#{$seq})";

		$series_id = $series_class::vinsert( [ 'title' => $title ] );

		if ( $series_id ) {
			$this->tag_generated( $series_id, 'series' );
		}

		return $series_id;
	}

	/**
	 * Links an event to a series via the Series_Relationship table.
	 */
	private function link_event_to_series( int $post_id, int $series_id ): bool {
		if ( ! class_exists( '\TEC\Events_Pro\Custom_Tables\V1\Models\Series_Relationship' )
			|| ! class_exists( '\TEC\Events\Custom_Tables\V1\Models\Event' ) ) {
			return false;
		}

		try {
			$event_post = get_post( $post_id );

			if ( ! $event_post || 'tribe_events' !== $event_post->post_type ) {
				return false;
			}

			// Get event_id from custom tables
			$event = \TEC\Events\Custom_Tables\V1\Models\Event::where( 'post_id', $post_id )->first();

			if ( ! $event ) {
				return false;
			}

			$series_relationship_class = '\TEC\Events_Pro\Custom_Tables\V1\Models\Series_Relationship';
			$existing = $series_relationship_class::where( 'series_post_id', $series_id )
				->where( 'event_post_id', $post_id )
				->where( 'event_id', $event->event_id )
				->first();

			if ( $existing ) {
				return true;
			}

			$series_relationship_class::insert( [
				'series_post_id' => $series_id,
				'event_post_id'  => $post_id,
				'event_id'       => $event->event_id,
			] );

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Round-robins post types by global sequence, guaranteeing an even 3-way split
	 * regardless of how many chunked calls it takes to reach the total count.
	 * The `container` option restricts this: 'event' always returns tribe_events,
	 * 'page' always returns page, 'mixed' keeps the legacy 3-way split.
	 */
	private function post_type_for_sequence( int $seq ): string {
		$container = $this->options['container'] ?? 'mixed';

		if ( 'event' === $container ) {
			return 'tribe_events';
		}

		if ( 'page' === $container ) {
			return 'page';
		}

		static $types = [ 'tribe_events', 'page', 'post' ];

		return $types[ $seq % 3 ];
	}

	/**
	 * Normalizes the container filter to a known value.
	 *
	 * @throws \Exception On unknown container names.
	 */
	private function normalize_container( $container ): string {
		$container = is_string( $container ) ? strtolower( trim( $container ) ) : 'mixed';

		if ( ! in_array( $container, [ 'mixed', 'event', 'page' ], true ) ) {
			throw new \Exception(
				sprintf(
					'Invalid container "%s". Use one of: mixed, event, page.',
					$container
				)
			);
		}

		return $container;
	}

	/**
	 * Normalizes the editor choice to a known value.
	 *
	 * @throws \Exception On unknown editor names.
	 */
	private function normalize_editor( $editor ): string {
		$editor = is_string( $editor ) ? strtolower( trim( $editor ) ) : 'classic';

		if ( ! in_array( $editor, [ 'classic', 'block' ], true ) ) {
			throw new \Exception(
				sprintf(
					'Invalid editor "%s". Use one of: classic, block.',
					$editor
				)
			);
		}

		return $editor;
	}

	/**
	 * Builds container post_content for the requested editor. Classic is the legacy plain
	 * text; block wraps the same words in real Gutenberg markup so the container opens in
	 * the block editor. Ticket creation is unaffected (always the production ticket_add()
	 * path) — this is presentation only.
	 */
	private function container_content( string $plain, string $title, bool $is_event = false ): string {
		if ( ( $this->options['editor'] ?? 'classic' ) !== 'block' ) {
			return $plain;
		}

		if ( $is_event ) {
			return $this->event_block_content( $plain );
		}

		return sprintf(
			"<!-- wp:heading --><h2>%s</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->",
			esc_html( $title ),
			esc_html( $plain )
		);
	}

	/**
	 * Matches the block template a real event gets when created via the tribe_events block
	 * editor — the same order TEC/Events Pro/Event Tickets build it via the
	 * `tribe_events_editor_default_template` filter chain (event-price/organizer/venue/
	 * website/links from TEC core, related-events from Events Pro, tickets/rsvp from Event
	 * Tickets). All of these are dynamic blocks that render live from post meta, so this is
	 * just the markup shape — it doesn't create tickets itself.
	 */
	private function event_block_content( string $plain ): string {
		$blocks = [
			'<!-- wp:tribe/event-datetime /-->',
			sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", esc_html( wp_strip_all_tags( $plain ) ) ),
			'<!-- wp:tribe/event-price /-->',
			'<!-- wp:tribe/event-organizer /-->',
			'<!-- wp:tribe/event-venue /-->',
			'<!-- wp:tribe/event-website /-->',
			'<!-- wp:tribe/event-links /-->',
			'<!-- wp:tribe/related-events /-->',
			'<!-- wp:tribe/tickets /-->',
			'<!-- wp:tribe/rsvp /-->',
		];

		return implode( "\n\n", $blocks );
	}

	/**
	 * Normalizes the event_types option to a non-empty list of known types.
	 *
	 * @param string|string[] $types
	 *
	 * @throws \Exception On unknown type names.
	 *
	 * @return string[]
	 */
	private function normalize_event_types( $types ): array {
		if ( is_string( $types ) ) {
			$types = explode( ',', $types );
		}

		$types = array_values( array_filter( array_map( 'trim', (array) $types ) ) );

		if ( ! $types ) {
			$types = [ 'single' ];
		}

		foreach ( $types as $type ) {
			if ( ! in_array( $type, Plugin_Availability::ALLOWED_EVENT_TYPES, true ) ) {
				throw new \Exception(
					sprintf(
						'Invalid event type "%s". Use one or more of: %s.',
						$type,
						implode( ', ', Plugin_Availability::ALLOWED_EVENT_TYPES )
					)
				);
			}
		}

		return $types;
	}

	/**
	 * Validates requested event/ticket types against active plugins. Runs before any
	 * creation so misconfiguration never leaves partial data behind.
	 *
	 * @throws \Exception With a message naming the missing plugin.
	 */
	private function validate_options(): void {
		foreach ( $this->options['event_types'] as $type ) {
			if ( 'recurring' === $type && ! Plugin_Availability::has_events_pro_or_ecp() ) {
				throw new \Exception( 'Recurring events require Events Pro or ECP plugin to be active.' );
			}

			if ( 'virtual' === $type && ! Plugin_Availability::has_ecp() ) {
				throw new \Exception( 'Virtual events require ECP (Events Calendar Pro) plugin to be active.' );
			}
		}

		$ticket_type = $this->options['ticket_type'];

		if ( ! in_array( $ticket_type, Plugin_Availability::ALLOWED_TICKET_TYPES, true ) ) {
			throw new \Exception(
				sprintf(
					'Invalid ticket type "%s". Use one of: %s.',
					$ticket_type,
					implode( ', ', Plugin_Availability::ALLOWED_TICKET_TYPES )
				)
			);
		}

		if ( in_array( $ticket_type, [ 'rsvp', 'paid' ], true ) && ! Plugin_Availability::has_event_tickets() ) {
			throw new \Exception(
				'rsvp' === $ticket_type
					? 'RSVP tickets require Event Tickets plugin to be active.'
					: 'Paid tickets require Event Tickets plugin to be active.'
			);
		}
	}

	/**
	 * Deterministically maps a global sequence offset to one of the requested event types,
	 * cycling through the list — so chunked runs keep a stable, reproducible distribution
	 * regardless of chunk size.
	 *
	 * @param string[] $types
	 */
	public function event_type_for_sequence( int $seq, array $types ): string {
		if ( ! $types ) {
			return 'single';
		}

		return $types[ $seq % count( $types ) ];
	}

	/**
	 * Dispatches to the matching create_*_event() method. Re-checks plugin availability
	 * per unit so a deactivation mid-run fails that unit (caught by the caller) rather
	 * than silently producing the wrong shape.
	 *
	 * @throws \Exception When the type's plugin is no longer active.
	 */
	private function create_event_by_type( int $seq, string $event_type ): int {
		$venue_id     = $this->pick_venue();
		$organizer_id = $this->pick_organizer();

		switch ( $event_type ) {
			case 'recurring':
				if ( ! Plugin_Availability::has_events_pro_or_ecp() ) {
					throw new \Exception( 'Recurring events require Events Pro or ECP plugin to be active.' );
				}

				return $this->create_recurring_event( $seq, $venue_id, $organizer_id );
			case 'virtual':
				if ( ! Plugin_Availability::has_ecp() ) {
					throw new \Exception( 'Virtual events require ECP (Events Calendar Pro) plugin to be active.' );
				}

				return $this->create_virtual_event( $seq, $venue_id, $organizer_id );
			default:
				return $this->create_single_event( $seq, $venue_id, $organizer_id );
		}
	}

	/**
	 * Attaches tickets to a freshly created container post according to the ticket_type
	 * option: 'rsvp' (existing V1 path), 'paid' (existing provider path), 'none' (skip).
	 *
	 * @return array{ticket_ids:int[],attendee_ids:int[]}
	 */
	private function attach_tickets( int $post_id, int $seq ): array {
		$ticket_type = $this->options['ticket_type'] ?? 'rsvp';

		if ( 'none' === $ticket_type ) {
			return [ 'ticket_ids' => [], 'attendee_ids' => [] ];
		}

		$min_rsvps  = max( 1, (int) ( $this->options['min_rsvps_per_unit'] ?? 1 ) );
		$max_rsvps  = max( $min_rsvps, (int) ( $this->options['max_rsvps_per_unit'] ?? 1 ) );
		$ticket_count = wp_rand( $min_rsvps, $max_rsvps );

		$ticket_ids   = [];
		$attendee_ids = [];

		for ( $r = 0; $r < $ticket_count; $r++ ) {
			if ( 'paid' === $ticket_type ) {
				$created = $this->add_paid_tickets( $post_id, 1 );

				if ( ! $created ) {
					// Fresh events default to the RSVP provider, which can't sell paid
					// tickets — warn once per unit instead of failing the run.
					error_log( sprintf( '[tec-data-generator] Skipping paid ticket for post %d: event provider does not support paid tickets.', $post_id ) );
					continue;
				}

				foreach ( $created as $paid_ticket_id ) {
					$ticket_ids[] = $paid_ticket_id;
					$attendee_ids = array_merge(
						$attendee_ids,
						$this->add_attendees( $paid_ticket_id, $this->random_attendee_count() )
					);
				}

				continue;
			}

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

		return [ 'ticket_ids' => $ticket_ids, 'attendee_ids' => $attendee_ids ];
	}

	/**
	 * Random attendee count within the configured min/max range (shared by the paid-ticket
	 * path; the RSVP path additionally caps at ticket capacity — see create_attendees_for_ticket()).
	 */
	private function random_attendee_count(): int {
		$min = max( 1, (int) ( $this->options['min_attendees'] ?? 1 ) );
		$max = max( $min, (int) ( $this->options['max_attendees'] ?? 20 ) );

		return wp_rand( $min, $max );
	}

	/**
	 * Creates a plain single (non-recurring, non-virtual) `tribe_events` post via
	 * The Events Calendar's own repository ORM, so it has valid start/end dates and
	 * behaves like a real event on the front end.
	 */
	private function create_single_event( int $seq, int $venue_id = 0, int $organizer_id = 0 ): int {
		if ( ! function_exists( 'tribe_events' ) ) {
			return 0;
		}

		$start = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( "+{$seq} hours" );
		$end   = $start->modify( '+2 hours' );

		$organizer_name = $organizer_id ? get_the_title( $organizer_id ) : '';
		$venue_name     = $venue_id ? get_the_title( $venue_id ) : '';
		$venue_city     = $venue_id ? (string) get_post_meta( $venue_id, '_VenueCity', true ) : '';

		$title   = Data::random_event_title() . " (#{$seq})";
		$plain   = Data::random_event_description( $organizer_name, $venue_name, $venue_city );
		$args = [
			'title'      => $title,
			'status'     => 'publish',
			'start_date' => $start->format( 'Y-m-d H:i:s' ),
			'end_date'   => $end->format( 'Y-m-d H:i:s' ),
			'content'    => $this->container_content( $plain, $title, true ),
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
			update_post_meta( $post_id, Data::EVENT_TYPE_META_KEY, 'single' );
			update_post_meta( $post_id, Data::EDITOR_META_KEY, $this->options['editor'] ?? 'classic' );
		}

		return $post_id;
	}

	/**
	 * Creates a recurring event: a normal event through the same repository ORM as
	 * create_single_event(), plus the Events Pro recurrence rule meta (`_EventRecurrence`)
	 * with a randomized daily/weekly/monthly pattern (1-30 occurrences, never infinite,
	 * so generated data stays manageable). Recurring instances are materialized by Events
	 * Pro itself from this rule — this tool only seeds the parent rule, not custom engine.
	 */
	private function create_recurring_event( int $seq, int $venue_id = 0, int $organizer_id = 0 ): int {
		$post_id = $this->create_single_event( $seq, $venue_id, $organizer_id );

		if ( ! $post_id ) {
			return 0;
		}

		$patterns = [ 'daily', 'weekly', 'monthly' ];
		$pattern  = $patterns[ array_rand( $patterns ) ];

		$occurrences = [
			'daily'   => wp_rand( 10, 30 ),
			'weekly'  => wp_rand( 4, 12 ),
			'monthly' => wp_rand( 2, 6 ),
		][ $pattern ];

		update_post_meta( $post_id, '_EventRecurrence', [
			'rules' => [
				[
					'type'        => $pattern,
					'end-count'   => $occurrences,
					'EventStartDate' => get_post_meta( $post_id, '_EventStartDate', true ),
				],
			],
		] );
		update_post_meta( $post_id, Data::EVENT_TYPE_META_KEY, 'recurring' );

		return $post_id;
	}

	/**
	 * Creates a virtual event: a normal event through the same repository ORM as
	 * create_single_event(), plus virtual meeting metadata (randomized platform + unique
	 * meeting URL per event). Meta is attached post-hoc because ECP stores virtual details
	 * as post meta — no special creation API needed.
	 */
	private function create_virtual_event( int $seq, int $venue_id = 0, int $organizer_id = 0 ): int {
		$post_id = $this->create_single_event( $seq, $venue_id, $organizer_id );

		if ( ! $post_id ) {
			return 0;
		}

		$platforms = array_keys( self::VIRTUAL_PLATFORMS );
		$platform  = $platforms[ array_rand( $platforms ) ];
		$meeting_id = strtolower( wp_generate_password( 12, false, false ) );

		update_post_meta( $post_id, '_tec_virtual_platform', $platform );
		update_post_meta( $post_id, '_tec_virtual_meeting_url', sprintf( self::VIRTUAL_PLATFORMS[ $platform ]['url'], $meeting_id ) );
		update_post_meta( $post_id, '_tec_virtual', 'yes' );
		update_post_meta( $post_id, Data::EVENT_TYPE_META_KEY, 'virtual' );

		return $post_id;
	}

	/**
	 * Creates a plain `page` or `post` container post.
	 */
	private function create_page_or_post( string $post_type, int $seq ): int {
		$title   = sprintf( 'Loadgen %s %d', ucfirst( $post_type ), $seq );
		$plain   = 'Generated by TEC Data Generator for migration stress testing.';
		$post_id = wp_insert_post( [
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => $this->container_content( $plain, $title ),
		], true );

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$this->tag_generated( $post_id, $post_type );
		update_post_meta( $post_id, Data::EDITOR_META_KEY, $this->options['editor'] ?? 'classic' );

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

		$ticket_id = $this->with_v1_rsvp_repositories( function () use ( $post_id, $data ) {
			return tribe( 'tickets.rsvp' )->ticket_add( $post_id, $data );
		} );

		if ( ! $ticket_id ) {
			return 0;
		}

		$this->tag_generated( (int) $ticket_id, 'ticket' );

		// Ensure the event's default ticket provider is set so the admin UI displays the tickets metabox.
		// On RSVP V2 sites, also create the V2 representation so the WordPress admin can see it via Tickets Commerce repository.
		update_post_meta( $post_id, '_tribe_default_ticket_provider', \Tribe__Tickets__RSVP::class );

		// On V2 sites, create the V2 representation so the WordPress admin UI can see it.
		if ( function_exists( 'tribe' ) && class_exists( 'Tribe__Tickets__RSVP' ) ) {
			// Call without the V1 wrapper to use the current provider binding (which will be V2 on RSVP V2 sites).
			$v2_ticket_id = tribe( 'tickets.rsvp' )->ticket_add( $post_id, $data );
			if ( $v2_ticket_id ) {
				$this->tag_generated( (int) $v2_ticket_id, 'ticket' );
				// set _type explicitly for V2 tickets so the repository query can find them
				update_post_meta( (int) $v2_ticket_id, '_type', 'tc-rsvp' );
			}
		}

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
	 * Forces `tribe( 'tickets.rsvp' )` calls made inside $callback to create the legacy V1
	 * `tribe_rsvp_tickets`/`tribe_rsvp_attendees` shape, regardless of whether the site's RSVP
	 * feature is currently running in V1 or V2 (Tickets Commerce-backed) mode.
	 *
	 * Event Tickets 5.x resolves `Tribe__Tickets__RSVP::save_ticket()` and
	 * `create_attendee_for_ticket()` through container-bound repositories
	 * (`tickets.ticket-repository.rsvp` / `tickets.attendee-repository.rsvp`) rather than
	 * hard-coding the post type — on a site that's already run the rsvp-to-tc migration (RSVP
	 * V2 active), those bindings point at Tickets Commerce repositories, so the "same production
	 * API" this plugin relies on would silently create `tec_tc_ticket` posts instead of the V1
	 * shape this tool's whole purpose depends on. Temporarily rebinding to the known V1
	 * repository classes guarantees V1 output on both V1 and V2 sites, then restores whatever
	 * was bound before so the rest of the site is unaffected.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	private function with_v1_rsvp_repositories( callable $callback ) {
		if ( ! class_exists( '\Tribe__Tickets__Repositories__Ticket__RSVP' ) || ! function_exists( 'tribe' ) ) {
			return $callback();
		}

		$container = tribe();

		$previous_ticket_repo   = $this->safe_make( $container, 'tickets.ticket-repository.rsvp' );
		$previous_attendee_repo = $this->safe_make( $container, 'tickets.attendee-repository.rsvp' );

		$container->bind( 'tickets.ticket-repository.rsvp', \Tribe__Tickets__Repositories__Ticket__RSVP::class );

		if ( class_exists( '\Tribe__Tickets__Repositories__Attendee__RSVP' ) ) {
			$container->bind( 'tickets.attendee-repository.rsvp', \Tribe__Tickets__Repositories__Attendee__RSVP::class );
		}

		try {
			return $callback();
		} finally {
			if ( $previous_ticket_repo ) {
				$container->bind( 'tickets.ticket-repository.rsvp', get_class( $previous_ticket_repo ) );
			}

			if ( $previous_attendee_repo ) {
				$container->bind( 'tickets.attendee-repository.rsvp', get_class( $previous_attendee_repo ) );
			}
		}
	}

	/**
	 * @return object|null
	 */
	private function safe_make( $container, string $id ) {
		try {
			return $container->make( $id );
		} catch ( \Exception $e ) {
			return null;
		}
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
