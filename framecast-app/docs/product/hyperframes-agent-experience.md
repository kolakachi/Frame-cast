# Hyperframes: conversation-first experience

Status: proposed UX revision, based on the user's September 28 direction and supplied workflow transcript. Supersedes the separate brief → plan → workspace → variants navigation in the earlier UI mockups. The integration spec's security, accounting, versioning and authorization requirements still apply.

## Primary experience

Use the existing WyvStudio navigation and workspace. Open one conversation, with a large prompt box and attachment action. Show uploaded images, video and audio inline. Ask about missing information conversationally; use sensible visible defaults rather than forcing a form. Do not ask users to choose a model, renderer or production pipeline.

The right panel is closed by default and contains Details and History. On phones it is a dismissible drawer. History shows all completed versions, their approval/export status and the changes made. Inspecting a previous version does not make it current. Restoring produces a new version and preserves existing exports.

Each assistant response can contain a short explanation, a media result, one clarification question, a compact approval card, or progress. A result has playback, download, share, schedule and optional alternatives. Follow-up edits stay in the same conversation. Inline media never remounts or restarts because job status updates.

## Approval and recovery without a wizard

Before paid work, show one plain-language summary, maximum cost and an explicit approval button. Put technical output details and cost breakdown behind “See the plan”. Approval binds to a specific plan, version, output and spend ceiling. Re-quote expired approvals and scope changes. Ask separately before additional paid media generation. A valid end-to-end quote may include final rendering; otherwise final rendering needs its own compact approval in the conversation.

Show understandable stages: Reading your footage, Making your video, Checking the result, Finishing. Detailed activity is collapsed. Only show percentage when actual progress is measurable. Cancellation, failure, insufficient credits and unavailable assets appear at the relevant message with clear recovery actions, not a different page. Preserve the previous working result. Unknown provider outcomes are reconciled rather than retried blindly.

Actions refer to the version on their result card. If the user's edits are newer than the selected export, show “Use this version” or “Update first”; do not silently share a different version. Share and schedule still require their own confirmation and permissions.

## Requirements surfaced by the transcript

The transcript is a workflow reference, not proof of model capabilities or a price/performance guarantee.

| Workflow | Required implementation |
| --- | --- |
| Transcribe first | Timestamped transcript, source asset identity and word/segment timing. Original audio stays aligned after edits. |
| Cut mistakes and silence | Non-destructive edit decisions with source in/out ranges and a source-to-output time map. Preserve negation and meaning; ambiguous edits should be reviewed. |
| Animate on spoken words | Map overlays and motion cues to transcript timestamps; validate final placement after cuts. |
| Keep a presenter visible | Explicit crop/layout rules, safe zones and sampled output checks. Do not claim face tracking unless it is implemented and tested. |
| Learn from a reference | Analyze pacing, typography and layout as inspiration. Distinguish reference-only assets from footage authorized for reuse. |
| Collect missing material | Scoped asset search/capture tools with provenance, usage rights, domain/network safeguards and workspace isolation. Not arbitrary browser or filesystem access. |
| Generate images then animate | Use existing generation tools with approved cost, retained intermediate assets and resumable parent jobs. |
| Save a reusable style | User-approved, workspace-scoped preferences with versions and explicit “remember this” consent. Treat references as data, not executable agent instructions. |
| Verify and repair | Bounded render → inspect → repair loop. Check text bounds, timing, audio presence, crop and source fidelity; report limitations. Do not promise that every frame was reviewed unless it was. |
| Build a reel from many recordings | Ingestion limits, resumable uploads, indexing, searchable transcripts, clip selection and cost estimates. The transcript's 105 GB example is outside the initial 5–30s pilot scope. |

## Design deliverable

`create-ui/agent-new.html` is the latest user-approved entry point, with `create-ui/agent.html` for an existing conversation. `hyperframes-ui/00-agent.html` is the earlier concept. It demonstrates the conversation, inline layout samples, local attachments, approval card and optional panel. The earlier screens remain design references for detailed states, not mandatory user steps.

The mockup has no model integration. Its animation is an illustrative CSS layout, not generated footage. Local video/audio attachments can be played from the user's device without upload. Sending a prompt demonstrates approval and result placement only; it does not implement semantic editing. Production should replace samples with actual immutable media artifacts and measured verification reports.

## Acceptance criteria

- A first-time user can attach footage and request an edit without opening Details.
- Defaults such as ratio, duration and voice are summarized before paid work; users can change them in plain language.
- One conversation contains the request, approval, resumable progress and downloadable result.
- Paid retries and additional media never exceed the approved budget silently.
- Every result remains associated with its exact revision and source assets.
- History is accessible with the right panel hidden; its toolbar toggle is keyboard accessible.
- No mandatory timeline, technical model names, codec choices or credit-ledger vocabulary in the main conversation.
- Transcript cuts preserve audio/video synchronization and intended meaning.
- Style preferences never leak across workspaces.
- Output checks are described accurately, including checks that could not be completed.

## Creative quality companion

The [creative direction brief](create-agent-creative-direction.md) records the intended capabilities, understand/plan/compose/review workflow, approved style memory and same-footage creative benchmark. Follow it alongside the technical implementation gates.
