<?php
/**
 * `wp tec-data-generator` command: bulk-generate or clean up legacy V1 TEC Data-test data.
 */

namespace TEC\DataGenerator;

class CLI {

	/**
	 * Generates bulk legacy V1 RSVP test data (events/pages/posts + RSVP tickets + attendees).
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : Total number of post+ticket units to generate, split evenly across event/page/post.
	 * ---
	 * default: 5000
	 * ---
	 *
	 * [--min-attendees=<number>]
	 * : Minimum attendees per ticket.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--max-attendees=<number>]
	 * : Maximum attendees per ticket.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : How many units to generate per internal batch/progress tick.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--with-venues]
	 * : Attach a random generated Venue to each Event unit.
	 *
	 * [--with-organizers]
	 * : Attach a random generated Organizer to each Event unit.
	 *
	 * [--event-types=<types>]
	 * : Comma-separated Event subtypes to generate (single,recurring,virtual). Recurring needs Events Pro or ECP; virtual needs ECP.
	 * ---
	 * default: single
	 * ---
	 *
	 * [--ticket-type=<type>]
	 * : Ticket mode per unit (rsvp, paid, or none). Paid uses the event's configured provider.
	 * ---
	 * default: rsvp
	 * options:
	 *   - rsvp
	 *   - paid
	 *   - none
	 * ---
	 *
	 * [--start-date=<date>]
	 * : Earliest date/time (any strtotime()-parsable value) generated events can fall on.
	 * ---
	 * default: now
	 * ---
	 *
	 * [--end-date=<date>]
	 * : Latest date/time generated events can fall on.
	 * ---
	 * default: +2 weeks from --start-date
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator generate --count=5000
	 *     wp tec-data-generator generate --count=200 --min-attendees=5 --max-attendees=50
	 *     wp tec-data-generator generate --count=30 --with-venues --with-organizers
	 *     wp tec-data-generator generate --count=100 --event-types=single,recurring --ticket-type=rsvp
	 *     wp tec-data-generator generate --count=5 --event-types=virtual --ticket-type=none
	 *
	 * @when after_wp_load
	 */
	public function generate( $args, $assoc_args ) {
		set_time_limit( 0 );

		$count         = max( 1, (int) ( $assoc_args['count'] ?? 5000 ) );
		$min_attendees = max( 1, (int) ( $assoc_args['min-attendees'] ?? 1 ) );
		$max_attendees = max( $min_attendees, (int) ( $assoc_args['max-attendees'] ?? 20 ) );
		$batch_size    = max( 1, (int) ( $assoc_args['batch-size'] ?? 100 ) );

		$run_id    = Data::new_run_id();
		$generator = new Generator( $run_id );
		$options   = [
			'min_attendees'   => $min_attendees,
			'max_attendees'   => $max_attendees,
			'with_venues'     => isset( $assoc_args['with-venues'] ),
			'with_organizers' => isset( $assoc_args['with-organizers'] ),
			'event_types'     => isset( $assoc_args['event-types'] ) ? explode( ',', (string) $assoc_args['event-types'] ) : [ 'single' ],
			'ticket_type'     => $assoc_args['ticket-type'] ?? 'rsvp',
			'date_start'      => $assoc_args['start-date'] ?? '',
			'date_end'        => $assoc_args['end-date'] ?? '',
		];

		\WP_CLI::log( "Starting generation of {$count} units (run: {$run_id})..." );

		$this->generate_in_chunks( $generator, $count, $batch_size, $options );

		\WP_CLI::success( "Generated {$count} tickets with attendees (run: {$run_id})." );
	}

