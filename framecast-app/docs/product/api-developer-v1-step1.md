# Step 1 scope — developer namespace and the five endpoints

25 September 2026 · Implements Stage A items A1 (namespace) and A3 (contract) of
[api-access-todo.md](api-access-todo.md). Baseline `5428bab`.

## What exists that this builds on

- `ProjectController::store` already validates, applies the plan duration cap,
  estimates, checks balance, creates the project and dispatches
  `GenerateScriptJob`. It is ~400 lines inside the controller.
- Every project created today auto-finishes: `FinishGeneratedVideoJob` calls
  `ProjectExportService::finishInitial` once generation is done, which queues
  the export. No explicit export call is needed for a new project.
- Progress lives in `GenerationProgressed::getProgress($projectId)` as
  `{current_stage, current_status, last_message, stages{...}}`.
- Export download links are signed routes with a TTL from
  `media.signed_url_ttl_minutes` (default 720).
- Credits are deducted per stage by 12 jobs, each passing `project_id` in the
  ledger context. There is no reservation primitive except the UGC pass.
- `AuthenticateWithJwt` already resolves API keys; it only needs the path rule
  changed.

## Routes

New group in `routes/api.php`, sibling of `Route::prefix('v1')`:

```
Route::prefix('developer/v1')->middleware('auth.jwt')->group(...)
  GET  /capabilities
  POST /quotes
  POST /videos
  GET  /videos/{id}
  GET  /videos/{id}/result
```

Session JWTs may also call these routes (dashboard testing). API keys may call
**only** these routes.

## Middleware change

In `handleApiKey`, replace the `FORBIDDEN` loop with:

```
if (! $request->is('api/developer/*')) → 403 api_key_forbidden_path
```

Delete the constant and its test; replace with HTTP tests below. Everything
else in `handleApiKey` is unchanged in this step (parity fixes are step 3).

## Endpoints

### GET /capabilities

Calls `CreditService::planTier`, `limitFor`, `balance`,
`UsageService::exportsRemaining`, and the same cost constants as
`/credit-costs`.

```json
{ "data": {
  "plan": "creator",
  "credits": { "balance": 1240 },
  "limits": { "max_duration_seconds": 300, "exports_remaining": 42 },
  "video": {
    "source_types": ["prompt", "script"],
    "visual_modes": ["stock", "ai_images", "ai_video"],
    "animate_tiers": ["quick", "balanced", "premium", "seedance_lite", "seedance_pro", "veo_fast", "seedance_25"],
    "aspect_ratios": ["9:16", "1:1", "16:9"],
    "languages_default": "en"
  },
  "costs": { "script_and_breakdown": 4, "voice_per_scene": 3, "export": 2, "visual_per_scene": { "stock": 1, "ai_images": 16, "ai_video": { "quick": 40, "...": 0 } } }
}}
```

No billing, payment, member or key data.

### POST /quotes

