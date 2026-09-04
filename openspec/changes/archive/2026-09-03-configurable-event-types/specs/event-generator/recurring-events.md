## Purpose

Enable the generator to create recurring events via Events Pro/ECP plugin APIs, allowing QA to test scenarios with multi-date events and recurring event management.

## ADDED Requirements

### Requirement: Recurring Event Creation
The generator SHALL create recurring events using the Events Pro or ECP recurrence API when the `recurring` event type is requested.

#### Scenario: Create daily recurring event
- **WHEN** user requests recurring event type and generator creates a unit with daily recurrence
- **THEN** event is created with recurrence pattern: daily, 10-30 occurrences

#### Scenario: Create weekly recurring event
- **WHEN** user requests recurring event type with weekly pattern
- **THEN** event is created with recurrence pattern: weekly on specified day, 4-12 occurrences

#### Scenario: Create monthly recurring event
- **WHEN** user requests recurring event type with monthly pattern
- **THEN** event is created with recurrence pattern: monthly, 2-6 occurrences

### Requirement: Recurring Event Metadata
Recurring events SHALL store recurrence pattern details in post meta for verification and cleanup purposes.

#### Scenario: Recurring pattern stored in meta
- **WHEN** recurring event is created
- **THEN** event includes `_tec_recurring_meta` or equivalent Events Pro/ECP recurrence metadata, plus `_tec_data_generator_event_type = 'recurring'`

### Requirement: Randomized Recurrence Patterns
Generated recurring events SHALL use randomized but realistic recurrence patterns: daily, weekly, or monthly, with randomized occurrence counts.

#### Scenario: Recurrence pattern randomization
- **WHEN** generator creates multiple recurring events in a single batch
- **THEN** each event is assigned a random recurrence pattern (daily, weekly, or monthly) and random occurrence count (1-30), producing varied test data

#### Scenario: Recurrence within realistic bounds
- **WHEN** recurring event is created
- **THEN** recurrence pattern has 1-30 occurrences (not infinite) to keep generated data manageable for testing

### Requirement: Recurring Events Support Tickets
Recurring events created SHALL support RSVP and paid ticket attachment, same as single events.

#### Scenario: RSVP on recurring event
- **WHEN** user specifies `--event-types=recurring --ticket-type=rsvp`
- **THEN** each recurring event occurrence SHALL have RSVP tickets attached (via existing ticket creation logic)

#### Scenario: Paid tickets on recurring event
- **WHEN** user specifies `--event-types=recurring --ticket-type=paid`
- **THEN** each recurring event occurrence SHALL have paid tickets attached (requires Event Tickets Plus or Tickets Commerce provider)

### Requirement: Recurrence Support Validation
The generator SHALL validate that Events Pro or ECP is active before allowing recurring event creation.

#### Scenario: Validation on recurring creation attempt
- **WHEN** user specifies `--event-types=recurring` and Events Pro/ECP is not active
- **THEN** generator returns error: "Recurring events require Events Pro or ECP plugin to be active"
