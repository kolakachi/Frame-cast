# UGC chat: the todo

Only what is still open. Add new items here and nowhere else; when one is done, delete it and note it under Done
(date and commit). Mockup the build must match: https://claude.ai/artifact/E9eE41WsjNVscrRS3aBHzP (look:
https://claude.ai/artifact/3rF2EVivGciqqgfD6e2bA4).

Last updated: 2026-10-11.

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
| WARDROBE-HOLD | The planned outfit is often ignored: the presenter's photo outfit wins (prod take 280: fleece planned, her white shirt shown) | open | try naming the outfit in every clip prompt and in the first-clip reference order; check on the next takes |
| FACE-RETRY | Face scores are recorded on every cast clip (take 128: 85/85, prod take 280: 92/85); remakes are behind UGC_FACE_RETRY, off | open | turn on once ~10 takes show where real mismatches score |
| P2-ASPECT | Only 9:16 offered: one-take always renders 9:16; composed takes accept 1:1 and 16:9 but the chat sends 9:16 | open | decide, then pass aspect through |
| P2-POLISH | Small: a picked presenter is named in the pitch only when the summary starts "A presenter/creator…" | open | build |
| OLD-FORM-CHANGES | Old-form one-takes change on deploy: captions on by default, cuts at shot changes re-anchored on photos, shorter job timeout (resumable) | review | owner OK |
| ORPHAN-PARTS | Saved one-take segments of projects removed by a query-builder delete (VariantController) are not cleaned up | open | small |
| DEDUCT-FALSE | Existing: a one-take delivered when deduct() returns false (balance gone) is not charged | open | decide |
| QUEUE-REAP | Built 2026-10-12: work is timed from when its job starts (SceneRun), queued work waits up to 2 h with a "nothing was charged" message, one-takes run on their own worker (worker-ugc, 2 at a time) | ready | push, then check worker-ugc is up |
| ASMR | ASMR as a silent one-take on Seedance 2.5 (hands + product, native close-up sound, product refs) instead of stills + text | proposed | build next |
| CAST-ALL | Multi-person ads (skit): cast every role from the presenter library (or the user's picks) before making; every person's photo as a reference; a voice per person; chosen presenters = one video's cast for a skit | open | build next |
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
| LIVE | Deployed 2026-10-10, open to every paid account; prod canary take 280 passed 2026-10-11 (delivered, charged once, plain-video Download) | done |

## 3. Other open items from 2026-10-10 (not UGC chat)

| ID | Item | Status |
|---|---|---|
| WEAVE-60 | Weave up to 60s before falling back to Classic: render-cap raise plus tests | open |
| EXP-WARN | Warn that a 16:9 export of a vertical clip is pillarboxed | proposed |
| CUTOUT-PC | Presenter cutout as an ffmpeg post-composite | proposed |
| UNDO-GAPS | "Re-record all voices" not busy-guarded; a failing step can't be discarded; voice re-record not undoable; history not deleted with the workspace | open |

## Done

- 2026-10-11 `911eb0b5` Places (24, director-picked or per presenter, loose reference photo), Presenters window with
  background picker, face scores (remakes off), light matched at continued joins, one-product and no-other-brand rules,
  captions off by default, Weave renamed the Creative Director (app, connector, docs). Prod: 25 new presenters, 16
  redrawn, 24 place photos imported; paid canary take 280 (Tamsin, park, 330 credits) delivered, Download plain video.
  Omni accepts 5 references but the face held better with 3: kept at 3.

- 2026-10-10 Pre-launch review (3 reviewers: money/pipeline, chat/security, web/captions), all fixed: jobs queued
  before the deploy unserialize safely; trims only on unambiguous matches; silent clips join; long lines always split;
  delivery clears the recipe, resume rechecks balance and refuses delivered takes; reaped runs lose the take; refused
  charges reported. Composed retries redo only failed shots; presenters scoped to the workspace; inner failures answer
  instead of crashing; Make keyed by the pitch, saved per take; rate limits; sent files store only ids; cascading
  deletes. One-take Download now burns captions over the video's own sound (BurnOneShotCaptionsJob; it was the raw
  file); transcript timing only for one-take videos. Web: capability-aware redirect, newest-export Download with a
  link, single chat creation, Make locked while sending, slow replies awaited, presenters never wiped, keyboard,
  accessibility, phone layout.

