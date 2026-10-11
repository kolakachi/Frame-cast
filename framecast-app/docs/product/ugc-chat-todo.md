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
| CAST-MODAL | Prompt box shows 6 faces + More; More opens a Presenters window (search, Actors/Your characters, gender and age filters, close-up, Background picker, add/remove); Background also in each presenter's mini settings; a place set on the presenter beats the director's | ready | owner look locally |
| CAST-25 | 25 new stock presenters (Imagen 4, 50 candidates made, $2): ages 18-75, plus-size, hijab, turban, glasses, tattoos, vitiligo, Pacific, Indigenous | partly | 25 picks + 16 redraws (Silas, Idris, Valentina, Lucas, Andre, Nia, Ben, Amara, Priya, Nadia, Rafa, Theo, Zayn, Callum, Marcus, Jonah) in the LOCAL library; prod import at push |
| SETTINGS | 24 places (config/ugc_settings.php); the director picks one, its empty background photo goes as a loose reference (kind of place, model free on spot and time); outfits suit the place | ready | 24 photos made locally; test take 128 (Megan, kitchen) came out in the kitchen, not her boutique photo; run `ugc:seed-settings` on prod at push |
| FACE-CHECK | Each cast clip's face checked against the presenter's photo (Haiku vision); under 70 is made again once at our cost, max two a take; scores on the scene | ready | take 128: 85 and 85, no remakes; watch the rate over more takes |
| LIGHT-JOIN | Continued clips matched in colour and brightness to where the last clip ended (within 15%); 118 jumped 11% at a join | ready | take 128: no jump at its join; one take is not proof |
| REFS-LIMIT | veo-3.1 references only work at 16:9 and 8 s (its schema), so vertical Veo HQ takes likely ignore them; Omni lists no limit, we cap at 3 | done | Omni accepts 5 and uses all (headphones and kitchen showed), but the face held less well than take 128's 3 (plus-size build and red lip lost): keep 3 |
| P2-ASPECT | Only 9:16 offered: one-take always renders 9:16; composed takes accept 1:1 and 16:9 but the chat sends 9:16 | open | decide, then pass aspect through |
| P2-POLISH | Small: a picked presenter is named in the pitch only when the summary starts "A presenter/creator…" | open | build |
| OLD-FORM-CHANGES | Old-form one-takes change on deploy: captions on by default, cuts at shot changes re-anchored on photos, shorter job timeout (resumable) | review | owner OK |
| ORPHAN-PARTS | Saved one-take segments of projects removed by a query-builder delete (VariantController) are not cleaned up | open | small |
| DEDUCT-FALSE | Existing: a one-take delivered when deduct() returns false (balance gone) is not charged | open | decide |
| VISION | The director reads only file names: the sweep's product (a tube) became a pump bottle in 3 of 6 videos. Let it look at product photos | proposed | build |
| QUEUE-REAP | Composed scenes waiting behind long one-takes on a busy queue are reaped as "timed out" at 10 minutes | open | fix |
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
| LIVE | Deployed 2026-10-10 and opened to every paid account (UGC_CHAT_OPEN_TO_ALL=true on prod). A real paid make from the chat on prod (charges, captions, Download, resume) still to run | run soon |

## 3. Other open items from 2026-10-10 (not UGC chat)

| ID | Item | Status |
|---|---|---|
| WEAVE-60 | Weave up to 60s before falling back to Classic: render-cap raise plus tests | open |
| EXP-WARN | Warn that a 16:9 export of a vertical clip is pillarboxed | proposed |
| CUTOUT-PC | Presenter cutout as an ffmpeg post-composite | proposed |
| UNDO-GAPS | "Re-record all voices" not busy-guarded; a failing step can't be discarded; voice re-record not undoable; history not deleted with the workspace | open |

## Done

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
