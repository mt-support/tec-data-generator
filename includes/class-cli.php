<?php
/**
 * `wp rsvp-loadgen` command: bulk-generate or clean up legacy V1 RSVP load-test data.
 */

namespace RSVP_Loadgen;

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
	 * ## EXAMPLES
	 *
	 *     wp rsvp-loadgen generate --count=5000
	 *     wp rsvp-loadgen generate --count=200 --min-attendees=5 --max-attendees=50
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
			'min_attendees' => $min_attendees,
			'max_attendees' => $max_attendees,
		];

		\WP_CLI::log( "Starting generation of {$count} units (run: {$run_id})..." );

		$done = 0;

		while ( $done < $count ) {
			$chunk = min( $batch_size, $count - $done );

			$generator->generate_batch( $chunk, $done, $options );

			$done += $chunk;

			\WP_CLI::log( "  ...{$done}/{$count} generated" );
		}

		\WP_CLI::success( "Generated {$count} tickets with attendees (run: {$run_id})." );
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
	 * ## EXAMPLES
	 *
	 *     wp rsvp-loadgen scenario --type=usual
	 *     wp rsvp-loadgen scenario --type=edge --batch-size=250
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
	 *     wp rsvp-loadgen cleanup
	 *     wp rsvp-loadgen cleanup --run=run_20260715_153000_ab12cd
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
	 *     wp rsvp-loadgen migrate
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
	 *     wp rsvp-loadgen revert
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
