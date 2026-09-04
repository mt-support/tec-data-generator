## Purpose

Enable the generator to create virtual events with meeting URLs and platform information via ECP plugin APIs, allowing QA to test virtual/hybrid event scenarios.

## ADDED Requirements

### Requirement: Virtual Event Creation
The generator SHALL create virtual events with platform and meeting URL metadata when the `virtual` event type is requested, using ECP plugin APIs.

#### Scenario: Create virtual event with Zoom
- **WHEN** user requests virtual event type and generator creates a unit with Zoom as the platform
- **THEN** event is created with virtual metadata: platform="zoom", meeting_url="https://zoom.us/j/{randomId}"

#### Scenario: Create virtual event with Google Meet
- **WHEN** generator creates virtual event with Google Meet
- **THEN** event is created with virtual metadata: platform="google_meet", meeting_url="https://meet.google.com/xyz-abc-def"

#### Scenario: Create virtual event with Teams
- **WHEN** generator creates virtual event with Microsoft Teams
- **THEN** event is created with virtual metadata: platform="teams", meeting_url="https://teams.microsoft.com/l/meetup-join/{id}"

### Requirement: Randomized Virtual Platforms
Virtual events SHALL be distributed across multiple virtual meeting platforms (Zoom, Google Meet, Microsoft Teams) to simulate realistic test data.

#### Scenario: Platform distribution
- **WHEN** generator creates multiple virtual events in a single batch
- **THEN** platforms are randomly distributed across Zoom, Google Meet, and Teams

#### Scenario: Unique meeting URLs
- **WHEN** virtual events are created
- **THEN** each event is assigned a unique, randomized meeting URL matching its platform

### Requirement: Virtual Event Metadata
Virtual events SHALL store platform and meeting information in post meta for cleanup and verification.

#### Scenario: Virtual metadata stored
- **WHEN** virtual event is created
- **THEN** event includes ECP virtual event metadata (post meta keys for platform, meeting URL, etc.) plus `_tec_data_generator_event_type = 'virtual'`

### Requirement: Virtual Events Support Tickets
Virtual events created SHALL support RSVP and paid ticket attachment, same as single and recurring events.

#### Scenario: RSVP on virtual event
- **WHEN** user specifies `--event-types=virtual --ticket-type=rsvp`
- **THEN** each virtual event SHALL have RSVP tickets attached

#### Scenario: Paid tickets on virtual event
- **WHEN** user specifies `--event-types=virtual --ticket-type=paid`
- **THEN** each virtual event SHALL have paid tickets attached

### Requirement: Virtual Event Validation
The generator SHALL validate that ECP plugin is active before allowing virtual event creation.

#### Scenario: Validation on virtual creation attempt
- **WHEN** user specifies `--event-types=virtual` and ECP plugin is not active
- **THEN** generator returns error: "Virtual events require ECP (Events Calendar Pro) plugin to be active"

### Requirement: Hybrid Events (Optional)
The system MAY create hybrid events (both in-person and virtual) by combining virtual metadata with event location/venue information, pending ECP plugin capability.

#### Scenario: Hybrid event creation
- **WHEN** user specifies `--event-types=virtual --with-venues` 
- **THEN** generator creates virtual events with both meeting URLs (virtual) and venue/location information (in-person component), if ECP supports hybrid events