	/**
	 * Generates event/page containers only (no tickets) — the "Events" half of the split.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : How many containers to create.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--container=<type>]
	 * : What to create: event (tribe_events only).
	 * ---
	 * default: event
	 * options:
	 *   - event
	 * ---
	 *
	 * [--editor=<editor>]
	 * : Container editor: classic (plain post_content) or block (Gutenberg markup).
	 * ---
	 * default: classic
	 * options:
	 *   - classic
	 *   - block
	 * ---
	 *
	 * [--event-types=<types>]
	 * : Comma-separated Event subtypes (single,recurring,virtual). Applies to event containers only.
	 * ---
	 * default: single
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : How many containers per internal batch/progress tick.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--with-venues]
	 * : Attach a random generated Venue to each Event container.
	 *
	 * [--with-organizers]
	 * : Attach a random generated Organizer to each Event container.
	 *
	 * [--start-date=<date>]
	 * : Earliest date/time (any strtotime()-parsable value) generated events can fall on.
	 * ---
	 * default: now
	 * ---
	 *
	 * [--end-date=<date>]
	 * : Latest date/time generated events can fall on.
	 * ---
	 * default: +2 weeks from --start-date
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator generate-events --count=50
	 *     wp tec-data-generator generate-events --count=50 --editor=block
	 *     wp tec-data-generator generate-events --count=20 --event-types=single,recurring --editor=block
	 *
	 * @subcommand generate-events
	 * @when after_wp_load
	 */
	public function generate_events( $args, $assoc_args ) {
		set_time_limit( 0 );

		$count      = max( 1, (int) ( $assoc_args['count'] ?? 100 ) );
		$batch_size = max( 1, (int) ( $assoc_args['batch-size'] ?? 100 ) );

		$run_id    = Data::new_run_id();
		$generator = new Generator( $run_id );
		$options   = [
			'ticket_type'     => 'none',
			'container'       => $assoc_args['container'] ?? 'event',
			'editor'          => $assoc_args['editor'] ?? 'classic',
			'with_venues'     => isset( $assoc_args['with-venues'] ),
			'with_organizers' => isset( $assoc_args['with-organizers'] ),
			'event_types'     => isset( $assoc_args['event-types'] ) ? explode( ',', (string) $assoc_args['event-types'] ) : [ 'single' ],
			'date_start'      => $assoc_args['start-date'] ?? '',
			'date_end'        => $assoc_args['end-date'] ?? '',
		];

		\WP_CLI::log( "Starting events-only generation of {$count} containers (run: {$run_id})..." );

		$this->generate_in_chunks( $generator, $count, $batch_size, $options );

		\WP_CLI::success( "Generated {$count} event containers with no tickets (run: {$run_id})." );
	}

