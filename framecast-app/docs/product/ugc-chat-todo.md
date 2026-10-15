# UGC chat: the todo

Only what is still open. Add new items here and nowhere else; when one is done, delete it and note it under Done
(date and commit). Mockup the build must match: https://claude.ai/artifact/E9eE41WsjNVscrRS3aBHzP (look:
https://claude.ai/artifact/3rF2EVivGciqqgfD6e2bA4).

Last updated: 2026-10-14.

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
| TYPES-LIVE | Open each type to customers once its reviewed test passes (UGC_TYPES_LIVE on prod). Live: problem_solution (2026-10-14, take 302). 2026-10-15, owner: "make all the types available for customers" (green screen and ASMR tested by the owner): all ten native types live; skit, POV hook and reaction live before a real take | done | watch the first customer skit, POV and reaction takes |
| END-ACTION | The last action a plan names can be skipped in a single take (take 302: "gets back to work" never happens, the reviewer flagged the right hand not on the keys). Hold the final action long enough, or remake when the reviewer says no | open | build |
| UGC-COST | ugc_oneshot charges record no upstream cost (take 302: 627 cr, upstream_cost_usd null; real about $4.39 at $0.2312/s), so God Mode economics miss UGC video spend. 2026-10-15: God Mode margins already counted UGC calls (api_usage_events); each charge line now carries the take's real cost (3eded466, f4a61025); no backfill (owner) | done | — |
| VEO-TEST | Veo 3.1 Fast (starts from the dressed look) and Veo 3.1 HQ are in the model picker; not yet tested on the new pipeline | open | test takes, then judge |
| MCP-BRIEF | A `ugc_brief` MCP tool that runs the Creative Director chat (brief in, pitch and price out, then make), so assistants get the director, the completeness check and pronunciations; API/MCP already take engine, wardrobe, place and pronunciations (2026-10-12) | proposed | after the API expansion order (owner) |
| LEARN | An agent that watches every UGC take and keeps a lessons list. 2026-10-15 (owner: "continue with learn"): every finished or failed one-take is reviewed (UgcLearn::review, ReviewUgcTakeJob, Sonnet 5.5, ~$0.02: brief, clip prompts, checks, six frames) into ugc_take_reviews; daily 04:40 `ugc:learn-lessons` merges them into at most ten lessons (ugc_lessons) that the director, shot planner and sheet writer read (UGC_LESSONS_APPLY, UGC_LEARN). `ugc:learn-lessons --show` prints them | done | read the first lessons after a day of takes |
| SWEEP | Varied briefs before UGC_TYPES_LIVE: skincare, app demo, try-on, drink outdoors, plain testimonial, problem/solution (~2,500–3,500 cr) | open | after PIPE-ON |
| SECTIONS | Videos in components: keep a take's clips after delivery (deleted today), a take bible (face, clothes, background, product state, camera) read by every clip and edit, "remake this section" in the editor (folds in P4-CHANGE) | proposed | owner OK on the plan |
| SEEDANCE-CONT | Does Seedance accept its own earlier video (continue / video edit) with a face in it? One call; a refusal is free | proposed | test |
| BYTEPLUS | BytePlus trusted-asset library so Seedance takes the exact cast face (no filter tricks) | proposed | check account and region requirements |
| FACE-STRICT | The face check is too lenient (take 296: a different woman scored 75–85); stricter model and several frames before the 85–90% target can be measured 2026-10-15: a face close-up from the photo rides beside the dressed picture, and the dressed picture is redone under 85 (cc095e46). | partly | compare scores on the next Omni takes |
| REVIEWER | The yes/no reviewer only records (UGC_REVIEW_REMAKE off): noisy on clothes and on talking blocks | open | calibrate on the sweep |
| WIDE-ID | Identity slips in wider shots (take 294: glasses gone in the propped clip, face 72) | open | face remakes (FACE-RETRY) or keep propped shots waist-up |
| OMNI-REPEAT | Google Gemini Omni itself repeats words (owner, 2026-10-14: demo test, same image and line through Fabric, Omni and OmniHuman; only Omni repeated). Repeats in takes are the model's, not our prompts'. Fix after the take: transcribe, find a phrase said twice where the line has it once, and cut it; or keep Omni's motion and lip-sync a clean TTS read onto it (Sync Lipsync-2-Pro, mouth only), which also fixes VOICE-MATCH 2026-10-15: Omni lines are now read once by a fixed voice and lip-synced (UGC_OMNI_LIPSYNC=true), so the words are exactly the script. | fix live (0fea68a5), untested | owner skit or Omni take |
| VOICE-MATCH | Each Omni clip makes its own voice; nothing checks they match across clips 2026-10-15: one fixed voice per speaker across clips (UgcVoices). | fix live (0fea68a5), untested | owner skit or Omni take |
| LEAN-SHEET | Is the full sheet over-directing the model? A/B a lean sheet (only what changes) | proposed | after the sweep |
| FACE-RETRY | Face scores are recorded on every cast clip (take 128: 85/85, prod take 280: 92/85); remakes are behind UGC_FACE_RETRY, off | open | turn on once ~10 takes show where real mismatches score |
| P2-ASPECT | Only 9:16 offered. 2026-10-15, owner: add 1:1 and 16:9: a Shape choice in the composer; 1:1 on Seedance only (a one-take over 30 s is made vertical; scene-by-scene keeps square), green screen and splits stay 9:16; videos show in their shape | done, untested | a 16:9 and a 1:1 take |
| P2-POLISH | Small: a picked presenter is named in the pitch only when the summary starts "A presenter/creator…" | open | build |
| OLD-FORM-CHANGES | Old-form one-takes change on deploy: captions on by default, cuts at shot changes re-anchored on photos, shorter job timeout (resumable) | review | owner OK |
| ORPHAN-PARTS | Saved one-take segments of projects removed by a query-builder delete (VariantController) are not cleaned up. 2026-10-15: variant delete cleans them; the nightly prune sweeps folders of takes that no longer exist (3eded466) | done | — |
| DEDUCT-FALSE | A one-take delivered when deduct() returned false (balance gone) was free. 2026-10-15, owner: hold credits at start: held at dispatch, given back on failure, reap or delete, held again on resume (f4a61025) | done | — |
| ASMR | ASMR as a silent one-take on Seedance 2.5 (hands + product, native close-up sound, product refs) instead of stills + text 2026-10-15: built with a sound check, a remake on silence or talking, and levelling. | built (6dde4af4), untested | owner ASMR take: pick the ASMR type, hands and sounds only |
| CAST-ALL | Multi-person ads (skit): cast every role from the presenter library (or the user's picks) before making; every person's photo as a reference; a voice per person; chosen presenters = one video's cast for a skit. 2026-10-15: the director casts the missing speaker (stock, not cast lately, other gender); speaker 2 gets their own outfit; a fixed voice each, lip-synced on Omni | done, untested | owner's skit take |
| POV-VIDEO | POV hook as video (owner, 2026-10-15: "build what's pending concerning pov"): one to three silent Seedance shots of the presenter, 5–10 s, each shot's headline burned on the take from its start to the next (UgcHeadline::cues, burnHeadlines). Reaction moved onto the same route (one silent 5/10 s shot, headline burned on). 2026-10-15: optional music bed (owner: default off): "Add a music bed" on the pitch for POV and reaction, +2 credits a video, MusicGen made to length and mixed low under the take | done, untested | owner's POV take (~265 cr for 8 s), reaction take (~165 cr for 5 s) |
| SKIT-ROOM | Both skit speakers in one room: the place photo rides with each speaker's references, "choose your own spot" only on the first clip, one-room wording, screen direction (speaker 1 frame-left, speaker 2 frame-right; the 180-degree rule from OpenMontage, ideas only, AGPL). Next if the take still splits rooms: a silent two-shot opener whose last frame anchors the singles, or Seedance multi-shot (one generation, "Shot 1 / Shot 2", both quoted lines) | done, untested | owner's skit take |
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

- 2026-10-12 The UGC pipeline rebuilt around the take (staff first; prod takes 283–298, Crunchy Pea, Lumora serum, Volt
  Rush can). Director and planner on Sonnet 5.5 (direct); every action the pitch names is planned and filmed
  (completeness check counts only actions shown being done); a long take carries a few actions in order. The timed
  action sheet is each clip's whole prompt: one camera from a library of setups and moves (selfie, propped, tripod,
  stick, walking, first person, set down, pick up…) described only as a view, timestamped blocks naming each hand, the
  product's place and state, the face and the words, positive throughout, references never re-described, nothing left
  floating. The take's look: the cast presenter dressed for the ad in its own version of the place, face-checked, used
  in every clip (WARDROBE-HOLD done). No clip is trimmed; the next clip's words follow what the last one said; the
  follow-on rewrites the next clip from how the last really ended (hands, product, background); the last frame rides
  as a reference. A trace keeps every step (prompt, sheet, raw clip, look, reviews). Users pick the model on the pitch
  (Auto, Omni, Seedance 2.5, Veo HQ, each priced); Seedance takes a cast presenter as Opus's identity description
  opening with their casting line. QUEUE-REAP pushed (worker-ugc). Storyboard pilot tried and dropped (owner: stills not
  needed; the dressed look replaces them). Commits b4584e8a…a491428e.

- 2026-10-12 PIPE-ON: after the Omni (prod take 297, passed: one room, continuous hands, nothing floating, no phone
  drawn) and Seedance (298) checks, the timed sheet, follow-on and the dressed look are on for every account (trace stays
  staff-only). Seedance 2.5 is the default model (owner: "the best I have seen"; Omni when a plan is over 30 s); the
  picker offers Seedance, Omni, Veo 3.1 Fast and Veo 3.1 HQ. Pronunciations the user gives in the chat reach the video;
  the label is asked to read the right way round (298 mirrored it).

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
