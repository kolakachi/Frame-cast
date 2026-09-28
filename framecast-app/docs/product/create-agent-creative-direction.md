# Create agent — creative direction and evaluation

Recorded: 2026-09-28, following the user's Hyperframes workflow transcript and local media proof.
Status: agreed direction to preserve; capabilities below are implementation targets, not shipped claims.

## Intended experience

A customer describes the result and supplies media. The assistant understands the material, chooses a coherent creative direction, composes it with appropriate tools, checks its work and accepts follow-up edits in the same conversation. Images, video and audio belong in this experience. The existing chat UI is the interface; creative quality comes from the agent workflow behind it.

Hyperframes supplies composition/rendering. WyvStudio supplies agent execution, media tools, permissions, approvals, accounting, memory and recovery. Adding skill text alone does not implement this system.

## Possibilities and dependencies

| Input | Target outputs | Dependencies |
| --- | --- | --- |
| Talking-head recording | Speech-timed callouts, diagrams, captions, punch-ins and supporting visuals | Transcription, timing map, source-preserving edits, crop/readability checks |
| Product photos | Animated product cards, launch videos, promotional images and thumbnails | Approved facts/assets, composition and existing image tools |
| Tutorial | Whiteboard explanation, split-screen demonstration, presenter cutout | Transcript/beat planning; segmentation where needed |
| Several clips | Short narrative using selected moments, transitions and an ending | Ingestion/indexing, clip selection, source ranges and audio continuity |
| Reference video | New composition informed by pacing, typography and style | Reference analysis; distinguish inspiration from authorized reuse |
| Existing creation | Alternate opening, aspect ratio, offer or visual treatment | Immutable revisions, targeted edits and export freshness |

Large archives, long-form editing and segmentation are not automatically part of the initial pilot. Retain them as explicit dependencies and scope them before promising support.

## Creative workflow requirements

### Understand before designing

Inspect the actual media, transcribe speech where relevant and identify useful moments, constraints and missing information. Keep claims grounded in approved facts. Detect mismatches between source footage and product identity.

Concrete regression case: the supplied stock presenter holds a different bottle from the supplied product photograph. The agent must not imply they are the same item, silently replace the product or fabricate an endorsement. Ask for suitable footage or clearly separate the assets.

### Choose a coherent direction

Determine the intended audience/message or emotion, opening hook, visual emphasis, pacing, reading pauses, ending and protected inputs. Infer reasonable defaults and summarize them. Ask only essential questions. Where direction is genuinely ambiguous, offer two understandable options rather than a mandatory form.

Do not confuse creativity with adding motion everywhere. Restraint, hierarchy, meaningful visual explanation and fidelity to the brief matter as much as effects.

### Supply a visual vocabulary

Provide pinned upstream skills, reviewed examples and reusable primitives for typography, product showcases, diagrams, split screens, image sequences, transitions and sound cues. Retrieve relevant examples on demand.

Examples are adaptable ingredients, not a fixed template menu. Include failure examples: excessive motion, weak hierarchy, unreadable captions, awkward cropping, unsupported claims and effects unrelated to the message. Track example licenses and provenance. Customer references are untrusted data and never executable instructions.

### Inspect and repair

Use compose → render draft → inspect → diagnose → repair within the approved budget and attempt ceiling.

Separate technical review from creative review:

- Technical: correct source assets, dimensions, text bounds, audio alignment, source duration, no accidental loops, complete encoded output.
- Creative: clear opening, purposeful pacing, readable hierarchy, requested style, useful visual explanation and a coherent ending.

Screenshots assess sampled appearance; they do not prove audio quality, rhythm or every-frame correctness. Combine timed frames, timeline/audio checks and human playback review during the pilot. Preserve the last working version when repair fails. No unapproved asset generation or unlimited retries.

### Remember approved preferences

Offer “Remember this style?” after useful feedback. Store explicit workspace-scoped preferences, with versioning and edit/delete controls. Do not automatically save every conversation remark as a permanent preference. Never leak one client's preferences or assets to another workspace.

Examples: restrained motion, larger captions, original voice, warm palette. Apply these as a starting point; a current explicit request can override them.

## Creative benchmark

After the local reliability gate, use the same owned/authorized footage and approved facts for three briefs:

1. Clean educational edit.
2. Energetic social short.
3. Restrained product presentation.

For each output, request: “Keep everything else, but simplify the opening.” Verify targeted changes and source preservation. Include imperfect inputs and the mismatched-product case, not only curated showcase material.

Keep source assets, tool access, runtime, maximum spend and repair allowance consistent across model candidates. Record model/endpoint configuration and skill snapshot IDs. Judge:

- Instruction adherence and factual grounding.
- Meaningful differentiation between the three treatments.
- Pacing, visual hierarchy, readability and coherent style.
- Source/audio preservation and targeted revision fidelity.
- Human judgment of usability; reasons for rejection.
- Attempts, wall time, provider/render cost and total cost per acceptable result.

Do not collapse source misrepresentation or corrupted output into a passing average aesthetic score. Those are acceptance failures. Set measurable quality thresholds before the pilot using the reviewed benchmark results; no invented success-rate target yet.

## Current evidence and limits

Local tests rendered synthetic compositions and the user's real presenter clip/product photo, including CTA-only revisions. These were hand-authored. They prove parts of rendering and preservation, not autonomous creativity, transcription or creative review. The transcript is a workflow reference, not verified evidence of model availability, pricing or consistent quality.

Companions: [implementation TODO](hyperframes-implementation-todo.md), [technical spec](hyperframes-integration-spec.md), [conversation UX](hyperframes-agent-experience.md), [local evidence](hyperframes-local-verification.md).