	/**
	 * Generates tickets (+attendees) — the "Tickets" half of the split. Creates fresh
	 * event/page containers first, unless --event-id points at an existing post.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : How many containers to create (ignored when --event-id is given).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--container=<type>]
	 * : Where new tickets live: event (create tribe_events containers) or page (create pages).
	 * ---
	 * default: event
	 * options:
	 *   - event
	 *   - page
	 * ---
	 *
	 * [--event-id=<id>]
	 * : Attach tickets to this existing event/page instead of creating containers.
	 *
	 * [--ticket-type=<type>]
	 * : Ticket mode per unit (rsvp or paid). Paid uses the event's configured provider.
	 * ---
	 * default: rsvp
	 * options:
	 *   - rsvp
	 *   - paid
	 * ---
	 *
	 * [--editor=<editor>]
	 * : Container editor for newly created containers: classic or block. Ignored with --event-id.
	 * ---
	 * default: classic
	 * options:
	 *   - classic
	 *   - block
	 * ---
	 *
	 * [--min-tickets=<number>]
	 * : Minimum tickets per container (new containers only).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--max-tickets=<number>]
	 * : Maximum tickets per container (new containers only).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--quantity=<number>]
	 * : Total tickets to add when --event-id is given.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--min-attendees=<number>]
	 * : Minimum attendees per ticket.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--max-attendees=<number>]
	 * : Maximum attendees per ticket.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : How many containers per internal batch/progress tick (new containers only).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--event-types=<types>]
	 * : Comma-separated Event subtypes for new event containers (single,recurring,virtual).
	 * ---
	 * default: single
	 * ---
	 *
	 * [--start-date=<date>]
	 * : Earliest date/time (any strtotime()-parsable value) new event containers can fall on. Ignored with --event-id.
	 * ---
	 * default: now
	 * ---
	 *
	 * [--end-date=<date>]
	 * : Latest date/time new event containers can fall on. Ignored with --event-id.
	 * ---
	 * default: +2 weeks from --start-date
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator generate-tickets --count=10 --container=event --editor=block
	 *     wp tec-data-generator generate-tickets --count=5 --container=page --ticket-type=rsvp
	 *     wp tec-data-generator generate-tickets --event-id=123 --quantity=5 --ticket-type=rsvp
	 *
	 * @subcommand generate-tickets
	 * @when after_wp_load
	 */
	public function generate_tickets( $args, $assoc_args ) {
		set_time_limit( 0 );

		$min_attendees = max( 1, (int) ( $assoc_args['min-attendees'] ?? 1 ) );
		$max_attendees = max( $min_attendees, (int) ( $assoc_args['max-attendees'] ?? 20 ) );
		$ticket_type   = $assoc_args['ticket-type'] ?? 'rsvp';
		$run_id        = Data::new_run_id();
		$generator     = new Generator( $run_id );

		$event_id = (int) ( $assoc_args['event-id'] ?? 0 );

		if ( $event_id ) {
			if ( ! get_post( $event_id ) ) {
				\WP_CLI::error( 'Invalid event ID.' );
			}

			$quantity = max( 1, (int) ( $assoc_args['quantity'] ?? 5 ) );

			try {
				if ( 'paid' === $ticket_type ) {
					$ticket_ids = $generator->add_paid_tickets( $event_id, $quantity );
				} else {
					$ticket_ids = $generator->add_rsvp_tickets( $event_id, $quantity );
				}

				$attendee_total = 0;

				foreach ( $ticket_ids as $ticket_id ) {
					$attendee_total += count( $generator->add_attendees( $ticket_id, wp_rand( $min_attendees, $max_attendees ) ) );
				}
			} catch ( \Exception $e ) {
				\WP_CLI::error( $e->getMessage() );
			}

			\WP_CLI::success( 'Added ' . count( $ticket_ids ) . " {$ticket_type} ticket(s) with {$attendee_total} attendees to event {$event_id} (run: {$run_id})." );

			return;
		}

		$count         = max( 1, (int) ( $assoc_args['count'] ?? 10 ) );
		$min_tickets   = max( 1, (int) ( $assoc_args['min-tickets'] ?? 1 ) );
		$max_tickets   = max( $min_tickets, (int) ( $assoc_args['max-tickets'] ?? 1 ) );
		$batch_size    = max( 1, (int) ( $assoc_args['batch-size'] ?? 100 ) );
		$options       = [
			'min_attendees'      => $min_attendees,
			'max_attendees'      => $max_attendees,
			'min_rsvps_per_unit' => $min_tickets,
			'max_rsvps_per_unit' => $max_tickets,
			'ticket_type'        => $ticket_type,
			'container'          => $assoc_args['container'] ?? 'event',
			'editor'             => $assoc_args['editor'] ?? 'classic',
			'event_types'        => isset( $assoc_args['event-types'] ) ? explode( ',', (string) $assoc_args['event-types'] ) : [ 'single' ],
			'date_start'         => $assoc_args['start-date'] ?? '',
			'date_end'           => $assoc_args['end-date'] ?? '',
		];

		\WP_CLI::log( "Starting tickets generation on {$count} new {$options['container']} containers (run: {$run_id})..." );

		$this->generate_in_chunks( $generator, $count, $batch_size, $options );

		\WP_CLI::success( "Generated {$ticket_type} tickets on {$count} new {$options['container']} containers (run: {$run_id})." );
	}

