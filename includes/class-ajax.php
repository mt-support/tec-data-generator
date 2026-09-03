<?php
/**
 * Chunked AJAX handlers backing the admin page's Generate/Cleanup buttons.
 *
 * Both actions do a small amount of work per request (default chunk size 75) so a
 * single request never risks hitting `max_execution_time`; the client-side loop in
 * assets/admin.js keeps calling until the response reports `finished: true`.
 */

namespace RSVP_Loadgen;

class Ajax {

	const NONCE_ACTION = 'rsvp_loadgen_ajax';

	const ACTION_GENERATE = 'rsvp_loadgen_generate_batch';
	const ACTION_CLEANUP  = 'rsvp_loadgen_cleanup_batch';
	const ACTION_STATUS   = 'rsvp_loadgen_status';

	const ACTION_SCHEDULE_SCENARIO = 'rsvp_loadgen_schedule_scenario';
	const ACTION_SCENARIO_STATUS   = 'rsvp_loadgen_scenario_status';

	const ACTION_RUN_MIGRATION      = 'rsvp_loadgen_run_migration';
	const ACTION_REVERT_MIGRATION   = 'rsvp_loadgen_revert_migration';
	const ACTION_MIGRATION_STATUS   = 'rsvp_loadgen_migration_status';

	const ACTION_ADD_RSVP      = 'rsvp_loadgen_add_rsvp_batch';
	const ACTION_ADD_TICKETS   = 'rsvp_loadgen_add_tickets_batch';
	const ACTION_ADD_ATTENDEES = 'rsvp_loadgen_add_attendees_batch';

	const TRANSIENT_PREFIX = 'rsvp_loadgen_run_';
	const MAX_CHUNK_SIZE   = 100;

	public function hook(): void {
		add_action( 'wp_ajax_' . self::ACTION_GENERATE, [ $this, 'handle_generate' ] );
		add_action( 'wp_ajax_' . self::ACTION_SCHEDULE_SCENARIO, [ $this, 'handle_schedule_scenario' ] );
		add_action( 'wp_ajax_' . self::ACTION_SCENARIO_STATUS, [ $this, 'handle_scenario_status' ] );
		add_action( 'wp_ajax_' . self::ACTION_CLEANUP, [ $this, 'handle_cleanup' ] );
		add_action( 'wp_ajax_' . self::ACTION_STATUS, [ $this, 'handle_status' ] );
		add_action( 'wp_ajax_' . self::ACTION_RUN_MIGRATION, [ $this, 'handle_run_migration' ] );
		add_action( 'wp_ajax_' . self::ACTION_REVERT_MIGRATION, [ $this, 'handle_revert_migration' ] );
		add_action( 'wp_ajax_' . self::ACTION_MIGRATION_STATUS, [ $this, 'handle_migration_status' ] );
		add_action( 'wp_ajax_' . self::ACTION_ADD_RSVP, [ $this, 'handle_add_rsvp' ] );
		add_action( 'wp_ajax_' . self::ACTION_ADD_TICKETS, [ $this, 'handle_add_tickets' ] );
		add_action( 'wp_ajax_' . self::ACTION_ADD_ATTENDEES, [ $this, 'handle_add_attendees' ] );
	}

	public function handle_generate(): void {
		$this->verify_request();

		$total         = max( 1, (int) ( $_POST['total'] ?? 0 ) );
		$min_attendees = max( 1, (int) ( $_POST['min_attendees'] ?? 1 ) );
		$max_attendees = max( $min_attendees, (int) ( $_POST['max_attendees'] ?? 20 ) );
		$chunk_size    = min( self::MAX_CHUNK_SIZE, max( 1, (int) ( $_POST['chunk_size'] ?? 75 ) ) );

		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$state  = $run_id ? get_transient( self::TRANSIENT_PREFIX . $run_id ) : false;

		if ( ! is_array( $state ) ) {
			$run_id = Data::new_run_id();
			$state  = [
				'total'           => $total,
				'done'            => 0,
				'min_attendees'   => $min_attendees,
				'max_attendees'   => $max_attendees,
				'with_venues'     => ! empty( $_POST['with_venues'] ),
				'with_organizers' => ! empty( $_POST['with_organizers'] ),
			];
		}

		$remaining = max( 0, $state['total'] - $state['done'] );
		$this_run  = min( $chunk_size, $remaining );

		if ( $this_run > 0 ) {
			$generator = new Generator( $run_id );
			$generator->generate_batch( $this_run, $state['done'], [
				'min_attendees'   => $state['min_attendees'],
				'max_attendees'   => $state['max_attendees'],
				'with_venues'     => $state['with_venues'] ?? false,
				'with_organizers' => $state['with_organizers'] ?? false,
			] );

			$state['done'] += $this_run;
		}

		set_transient( self::TRANSIENT_PREFIX . $run_id, $state, HOUR_IN_SECONDS );

		wp_send_json_success( [
			'run_id'   => $run_id,
			'done'     => $state['done'],
			'total'    => $state['total'],
			'finished' => $state['done'] >= $state['total'],
		] );
	}

