## Purpose

Allow users to choose whether events include RSVP tickets, paid tickets, or no tickets at all, providing flexible control over the generated test data composition.

## ADDED Requirements

### Requirement: Ticket Type Selection
The generator SHALL accept a `ticket_type` parameter that specifies which type of tickets to attach: `rsvp`, `paid`, or `none`. Default is `rsvp` for backward compatibility.

#### Scenario: Generate events with RSVP tickets
- **WHEN** user specifies `--ticket-type=rsvp` or selects "RSVP" in admin UI
- **THEN** each generated event SHALL have 1+ RSVP tickets attached (existing behavior)

#### Scenario: Generate events with paid tickets
- **WHEN** user specifies `--ticket-type=paid`
- **THEN** each generated event SHALL have paid tickets via the event's configured provider (Tickets Commerce, PayPal, etc., requires Event Tickets Plus or provider plugin)

#### Scenario: Generate events with no tickets
- **WHEN** user specifies `--ticket-type=none`
- **THEN** each generated event is created without any RSVP or paid tickets

#### Scenario: Default ticket type (RSVP)
- **WHEN** user does not specify ticket type
- **THEN** generator defaults to `ticket_type=rsvp` and creates events with RSVP tickets

### Requirement: Ticket Configuration Validation
The system SHALL validate that required plugins are available for the requested ticket type before generating events.

#### Scenario: Paid tickets require Event Tickets
- **WHEN** user specifies `--ticket-type=paid` and Event Tickets plugin is not active
- **THEN** generator returns error: "Paid tickets require Event Tickets plugin to be active"

#### Scenario: RSVP tickets validation
- **WHEN** user specifies `--ticket-type=rsvp` and Event Tickets plugin is not active
- **THEN** generator returns error: "RSVP tickets require Event Tickets plugin to be active"

### Requirement: Attendee Range Configuration
For events with RSVP or paid tickets, the generator SHALL respect `min_attendees` and `max_attendees` parameters.

#### Scenario: Attendees on RSVP tickets
- **WHEN** user specifies `--min-attendees=5 --max-attendees=50 --ticket-type=rsvp`
- **THEN** each RSVP ticket is created with 5-50 attendees (randomly distributed within that range)

#### Scenario: Attendees on paid tickets
- **WHEN** user specifies `--min-attendees=1 --max-attendees=100 --ticket-type=paid`
- **THEN** each paid ticket is created with 1-100 attendees

#### Scenario: Attendee range ignored for no-ticket events
- **WHEN** user specifies `--ticket-type=none`
- **THEN** `min_attendees` and `max_attendees` parameters are ignored

### Requirement: Multiple Tickets Per Event
The generator SHALL support creating multiple tickets per event (via existing `min_rsvps_per_unit`/`max_rsvps_per_unit` configuration).

#### Scenario: Multiple RSVP tickets per event
- **WHEN** user specifies `--min-rsvps=2 --max-rsvps=5 --ticket-type=rsvp`
- **THEN** each event is created with 2-5 RSVP tickets, each with independent attendee counts

#### Scenario: Multiple paid tickets per event
- **WHEN** user specifies `--min-rsvps=1 --max-rsvps=3 --ticket-type=paid`
- **THEN** each event is created with 1-3 paid tickets

### Requirement: Ticket Type Applies to All Event Types
Ticket type selection SHALL apply uniformly to single, recurring, and virtual events.

#### Scenario: Recurring events with paid tickets
- **WHEN** user specifies `--event-types=recurring --ticket-type=paid`
- **THEN** each recurring event occurrence has paid tickets attached

#### Scenario: Virtual events with no tickets
- **WHEN** user specifies `--event-types=virtual --ticket-type=none`
- **THEN** virtual events are created without tickets
