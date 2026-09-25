# Editor operations through MCP — Phase B

Local implementation, 25 September 2026. MCP version 1.4.0. Not deployed.

## Read, propose, approve, apply

1. Call `get_project(video_id)` for the current revision, scenes, readiness,
   saved settings and animation history.
2. Call `get_project_schema(video_id)`. `settings_schema` contains field types,
   enum values, bounds, defaults, units, null/merge behavior and provider limits.
3. Send `propose_edits(video_id, revision, changes)`. Review the validated changes,
   any bulk preview and total credits with the user. Proposing does not apply them.
4. After approval, call `apply_edits(video_id, proposal_id)`. Replay that same
   proposal after a transport timeout. Never obtain a replacement just because
   the HTTP response was lost; inspect `get_operation` first.
5. Read the project again; wait for queued work and resolve stale narration or
   lip-sync before exporting. Download through `get_video_result`, selecting an
   explicit export and obtaining approval if it is stale.

Unknown operation fields and unsupported nested settings are rejected. Stored
job tokens, progress flags and image-generation history are not writable settings.
Owned profile/asset IDs remain workspace scoped. Whole-video one-shot/restyle
UGC takes reject scene and bulk edits.

## Clearing settings

Omitted project fields stay unchanged. Explicit null clears the selected channel,
brand kit or music asset:

```json
{"op":"update_project","channel_id":null,"brand_kit_id":null,"music_asset_id":null}
```

Voice settings shallow-merge into existing settings. Other settings objects
replace their saved object; caption edits retain an omitted UGC headline. Check
schema descriptions before sending null: null voice settings preserve the current
voice object, whereas null music settings reset the music mix defaults.

Scene voice ID/provider/speed/direction are now retained by shared editor request
validation. Speed is 0.25–4×. Gemini consumes delivery direction; OpenAI and clone
adapters do not. Stability remains a stored legacy preference with no promised
synthesis effect. The legacy transition field is stored but not consumed by the
current export renderer. These limitations are also included in discovery.

## Images and previous animations

```json
{"op":"generate_image","scene_id":123,"model_key":"gpt-image-2","style":"anime","prompt_override":"A ceramic mug on a clean desk."}
```

The image prompt override is limited to 1,000 characters. Use discovered model
keys and visual styles. The same controller and GenerateAIImageJob receive these
inputs from the app and MCP. They configure the requested render, but successful
generation saves the resulting prompt, style and model back onto the scene.
This is not an ephemeral preview. Omitting model_key uses the configured default.

`get_project` exposes each scene's `animation_history`. Restore a listed owned
asset without paying for another render:

```json
{"op":"use_animation_history","scene_id":123,"asset_id":456}
```

An asset must still exist in the workspace AND in that scene's history. Restoration
is refused while image/animation generation is active. Relevant scene locks must
be deliberately unlocked in a separately approved edit first.

`swap_visual` with a library asset now uses the editor's ownership-checked scene
update. A stock query uses its visual-provider path. Supplying both is rejected.

## Rewrites

`rewrite_scene` is explicitly **direct apply after proposal approval**. It does
not show a candidate before replacement. The proposal describes the rewrite mode,
not the as-yet ungenerated words. If the user wants to approve exact wording,
prepare that text first and propose `update_scene` with `script_text` instead.
That applies the exact approved text without another rewrite call.

## Bulk workflows

Each bulk action must have its own proposal, after prior edits/generation finish.

```json
{"op":"rerecord_all","scene_ids":[123,124]}
```

Rerecording keeps each scene's script and voice. Empty scripts, relevant locks,
active narration and unselected scenes are reported as skipped. Bulk generation
uses the project's narration language; the individual re-record path can use a
scene language override.

```json
{"op":"restyle_all","style":"anime","model_key":"gpt-image-2","scene_ids":[123,124]}
```

Restyling retains each scene's own prompt; it has no bulk prompt override. It skips
active image generation and relevant locked fields. When the entire project is
selected, the dashboard behavior also updates the default style for later scenes.
A subset (including exclusion of locked scenes) does not change that default.

```json
{"op":"animate_all","tier":"quick","quality":"720p","duration_seconds":5,"scene_ids":[123,124]}
```

Animation uses each scene's still, or an explicitly selected `source_asset_id`
for every selected scene. The preview reports replacements. Identical stills
share one paid render; spokesperson scenes cannot share because their audio differs.
Missing images, active animation, missing spokesperson audio, locks and unselected
scenes are disclosed. Spokesperson needs likeness consent; its price uses each
scene's actual audio duration and saved lip-sync engine (otherwise the configured
default). Select a different engine through a single-scene animation proposal.

Omit `scene_ids` for all eligible scenes; an empty array selects none. Foreign
scene IDs fail validation. A preview lists per-scene costs, skips, render count,
sharing and aggregate price. The API rechecks eligibility/sources/cost before
confirmed dispatch; changes require another proposal. No partial affordability
strategy spends as much as possible without approval.

Apply results list accepted scene IDs and skips; `queued` means dispatched, not
successful media. Poll `get_operation` and `get_project` for results and errors.
For a partial failure, inspect and reconcile uncertain execution first, then
propose ONLY the failed scene IDs. Never rerun the entire bulk request to recover
one scene. Original-proposal replay does not dispatch completed work again.

## Verification

`mcp/tests/editor-contract.mjs` runs the real MCP SDK over localhost HTTP against
a disposable mock API. It records the actual forwarded null/image payloads.
`DeveloperApiTest::test_recorded_mcp_payloads_reach_project_storage_and_image_job`
then submits those payloads through the real Laravel controllers and asserts
persisted selections and the dispatched image job arguments. No paid provider runs.

To reproduce with Node and installed MCP dependencies:

1. Prepare a disposable directory containing the MCP package's `node_modules`
   (install its package.json there; do not change the running sidecar).
2. Run `MCP_TEST_DIRECTORY=/path/to/test-dir node framecast-app/mcp/tests/editor-contract.mjs`.
3. Run the API tests with `MCP_EDITOR_PAYLOADS=/path/to/test-dir/editor-payloads.json`.
   Add `PHASE_A_ACCOUNTING_TESTS=1` for the accounting-enabled pass.

The normal PHP suite explicitly skips the recorded-transport test when its fixture
is absent; a normal pass alone therefore does not prove MCP-to-storage forwarding.
