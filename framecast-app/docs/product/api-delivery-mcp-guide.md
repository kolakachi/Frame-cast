# Phase E — delivery and assistant handoffs

Local implementation, 25 September 2026. Not committed or deployed. No external
messages, social posts or paid provider calls were made during verification.

## Delivery contract

Use MCP `prepare_delivery` or `POST /api/developer/v1/videos/{id}/delivery/handoff`.
Supply `action` (`public_share`, `approval_request`, or `schedule`), the current
editor `revision`, and a completed `export_id` belonging to this project.
`allow_stale: true` is only appropriate after the user explicitly chooses an older
or stale export. It does not bypass ownership or completion checks.

The endpoint validates workspace access, current revision, export freshness and
completion. Scheduling also requires the social publishing entitlement. Viewer
API keys cannot invoke this POST. Errors retain the result endpoint's structured
codes, including revision_conflict, stale_export and not_ready.

Success means `outcome: handoff_required`, `external_action_completed: false`.
Return the supplied `app_url` and `next_step` to the user. Nothing has been
published, emailed, shared or scheduled. The API returns the reviewed version,
not a delivery receipt.

This is a preflight, not a pinned-version delivery link. The app opens the editor;
it does not automatically select the supplied export. The user must check the
version again, select the delivery action and confirm:

- Public sharing: anyone-with-link visibility. The public link can follow newer exports.
- Approval request: reviewer email, message and expiry before sending.
- Scheduling: connected account, destination, content, time and timezone.

## Assistant results

`schedule_post` is an app navigation tool. Its planned execution is `app_handoff`;
applying it preserves the native `navigate` result, supplies an explicit handoff,
and reports zero scheduling spend. A replay returns the same recorded handoff.
It must never be described as a completed post. Assistant handoffs set
`version_checked: false`; use the delivery preflight for an explicit export check.

The assistant tool inventory uses an explicit allowlist. Newly added app tools do
not automatically become public API tools. Capabilities advertise the delivery
contract and app-only exclusions.

## Deliberate app-only scope

| Operation | Decision |
| --- | --- |
| Scene, character and voice-profile deletion | App-only; no destructive API parity expansion |
| Preset creation/deletion | App-only; existing preset discovery/use remains supported |
| Assistant undo and conversation reset | App-only UI/history workflow |
| Persistent assistant brief editing | App-only; per-request video briefs remain supported |
| Assistant auto-apply preferences | App-only; API changes retain explicit plan/apply steps |

Ordinary editor changes, scene script editing, generation and export are separate
supported API operations. Export/download availability does not imply publishing
support. Actual programmatic delivery would require a separate contract for
recipient/destination confirmation, pinned versions and durable delivery receipts.

## Verification

Laravel regressions exercise ownership, stale-version acknowledgement, incomplete
exports, revision conflicts, viewer restrictions, absence of emails/share-token
creation, assistant scheduling navigation and durable replay. The real MCP HTTP
contract checks tool discovery and exact delivery payload forwarding to a mock
API. Production destination delivery and browser confirmation flows were not
exercised; they remain the existing authenticated app workflow.

Local check results: 139 focused API/editor/auth/UGC regression tests passed
(1,112 assertions); DeveloperApiTest with operation accounting enabled passed
78 tests (792 assertions). Real MCP HTTP forwarding passed; OpenAPI YAML parsed
and `git diff --check` passed. This is targeted coverage, not a claim that the
entire repository suite is green; existing caption-render parity failures remain
recorded in the backlog.
