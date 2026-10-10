# UGC chat: the todo

Only what is still open. Add new items here and nowhere else; when one is done, delete it and note it under Done
(date and commit). Mockup the build must match: https://claude.ai/artifact/E9eE41WsjNVscrRS3aBHzP (look:
https://claude.ai/artifact/3rF2EVivGciqqgfD6e2bA4).

Last updated: 2026-10-10.

**Status:** `open` not started · `partly` some built · `ready` built, needs a real run or a look · `blocked` waiting
on the owner or another item · `proposed` not yet approved.

**Rules (owner, 2026-10-10):** no wizard, everything from one prompt box at once; the agent infers the UGC type, every
type stays available and the user can name one (Type: Auto); ask when unclear, one question at a time with chips, as
many as needed; product photos are references, never the first frame (the avatar's look and the product's real shape
and size matter); always ONE video unless the user is explicit (several presenters, or asks); a pasted script is kept
word for word, with a "Rewrite with AI" offer; presenters are stock actors, your own upload, saved characters (default
voice or set one) or "Let us pick"; no voice upload, voice is pace, delivery and direction per presenter; sample UGC
upload allowed; chips compact like Create's ComposerTray; "Fine-tune in editor" stays (videos are Classic projects);
assembly stays simple, motion graphics stay in Create.

## 1. Build next

| ID | Item | Status | Next |
|---|---|---|---|
| P2-UI | Chat screen: prompt box with Create-style pills (product, footage, link, sample, script), thread, question chips | open | build to the mockup |
| P2-CAST | Presenter popover: stock / upload / saved character / Let us pick; per-presenter voice (pace, delivery, direction) | open | build |
| P2-TYPE | Type: Auto ▾ menu with all 13 types | open | build |
| P2-PITCH | Pitch card: summary, script, presenters, price, consent, Make it; low-balance state | open | build |
| P2-MAKING | Making and delivered: live status per video, then Download / Share / Fine-tune in editor | open | build |
| P2-ROUTE | Chat becomes the UGC entry; the old wizard stays reachable | open | build |
| P2-REWRITE | "Rewrite with AI" on a pasted script (free) | open | build (API + UI) |
| P3-TYPES | Assembly for new types: green screen cutout over the product page, before/after and comparison splits, ASMR, skit | open | after P2 |
| P3-PLAYBOOKS | Per-type playbooks for the director and planner | open | after P2 |
| P4-CHANGE | Change the delivered video by chat (one component, per change-scope rule) | open | after P3 |
| P4-MCP | MCP / developer API tools for the chat | open | after P3 |

## 2. Needs a decision or a look

| ID | Item | Next |
|---|---|---|
| D-TYPE | An explicit type beats the sample's type (recommended) | owner OK |
| D-LEN | A pasted script's length wins over the chosen length (recommended; built that way) | owner OK |
| D-CHAR | Saved characters come from Library → Characters (recommended) | owner OK |
| LIVE | A real paid make from the chat on prod after deploy | after push |

## 3. Other open items from 2026-10-10 (not UGC chat)

| ID | Item | Status |
|---|---|---|
| WEAVE-60 | Weave up to 60s before falling back to Classic: render-cap raise plus tests | open |
| EXP-WARN | Warn that a 16:9 export of a vertical clip is pillarboxed | proposed |
| CUTOUT-PC | Presenter cutout as an ffmpeg post-composite | proposed |
| UNDO-GAPS | "Re-record all voices" not busy-guarded; a failing step can't be discarded; voice re-record not undoable; history not deleted with the workspace | open |

## Done

- 2026-10-10 `32e61846` Phase 1 backend: chats + messages tables, `ugc_chat_turn` director (asks or pitches, word
  budget enforced, no invented claims), pitch priced by the existing pipeline, Make it via one-shot/composed with
  consent and a double-click lock, live take status.
