<?php
/**
 * Tools > TEC Data Generator admin page.
 */

namespace TEC\DataGenerator;

class Admin {

	const PAGE_SLUG = 'tec-data-generator';

	public function hook(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_page(): void {
		add_management_page(
			__( 'TEC Data Generator', 'tec-data-generator' ),
			__( 'TEC Data Generator', 'tec-data-generator' ),
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
			'tec-data-generator-admin',
			plugins_url( 'assets/admin.js', TEC_DATA_GENERATOR_FILE ),
			[],
			TEC_DATA_GENERATOR_VERSION,
			true
		);

		wp_enqueue_style(
			'tec-data-generator-admin',
			plugins_url( 'assets/admin.css', TEC_DATA_GENERATOR_FILE ),
			[],
			TEC_DATA_GENERATOR_VERSION
		);

		// TEC admin variables (colors/spacers) our stylesheet references, with CSS fallbacks
		// when Event Tickets isn't active. Guarded so this never fatals on a bare site.
		if ( function_exists( 'wp_style_is' ) && wp_style_is( 'tribe-common-admin', 'registered' ) ) {
			wp_enqueue_style( 'tribe-common-admin' );
		}

		wp_localize_script( 'tec-data-generator-admin', 'TecDataGenerator', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( Ajax::NONCE_ACTION ),
			'chunkSize' => 75,
			'actions'   => [
				'generate'         => Ajax::ACTION_GENERATE,
				'generateEvents'   => Ajax::ACTION_GENERATE_EVENTS,
				'generateTickets'  => Ajax::ACTION_GENERATE_TICKETS,
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

		$plugin_availability = [
			'has_event_tickets' => Plugin_Availability::has_event_tickets(),
			'has_events_pro'    => Plugin_Availability::has_events_pro_or_ecp(),
			'has_ecp'           => Plugin_Availability::has_ecp(),
		];

		// The block editor gates ticket blocks on the stored option directly (it never
		// sees this plugin's runtime tribe_tickets_post_types filter), so warn when
		// Page/Post support isn't enabled in Event Tickets settings.
		$ticket_enabled_types = function_exists( 'tribe_get_option' )
			? (array) tribe_get_option( 'ticket-enabled-post-types', [] )
			: [];
		$missing_ticket_types = array_values( array_diff( [ 'page', 'post' ], $ticket_enabled_types ) );

		include __DIR__ . '/../views/admin-page.php';
	}
}