	/**
	 * Generates event series: groups of events with different details, linked together.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : Number of series to create.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--events-per-series=<number>]
	 * : Events per series.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--with-venues]
	 * : Attach different random venue to each event in series.
	 *
	 * [--with-organizers]
	 * : Attach different random organizer to each event in series.
	 *
	 * [--ticket-type=<type>]
	 * : Ticket mode per event (rsvp, paid, or none).
	 * ---
	 * default: rsvp
	 * options:
	 *   - rsvp
	 *   - paid
	 *   - none
	 * ---
	 *
	 * [--min-attendees=<number>]
	 * : Minimum attendees per ticket.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--max-attendees=<number>]
	 * : Maximum attendees per ticket.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : How many series per internal batch/progress tick.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--start-date=<date>]
	 * : Earliest date/time (any strtotime()-parsable value) generated events can fall on.
	 * ---
	 * default: now
	 * ---
	 *
	 * [--end-date=<date>]
	 * : Latest date/time generated events can fall on.
	 * ---
	 * default: +2 weeks from --start-date
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator generate-series --count=5 --events-per-series=5
	 *     wp tec-data-generator generate-series --count=10 --events-per-series=3 --with-venues
	 *     wp tec-data-generator generate-series --count=3 --with-venues --with-organizers --ticket-type=paid
	 *
	 * @subcommand generate-series
	 * @when after_wp_load
	 */
	public function generate_series( $args, $assoc_args ) {
		set_time_limit( 0 );

		$count              = max( 1, (int) ( $assoc_args['count'] ?? 5 ) );
		$events_per_series  = max( 1, (int) ( $assoc_args['events-per-series'] ?? 5 ) );
		$batch_size         = max( 1, (int) ( $assoc_args['batch-size'] ?? 5 ) );
		$min_attendees      = max( 1, (int) ( $assoc_args['min-attendees'] ?? 1 ) );
		$max_attendees      = max( $min_attendees, (int) ( $assoc_args['max-attendees'] ?? 20 ) );
		$ticket_type        = $assoc_args['ticket-type'] ?? 'rsvp';
		$run_id             = Data::new_run_id();
		$generator          = new Generator( $run_id );

		$options = [
			'min_attendees'    => $min_attendees,
			'max_attendees'    => $max_attendees,
			'with_venues'      => isset( $assoc_args['with-venues'] ),
			'with_organizers'  => isset( $assoc_args['with-organizers'] ),
			'ticket_type'      => $ticket_type,
			'editor'           => 'classic',
			'date_start'       => $assoc_args['start-date'] ?? '',
			'date_end'         => $assoc_args['end-date'] ?? '',
		];

		\WP_CLI::log( "Starting series generation of {$count} series with {$events_per_series} events each (run: {$run_id})..." );

		$offset = 0;
		for ( $b = 0; $b < $count; $b += $batch_size ) {
			$batch_count = min( $batch_size, $count - $b );
			$result      = $generator->generate_series( $batch_count, $offset, $events_per_series, $options );
			$offset     += $batch_count;

			\WP_CLI::log( sprintf(
				'  Generated batch: %d series, %d events, %d tickets, %d attendees',
				count( $result['series_ids'] ),
				count( $result['post_ids'] ),
				count( $result['ticket_ids'] ),
				count( $result['attendee_ids'] )
			) );
		}

		\WP_CLI::success( "Generated {$count} series with {$events_per_series} events each (run: {$run_id})." );
	}

