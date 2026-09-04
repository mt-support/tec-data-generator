## Purpose

Ensure the generator only enables features whose required plugins are active, and provide clear error messages when unsupported features are requested.

## ADDED Requirements

### Requirement: Plugin Availability Detection
The generator SHALL detect and report the availability of dependent plugins: Event Tickets, Events Pro, ECP, Event Tickets Plus.

#### Scenario: Detect Event Tickets active
- **WHEN** Event Tickets plugin is active
- **THEN** RSVP and paid ticket features are available

#### Scenario: Detect Events Pro active
- **WHEN** Events Pro plugin is active
- **THEN** recurring event feature is available

#### Scenario: Detect ECP active
- **WHEN** ECP (Events Calendar Pro) plugin is active
- **THEN** virtual event and recurring event features are available

#### Scenario: Detect Event Tickets Plus active
- **WHEN** Event Tickets Plus plugin is active
- **THEN** advanced paid ticket types (legacy Event Tickets Plus) are supported

### Requirement: Feature Validation Before Generation
The generator SHALL validate that all requested features have their required plugins active before starting event generation. If validation fails, it SHALL return an error without generating any events.

#### Scenario: Recurring events validation fails
- **WHEN** user requests `--event-types=recurring` and neither Events Pro nor ECP is active
- **THEN** generator returns error before creating any events: "Recurring events require Events Pro or ECP plugin to be active"

#### Scenario: Virtual events validation fails
- **WHEN** user requests `--event-types=virtual` and ECP is not active
- **THEN** generator returns error before creating any events: "Virtual events require ECP (Events Calendar Pro) plugin to be active"

#### Scenario: Validation passes for available features
- **WHEN** user requests `--event-types=single --ticket-type=rsvp` and Event Tickets is active
- **THEN** validation passes and generation proceeds

### Requirement: Admin UI Feature Availability
The admin page SHALL dynamically enable/disable feature options based on plugin availability.

#### Scenario: Recurring checkbox disabled without Events Pro
- **WHEN** user opens Tools > TEC Data Generator and Events Pro/ECP is not active
- **THEN** "Recurring" event type checkbox is disabled with tooltip: "Requires Events Pro or ECP plugin"

#### Scenario: Virtual checkbox disabled without ECP
- **WHEN** user opens Tools > TEC Data Generator and ECP is not active
- **THEN** "Virtual" event type checkbox is disabled with tooltip: "Requires ECP plugin"

#### Scenario: Paid tickets disabled without Event Tickets
- **WHEN** user opens Tools > TEC Data Generator and Event Tickets is not active
- **THEN** "Paid" ticket type option is disabled with tooltip: "Requires Event Tickets plugin"

### Requirement: Help Text on Missing Dependencies
Both CLI and admin UI SHALL provide clear messaging about which plugins are required for each feature.

#### Scenario: CLI help output includes requirements
- **WHEN** user runs `wp tec-data-generator generate --help`
- **THEN** help text includes: "Recurring events require Events Pro or ECP. Virtual events require ECP. Paid tickets require Event Tickets Plus."

#### Scenario: Admin page shows plugin requirement hints
- **WHEN** user hovers over or views disabled feature checkboxes
- **THEN** tooltips or help text clearly state which plugin(s) are required

### Requirement: Graceful Degradation
If a requested feature's plugin becomes unavailable during a chunked generation run, the system SHALL gracefully skip that feature (not crash) and log a warning.

#### Scenario: Plugin deactivated mid-run
- **WHEN** user starts background scenario generation, and Events Pro is deactivated before all chunks complete
- **THEN** generator logs warning for each chunk attempting recurring events, skips those events, and completes other event types successfully

#### Scenario: All chunks complete despite missing plugin
- **WHEN** recurring event type is unavailable mid-run
- **THEN** generator completes run, reporting events created without recurrence and a warning in logs/UI: "Recurring events were skipped due to missing Events Pro/ECP plugin"
