# API/MCP media, narration and voices — Phase C

Deployed (first in MCP **1.5.0**; current server 1.8.x). All routes below use
`/api/developer/v1`, the existing bearer authentication, workspace membership and
API rate limits. Never put a bearer token in a public media URL.

## Upload and inspect media

- `POST /assets`: `title`, `asset_type` (`image`, `audio`, `music`, `sound`, `video`),
  and **either** multipart `asset_file` (100 MiB maximum) **or** JSON
  `content_base64` (8 MiB decoded maximum). No URL imports. Actual file bytes are
  MIME-checked; renaming an image to MP4 does not make it a video.
- Supported formats: JPEG, PNG, WebP; MP3, WAV, M4A, OGG, FLAC; MP4, MOV, WebM.
  SVG/HTML and unsupported types are rejected. Music and sound require audio.
- Upload returns `data.asset.id`. Use `GET /assets/{id}` (`get_asset`) to inspect
  the file, duration when available and transcription. Statuses are
  `not_requested`, `queued`, `processing`, `completed`, `failed`.
- `GET /library?type=audio` and `type=sound` expose workspace narration/samples and
  sound effects, alongside existing image/music/video discovery. Optional `q`
  searches titles; pagination is 50 items. Foreign workspace assets return 404.
- `upload_asset` sends base64 via MCP. The client must have actual file bytes;
  it must never invent base64. ChatGPT attachment access depends on the client.
  For large footage use multipart from a developer client; the MCP tool does
  not itself read a user's local disk. Reverse proxy and PHP upload limits must
  permit the requested size (the API does not override those settings).
- Uploads are not idempotent: each successful submission creates a new asset.
  Keep the returned id. Do not blindly replay an upload after an ambiguous timeout.
- Thumbnail/transcription jobs follow the app's existing queues. Upload and
  transcription currently have no customer credit debit; this does not mean
  the underlying transcription provider has no operating cost.

## Attach custom narration

Read `get_video_project` and use the returned revision with `propose_edits`:

```json
{"video_id":216,"revision":"<current revision>","changes":[
  {"op":"use_narration","scene_id":123,"asset_id":456,"mode":"audio_and_script"}
]}
```

- `audio_only` attaches workspace audio and keeps the scene script, even while
  transcription is pending or failed.
- `audio_and_script` requires completed, nonempty transcription. The proposal
  freezes the transcript shown for approval; later transcription changes do not
  silently replace approved text.
- Both are zero-credit edits and use the existing proposal/apply workflow,
  revision checks, idempotency and scene locks. Neither synthesizes new audio.
- Replacing audio marks an existing spokesperson animation stale. Regenerate
  lip sync in a separately quoted action. Existing export freshness behavior
  still applies. Scene duration is not automatically promised to change.

## Create, preview and reuse voices

1. Upload an `audio` asset with a clean sample of the permitted speaker.
2. Ask the user to confirm the rights and speaker's consent to clone/use the voice.
3. Call `clone_voice` / `POST /voices/clone` with `name`, `source_asset_id`,
   `consent: true`.
4. This registers a **zero-shot Chatterbox reference**, not a training job.
   Registration/conversion is synchronous; success returns an active voice.
   Errors include invalid sample, conversion failure and `voice_cloning_limit`.
5. Creation checks the existing plan allowance. Workspace locking serializes
   allowance checks, including native app cloning. A retry using the same source
   asset reuses an active developer-created clone instead of consuming another
   slot. A reuploaded copy has a different asset id and is a separate sample.
6. The original sample id, consenting user and consent time are persisted.
7. `preview_voice` / `POST /voices/preview` accepts string `voice_id`. **Clone
   preview plays the original source sample**, identified as `source_sample`.
   It is not a synthesized demonstration. Built-in voices use the app's fixed
   cached synthesized sample. No arbitrary text synthesis is exposed here.
8. `save_voice` / `POST /voices` takes `name` and accessible string `voice_id`.
   It saves/reuses a workspace profile, preserving the known provider. Arbitrary
   provider keys and other workspaces' clones cannot be registered through it.
9. `list_voices` returns string `id` for generation plus numeric
   `voice_profile_id` for editor profile references. To change an existing scene,
   set `voice_settings_json.voice_id` explicitly (a profile id alone does not
   select the synthesis voice), apply that edit, then quote `regenerate_voice`.
   Show the credit estimate and obtain approval before synthesis.

Registration, save and fixed preview carry no customer credit charge. Actual
narration is separately quoted at the resolved voice engine's current rate.
Selecting an existing clone does not create a new clone or consume another slot.

## Character reference consent

Creating from references and adding/replacing references on update require
`consent: true`. Updates store `consent_acknowledged_at` and invalidate the cached
appearance. References must be workspace image assets. Description/name-only
updates do not require new consent. Passing `reference_asset_ids: []` removes the
primary reference as well. MCP `update_character` exposes consent and instructs
the assistant to ask first; native new-file selection resets its consent checkbox.

## Deployment and validation

Apply `2026_09_25_220000_add_voice_consent.php` before exposing clone registration.
It adds nullable fields without changing existing voices. Deploy API and MCP
1.5.0 together; refresh client tool discovery. No production changes were made
while implementing this phase. Phase A accounting rollout gates still apply.

Regression tests cover upload byte validation, size rejection, processing status,
workspace isolation, clone consent/allowance/reuse, provider-preserving profile
save, character update consent and narration transcript freezing. The real HTTP
MCP contract test covers tool discovery, media/voice/consent forwarding and a
payload above the SDK's old default JSON size limit. Provider quality, live
transcoding and production upload/proxy limits require deployment smoke checks;
the automated tests do not spend money on live generation.
