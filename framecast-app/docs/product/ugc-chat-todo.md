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
| P2-ASPECT | Only 9:16 offered: one-take always renders 9:16; composed takes accept 1:1 and 16:9 but the chat sends 9:16 | open | decide, then pass aspect through |
| P2-POLISH | Small: a picked presenter is named in the pitch only when the summary starts "A presenter/creator…" | open | build |
| RUN-RETRY | The take page's "Retry this scene" now refuses one-take videos (it would have made a still image); give it a real one-take retry (remake the clips) for the old form too | open | build |
| P3-TYPES | Assembly for new types: green screen cutout over the product page, before/after and comparison splits, ASMR, skit | open | after P2 |
| P3-PLAYBOOKS | Per-type playbooks for the director and planner | open | after P2 |
| P4-CHANGE | Change the delivered video by chat (one component, per change-scope rule) | open | after P3 |
| P4-MCP | MCP / developer API tools for the chat | open | after P3 |

## 2. Needs a decision or a look

| ID | Item | Next |
|---|---|---|
| D-TYPE | An explicit type beats the sample's type (recommended) | owner OK |
| D-LEN | A pasted script's length wins over the chosen length (recommended; built that way) | owner OK |
| D-REWRITE | Rewrite with AI is free (built that way) | owner OK |
| D-CHAR | Saved characters come from Library → Characters (recommended) | owner OK |
| LIVE | A real paid make from the chat on prod after deploy (Make it, making, ready, Download, Review & share, Fine-tune) | after push |

## 3. Other open items from 2026-10-10 (not UGC chat)

| ID | Item | Status |
|---|---|---|
| WEAVE-60 | Weave up to 60s before falling back to Classic: render-cap raise plus tests | open |
| EXP-WARN | Warn that a 16:9 export of a vertical clip is pillarboxed | proposed |
| CUTOUT-PC | Presenter cutout as an ffmpeg post-composite | proposed |
| UNDO-GAPS | "Re-record all voices" not busy-guarded; a failing step can't be discarded; voice re-record not undoable; history not deleted with the workspace | open |

## Done

- 2026-10-10 Try again in the chat: a failed video is made again from the same pitch inside the chat (failed take
  removed; it was never charged). The take page's scene retry no longer turns a one-take video into a still image.
  Local workers were on 2026-10-09 code without the Omni start-frame fix (prod has it); synced.

- 2026-10-10 One-take videos longer than 8 s on Omni/Veo were cut off mid-sentence (a long talking shot went whole into
  one 8 s clip); long shots now split at sentences into clips that each fit. Sent files show as pills with thumbnails.
  A pitch's state follows its video (Making… / Made / Needs a look) instead of "Made" at Make it.

- 2026-10-10 User-POV review, local (vague prompt, photo + link, pasted script + Rewrite, two presenters with voices +
  explicit type, change after pitch, reload, typed "I'll send the link", Make it hand-off with jobs held, phone). Fixed:
  uploads stuck at 0% and blocking Send (non-reactive copy); chips that promised a later answer looped the same
  question 4 times; invented product names; pitches promising green-screen pages and splits the pipeline cannot make;
  "same again" drifting from 15 s to 27 s (the director now sees its last pitch's type, length and script); videos
  titled with the script's first words (now the pitch title); unfinished chats listed as "New UGC video"; long fact
  cards; link shown twice; phone pitch card layout; "no product photo" hint on pitches.

- 2026-10-10 Stock actors (38) imported from prod into local (photos into local MinIO) so the picker can be checked locally.

- 2026-10-10 Phase 2 chat screen (`/ugc-ads/new`, `/ugc-ads/chat/:id`; old form at `/ugc-ads/wizard`): one prompt box
  with Create-style pills (photos, footage, sample, link; drop and paste), presenter popover (Let us pick, upload a photo
  with consent, actors, your characters, per-presenter pace/delivery/direction, same voice for all), Type: Auto menu,
  length menu, fact cards from page and sample, question chips, pitch card with script, price, top-up and consent,
  making and ready states (Download, Review & share, Fine-tune in editor), free Rewrite with AI on a quoted script.

- 2026-10-10 `32e61846` Phase 1 backend: chats + messages tables, `ugc_chat_turn` director (asks or pitches, word
  budget enforced, no invented claims), pitch priced by the existing pipeline, Make it via one-shot/composed with
  consent and a double-click lock, live take status.
