---
name: tec-dg-events-tickets
description: Generate only event containers or only tickets with wp tec-data-generator generate-events and generate-tickets. Use when decoupling containers from tickets, testing block vs classic editor, or attaching tickets to an existing event.
---

# Generate events or tickets separately

Containers only (`ticket_type=none` always):

```bash
wp tec-data-generator generate-events --count=50 --container=event --editor=block
wp tec-data-generator generate-events --count=20 --container=page
```

Tickets only (fresh containers, or attach to existing):

```bash
wp tec-data-generator generate-tickets --count=10 --container=event --min-tickets=1 --max-tickets=3
wp tec-data-generator generate-tickets --event-id=123 --quantity=5 --ticket-type=rsvp
```

Flags: `generate-events --count` (100), `--container` (event|page),
`--editor` (classic|block), `--event-types`, `--batch-size` (100).
`generate-tickets` adds `--min-tickets`/`--max-tickets` (1/1),
`--min-attendees`/`--max-attendees` (1/20), `--ticket-type` (rsvp|paid).

## Safety

- `editor` only switches container `post_content` (plain vs Gutenberg markup,
  tagged `_tec_data_generator_editor`). Ticket creation always uses
  production `ticket_add()` regardless of editor — never invent block-ticket
  storage.
- Every container tagged with the generated marker; cleanup finds both editors.

## Verify

Confirm containers exist with no tickets (events) or with expected
ticket/attendee counts (tickets). Save the run ID.
