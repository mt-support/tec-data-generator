<?php
/**
 * Admin page markup for Tools > RSVP Load Generator.
 *
 * @var array<string,int>       $counts    Current generated-record counts, keyed by kind.
 * @var \RSVP_Loadgen\Migration $migration Wrapper around the rsvp-to-tc migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap rsvp-loadgen-wrap">
	<h1><?php esc_html_e( 'RSVP Load Generator', 'rsvp-migration-loadgen' ); ?></h1>

	<p>
		<?php esc_html_e( 'Generates legacy V1 RSVP tickets (with attendees) on newly-created Event/Page/Post content, to stress-test the RSVP-to-Tickets-Commerce migration. Everything created here is tagged and can be fully removed with Cleanup.', 'rsvp-migration-loadgen' ); ?>
	</p>

	<p>
		<strong><?php esc_html_e( 'For large runs (1000+), prefer WP-CLI:', 'rsvp-migration-loadgen' ); ?></strong>
		<code>wp rsvp-loadgen generate --count=5000</code> &mdash;
		<?php esc_html_e( 'the admin UI below is chunked but slower and tab-bound.', 'rsvp-migration-loadgen' ); ?>
	</p>

	<h2><?php esc_html_e( 'Currently generated', 'rsvp-migration-loadgen' ); ?></h2>
	<table class="widefat striped" style="max-width: 480px;" id="rsvp-loadgen-counts">
		<tbody>
		<?php foreach ( $counts as $kind => $count ) : ?>
			<tr>
				<td><?php echo esc_html( ucfirst( $kind ) ); ?></td>
				<td data-kind="<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Scenario (recommended)', 'rsvp-migration-loadgen' ); ?></h2>
	<p>
		<?php esc_html_e( 'Generates one of two pre-defined QA scenarios, including a percentage of "orphaned" RSVPs — an Event/Page/Post deleted while its RSVP ticket(s) and attendees are left behind. This is the main edge case this tool exists to reproduce.', 'rsvp-migration-loadgen' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'Runs entirely in the background (via Action Scheduler), same as the migration controls below — feel free to navigate away or close this tab once started. Progress resumes automatically when you come back to this page.', 'rsvp-migration-loadgen' ); ?>
	</p>
	<table class="form-table">
		<tr>
			<th><label for="rsvp-loadgen-scenario-type"><?php esc_html_e( 'Scenario', 'rsvp-migration-loadgen' ); ?></label></th>
			<td>
				<select id="rsvp-loadgen-scenario-type">
					<option value="usual"><?php esc_html_e( 'Usual (25–250 events, 1–3 RSVPs each, 5%–20% orphaned)', 'rsvp-migration-loadgen' ); ?></option>
					<option value="edge"><?php esc_html_e( 'Edge case (7,000–11,000 events, 1–9 RSVPs each, 5%–20% orphaned — can take a while)', 'rsvp-migration-loadgen' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Attach', 'rsvp-migration-loadgen' ); ?></th>
			<td>
				<label><input type="checkbox" id="rsvp-loadgen-scenario-with-venues"> <?php esc_html_e( 'Venues', 'rsvp-migration-loadgen' ); ?></label>
				&nbsp;&nbsp;
				<label><input type="checkbox" id="rsvp-loadgen-scenario-with-organizers"> <?php esc_html_e( 'Organizers', 'rsvp-migration-loadgen' ); ?></label>
			</td>
		</tr>
	</table>
	<p>
		<button type="button" class="button button-primary" id="rsvp-loadgen-scenario-btn">
			<?php esc_html_e( 'Generate scenario', 'rsvp-migration-loadgen' ); ?>
		</button>
		<span class="spinner rsvp-loadgen-spinner" id="rsvp-loadgen-scenario-spinner"></span>
	</p>
	<div id="rsvp-loadgen-scenario-progress" class="rsvp-loadgen-progress" hidden>
		<div class="rsvp-loadgen-progress-bar"><div class="rsvp-loadgen-progress-fill"></div></div>
		<p class="rsvp-loadgen-progress-label"></p>
	</div>
	<div id="rsvp-loadgen-scenario-notice" class="notice inline rsvp-loadgen-notice" hidden><p></p></div>

	<h2><?php esc_html_e( 'Generate (advanced: plain, no orphaning)', 'rsvp-migration-loadgen' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><label for="rsvp-loadgen-total"><?php esc_html_e( 'Total tickets', 'rsvp-migration-loadgen' ); ?></label></th>
			<td><input type="number" id="rsvp-loadgen-total" value="5000" min="1" step="1" class="small-text"></td>
		</tr>
		<tr>
			<th><label for="rsvp-loadgen-min-attendees"><?php esc_html_e( 'Min attendees per ticket', 'rsvp-migration-loadgen' ); ?></label></th>
			<td><input type="number" id="rsvp-loadgen-min-attendees" value="1" min="1" step="1" class="small-text"></td>
		</tr>
		<tr>
			<th><label for="rsvp-loadgen-max-attendees"><?php esc_html_e( 'Max attendees per ticket', 'rsvp-migration-loadgen' ); ?></label></th>
			<td><input type="number" id="rsvp-loadgen-max-attendees" value="20" min="1" step="1" class="small-text"></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Attach', 'rsvp-migration-loadgen' ); ?></th>
			<td>
				<label><input type="checkbox" id="rsvp-loadgen-with-venues"> <?php esc_html_e( 'Venues', 'rsvp-migration-loadgen' ); ?></label>
				&nbsp;&nbsp;
				<label><input type="checkbox" id="rsvp-loadgen-with-organizers"> <?php esc_html_e( 'Organizers', 'rsvp-migration-loadgen' ); ?></label>
			</td>
		</tr>
	</table>

	<p>
		<button type="button" class="button button-primary" id="rsvp-loadgen-generate-btn">
			<?php esc_html_e( 'Generate', 'rsvp-migration-loadgen' ); ?>
		</button>
		<button type="button" class="button" id="rsvp-loadgen-cleanup-btn">
			<?php esc_html_e( 'Cleanup all generated data', 'rsvp-migration-loadgen' ); ?>
		</button>
		<span class="spinner rsvp-loadgen-spinner" id="rsvp-loadgen-generate-spinner"></span>
	</p>

	<div id="rsvp-loadgen-progress" class="rsvp-loadgen-progress" hidden>
		<div class="rsvp-loadgen-progress-bar"><div class="rsvp-loadgen-progress-fill"></div></div>
		<p class="rsvp-loadgen-progress-label"></p>
	</div>
	<div id="rsvp-loadgen-generate-notice" class="notice inline rsvp-loadgen-notice" hidden><p></p></div>

	<h2><?php esc_html_e( 'Migration controls', 'rsvp-migration-loadgen' ); ?></h2>
	<p>
		<?php esc_html_e( 'Run or revert the rsvp-to-tc migration directly against the data above, so you can repeat the migrate/revert cycle without regenerating test data each time. Runs in the background via Shepherd/Action Scheduler once scheduled.', 'rsvp-migration-loadgen' ); ?>
	</p>
	<p>
		<?php esc_html_e( 'Status:', 'rsvp-migration-loadgen' ); ?>
		<strong id="rsvp-loadgen-migration-status"><?php echo esc_html( $migration->get_status_label() ); ?></strong>
	</p>
	<p>
		<button
			type="button"
			class="button button-primary"
			id="rsvp-loadgen-run-migration-btn"
			<?php disabled( ! $migration->can_run() ); ?>
		>
			<?php esc_html_e( 'Run migration (V1 → Tickets Commerce)', 'rsvp-migration-loadgen' ); ?>
		</button>
		<button
			type="button"
			class="button"
			id="rsvp-loadgen-revert-migration-btn"
			<?php disabled( ! $migration->can_revert() ); ?>
		>
			<?php esc_html_e( 'Revert to V1', 'rsvp-migration-loadgen' ); ?>
		</button>
		<span class="spinner rsvp-loadgen-spinner" id="rsvp-loadgen-migration-spinner"></span>
	</p>
	<div id="rsvp-loadgen-migration-notice" class="notice inline rsvp-loadgen-notice" hidden><p></p></div>

	<h2><?php esc_html_e( 'Add to existing content', 'rsvp-migration-loadgen' ); ?></h2>
	<p>
		<?php esc_html_e( 'Attach RSVP tickets, paid tickets, or attendees to an event/ticket that already exists on this site (generated by this tool or not).', 'rsvp-migration-loadgen' ); ?>
	</p>
	<table class="form-table">
		<tr>
			<th><label for="rsvp-loadgen-addrsvp-event-id"><?php esc_html_e( 'Add RSVP tickets — Event ID', 'rsvp-migration-loadgen' ); ?></label></th>
			<td>
				<input type="number" id="rsvp-loadgen-addrsvp-event-id" min="1" step="1" class="small-text">
				<label><?php esc_html_e( 'Quantity', 'rsvp-migration-loadgen' ); ?> <input type="number" id="rsvp-loadgen-addrsvp-quantity" value="1" min="1" step="1" class="small-text"></label>
				<button type="button" class="button" id="rsvp-loadgen-addrsvp-btn"><?php esc_html_e( 'Add RSVP tickets', 'rsvp-migration-loadgen' ); ?></button>
				<span class="spinner rsvp-loadgen-spinner" id="rsvp-loadgen-addrsvp-spinner"></span>
			</td>
		</tr>
		<tr>
			<th><label for="rsvp-loadgen-addtickets-event-id"><?php esc_html_e( 'Add paid tickets — Event ID', 'rsvp-migration-loadgen' ); ?></label></th>
			<td>
				<input type="number" id="rsvp-loadgen-addtickets-event-id" min="1" step="1" class="small-text">
				<label><?php esc_html_e( 'Quantity', 'rsvp-migration-loadgen' ); ?> <input type="number" id="rsvp-loadgen-addtickets-quantity" value="1" min="1" step="1" class="small-text"></label>
				<button type="button" class="button" id="rsvp-loadgen-addtickets-btn"><?php esc_html_e( 'Add paid tickets', 'rsvp-migration-loadgen' ); ?></button>
				<span class="spinner rsvp-loadgen-spinner" id="rsvp-loadgen-addtickets-spinner"></span>
			</td>
		</tr>
		<tr>
			<th><label for="rsvp-loadgen-addattendees-ticket-id"><?php esc_html_e( 'Add attendees — Ticket ID', 'rsvp-migration-loadgen' ); ?></label></th>
			<td>
				<input type="number" id="rsvp-loadgen-addattendees-ticket-id" min="1" step="1" class="small-text">
				<label><?php esc_html_e( 'Quantity', 'rsvp-migration-loadgen' ); ?> <input type="number" id="rsvp-loadgen-addattendees-quantity" value="1" min="1" step="1" class="small-text"></label>
				<button type="button" class="button" id="rsvp-loadgen-addattendees-btn"><?php esc_html_e( 'Add attendees', 'rsvp-migration-loadgen' ); ?></button>
				<span class="spinner rsvp-loadgen-spinner" id="rsvp-loadgen-addattendees-spinner"></span>
			</td>
		</tr>
	</table>
	<div id="rsvp-loadgen-addon-notice" class="notice inline rsvp-loadgen-notice" hidden><p></p></div>
</div>
