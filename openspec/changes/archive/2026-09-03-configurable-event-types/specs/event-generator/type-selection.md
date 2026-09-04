## Purpose

Allow users to specify which types of events to generate (Single, Recurring, Virtual) instead of the fixed round-robin post-type approach, enabling QA to test specific event configurations.

## ADDED Requirements

### Requirement: Event Type Selection
The generator SHALL accept a list of event types and create events matching the specified types. If no type is specified, the generator SHALL create a random mix of available types.

#### Scenario: User specifies single event type
- **WHEN** user specifies `--event-types=single` or selects "Single" in the admin UI
- **THEN** generator creates only single (non-recurring, non-virtual) events

#### Scenario: User specifies multiple event types
- **WHEN** user specifies `--event-types=single,recurring,virtual`
- **THEN** generator creates events distributed randomly across all three types

#### Scenario: No event type specified
- **WHEN** user does not specify event types
- **THEN** generator creates a random mix of available types matching the generated quantity

### Requirement: Event Type Availability
The system SHALL only allow event types whose required plugins are active. Recurring and Virtual types require Events Pro or ECP; Single events require only The Events Calendar.

#### Scenario: Recurring events with Events Pro active
- **WHEN** Events Pro plugin is active and user selects `--event-types=recurring`
- **THEN** generator successfully creates recurring events

#### Scenario: Recurring events without Events Pro
- **WHEN** Events Pro/ECP plugins are not active and user attempts `--event-types=recurring`
- **THEN** generator SHALL return an error: "Recurring events require Events Pro or ECP plugin to be active"

#### Scenario: Virtual events without ECP
- **WHEN** ECP plugin is not active and user attempts `--event-types=virtual`
- **THEN** generator SHALL return an error: "Virtual events require ECP (Events Calendar Pro) plugin to be active"

### Requirement: Event Type Tracking
Every generated event SHALL be tagged with its event type (single, recurring, or virtual) in addition to the existing `_tec_data_generator_generated` marker.

#### Scenario: Event type metadata stored
- **WHEN** event is created with type "recurring"
- **THEN** event post meta includes `_tec_data_generator_event_type = 'recurring'`

#### Scenario: Cleanup includes type metadata
- **WHEN** cleanup deletes generated events
- **THEN** cleanup finds events by the combination of `_tec_data_generator_generated` marker and type meta, ensuring correct scope

### Requirement: CLI and Admin UI Support
Both WP-CLI (`generate` command) and the admin UI form SHALL expose event type selection.

#### Scenario: CLI event type selection
- **WHEN** user runs `wp tec-data-generator generate --count=100 --event-types=single,recurring`
- **THEN** generator creates 100 events distributed across single and recurring types

#### Scenario: Admin UI event type checkboxes
- **WHEN** user opens Tools > TEC Data Generator and checks "Recurring" and "Virtual" under "Event Types"
- **THEN** the form submission includes selected types; if Events Pro/ECP is not active, the checkbox is disabled with a note explaining the requirement