- 2026-10-10 Go-live blockers: saved one-take clips and recipes moved off the public media bucket to the private disk
  (api_private on prod); Replicate running dry now alerts the team (video submits and the chat's AI calls report to
  VendorAlerts) and UGC refuses new videos with a clear message while it is down; skit, POV hook and reaction marked
  Soon (never picked, refused); the chat is staff-only until UGC_CHAT_OPEN_TO_ALL (team domains/emails, super admins,
  UGC_CHAT_ALLOWED_EMAILS), everyone else keeps the step-by-step form.

- 2026-10-10 Second review: the phone is the camera and never in frame (a mirror-selfie phone floated while both hands
  rubbed lotion); two-handed actions use a propped camera. A new shot's references are presenter + the previous
  shot's last frame + product (hair came back different after a cutaway), and its silent lead-in is trimmed. Clip ends
  keep 0.6 s after the last word (1 s at the ad's end, with a fade): 0.4 s clipped the last syllable. My ads opens a
  chat-made take in its chat. The review page no longer swaps its player out under the first click on play.

- 2026-10-10 Owner review of the sweep videos. Joins: next clip starts from the true last frame and its duplicate
  first frame is dropped; 30 ms audio fades; Omni clips up to 10 s (fewer joins). A clip opening a new shot starts
  from the presenter/product photos (identity and packaging re-anchored; drift across a before/after). Hands and props
  rules (phone hand, set the phone down for two-handed actions, nothing floats) in director, planner and video
  prompts; no finger counting. Variety: recently cast faces sit out of "let us pick"; each ad gets a fresh outfit and
  stock descriptions lose their clothing. Duplicates: clips say exactly their lines (sized to their words), and each
  clip is cut after its last scripted word from its own transcript, so joins never repeat.

- 2026-10-10 Sweep videos (local POC): clips joined by stream copy came out with broken timestamps (18 s playing as
  6 or 87 s, sound out of step) once Omni chained clips; the join now re-times and re-encodes. The video model drew
  garbled text where shot briefs asked for headline space ("UGC her headlines"); headline notes are removed from
  one-take prompts. The six local videos were re-timed in place.

- 2026-10-10 Type sweep (13 types, one product): all pitch without questions; reaction was broken (spoken script for a
  silent clip; empty script dropped at hand-off), fixed. Captions on one-take videos (were off since one-take began):
  timed from the video's own speech (transcribed before the take counts as ready; placeholder timings ignored),
  Download in the chat hands over the captioned finished file. Founder story only with the user's own face (photo or
  saved character); otherwise it asks, or makes a testimonial.

- 2026-10-10 One-take videos resume from the clip that failed (clips saved as they land, recipe saved, reused only while
  unchanged and up to the first clip remade, damaged clips never saved; removed on delivery, delete, or after 7 days).
  Each run has a token: a resumed, redelivered or superseded run never saves, fails or charges; delivery + charge and
  the retry claim share the scene's row lock. Job timeout 2300 s (< retry_after 2400). The stuck-generation reaper no
  longer marks a running one-take failed at 10 minutes (it did on prod). Take-page Retry resumes one-takes too.

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
- 2026-10-11 VISION (c3c1013c): product photos read once (Haiku sheet: form, size, colours, label) and used by the director, plan and every clip prompt; a link brings up to 3 of its own product shots. Prod check take 281 (Nécessaire tube): the tube stayed a tube in every frame, label legible. Note: some shops (Nécessaire's Cloudflare) rate-limit our prod IP (429), so their page can't be read from the server; the chat says so.
