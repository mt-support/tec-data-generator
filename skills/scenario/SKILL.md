---
name: tec-dg-scenario
description: Generate realistic QA scenarios (usual 25-250 units or edge 7k-11k units, 1-9 RSVP tickets each, 5-20 percent orphaned) with wp tec-data-generator scenario. Use for day-to-day load or extreme-scale migration stress tests.
---

# Generate QA scenario

Use `wp tec-data-generator scenario --type=usual|edge`. Unit count and orphan
rate resolve via `wp_rand()` within the preset range and print before
generation — every run differs by design.

| type  | units     | tickets/unit | orphan rate |
|-------|-----------|--------------|-------------|
| usual | 25–250    | 1–3          | 5%–20%      |
| edge  | 7,000–11,000 | 1–9       | 5%–20%      |

## Examples

```bash
wp tec-data-generator scenario --type=usual
wp tec-data-generator scenario --type=edge --batch-size=250
wp tec-data-generator scenario --type=usual --min-attendees=10 --max-attendees=100
```

Flags: `--type` (required), `--min-attendees` (1), `--max-attendees` (20),
`--batch-size` (100), `--with-venues` / `--with-organizers` for realistic
titles + Venue/Organizer attach.

## Safety

- Never use `edge` as first smoke test (up to ~100k+ posts). Verify `usual` first.
- Orphans are intentional: container force-deleted, ticket/attendees left
  tagged behind. Confirm via `get_post()` on `_tribe_rsvp_for_event` → null.
- Log the resolved unit count, orphan rate, and run ID from output.

## Verify

Output reports units, tickets, attendees, orphaned count. Save the run ID.
Next: `migrate-revert` to migrate, `cleanup` to remove.
