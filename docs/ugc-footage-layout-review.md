# UGC and My Footage layout integration

The supplied HTML layouts guide screen organization. Existing WyvStudio navigation, orange/dark tokens, API contracts, generation jobs, and editing controls remain in use.

## Feature mapping

| Screen | Implementation |
| --- | --- |
| UGC brief | Idea, exact script, product URL, optional direction, product image, references, owned assets, and delivery settings. Optional direction collapses to keep the initial form short. |
| UGC plan | Passage navigation beside the selected passage's existing script, visuals, voice delivery, pace, headline, timing and asset controls. Cast and voice selection remain beside the passage list. Additional openings and estimate validation remain available. |
| Approval | Actual credit quote, selected scope, existing consent gates. No unsupported dollar prices or per-video spending ceiling. |
| Production | Real scene status, failed-scene retry, latest completed passage preview. Polling continues while other passages are unfinished, even when a take needs attention. |
| Review | Scene preview, proposed revision actions and costs, explicit approval, persisted revision status, and export. Prior exports are excluded after a revision; the export-ID watermark handles same-second timestamps. |
| Footage analysis | Workspace-authorized source playback, passage seeking, transcript corrections, speakers, important/drop flags. |
| Footage plan | Source versus target wording, treatment rationale, presenter/input collection and approval. |
| Footage comparison | Source playback beside the generated scene preview, passage selection, and links to production/review. These are passage previews, not synchronized final renders. |

## Reliability changes

- UGC submits a stable request UUID for retries of the same payload. A workspace-scoped receipt returns the original run; reusing the key with changed inputs is rejected.
- My Footage derives its request UUID from the session and production inputs.
- Workspace row locking places quota checks and run creation in one transaction. Jobs remain dispatched after commit.
- Uploaded/stock assets are validated and resolved for every selected variant before any project is created.
- Revision proposals show the action diff and estimated credits. Apply rejects a changed estimate. An uncertain or partly successful action batch is not automatically replayed.
- Revision status gates export. Existing exports remain stored but are not offered as the revised result.

## Validation

- PHPUnit: 378 tests, 1,019 assertions passed.
- Production frontend build passed; existing bundle-size/mixed-import warnings remain.
- Disposable PostgreSQL 16: additive migration passed; concurrent duplicate requests returned one identical run; concurrent distinct requests against a one-take cap accepted only one.
- Browser checks with synthetic API responses: brief → passage editing → cast → approval; source analysis/player and comparison; revision proposal → approval → completion; stale download hidden; polling continued after partial failure.
- Paid provider generation and production deployment were not exercised.

## Integration

This branch builds on local commits `0bf7e9f`, `669735c`, and `7b5fda8`, plus remote master `36187b3`. The local commits contain the existing run/review and My Footage implementations.

Deploy the additive `2026_09_18_120000_create_ugc_run_requests` migration before serving the new generation controller. It adds a receipt table and does not alter existing projects or media. No production service, database, or Docker configuration was changed during this work.
