# Phase D — UGC API/MCP workflow

Implemented locally; not deployed. MCP **1.6.0**. Uses the existing developer
bearer authentication and workspace scope. No paid provider smoke runs were made.

## Supported modes and settings

| Setting | Composed | One-shot |
|---|---|---|
| Cast | `character_ids`, up to five | `character_id` or `presenter_description` |
| Voice | Gemini `voice_key`; otherwise a gender default per character | Native model speech; no selected or cloned voice |
| Variants | Base plan plus up to five variants, at most ten total takes | One take only |
| Aspect ratio | 9:16, 1:1, 16:9 | 9:16 only |
| Product references | `product_asset_id` | Singular and plural `product_asset_ids` |
| Demo video | Planned uploaded cutaway segments | `demo_asset_id`, subject to one-shot demo restrictions |
| Quality | Pipeline-defined | Full, or draft only when resolved Seedance route supports it |
| Presenter strategy | Referenced cast | `cast_style`: exact or variant |
| Setting/product/tone description | Put direction into the plan | Explicit setting/product/tone fields |
| Fidelity | Unsupported | Unsupported: native controller validates the old field but never consumes it |

Quotes now reject mode-specific settings that would otherwise disappear silently.
`fidelity` was removed from MCP; the API explicitly refuses it rather than
pretending to switch models. Quotes return `chosen` including the aspect ratio,
language, voice mechanism, product ids, resolved one-shot engine and quality, and
composed voices per character. One-shot exact/reference routing is reported via
`presenter_reference_used`; no pixel-identical identity guarantee is made.

Cloned voices work in the ordinary editor TTS workflow, **not composed UGC**.
The developer API now rejects clone keys at quote time instead of at generation.
One-shot native speech does not preserve a saved clone.

## Reference-inspired planning

1. `upload_asset` a video/audio file, or use an existing owned asset.
2. `analyze_ugc_reference` calls `POST /ugc/reference` with `asset_id`.
3. Inspect the returned `reference.shape` and `reference.beats`.
4. Pass these two fields as `plan_ugc.reference`, with the new brief/script.
5. Review the plan, then `estimate_ugc` and obtain approval before `create_ugc`.

Analysis delegates to the app's existing reader; no customer credit debit is
added. Transcription/model processing still has an operating cost. This is a
synchronous read; MCP allows up to 180 seconds, subject to upstream proxy limits.
This does **not** expose My Footage/restyle creation. That requires a separate
contract for source rights, preservation, replacement instructions and fidelity;
it must not be advertised as supported by these two UGC modes.

## Presenter preview

- `estimate_presenter_preview`: character id and explicit user consent. Returns a
  ten-minute quote at the current GPT Image 2 preview price.
- `create_presenter_preview`: character id, quote id and idempotency key (MCP
  defaults to quote id). Charged only through the native preview workflow.
- The preview is a generated still **inspired by the character's appearance
  description**, not the final model's output or a guaranteed identity match.
- Character changes invalidate the quote. Consent is retained in the quote.
  Workspace, key spend cap and balance checks apply before execution.
- Success persists the result in the quote. Replaying the same request returns
  that asset with a fresh signed URL, without another render or charge.
- After an ambiguous timeout, retry the same key/quote. A pending operation means
  wait or inspect operation status; do not create a replacement paid request.
- A deleted preview returns 410 rather than silently paying to regenerate it.

## Asynchronous lifecycle and narration

`create_ugc` returns each take separately. Poll each `get_video_status` and use
`get_video_result` only after completion. Replaying creation now reads those same
status rules, including export completion and stale exports, instead of reporting
completed takes as still generating/exporting.

Single and bulk spokesperson animation refuse/skip stale narration. The worker
checks again before charging, and automatic dispatch waits for current audio.
If narration changes during a render, the resulting clip is marked outdated;
a fresh lip-sync render against the replacement audio clears it. Generation tokens
prevent an obsolete worker from replacing a newer animation.

## Local verification

- Developer API: mode restrictions, resolved choices, foreign product rejection,
  reference ownership and planner forwarding, consent and preview single-charge
  replay, composed variants and one-shot create/pending/result/completed replay.
- Shared UGC execution suite: take caps, insufficient/changed estimate rejection,
  replay, variant assets, foreign assets, interrupted creation and take recovery.
- Spokesperson: preflight and queued stale narration refusal with no debit;
  mocked provider render completing after an audio change stays stale, and a
  rerender uses replacement audio and clears the flag.
- Existing editor/export suites cover image operations, animation/export settings,
  revision guards, export freshness, permissions and completed-file selection.
- Real HTTP MCP contract exercises reference analysis → planning and presenter
  preview estimate/create forwarding alongside the earlier editor/media tools.

Providers are mocked and stored/exported output fixtures are used in automated
lifecycle tests. These checks do not establish visual quality, live provider
availability or real FFmpeg encode success for every model. A paid smoke matrix
must record consent, model, credits and result separately if later authorized.
