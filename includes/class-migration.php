<?php
/**
 * Thin wrapper around the stellarwp/migrations framework for the `rsvp-to-tc`
 * migration, so QA can trigger a full run/revert from this plugin's own admin
 * page or CLI command, without needing WP-CLI's `wp tec migrations ...` or the
 * hidden Migrations admin submenu.
 */

namespace RSVP_Loadgen;

use TEC\Common\StellarWP\Migrations\Enums\Operation;
use TEC\Common\StellarWP\Migrations\Enums\Status;

class Migration {

	const MIGRATION_ID = 'rsvp-to-tc';

	/**
	 * @return \TEC\Common\StellarWP\Migrations\Contracts\Migration|null
	 */
	private function get_migration() {
		if ( ! function_exists( '\TEC\Common\StellarWP\Migrations\migrations' ) ) {
			return null;
		}

		return \TEC\Common\StellarWP\Migrations\migrations()->get_registry()->get( self::MIGRATION_ID );
	}

	public function is_available(): bool {
		return null !== $this->get_migration();
	}

	public function get_status_label(): string {
		$migration = $this->get_migration();

		return $migration
			? $migration->get_status()->get_label()
			: __( 'Migration framework unavailable', 'rsvp-migration-loadgen' );
	}

	/**
	 * Whether the migration can be run forward (V1 RSVP -> Tickets Commerce) right now.
	 * Mirrors the same guard the core Migrations admin UI uses for its "Run" button.
	 */
	public function can_run(): bool {
		$migration = $this->get_migration();

		if ( ! $migration ) {
			return false;
		}

		$runnable = [
			Status::COMPLETED()->getValue(),
			Status::PENDING()->getValue(),
			Status::CANCELED()->getValue(),
			Status::FAILED()->getValue(),
			Status::REVERTED()->getValue(),
		];

		return in_array( $migration->get_status()->getValue(), $runnable, true )
			&& $migration->get_total_items( Operation::UP() ) > 0;
	}

	/**
	 * Whether the migration can be rolled back (Tickets Commerce -> V1 RSVP) right now.
	 * Mirrors the same guard the core Migrations admin UI uses for its "Rollback" button.
	 */
	public function can_revert(): bool {
		$migration = $this->get_migration();

		if ( ! $migration ) {
			return false;
		}

		$rollbackable = [
			Status::COMPLETED()->getValue(),
			Status::CANCELED()->getValue(),
			Status::FAILED()->getValue(),
		];

		return $migration->is_applicable()
			&& in_array( $migration->get_status()->getValue(), $rollbackable, true )
			&& $migration->get_total_items( Operation::DOWN() ) > 0;
	}

	/**
	 * Schedules the full forward migration (every batch), same as a "Run" from the
	 * core Migrations UI. Runs in the background via Shepherd/Action Scheduler.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function run(): array {
		return $this->schedule_full(
			Operation::UP(),
			'can_run',
			__( 'Migration is not currently in a runnable state.', 'rsvp-migration-loadgen' )
		);
	}

	/**
	 * Schedules the full rollback (every batch), same as a "Rollback" from the
	 * core Migrations UI. Runs in the background via Shepherd/Action Scheduler.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function revert(): array {
		return $this->schedule_full(
			Operation::DOWN(),
			'can_revert',
			__( 'Migration is not currently in a revertible state.', 'rsvp-migration-loadgen' )
		);
	}

	private function schedule_full( Operation $operation, string $guard_method, string $guard_message ): array {
		$migration = $this->get_migration();

		if ( ! $migration ) {
			return [
				'success' => false,
				'message' => __( 'The migrations framework is not available.', 'rsvp-migration-loadgen' ),
			];
		}

		if ( ! $this->{$guard_method}() ) {
			return [ 'success' => false, 'message' => $guard_message ];
		}

		$batch_size    = $migration->get_default_batch_size();
		$total_batches = max( 1, $migration->get_total_batches( $batch_size, $operation ) );

		try {
			\TEC\Common\StellarWP\Migrations\migrations()->schedule( $migration, $operation, 1, $total_batches, $batch_size );
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => $e->getMessage() ];
		}

		return [
			'success' => true,
			/* translators: %s: migration operation label, e.g. "Up" or "Down" */
			'message' => sprintf( __( 'Migration "%s" scheduled and running in the background.', 'rsvp-migration-loadgen' ), $operation->get_label() ),
		];
	}
}
