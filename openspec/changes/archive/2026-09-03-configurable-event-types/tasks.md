## 1. Foundation: Plugin Detection and Validation

- [x] 1.1 Create `includes/class-plugin-availability.php` with static methods: `has_event_tickets()`, `has_events_pro()`, `has_ecp()`. Verify each method returns a boolean and checks correct class/function existence; test by requiring and calling each method.
- [x] 1.2 Add event type validation to `Generator::generate_batch()` before the main loop (before line 58): check `$options['event_types']` against available plugins. Throw clear exception if any requested type is unsupported (e.g., "Recurring events require Events Pro or ECP"). Test by calling with unavailable type and confirm exception message.
- [x] 1.3 Add ticket type validation to `Generator::generate_batch()`: validate `$options['ticket_type']` is one of 'rsvp', 'paid', 'none', and that Event Tickets is active if 'rsvp' or 'paid' is requested. Test by calling with invalid ticket type and confirm exception message.

## 2. Generator Extension: Event Type Selection and Creation Methods

- [x] 2.1 Add method `Generator::event_type_for_sequence( $seq, $types )` to deterministically map sequence offset to event type. Test: call with seq=0-5 and types=['single','recurring'] and verify cyclic distribution (0→single, 1→recurring, 2→single, etc.).
- [x] 2.2 Rename or wrap existing `Generator::create_event_post()` as `Generator::create_single_event()`. Ensure it returns `$post_id` unchanged. Test: call method and verify single event is created with existing behavior.
- [x] 2.3 Add method `Generator::create_recurring_event( $seq, $venue_id, $organizer_id )` to create recurring events via `tribe_events()->set_args()` with recurrence pattern. Use Events Pro/ECP recurrence API. Tag event with `_tec_data_generator_event_type = 'recurring'`. Test: call with Events Pro active, confirm event post is created with recurrence meta set.
- [x] 2.4 Add method `Generator::create_virtual_event( $seq, $venue_id, $organizer_id )` to create virtual events via `tribe_events()->create()`, then add virtual metadata (platform, meeting URL) via `update_post_meta()`. Randomize platform (Zoom/Google Meet/Teams) and generate unique meeting URL. Tag event with `_tec_data_generator_event_type = 'virtual'`. Test: call with ECP active, confirm event post is created with virtual meta set.
- [x] 2.5 Modify `Generator::generate_batch()` loop (lines 63-69) to: (1) determine event type via `event_type_for_sequence()`, (2) call corresponding `create_*_event()` method, (3) store returned `$post_id`. Verify post_ids are collected correctly for all three event types. Test: call with mixed event_types, confirm loop creates correct number of each type.

## 3. Generator Extension: Ticket Type Selection

- [x] 3.1 Modify ticket attachment logic in `Generator::generate_batch()` (after line 79) to check `$options['ticket_type']`: if 'rsvp', call existing `add_rsvp_tickets()` path; if 'paid', call existing `add_paid_tickets()` path; if 'none', skip ticket attachment entirely. Test: call with each ticket_type and verify correct attachment (or none).
- [x] 3.2 Ensure default behavior: if `$options['event_types']` not provided, default to `['single']`; if `$options['ticket_type']` not provided, default to `'rsvp'`. Test: call `generate_batch()` without options and confirm single events with RSVP tickets are created (existing behavior).

## 4. CLI Implementation: New Flags for `generate` Command

- [x] 4.1 Add `--event-types` flag to `wp tec-data-generator generate` command (in `class-cli.php` `generate()` method): accepts comma-separated values (single,recurring,virtual), validates format. Parse into array and pass to `Generator::generate_batch()` via options. Test: run `wp tec-data-generator generate --count=10 --event-types=single,recurring` and verify command parses flags correctly.
- [x] 4.2 Add `--ticket-type` flag to `wp tec-data-generator generate`: accepts 'rsvp', 'paid', or 'none', defaults to 'rsvp'. Pass to `Generator::generate_batch()` via options. Test: run with each ticket_type value and verify it's passed through.
- [x] 4.3 Update `generate` command help text to explain new flags and plugin requirements. Include examples: `wp tec-data-generator generate --count=100 --event-types=single,recurring --ticket-type=rsvp`. Test: run `wp tec-data-generator generate --help` and confirm help text is clear and includes requirements.
- [x] 4.4 Add validation in CLI `generate()` method before calling `Generator`: if user specifies unavailable event type or ticket type, catch exception from Generator and print user-friendly error message. Test: run with Events Pro inactive and `--event-types=recurring`, confirm clear error message is printed.

