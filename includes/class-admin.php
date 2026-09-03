<?php
/**
 * Tools > RSVP Load Generator admin page.
 */

namespace RSVP_Loadgen;

class Admin {

	const PAGE_SLUG = 'rsvp-loadgen';

	public function hook(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_page(): void {
		add_management_page(
			__( 'RSVP Load Generator', 'rsvp-migration-loadgen' ),
			__( 'RSVP Load Generator', 'rsvp-migration-loadgen' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'rsvp-loadgen-admin',
			plugins_url( 'assets/admin.js', RSVP_LOADGEN_FILE ),
			[],
			RSVP_LOADGEN_VERSION,
			true
		);

		wp_enqueue_style(
			'rsvp-loadgen-admin',
			plugins_url( 'assets/admin.css', RSVP_LOADGEN_FILE ),
			[],
			RSVP_LOADGEN_VERSION
		);

		wp_localize_script( 'rsvp-loadgen-admin', 'RSVPLoadgen', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( Ajax::NONCE_ACTION ),
			'chunkSize' => 75,
			'actions'   => [
				'generate'         => Ajax::ACTION_GENERATE,
				'scheduleScenario' => Ajax::ACTION_SCHEDULE_SCENARIO,
				'scenarioStatus'   => Ajax::ACTION_SCENARIO_STATUS,
				'cleanup'          => Ajax::ACTION_CLEANUP,
				'status'           => Ajax::ACTION_STATUS,
				'runMigration'     => Ajax::ACTION_RUN_MIGRATION,
				'revertMigration'  => Ajax::ACTION_REVERT_MIGRATION,
				'migrationStatus'  => Ajax::ACTION_MIGRATION_STATUS,
				'addRsvp'          => Ajax::ACTION_ADD_RSVP,
				'addTickets'       => Ajax::ACTION_ADD_TICKETS,
				'addAttendees'     => Ajax::ACTION_ADD_ATTENDEES,
			],
		] );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$counts    = ( new Cleanup() )->count_by_type();
		$migration = new Migration();

		include __DIR__ . '/../views/admin-page.php';
	}
}
