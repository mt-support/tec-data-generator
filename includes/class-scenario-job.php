<?php
/**
 * Runs a `scenario` (usual/edge) generation entirely in the background via Action Scheduler,
 * so the admin page never has to keep a browser tab open or chunk requests through it — it
 * only has to poll status. State for the single active job lives in one WP option (not a
 * transient, so a long-running "edge" job can never expire mid-run).
 */

namespace RSVP_Loadgen;

class Scenario_Job {

	const OPTION_KEY = 'rsvp_loadgen_scenario_job';
	const AS_HOOK     = 'rsvp_loadgen_process_scenario_chunk';
	const AS_GROUP    = 'rsvp-loadgen';
	const CHUNK_SIZE  = 100;

	public function hook(): void {
		add_action( self::AS_HOOK, [ $this, 'process_chunk' ] );
	}

	/**
	 * Whether Action Scheduler (bundled by Event Tickets/The Events Calendar) is loaded.
	 */
	public static function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get(): ?array {
		$job = get_option( self::OPTION_KEY, null );

		return is_array( $job ) ? $job : null;
	}

	public static function is_running(): bool {
		$job = self::get();

		return is_array( $job ) && 'running' === $job['status'];
	}

	/**
	 * Starts a new scenario job: resolves concrete unit count + orphan rate via `wp_rand()`
	 * within the preset's range, persists the job, and enqueues the first chunk. Returns
	 * immediately — the actual generation happens across many background Action Scheduler runs.
	 *
	 * @return array{success:bool,message?:string,job?:array<string,mixed>}
	 */
	public function start( string $type, int $min_attendees, int $max_attendees ): array {
		if ( ! self::is_available() ) {
			return [
				'success' => false,
				'message' => __( 'Action Scheduler is not available on this site. Run this via WP-CLI instead: wp rsvp-loadgen scenario --type=...', 'rsvp-migration-loadgen' ),
			];
		}

		if ( ! isset( Data::SCENARIOS[ $type ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Invalid scenario type.', 'rsvp-migration-loadgen' ),
			];
		}

		if ( self::is_running() ) {
			$job = self::get();

			return [
				'success' => false,
				/* translators: %s: run ID of the scenario already in progress */
				'message' => sprintf( __( 'A scenario is already running (run: %s). Wait for it to finish first.', 'rsvp-migration-loadgen' ), $job['run_id'] ?? '?' ),
			];
		}

		$preset = Data::SCENARIOS[ $type ];

		$job = [
			'run_id'         => Data::new_run_id(),
			'type'           => $type,
			'status'         => 'running',
			'total'          => wp_rand( $preset['units_min'], $preset['units_max'] ),
			'orphan_rate'    => wp_rand( $preset['orphan_min'], $preset['orphan_max'] ),
			'min_rsvps'      => $preset['rsvps_min'],
			'max_rsvps'      => $preset['rsvps_max'],
			'min_attendees'  => $min_attendees,
			'max_attendees'  => $max_attendees,
			'done'           => 0,
			'post_ids'       => [],
			'ticket_total'   => 0,
			'attendee_total' => 0,
			'orphaned'       => null,
			'error'          => null,
		];

		self::save( $job );

		as_enqueue_async_action( self::AS_HOOK, [], self::AS_GROUP );

		return [ 'success' => true, 'job' => $job ];
	}

	/**
	 * The Action Scheduler callback: generates one chunk, reschedules itself if unfinished,
	 * or orphans the resolved percentage and marks the job complete once generation is done.
	 * Any exception marks the job failed instead of endlessly rescheduling.
	 */
	public function process_chunk(): void {
		$job = self::get();

		if ( ! is_array( $job ) || 'running' !== $job['status'] ) {
			return;
		}

		try {
			$remaining = max( 0, $job['total'] - $job['done'] );
			$chunk     = min( self::CHUNK_SIZE, $remaining );

			if ( $chunk > 0 ) {
				$generator = new Generator( $job['run_id'] );
				$result    = $generator->generate_batch( $chunk, $job['done'], [
					'min_attendees'      => $job['min_attendees'],
					'max_attendees'      => $job['max_attendees'],
					'min_rsvps_per_unit' => $job['min_rsvps'],
					'max_rsvps_per_unit' => $job['max_rsvps'],
				] );

				$job['post_ids']       = array_merge( $job['post_ids'], $result['post_ids'] );
				$job['ticket_total']  += count( $result['ticket_ids'] );
				$job['attendee_total'] += count( $result['attendee_ids'] );
				$job['done']          += $chunk;
			}

			if ( $job['done'] < $job['total'] ) {
				self::save( $job );
				as_enqueue_async_action( self::AS_HOOK, [], self::AS_GROUP );

				return;
			}

			$orphan_count = (int) round( $job['total'] * $job['orphan_rate'] / 100 );

			if ( $orphan_count > 0 ) {
				$to_orphan = $job['post_ids'];
				shuffle( $to_orphan );
				$to_orphan = array_slice( $to_orphan, 0, $orphan_count );

				$job['orphaned'] = ( new Cleanup() )->orphan_posts( $to_orphan );
			} else {
				$job['orphaned'] = 0;
			}

			$job['status'] = 'completed';
			self::save( $job );
		} catch ( \Throwable $e ) {
			$job['status'] = 'failed';
			$job['error']  = $e->getMessage();
			self::save( $job );
		}
	}

	private static function save( array $job ): void {
		update_option( self::OPTION_KEY, $job, false );
	}
}