## 5. Admin UI: Form Fields and Display

- [x] 5.1 Update `views/admin-page.php` to add new form section "Event Types" with checkboxes for "Single", "Recurring", "Virtual". Initially add HTML markup only. Use data attributes to track which features are available (based on PluginAvailability checks in PHP). Test: open admin page and verify checkboxes render.
- [x] 5.2 Add "Ticket Type" radio button group in admin page: "RSVP", "Paid", "None" options. Test: open admin page and verify radio buttons render.
- [x] 5.3 Disable checkboxes/radio buttons in admin page if required plugins are missing. Pass `$plugin_availability` data to view template from `class-admin.php` to determine which options should be disabled. Add help text/tooltips explaining requirements. Test: deactivate Events Pro, reload admin page, verify "Recurring" checkbox is disabled with tooltip visible.
- [x] 5.4 Serialize selected event types and ticket type from form inputs and pass them via AJAX to the generate handler (existing AJAX flow). Ensure form data is correctly encoded and transmitted. Test: select checkboxes/radio buttons on admin page and verify data is sent to server via AJAX.

## 6. AJAX Handlers and Scenario_Job Integration

- [x] 6.1 Update AJAX handler in `class-ajax.php` (generate chunking route) to accept and pass through `event_types` and `ticket_type` from form data to `Generator::generate_batch()` options. Test: trigger generate via admin page with custom event types, verify chunks are created with correct types.
- [x] 6.2 Modify `Scenario_Job::process_chunk()` (if scenario is extended to support new options) to pass event type and ticket type options through to `Generator::generate_batch()`. (Note: proposal says scenarios remain unchanged; verify if this is needed or skip if scenarios don't support new options.) Test: trigger scenario generation via admin page, verify chunks process correctly.
- [x] 6.3 Handle graceful degradation in AJAX/Scenario_Job: if plugin becomes unavailable mid-run, catch exception, log warning, and continue with other event types. Test: start chunked generation, deactivate required plugin mid-run, verify remaining chunks complete with warning logged.

## 7. Testing and Verification

- [x] 7.1 CLI smoke test: Run `wp tec-data-generator generate --count=10 --event-types=single --ticket-type=rsvp` and verify 10 single events with RSVP tickets are created. Check post meta for `_tec_data_generator_event_type = 'single'`.
- [x] 7.2 CLI smoke test (recurring): Run `wp tec-data-generator generate --count=5 --event-types=recurring --ticket-type=none` with Events Pro active and verify 5 recurring events are created without tickets. Check for recurrence meta.
- [x] 7.3 CLI smoke test (virtual): Run `wp tec-data-generator generate --count=5 --event-types=virtual --ticket-type=rsvp` with ECP active and verify 5 virtual events with RSVP tickets are created. Check for virtual meta (platform, meeting URL).
- [x] 7.4 CLI error test: Run `wp tec-data-generator generate --count=5 --event-types=recurring` without Events Pro/ECP active and verify clear error message is printed (no partial generation).
- [x] 7.5 Admin UI smoke test: Open Tools > TEC Data Generator, select "Single" and "Recurring" event types, select "RSVP" ticket type, click "Generate scenario (Usual)", and verify scenario generates with correct event types and tickets. Monitor progress bar and final counts.
- [x] 7.6 Admin UI plugin availability test: Deactivate Events Pro in wp-admin, reload TEC Data Generator page, verify "Recurring" checkbox is disabled with tooltip. Re-enable Events Pro, reload, verify checkbox is enabled.
- [x] 7.7 Cleanup verification: Generate events with mixed types and ticket types, run `wp tec-data-generator cleanup`, and verify all generated events (all types) are correctly deleted by checking post meta for `_tec_data_generator_generated` marker.
- [x] 7.8 Backward compatibility test: Run existing `wp tec-data-generator scenario --type=usual` without new flags/options and verify it still generates events as before (no breaking changes to CLI).

## 8. Documentation and Help Text

- [x] 8.1 Update `README.md` with new event type and ticket type options: explain `--event-types`, `--ticket-type` flags, list which plugins are required for each option, provide examples. Verify README renders correctly and examples are accurate.
- [x] 8.2 Add comments to new methods in `class-generator.php` explaining event type selection logic, recurring/virtual event creation, and ticket type handling. Ensure inline comments explain WHY, not just WHAT.