	/**
	 * Generates a pre-defined QA scenario: realistic day-to-day load ("usual"), or an
	 * extreme-scale edge case ("edge") — both including a percentage of "orphaned" RSVPs
	 * (the Event/Page/Post deleted while its RSVP ticket + attendees are left behind, which
	 * is the main edge case this whole tool exists to reproduce).
	 *
	 * Total units and the orphan rate are each picked via `wp_rand()` within the scenario's
	 * preset range and logged before generation starts. RSVP tickets per unit, and attendees
	 * per ticket, vary per unit/ticket the same way `generate`'s own min/max options do.
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : Which scenario to generate.
	 * ---
	 * options:
	 *   - usual
	 *   - edge
	 * ---
	 *
	 * [--min-attendees=<number>]
	 * : Minimum attendees per RSVP ticket.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--max-attendees=<number>]
	 * : Maximum attendees per RSVP ticket.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : How many units to generate per internal batch/progress tick.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--with-venues]
	 * : Attach a random generated Venue to each Event unit.
	 *
	 * [--with-organizers]
	 * : Attach a random generated Organizer to each Event unit.
	 *
	 * [--start-date=<date>]
	 * : Earliest date/time (any strtotime()-parsable value) generated events can fall on.
	 * ---
	 * default: now
	 * ---
	 *
	 * [--end-date=<date>]
	 * : Latest date/time generated events can fall on.
	 * ---
	 * default: +2 weeks from --start-date
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator scenario --type=usual
	 *     wp tec-data-generator scenario --type=edge --batch-size=250
	 *     wp tec-data-generator scenario --type=usual --with-venues --with-organizers
	 *
	 * @when after_wp_load
	 */
	public function scenario( $args, $assoc_args ) {
		set_time_limit( 0 );

		$type = $assoc_args['type'] ?? '';

		if ( ! isset( Data::SCENARIOS[ $type ] ) ) {
			\WP_CLI::error( 'Invalid --type. Use one of: ' . implode( ', ', array_keys( Data::SCENARIOS ) ) );
		}

		$preset = Data::SCENARIOS[ $type ];

		$unit_count    = wp_rand( $preset['units_min'], $preset['units_max'] );
		$orphan_rate   = wp_rand( $preset['orphan_min'], $preset['orphan_max'] );
		$min_attendees = max( 1, (int) ( $assoc_args['min-attendees'] ?? 1 ) );
		$max_attendees = max( $min_attendees, (int) ( $assoc_args['max-attendees'] ?? 20 ) );
		$batch_size    = max( 1, (int) ( $assoc_args['batch-size'] ?? 100 ) );

		\WP_CLI::log( "Scenario: {$type}" );
		\WP_CLI::log( "  units (Event/Page/Post):  {$preset['units_min']}-{$preset['units_max']} -> {$unit_count}" );
		\WP_CLI::log( "  RSVP tickets per unit:    {$preset['rsvps_min']}-{$preset['rsvps_max']} (randomized per unit)" );
		\WP_CLI::log( "  attendees per ticket:     {$min_attendees}-{$max_attendees} (randomized per ticket)" );
		\WP_CLI::log( "  orphan rate:              {$preset['orphan_min']}%-{$preset['orphan_max']}% -> {$orphan_rate}%" );

		$run_id    = Data::new_run_id();
		$generator = new Generator( $run_id );
		$options   = [
			'min_attendees'      => $min_attendees,
			'max_attendees'      => $max_attendees,
			'min_rsvps_per_unit' => $preset['rsvps_min'],
			'max_rsvps_per_unit' => $preset['rsvps_max'],
			'with_venues'        => isset( $assoc_args['with-venues'] ),
			'with_organizers'    => isset( $assoc_args['with-organizers'] ),
			'date_start'         => $assoc_args['start-date'] ?? '',
			'date_end'           => $assoc_args['end-date'] ?? '',
		];

		\WP_CLI::log( "Generating {$unit_count} units (run: {$run_id})..." );

		$done           = 0;
		$all_post_ids   = [];
		$ticket_total   = 0;
		$attendee_total = 0;

		while ( $done < $unit_count ) {
			$chunk  = min( $batch_size, $unit_count - $done );
			$result = $generator->generate_batch( $chunk, $done, $options );

			$all_post_ids    = array_merge( $all_post_ids, $result['post_ids'] );
			$ticket_total   += count( $result['ticket_ids'] );
			$attendee_total += count( $result['attendee_ids'] );

			$done += $chunk;

			\WP_CLI::log( "  ...{$done}/{$unit_count} units generated" );
		}

		$orphan_count = (int) round( $unit_count * $orphan_rate / 100 );

		if ( $orphan_count > 0 ) {
			shuffle( $all_post_ids );
			$to_orphan = array_slice( $all_post_ids, 0, $orphan_count );
			$orphaned  = ( new Cleanup() )->orphan_posts( $to_orphan );

			\WP_CLI::log( "Orphaned {$orphaned} container posts (their RSVP tickets/attendees were left in place)." );
		}

		\WP_CLI::success( "Scenario '{$type}' complete: {$unit_count} units, {$ticket_total} RSVP tickets, {$attendee_total} attendees, {$orphan_count} orphaned (run: {$run_id})." );
	}