Request (pilot subset of `store()`'s validation; nothing else accepted):

| Field | Rule |
|---|---|
| `source_type` | required, `prompt` or `script` |
| `content` | required string, max 10000 (mapped to `source_content_raw`) |
| `visual_mode` | required, `stock` / `ai_images` / `ai_video` |
| `duration_seconds` | integer 5..600, default 60; plan cap enforced here |
| `animate_tier` | required when `ai_video`, else rejected |
| `animation_pacing` | `short` / `long`, `ai_video` only |
| `aspect_ratio` | `9:16` / `1:1` / `16:9`, default `9:16` |
| `tone`, `title`, `content_goal` | optional strings, same limits as `store()` |

Runs `validateSourceContent`, the plan duration guard and
`CreditService::estimateProject`. Persists an `api_quotes` row and returns:

```json
{ "data": {
  "quote_id": "q_01J...", 
  "credits": { "min": 38, "max": 61, "mid": 50, "breakdown": {...} },
  "scenes": { "min": 6, "max": 9 },
  "balance": 1240, "can_afford": true, "shortage": 0,
  "expires_at": "2026-09-25T10:15:00Z",
  "request": { ...the validated payload echoed back... }
}}
```

Free: no credits are spent by quoting.

### POST /videos

Request: `{ "quote_id": "q_...", "idempotency_key": "client-chosen-string" }`.
`Idempotency-Key` header is accepted as an alternative.

Order of checks:

1. Quote exists for this workspace, else 404 `quote_not_found`.
2. Not expired, else 410 `quote_expired`.
3. If already consumed: same `idempotency_key` → 200 with the existing video;
   different key → 409 `quote_consumed`.
4. Balance ≥ `credits_max`, else 402 `insufficient_credits` (stricter than the
   dashboard's `credits_min`; see decisions).
5. Create via `ProjectCreationService::create($user, $quote->payload)`,
   in a transaction with the quote row locked (`consumed_at`,
   `idempotency_key`, `project_id` set).

Response 202:

```json
{ "data": { "video": {
  "id": 4821, "status": "queued", "quote_id": "q_...",
  "credits": { "authorized_max": 61 },
  "project_url": "https://app.../projects/4821"
}}}
```

### GET /videos/{id}

Loads the project scoped to the workspace (404 otherwise). Maps state:

| Condition | `status` |
|---|---|
| `project.status = generating` | `generating` |
| `ready_for_review`, no completed export, export job queued/processing or none yet | `exporting` |
| latest export `completed` | `completed` |
| `project.status = failed` or latest export `failed` | `failed` |

```json
{ "data": { "video": {
  "id": 4821, "status": "generating",
  "stage": { "current": "tts", "message": "Generating voiceover 3/8", "stages": { "script": "completed", "visuals": "completed", "tts": "running" } },
  "failure": null,
  "credits": { "authorized_max": 61, "spent": 27 },
  "retry_after_seconds": 15,
  "project_url": "..."
}}}
```

`credits.spent` = sum of ledger debits with this `project_id`. `failure` carries
`{ code, message, retryable }` when failed; `retryable` is true only when the
dashboard's `retry-generation` would be offered.

### GET /videos/{id}/result

409 `not_ready` with the current status unless a completed export exists.
Otherwise:

```json
{ "data": { "video": {
  "id": 4821, "status": "completed",
  "download_url": "https://.../media/assets/9911/content?...signature",
  "download_expires_at": "2026-09-26T10:00:00Z",
  "file_name": "video.mp4", "aspect_ratio": "9:16", "duration_seconds": 58,
  "credits": { "spent": 54 },
  "project_url": "..."
}}}
```

Never creates a share token. Uses the same signed route as the dashboard.

## Data changes

- `api_quotes`: `id` (ULID string PK), `workspace_id`, `api_key_id` nullable,
  `created_by_user_id`, `payload_json`, `payload_hash`, `credits_min`,
  `credits_max`, `expires_at`, `consumed_at` nullable, `idempotency_key`
  nullable, `project_id` nullable, timestamps. Unique
  `(workspace_id, idempotency_key)`.
- `projects.api_key_id` nullable, indexed. Set on create through the
  namespace. Ledger attribution per key is then derived through
  `project_id`; no ledger schema change in this step.

## Refactor required

Extract the body of `ProjectController::store` after validation into
`App\Services\Projects\ProjectCreationService::create(User $user, array $validated): Project`.
Domain failures throw `ProjectCreationException(code, message, status, context)`
which both controllers turn into the existing error envelope. `store()` keeps
its validation rules and becomes a thin wrapper. No behaviour change; the
existing project tests must pass unchanged.

## Errors

Envelope is the existing `{ "error": { "code", "message", "context" } }`.
Codes introduced: `api_key_forbidden_path`, `quote_not_found`,
`quote_expired`, `quote_consumed`, `insufficient_credits`,
`plan_duration_exceeded`, `invalid_source_content`, `not_ready`, `not_found`.

## Tests (Feature, HTTP)

1. API key → `GET /api/v1/projects` is 403; `GET /api/developer/v1/capabilities` is 200.
2. Session JWT → developer routes 200.
3. Quote → create → status → result happy path with the queue faked and an
   export job inserted as completed.
4. Expired quote 410; foreign-workspace quote 404; consumed quote with new key 409;
   replay with same key returns the same video id and no second project.
5. Balance below `credits_max` → 402, no project created, quote unconsumed.
6. Result before completion → 409; video id from another workspace → 404.
7. Plan duration cap enforced at quote time.
8. Existing `ProjectController` tests unchanged and green after the extraction.

## Out of scope for this step

Throttling (step 4), membership/suspension/hash-lookup parity (step 3),
voice and character selection, UGC flow, uploads, URL/PDF sources, explicit
export options, the sidecar, OAuth, dashboard key screen.

## Decisions taken in this scope

1. **Create requires balance ≥ quoted maximum**, not minimum as the dashboard
   does. An integration cannot watch a balance mid-render.
2. **No hard mid-pipeline reservation in this step.** The plan's "reserve
   exactly the quoted spend" is not implementable without touching 12
   deduction sites. Step 1 authorizes the maximum, records actual spend per
   project, and A2 adds the enforced ceiling. Plan doc updated to say so.
3. **Pilot source types are `prompt` and `script`** and visual modes `stock`,
   `ai_images`, `ai_video`. Everything else needs assets or uploads.
4. **Session JWTs can call the developer namespace.** Lets the dashboard and
   tests exercise it without a key.
