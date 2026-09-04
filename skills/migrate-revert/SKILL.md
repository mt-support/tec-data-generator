---
name: tec-dg-migrate-revert
description: Schedule the rsvp-to-tc migration forward or roll it back with wp tec-data-generator migrate and revert, then poll status. Use after generating test data to migrate V1 RSVP to Tickets Commerce or reset it to V1.
---

# Migrate or revert

```bash
wp tec-data-generator migrate
wp tec migrations executions rsvp-to-tc
wp tec-data-generator revert
```

Both commands only schedule the full run — background processing via
Shepherd/Action Scheduler follows. They return immediately; never loop
expecting synchronous completion.

## Safety

- Poll status (`wp tec migrations executions rsvp-to-tc` or admin page)
  instead of assuming completion.
- Errors are status-guard rejections (migration not in a runnable state) —
  read the message, it names the blocking state. Don't retry blindly.
- Run/revert cover the entire migration (every batch), including any
  non-generated legacy RSVP data on the site.

## Verify

Status settles to completed after `migrate`; `revert` returns it to a
runnable state for another cycle.