	/**
	 * Adds RSVP tickets to an event that already exists on the site (generated by this tool or not) —
	 * unlike `generate`/`scenario`, this targets a specific existing event rather than creating new units.
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : The event/post ID to attach RSVP tickets to.
	 *
	 * [--quantity=<number>]
	 * : How many RSVP tickets to add.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--capacity=<number>]
	 * : Capacity per ticket.
	 * ---
	 * default: random (20-200)
	 * ---
	 *
	 * [--stock=<number>]
	 * : Stock per ticket. Defaults to the same value as capacity.
	 *
	 * [--unlimited-capacity]
	 * : Give each ticket unlimited capacity/stock instead of a fixed number.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator add-rsvp 123 --quantity=5
	 *     wp tec-data-generator add-rsvp 123 --quantity=5 --capacity=50
	 *     wp tec-data-generator add-rsvp 123 --quantity=5 --unlimited-capacity
	 *
	 * @subcommand add-rsvp
	 * @when after_wp_load
	 */
	public function add_rsvp( $args, $assoc_args ) {
		$event_id = (int) ( $args[0] ?? 0 );

		if ( ! $event_id || ! get_post( $event_id ) ) {
			\WP_CLI::error( 'Invalid event ID.' );
		}

		$quantity = max( 1, (int) ( $assoc_args['quantity'] ?? 1 ) );
		$options  = $this->parse_ticket_options( $assoc_args );

		$run_id     = Data::new_run_id();
		$generator  = new Generator( $run_id );
		$ticket_ids = $generator->add_rsvp_tickets( $event_id, $quantity, $options );

		\WP_CLI::success( 'Added ' . count( $ticket_ids ) . " RSVP ticket(s) to event {$event_id} (run: {$run_id})." );
	}

	/**
	 * Adds paid tickets (via whatever provider the event already uses — Tickets Commerce, PayPal, etc.)
	 * to an event that already exists on the site. This is the one command in this plugin that creates
	 * non-RSVP tickets, by design — see CLAUDE.md's "Legacy V1 shape only" hard constraint.
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : The event/post ID to attach paid tickets to.
	 *
	 * [--quantity=<number>]
	 * : How many tickets to add.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--capacity=<number>]
	 * : Capacity per ticket.
	 * ---
	 * default: random (20-200)
	 * ---
	 *
	 * [--stock=<number>]
	 * : Stock per ticket. Defaults to the same value as capacity.
	 *
	 * [--unlimited-capacity]
	 * : Give each ticket unlimited capacity/stock instead of a fixed number.
	 *
	 * [--shared-capacity]
	 * : Make the ticket share capacity with other tickets on the same event (global stock mode).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator add-tickets 123 --quantity=5
	 *     wp tec-data-generator add-tickets 123 --quantity=5 --capacity=50 --shared-capacity
	 *
	 * @subcommand add-tickets
	 * @when after_wp_load
	 */
	public function add_tickets( $args, $assoc_args ) {
		$event_id = (int) ( $args[0] ?? 0 );

		if ( ! $event_id || ! get_post( $event_id ) ) {
			\WP_CLI::error( 'Invalid event ID.' );
		}

		$quantity                   = max( 1, (int) ( $assoc_args['quantity'] ?? 1 ) );
		$options                    = $this->parse_ticket_options( $assoc_args );
		$options['shared_capacity'] = isset( $assoc_args['shared-capacity'] );

		$run_id     = Data::new_run_id();
		$generator  = new Generator( $run_id );
		$ticket_ids = $generator->add_paid_tickets( $event_id, $quantity, $options );

		if ( ! $ticket_ids ) {
			\WP_CLI::error( "0 tickets added — either this event's ticket provider is RSVP (paid tickets don't apply), or ticket creation failed." );
		}

		\WP_CLI::success( 'Added ' . count( $ticket_ids ) . " paid ticket(s) to event {$event_id} (run: {$run_id})." );
	}

	/**
	 * Adds attendees to an existing ticket (RSVP or paid) that already exists on the site.
	 *
	 * ## OPTIONS
	 *
	 * <ticket_id>
	 * : The RSVP or paid ticket ID to attach attendees to.
	 *
	 * [--quantity=<number>]
	 * : How many attendees to add.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator add-attendees 456 --quantity=10
	 *
	 * @subcommand add-attendees
	 * @when after_wp_load
	 */
	public function add_attendees( $args, $assoc_args ) {
		$ticket_id = (int) ( $args[0] ?? 0 );

		if ( ! $ticket_id || ! get_post( $ticket_id ) ) {
			\WP_CLI::error( 'Invalid ticket ID.' );
		}

		$quantity = max( 1, (int) ( $assoc_args['quantity'] ?? 1 ) );

		$run_id       = Data::new_run_id();
		$generator    = new Generator( $run_id );
		$attendee_ids = $generator->add_attendees( $ticket_id, $quantity );

		\WP_CLI::success( 'Added ' . count( $attendee_ids ) . " attendee(s) to ticket {$ticket_id} (run: {$run_id})." );
	}

