<?php
/**
 * Admin page markup for Tools > TEC Data Generator.
 *
 * @var array<string,int>       $counts    Current generated-record counts, keyed by kind.
 * @var \TEC\DataGenerator\Migration $migration Wrapper around the rsvp-to-tc migration.
 * @var array<string,bool>      $plugin_availability Which optional dependency plugins are active.
 * @var array<int,array{name:string,status:string,status_label:string,version:string}> $plugin_statuses Name, status, and version of each related plugin.
 * @var string[]                $missing_ticket_types Page/Post types missing from the stored ticket-enabled list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap tec-data-generator-wrap">
	<h1><?php esc_html_e( 'TEC Data Generator', 'tec-data-generator' ); ?></h1>

	<p>
		<strong><?php esc_html_e( 'For large runs (1000+), prefer WP-CLI:', 'tec-data-generator' ); ?></strong>
		<code>wp tec-data-generator generate --count=5000</code> &mdash;
		<?php esc_html_e( 'the admin UI below is chunked but slower and tab-bound.', 'tec-data-generator' ); ?>
	</p>
	<?php if ( ! empty( $missing_ticket_types ) ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: %s: comma-separated list of post types, e.g. "Post" or "Page, Post". */
				esc_html__( 'Heads up: %s is not enabled for tickets in Event Tickets settings, so the Tickets/RSVP blocks will not appear in the block editor for it. Enable it under Event Tickets > Settings > Ticket-enabled post types. Generated data is unaffected (the CLI/admin generation path adds its own runtime support).', 'tec-data-generator' ),
				esc_html( implode( ', ', array_map( 'ucfirst', $missing_ticket_types ) ) )
			);
			?>
		</p>
	</div>
	<?php endif; ?>
	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Plugin status', 'tec-data-generator' ); ?></h2>
		<table class="widefat striped" style="max-width: 640px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin', 'tec-data-generator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tec-data-generator' ); ?></th>
					<th><?php esc_html_e( 'Version', 'tec-data-generator' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $plugin_statuses as $plugin ) : ?>
				<tr>
					<td><?php echo esc_html( $plugin['name'] ); ?></td>
					<td><?php echo esc_html( $plugin['status_label'] ); ?></td>
					<td><?php echo '' !== $plugin['version'] ? esc_html( $plugin['version'] ) : '&mdash;'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Currently generated', 'tec-data-generator' ); ?></h2>
		<table class="widefat striped" style="max-width: 480px;" id="tec-data-generator-counts">
			<tbody>
			<?php
			// Ticket/attendee post types have no edit.php list screen (registered
			// show_ui => false; WordPress core's edit.php hard-dies on those), so they
			// aren't linkable here — only post types with a real list table are.
			$post_type_map = [
				'event'     => 'tribe_events',
				'page'      => 'page',
				'post'      => 'post',
				'series'    => 'tribe_event_series',
				'venue'     => 'tribe_venue',
				'organizer' => 'tribe_organizer',
			];
			?>
			<?php foreach ( $counts as $kind => $count ) : ?>
				<tr>
					<td><?php echo esc_html( ucfirst( $kind ) ); ?></td>
					<td data-kind="<?php echo esc_attr( $kind ); ?>">
						<?php
						if ( $count > 0 && isset( $post_type_map[ $kind ] ) ) {
							$edit_url = add_query_arg( 'tec_generated_data', '1', admin_url( 'edit.php?post_type=' . $post_type_map[ $kind ] ) );
							echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
						} else {
							echo esc_html( number_format_i18n( $count ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Scenario (recommended)', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Generates one of two pre-defined QA scenarios, including a percentage of "orphaned" RSVPs — an Event/Page/Post deleted while its RSVP ticket(s) and attendees are left behind. This is the main edge case this tool exists to reproduce.', 'tec-data-generator' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Runs entirely in the background (via Action Scheduler), same as the migration controls below — feel free to navigate away or close this tab once started. Progress resumes automatically when you come back to this page.', 'tec-data-generator' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><label for="tec-data-generator-scenario-type"><?php esc_html_e( 'Scenario', 'tec-data-generator' ); ?></label></th>
				<td>
					<select id="tec-data-generator-scenario-type">
						<option value="usual"><?php esc_html_e( 'Usual (25–250 events, 1–3 RSVPs each, 5%–20% orphaned)', 'tec-data-generator' ); ?></option>
						<option value="edge"><?php esc_html_e( 'Edge case (7,000–11,000 events, 1–9 RSVPs each, 5%–20% orphaned — can take a while)', 'tec-data-generator' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Attach', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="checkbox" id="tec-data-generator-scenario-with-venues"> <?php esc_html_e( 'Venues', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="checkbox" id="tec-data-generator-scenario-with-organizers"> <?php esc_html_e( 'Organizers', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Date range', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'From', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-scenario-start-date"></label>
					<label><?php esc_html_e( 'To', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-scenario-end-date"></label>
					<p class="description"><?php esc_html_e( 'Defaults to today through two weeks out.', 'tec-data-generator' ); ?></p>
				</td>
			</tr>
		</table>
		<p>
			<button type="button" class="button button-primary" id="tec-data-generator-scenario-btn">
				<?php esc_html_e( 'Generate scenario', 'tec-data-generator' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="tec-data-generator-scenario-cancel-btn" style="margin-left: 10px;" hidden>
				<?php esc_html_e( 'Cancel scenario', 'tec-data-generator' ); ?>
			</button>
			<span class="spinner tec-data-generator-spinner" id="tec-data-generator-scenario-spinner"></span>
		</p>
		<div id="tec-data-generator-scenario-progress" class="tec-data-generator-progress" hidden>
			<div class="tec-data-generator-progress-bar"><div class="tec-data-generator-progress-fill"></div></div>
			<p class="tec-data-generator-progress-label"></p>
		</div>
		<div id="tec-data-generator-scenario-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Add to existing content', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Attach RSVP tickets, paid tickets, or attendees to an event/ticket that already exists on this site (generated by this tool or not).', 'tec-data-generator' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><label for="tec-data-generator-addrsvp-event-id"><?php esc_html_e( 'Add RSVP tickets — Event ID', 'tec-data-generator' ); ?></label></th>
				<td>
					<input type="number" id="tec-data-generator-addrsvp-event-id" min="1" step="1" class="small-text">
					<label><?php esc_html_e( 'Quantity', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-addrsvp-quantity" value="1" min="1" step="1" class="small-text"></label>
					<button type="button" class="button" id="tec-data-generator-addrsvp-btn"><?php esc_html_e( 'Add RSVP tickets', 'tec-data-generator' ); ?></button>
					<span class="spinner tec-data-generator-spinner" id="tec-data-generator-addrsvp-spinner"></span>
				</td>
			</tr>
			<tr>
				<th><label for="tec-data-generator-addtickets-event-id"><?php esc_html_e( 'Add paid tickets — Event ID', 'tec-data-generator' ); ?></label></th>
				<td>
					<input type="number" id="tec-data-generator-addtickets-event-id" min="1" step="1" class="small-text">
					<label><?php esc_html_e( 'Quantity', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-addtickets-quantity" value="1" min="1" step="1" class="small-text"></label>
					<button type="button" class="button" id="tec-data-generator-addtickets-btn"><?php esc_html_e( 'Add paid tickets', 'tec-data-generator' ); ?></button>
					<span class="spinner tec-data-generator-spinner" id="tec-data-generator-addtickets-spinner"></span>
				</td>
			</tr>
			<tr>
				<th><label for="tec-data-generator-addattendees-ticket-id"><?php esc_html_e( 'Add attendees — Ticket ID', 'tec-data-generator' ); ?></label></th>
				<td>
					<input type="number" id="tec-data-generator-addattendees-ticket-id" min="1" step="1" class="small-text">
					<label><?php esc_html_e( 'Quantity', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-addattendees-quantity" value="1" min="1" step="1" class="small-text"></label>
					<button type="button" class="button" id="tec-data-generator-addattendees-btn"><?php esc_html_e( 'Add attendees', 'tec-data-generator' ); ?></button>
					<span class="spinner tec-data-generator-spinner" id="tec-data-generator-addattendees-spinner"></span>
				</td>
			</tr>
		</table>
		<div id="tec-data-generator-addon-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Generate Events (containers only, no tickets)', 'tec-data-generator' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="tec-data-generator-events-total"><?php esc_html_e( 'Total events', 'tec-data-generator' ); ?></label></th>
				<td><input type="number" id="tec-data-generator-events-total" value="100" min="1" step="1" class="small-text"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Container', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="radio" name="tec-data-generator-events-container" value="event" checked> <?php esc_html_e( 'Event', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Editor', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="radio" name="tec-data-generator-events-editor" value="classic" checked> <?php esc_html_e( 'Classic', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="tec-data-generator-events-editor" value="block"> <?php esc_html_e( 'Block', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Attach', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="checkbox" id="tec-data-generator-events-with-venues"> <?php esc_html_e( 'Venues', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="checkbox" id="tec-data-generator-events-with-organizers"> <?php esc_html_e( 'Organizers', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Event types', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="checkbox" class="tec-data-generator-events-event-type" value="single" checked> <?php esc_html_e( 'Single', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label title="<?php esc_attr_e( 'Requires Events Pro or ECP plugin', 'tec-data-generator' ); ?>"><input type="checkbox" class="tec-data-generator-events-event-type" value="recurring" <?php disabled( empty( $plugin_availability['has_events_pro'] ) ); ?>> <?php esc_html_e( 'Recurring', 'tec-data-generator' ); ?><?php if ( empty( $plugin_availability['has_events_pro'] ) ) : ?> (<?php esc_html_e( 'requires Events Pro or ECP', 'tec-data-generator' ); ?>)<?php endif; ?></label>
					&nbsp;&nbsp;
					<label title="<?php esc_attr_e( 'Requires ECP plugin', 'tec-data-generator' ); ?>"><input type="checkbox" class="tec-data-generator-events-event-type" value="virtual" <?php disabled( empty( $plugin_availability['has_ecp'] ) ); ?>> <?php esc_html_e( 'Virtual', 'tec-data-generator' ); ?><?php if ( empty( $plugin_availability['has_ecp'] ) ) : ?> (<?php esc_html_e( 'requires ECP', 'tec-data-generator' ); ?>)<?php endif; ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Date range', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'From', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-events-start-date"></label>
					<label><?php esc_html_e( 'To', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-events-end-date"></label>
					<p class="description"><?php esc_html_e( 'Defaults to today through two weeks out.', 'tec-data-generator' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" class="button button-primary" id="tec-data-generator-events-generate-btn">
				<?php esc_html_e( 'Generate events', 'tec-data-generator' ); ?>
			</button>
			<span class="spinner tec-data-generator-spinner" id="tec-data-generator-events-spinner"></span>
		</p>

		<div id="tec-data-generator-events-progress" class="tec-data-generator-progress" hidden>
			<div class="tec-data-generator-progress-bar"><div class="tec-data-generator-progress-fill"></div></div>
			<p class="tec-data-generator-progress-label"></p>
		</div>
		<div id="tec-data-generator-events-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Generate Tickets (with attendees)', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Creates fresh event/page containers with tickets attached — or leave "Existing event/page ID" filled to attach tickets to content already on this site instead.', 'tec-data-generator' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><label for="tec-data-generator-tickets-total"><?php esc_html_e( 'Total containers (new only)', 'tec-data-generator' ); ?></label></th>
				<td><input type="number" id="tec-data-generator-tickets-total" value="10" min="1" step="1" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="tec-data-generator-tickets-event-id"><?php esc_html_e( 'Existing event/page ID (optional)', 'tec-data-generator' ); ?></label></th>
				<td>
					<input type="number" id="tec-data-generator-tickets-event-id" min="1" step="1" class="small-text">
					<label><?php esc_html_e( 'Quantity', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-tickets-quantity" value="5" min="1" step="1" class="small-text"></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Container (new only)', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="radio" name="tec-data-generator-tickets-container" value="event" checked> <?php esc_html_e( 'Event', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="tec-data-generator-tickets-container" value="page"> <?php esc_html_e( 'Page', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Ticket type', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="radio" name="tec-data-generator-tickets-ticket-type" value="rsvp" checked <?php disabled( empty( $plugin_availability['has_event_tickets'] ) ); ?>> <?php esc_html_e( 'RSVP', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="tec-data-generator-tickets-ticket-type" value="paid" <?php disabled( empty( $plugin_availability['has_event_tickets'] ) ); ?>> <?php esc_html_e( 'Paid', 'tec-data-generator' ); ?><?php if ( empty( $plugin_availability['has_event_tickets'] ) ) : ?> (<?php esc_html_e( 'requires Event Tickets', 'tec-data-generator' ); ?>)<?php endif; ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tickets per container (new only)', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Min', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-tickets-min" value="1" min="1" step="1" class="small-text"></label>
					<label><?php esc_html_e( 'Max', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-tickets-max" value="1" min="1" step="1" class="small-text"></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Attendees per ticket', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Min', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-tickets-min-attendees" value="1" min="1" step="1" class="small-text"></label>
					<label><?php esc_html_e( 'Max', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-tickets-max-attendees" value="20" min="1" step="1" class="small-text"></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Editor (new only)', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="radio" name="tec-data-generator-tickets-editor" value="classic" checked> <?php esc_html_e( 'Classic', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="tec-data-generator-tickets-editor" value="block"> <?php esc_html_e( 'Block', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Event types (new events only)', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="checkbox" class="tec-data-generator-tickets-event-type" value="single" checked> <?php esc_html_e( 'Single', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label title="<?php esc_attr_e( 'Requires Events Pro or ECP plugin', 'tec-data-generator' ); ?>"><input type="checkbox" class="tec-data-generator-tickets-event-type" value="recurring" <?php disabled( empty( $plugin_availability['has_events_pro'] ) ); ?>> <?php esc_html_e( 'Recurring', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label title="<?php esc_attr_e( 'Requires ECP plugin', 'tec-data-generator' ); ?>"><input type="checkbox" class="tec-data-generator-tickets-event-type" value="virtual" <?php disabled( empty( $plugin_availability['has_ecp'] ) ); ?>> <?php esc_html_e( 'Virtual', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Date range (new events only)', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'From', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-tickets-start-date"></label>
					<label><?php esc_html_e( 'To', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-tickets-end-date"></label>
					<p class="description"><?php esc_html_e( 'Defaults to today through two weeks out.', 'tec-data-generator' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" class="button button-primary" id="tec-data-generator-tickets-generate-btn">
				<?php esc_html_e( 'Generate tickets', 'tec-data-generator' ); ?>
			</button>
			<span class="spinner tec-data-generator-spinner" id="tec-data-generator-tickets-spinner"></span>
		</p>

		<div id="tec-data-generator-tickets-progress" class="tec-data-generator-progress" hidden>
			<div class="tec-data-generator-progress-bar"><div class="tec-data-generator-progress-fill"></div></div>
			<p class="tec-data-generator-progress-label"></p>
		</div>
		<div id="tec-data-generator-tickets-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Generate Series', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Creates event series: groups of events with different venues and organizers, linked together. Each event can have tickets and attendees.', 'tec-data-generator' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><label for="tec-data-generator-series-total"><?php esc_html_e( 'Series to create', 'tec-data-generator' ); ?></label></th>
				<td><input type="number" id="tec-data-generator-series-total" value="5" min="1" step="1" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="tec-data-generator-series-events-per"><?php esc_html_e( 'Events per series', 'tec-data-generator' ); ?></label></th>
				<td><input type="number" id="tec-data-generator-series-events-per" value="5" min="1" step="1" class="small-text"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Per event', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="checkbox" id="tec-data-generator-series-with-venues"> <?php esc_html_e( 'Different venue', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="checkbox" id="tec-data-generator-series-with-organizers"> <?php esc_html_e( 'Different organizer', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Ticket type', 'tec-data-generator' ); ?></th>
				<td>
					<label><input type="radio" name="tec-data-generator-series-ticket-type" value="rsvp" checked <?php disabled( empty( $plugin_availability['has_event_tickets'] ) ); ?>> <?php esc_html_e( 'RSVP', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="tec-data-generator-series-ticket-type" value="paid" <?php disabled( empty( $plugin_availability['has_event_tickets'] ) ); ?>> <?php esc_html_e( 'Paid', 'tec-data-generator' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="tec-data-generator-series-ticket-type" value="none"> <?php esc_html_e( 'None', 'tec-data-generator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Attendees per ticket', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Min', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-series-min-attendees" value="1" min="1" step="1" class="small-text"></label>
					<label><?php esc_html_e( 'Max', 'tec-data-generator' ); ?> <input type="number" id="tec-data-generator-series-max-attendees" value="20" min="1" step="1" class="small-text"></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Date range', 'tec-data-generator' ); ?></th>
				<td>
					<label><?php esc_html_e( 'From', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-series-start-date"></label>
					<label><?php esc_html_e( 'To', 'tec-data-generator' ); ?> <input type="date" id="tec-data-generator-series-end-date"></label>
					<p class="description"><?php esc_html_e( 'Defaults to today through two weeks out.', 'tec-data-generator' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" class="button button-primary" id="tec-data-generator-series-generate-btn">
				<?php esc_html_e( 'Generate series', 'tec-data-generator' ); ?>
			</button>
			<span class="spinner tec-data-generator-spinner" id="tec-data-generator-series-spinner"></span>
		</p>

		<div id="tec-data-generator-series-progress" class="tec-data-generator-progress" hidden>
			<div class="tec-data-generator-progress-bar"><div class="tec-data-generator-progress-fill"></div></div>
			<p class="tec-data-generator-progress-label"></p>
		</div>
		<div id="tec-data-generator-series-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section tec-data-generator-danger">
		<h2><?php esc_html_e( 'Cleanup', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Deletes everything this tool ever generated — events, pages, posts, venues, organizers, RSVP and paid tickets, and attendees across all runs (classic and block). Only posts tagged by this tool are touched; real site content is never deleted.', 'tec-data-generator' ); ?>
		</p>
		<p>
			<button type="button" class="button button-link-delete button-large" id="tec-data-generator-cleanup-btn">
				<?php esc_html_e( 'Cleanup all generated data', 'tec-data-generator' ); ?>
			</button>
			<span class="spinner tec-data-generator-spinner" id="tec-data-generator-generate-spinner"></span>
		</p>
		<div id="tec-data-generator-progress" class="tec-data-generator-progress" hidden>
			<div class="tec-data-generator-progress-bar"><div class="tec-data-generator-progress-fill"></div></div>
			<p class="tec-data-generator-progress-label"></p>
		</div>
		<div id="tec-data-generator-generate-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'Migration controls', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Run or revert the rsvp-to-tc migration directly against the data above, so you can repeat the migrate/revert cycle without regenerating test data each time. Runs in the background via Shepherd/Action Scheduler once scheduled.', 'tec-data-generator' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Status:', 'tec-data-generator' ); ?>
			<strong id="tec-data-generator-migration-status"><?php echo esc_html( $migration->get_status_label() ); ?></strong>
		</p>
		<p>
			<button
				type="button"
				class="button button-primary"
				id="tec-data-generator-run-migration-btn"
				<?php disabled( ! $migration->can_run() ); ?>
			>
				<?php esc_html_e( 'Run migration (V1 → Tickets Commerce)', 'tec-data-generator' ); ?>
			</button>
			<button
				type="button"
				class="button"
				id="tec-data-generator-revert-migration-btn"
				<?php disabled( ! $migration->can_revert() ); ?>
			>
				<?php esc_html_e( 'Revert to V1', 'tec-data-generator' ); ?>
			</button>
			<span class="spinner tec-data-generator-spinner" id="tec-data-generator-migration-spinner"></span>
		</p>
		<div id="tec-data-generator-migration-notice" class="notice inline tec-data-generator-notice" hidden><p></p></div>
	</section>

	<section class="tec-data-generator-section">
		<h2><?php esc_html_e( 'CLI reference', 'tec-data-generator' ); ?></h2>
		<p>
			<?php esc_html_e( 'The same operations above, for large runs (1000+) where WP-CLI has no request-timeout ceiling. Migrate/revert only schedule the run — the work happens in the background.', 'tec-data-generator' ); ?>
		</p>
		<table class="widefat striped" style="max-width: 640px;">
			<tbody>
				<tr>
					<td><code>wp tec-data-generator generate --count=5000</code></td>
					<td><?php esc_html_e( 'Generate units (Event/Page/Post + 1 RSVP each)', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator scenario --type=usual</code></td>
					<td><?php esc_html_e( 'Realistic QA scenario (25–250 units, some orphaned)', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator scenario --type=edge</code></td>
					<td><?php esc_html_e( 'Edge-case scenario (7k–11k units, some orphaned)', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator generate-events --count=50</code></td>
					<td><?php esc_html_e( 'Event containers only, no tickets', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator generate-tickets --count=10</code></td>
					<td><?php esc_html_e( 'Fresh containers with tickets + attendees', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator add-rsvp &lt;event_id&gt; --quantity=5</code></td>
					<td><?php esc_html_e( 'Attach RSVP tickets to an existing event', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator add-tickets &lt;event_id&gt; --quantity=5</code></td>
					<td><?php esc_html_e( 'Attach paid tickets to an existing event', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator add-attendees &lt;ticket_id&gt; --quantity=10</code></td>
					<td><?php esc_html_e( 'Attach attendees to an existing ticket', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator migrate</code></td>
					<td><?php esc_html_e( 'Schedule the rsvp-to-tc migration (full run)', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator revert</code></td>
					<td><?php esc_html_e( 'Revert the migration back to V1', 'tec-data-generator' ); ?></td>
				</tr>
				<tr>
					<td><code>wp tec-data-generator cleanup</code></td>
					<td><?php esc_html_e( 'Delete everything this tool generated', 'tec-data-generator' ); ?></td>
				</tr>
			</tbody>
		</table>
	</section>
</div>
