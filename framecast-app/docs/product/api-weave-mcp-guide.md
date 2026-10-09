# Weave (Create) through the API and MCP

Implemented locally 2026-10-09; not deployed. MCP **1.9.0**. Uses the existing developer
bearer authentication (API keys and OAuth tokens) and workspace scope. Create's own access
rules still apply: a workspace that cannot use Weave in the app is refused here too.

Owner decisions (2026-10-09): the price is **always** confirmed (no auto-run, unlike the app's
under-15-credit rule); a brief **starts planning at once** (planning costs a few credits); v1 is
the tools below. Publishing to socials and agency client workspaces come later.

## Tools and routes

| MCP tool | Developer API | What it does |
|---|---|---|
| `weave_start` | `POST /api/developer/v1/weave/videos` | Brief plus optional `format`, `aspect_ratio`, `duration_seconds`, `voice`, `style_pack`, `effort`, `captions`, `reference_url`, `asset_ids`. Makes the conversation, attaches files and the link, sends the brief, starts planning. `idempotency_key` replays the same video. |
| `weave_status` | `GET …/weave/videos/{id}` | One `state` with a `next` hint (see below). |
| `weave_reply` | `POST …/weave/videos/{id}/messages` | An answer to Weave's question, a change to the plan, or a change to the finished video. Re-plans. |
| `weave_approve` (step 1) | `POST …/weave/videos/{id}/quotes` | The price: `quote_id`, `credits_max`, `credits_estimate`, what it `makes`, `paid_media`, `credits_available`. Nothing runs. `look_token` first records an approved look. |
| `weave_approve` (step 2) | `POST …/weave/videos/{id}/runs` | With the `quote_id` the user agreed to: the build starts. Counts as consent to send the brief and approved media to AI providers. |
| `weave_list` | `GET …/weave/videos?limit=` | Newest Weave videos with their state. |
| `weave_share` | `POST …/weave/videos/{id}/share` | Saves the version to the library, then returns a public link. `version` picks an earlier one; `enabled: false` turns it off. |

A change to a finished video is a `weave_reply`, as in the app, where every message re-plans;
there is no separate change tool.

## States

| `state` | Meaning | The assistant should |
|---|---|---|
| `planning` | The plan is being made (a minute or two) | Check again shortly |
| `question` | Weave asks the user something (`question`) | Ask the user; send the answer with `weave_reply` |
| `plan_ready` | `plan`: summary, idea, other directions, scenes, script, voice, assumptions | Show it; price it with `weave_approve` |
| `look_ready` | `images` of the people or storyboard to approve, `look_token` | Show them; on a yes, `weave_approve` with `look_token` |
| `building` | `stage` of the build | Check again in a minute or two |
| `ready` | `video.preview_url` (signed, about 45 minutes), `version`, `summary` | Share the link; call again for a fresh one |
| `needs_attention` | `problem` | Follow `next`, or open `app_url` |

Every state carries `app_url` (the video in WyvStudio) and, once one exists, the latest `video`.
Scores, review rounds and the reviewer never appear.

## How it is built

`App\Http\Controllers\Api\Developer\V1\WeaveController` forwards each step to the app's own
Create controller and services (ConversationService, PlanService, VariantService,
DeliveryService, CompositionOutputService), reading the conversation's version fresh each time,
so the API cannot drift from the app. MCP tools are in `mcp/server.js`.

## Tests

- `CreateIntegrationTest::test_an_assistant_makes_a_weave_video_through_the_developer_api_and_always_confirms_the_price`:
  start (settings and brief stored), replay by key, plan ready, quote runs nothing, approval starts one run, list.
- `mcp/tests/editor-contract.mjs`: every Weave tool reaches its route and method; step 1 of
  `weave_approve` sends no quote; an unknown format is refused by the schema.

## Not yet

- A live run with a real key against a deployed stack (needs Weave opened to the workspace).
- Publishing (`weave_publish`) through the scheduled-post path; agency client workspaces.
- The ChatGPT app listing text (`chatgpt-app-submission.md`) does not mention Weave yet.