	/**
	 * Runs generate_batch() in --batch-size chunks until $count is reached, logging progress
	 * after each chunk. Shared by generate()/generate_events()/generate_tickets().
	 */
	private function generate_in_chunks( Generator $generator, int $count, int $batch_size, array $options ): void {
		$done = 0;

		try {
			while ( $done < $count ) {
				$chunk = min( $batch_size, $count - $done );

				$generator->generate_batch( $chunk, $done, $options );

				$done += $chunk;

				\WP_CLI::log( "  ...{$done}/{$count} generated" );
			}
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Translates --capacity/--stock/--unlimited-capacity CLI flags into the $options array shape
	 * Generator::add_rsvp_tickets()/add_paid_tickets() expect.
	 *
	 * @return array{capacity?:int,stock?:int,unlimited_capacity?:bool}
	 */
	private function parse_ticket_options( array $assoc_args ): array {
		$options = [];

		if ( isset( $assoc_args['capacity'] ) ) {
			$options['capacity'] = (int) $assoc_args['capacity'];
		}

		if ( isset( $assoc_args['stock'] ) ) {
			$options['stock'] = (int) $assoc_args['stock'];
		}

		if ( isset( $assoc_args['unlimited-capacity'] ) ) {
			$options['unlimited_capacity'] = true;
		}

		return $options;
	}

	/**
	 * Deletes all data previously generated by this plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--run=<run_id>]
	 * : Only clean up a specific run ID. Defaults to all load-gen data.
	 *
	 * [--batch-size=<number>]
	 * : Deletion batch size.
	 * ---
	 * default: 200
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator cleanup
	 *     wp tec-data-generator cleanup --run=run_20260715_153000_ab12cd
	 *
	 * @when after_wp_load
	 */
	public function cleanup( $args, $assoc_args ) {
		set_time_limit( 0 );

		$run_id     = $assoc_args['run'] ?? null;
		$batch_size = max( 1, (int) ( $assoc_args['batch-size'] ?? 200 ) );

		$cleanup = new Cleanup();
		$total   = $cleanup->count_all( $run_id );

		\WP_CLI::log( "Found {$total} generated posts to remove (run filter: " . ( $run_id ?: 'all' ) . ')...' );

		$removed = 0;

		do {
			$n = $cleanup->cleanup_batch( $batch_size, $run_id );
			$removed += $n;

			if ( $n > 0 ) {
				\WP_CLI::log( "  ...removed {$removed}/{$total}" );
			}
		} while ( $n > 0 );

		$remaining = $cleanup->count_all( $run_id );

		if ( $remaining > 0 ) {
			\WP_CLI::error( "Cleanup stopped: {$remaining} record(s) could not be deleted (wp_delete_post() failed for all of them, likely blocked by another plugin). Removed {$removed}/{$total}." );
		}

		\WP_CLI::success( 'Cleanup complete.' );
	}

	/**
	 * Runs the rsvp-to-tc migration forward (legacy V1 RSVP -> Tickets Commerce).
	 *
	 * Schedules every batch via Shepherd/Action Scheduler; the actual work happens
	 * in the background, not synchronously within this command.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator migrate
	 *
	 * @when after_wp_load
	 */
	public function migrate( $args, $assoc_args ) {
		$result = ( new Migration() )->run();

		if ( ! $result['success'] ) {
			\WP_CLI::error( $result['message'] );
		}

		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Reverts the rsvp-to-tc migration (Tickets Commerce -> legacy V1 RSVP), so the
	 * same generated data can be migrated again without regenerating it.
	 *
	 * Schedules every batch via Shepherd/Action Scheduler; the actual work happens
	 * in the background, not synchronously within this command.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tec-data-generator revert
	 *
	 * @when after_wp_load
	 */
	public function revert( $args, $assoc_args ) {
		$result = ( new Migration() )->revert();

		if ( ! $result['success'] ) {
			\WP_CLI::error( $result['message'] );
		}

		\WP_CLI::success( $result['message'] );
	}
}
