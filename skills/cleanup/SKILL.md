---
name: tec-dg-cleanup
description: Remove generated test data with wp tec-data-generator cleanup, optionally scoped to one run ID. Use to tear down after migration tests or reset a QA site to clean slate.
---

# Cleanup generated data

```bash
wp tec-data-generator cleanup
wp tec-data-generator cleanup --run=run_20260715_153000_ab12cd
```

Flags: `--run` (optional, one run only), `--batch-size` (200).

## Safety

- Deletes leaf-to-root (attendees → tickets → containers), force-delete
  (`wp_delete_post( $id, true )`), orphans included.
- Only posts tagged `_tec_data_generator_generated` are ever touched.
  Never delete by title prefix or date range — that risks real site content.
- Do not install or run this on production.

## Verify

Rerun scope shows nothing left for the run ID. `cleanup` with no `--run`
fully resets all generated data.
