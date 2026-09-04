---
name: tec-dg-generate
description: Generate bulk V1 test units (Event/Page/Post + RSVP ticket + attendees) with wp tec-data-generator generate. Use for load-test data, smoke runs before large generation, custom attendee ranges, event-types and ticket-type flags.
---

# Generate bulk test units

Use `wp tec-data-generator generate` for coupled container + ticket units.

## Command

```bash
wp tec-data-generator generate --count=5000 --min-attendees=1 --max-attendees=20 --batch-size=100
```

Flags: `--count` (default 5000), `--min-attendees` (1), `--max-attendees`
(20), `--batch-size` (100, progress ticks only), `--event-types`
(single|recurring|virtual, default single), `--ticket-type` (rsvp|paid|none,
default rsvp).

Requires: `recurring` needs Events Pro or ECP; `virtual` needs ECP;
rsvp/paid need Event Tickets. Bad combos error before creating anything.

## Examples

```bash
wp tec-data-generator generate --count=30
wp tec-data-generator generate --count=200 --min-attendees=5 --max-attendees=50
wp tec-data-generator generate --count=100 --event-types=single --ticket-type=rsvp
wp tec-data-generator generate --count=50 --event-types=virtual --ticket-type=paid
```

## Safety

- Always smoke-test small (`--count=30`) before thousands.
- Default output is V1 RSVP only; `paid` reuses the add-tickets provider path.
- Everything created is tagged `_tec_data_generator_generated` + run ID.
- No Composer, no Faker, no build step.

## Verify

Read the logged run ID (e.g. `run_20260715_153000_ab12cd`) — save it for
scoped cleanup. Spot-check a ticket/attendee in wp-admin.
Next: `migrate-revert` to migrate, `cleanup` to remove.