	/**
	 * Schedules a scenario as a background Action Scheduler job (see Scenario_Job) and returns
	 * immediately with the resolved unit count/orphan rate — the admin page only has to poll
	 * handle_scenario_status() afterward, it never drives the generation itself.
	 */
	public function handle_schedule_scenario(): void {
		$this->verify_request();

		$type          = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$min_attendees = max( 1, (int) ( $_POST['min_attendees'] ?? 1 ) );
		$max_attendees = max( $min_attendees, (int) ( $_POST['max_attendees'] ?? 20 ) );

		$result = ( new Scenario_Job() )->start(
			$type,
			$min_attendees,
			$max_attendees,
			! empty( $_POST['with_venues'] ),
			! empty( $_POST['with_organizers'] )
		);

		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
		}

		wp_send_json_success( $this->scenario_job_response( $result['job'] ) );
	}

	public function handle_scenario_status(): void {
		$this->verify_request();

		$job = Scenario_Job::get();

		if ( ! $job ) {
			wp_send_json_success( [ 'status' => 'idle' ] );
		}

		wp_send_json_success( $this->scenario_job_response( $job ) );
	}

	/**
	 * @param array<string,mixed> $job
	 *
	 * @return array<string,mixed>
	 */
	private function scenario_job_response( array $job ): array {
		return [
			'run_id'         => $job['run_id'],
			'type'           => $job['type'],
			'status'         => $job['status'],
			'done'           => $job['done'],
			'total'          => $job['total'],
			'orphan_rate'    => $job['orphan_rate'],
			'orphaned'       => $job['orphaned'],
			'ticket_total'   => $job['ticket_total'],
			'attendee_total' => $job['attendee_total'],
			'error'          => $job['error'],
		];
	}

	public function handle_cleanup(): void {
		$this->verify_request();

		$chunk_size = min( 200, max( 1, (int) ( $_POST['chunk_size'] ?? 100 ) ) );

		$cleanup   = new Cleanup();
		$removed   = $cleanup->cleanup_batch( $chunk_size );
		$remaining = $cleanup->count_all();

		// Stall guard: if a batch deletes nothing yet records remain, `wp_delete_post()` is
		// failing for them (a plugin's `pre_delete_post` filter, or similar) — stop here with
		// an error instead of the client looping on an unchanging "26 remaining" forever.
		if ( 0 === $removed && $remaining > 0 ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %d: number of records that could not be deleted */
					__( 'Cleanup stalled: %d generated record(s) could not be deleted (wp_delete_post() failed for all of them, likely blocked by another plugin). Check the site error log.', 'rsvp-migration-loadgen' ),
					$remaining
				),
				'remaining' => $remaining,
			] );
		}

		wp_send_json_success( [
			'removed_this_batch' => $removed,
			'remaining'          => $remaining,
			'finished'           => 0 === $remaining,
		] );
	}

	public function handle_status(): void {
		$this->verify_request();

		wp_send_json_success( ( new Cleanup() )->count_by_type() );
	}

	public function handle_run_migration(): void {
		$this->verify_request();

		$this->send_migration_result( ( new Migration() )->run() );
	}

	public function handle_revert_migration(): void {
		$this->verify_request();

		$this->send_migration_result( ( new Migration() )->revert() );
	}

	private function send_migration_result( array $result ): void {
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	public function handle_migration_status(): void {
		$this->verify_request();

		$migration = new Migration();

		wp_send_json_success( [
			'label'     => $migration->get_status_label(),
			'can_run'   => $migration->can_run(),
			'can_revert' => $migration->can_revert(),
		] );
	}

	public function handle_add_rsvp(): void {
		$this->verify_request();

		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		$quantity = min( self::MAX_CHUNK_SIZE, max( 1, (int) ( $_POST['quantity'] ?? 1 ) ) );

		if ( ! $event_id || ! get_post( $event_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid Event ID.', 'rsvp-migration-loadgen' ) ] );
		}

		$run_id     = Data::new_run_id();
		$generator  = new Generator( $run_id );
		$ticket_ids = $generator->add_rsvp_tickets( $event_id, $quantity );

		wp_send_json_success( [ 'created' => count( $ticket_ids ), 'run_id' => $run_id ] );
	}

	public function handle_add_tickets(): void {
		$this->verify_request();

		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		$quantity = min( self::MAX_CHUNK_SIZE, max( 1, (int) ( $_POST['quantity'] ?? 1 ) ) );

		if ( ! $event_id || ! get_post( $event_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid Event ID.', 'rsvp-migration-loadgen' ) ] );
		}

		$run_id     = Data::new_run_id();
		$generator  = new Generator( $run_id );
		$ticket_ids = $generator->add_paid_tickets( $event_id, $quantity );

		if ( ! $ticket_ids ) {
			wp_send_json_error( [ 'message' => __( "0 tickets added — either this event's ticket provider is RSVP (paid tickets don't apply), or ticket creation failed. Check the site error log if you expected a paid provider here.", 'rsvp-migration-loadgen' ) ] );
		}

		wp_send_json_success( [ 'created' => count( $ticket_ids ), 'run_id' => $run_id ] );
	}

	public function handle_add_attendees(): void {
		$this->verify_request();

		$ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
		$quantity  = min( self::MAX_CHUNK_SIZE, max( 1, (int) ( $_POST['quantity'] ?? 1 ) ) );

		if ( ! $ticket_id || ! get_post( $ticket_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid Ticket ID.', 'rsvp-migration-loadgen' ) ] );
		}

		$run_id       = Data::new_run_id();
		$generator    = new Generator( $run_id );
		$attendee_ids = $generator->add_attendees( $ticket_id, $quantity );

		wp_send_json_success( [ 'created' => count( $attendee_ids ), 'run_id' => $run_id ] );
	}

	private function verify_request(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
	}
}
