# Create: the todo

The one list of everything still open for Create. Add new items here and nowhere else; tick them off by moving them
to "Done lately" with the date and commit. Detail for older items lives in `archive/create/` (history only, not
kept up to date).

Last updated: 2026-10-07 (late).

**Status:** `open` not started · `partly` some built · `ready` built, needs a real run or a look · `blocked` waiting
on the owner or another item · `proposed` not yet approved.

**Live reference (not todos):** `create-drain-runbook.md`, `create-worker-recovery-runbook.md`,
`create-web-release-runbook.md`, `create-local-testing.md`, `create-bench.md`, `hyperframes-integration-spec.md`,
`create-ui/` (mockups the build must match).

**Production today:** live for the team (kolakachi@gmail.com and `@wyvstudio.com`); private B2 bucket
`wyv-create-private`; durable planning; 3 builds at once, one per workspace; health alerts every 5 min; vendor alerts
and a 09:05 digest; planning billed at half cost (needs 60 credits); no spend cap (watch the digest and
`create:failures`).

## Go-to-market gate

Sections 1 and 2 make Create safe to run; they do not prove it is good enough or that it pays. Fast path, agreed
2026-10-07: everything below runs in parallel, about a week.

1. **GTM-1 acceptance round now** (10 briefs, below). It also proves section 2 (BG1 on a Thorough brief, FS8 with
   own audio, I7 and FS5 on the motion-graphics briefs). The outputs are candidates for the landing page.
2. **Drills alongside** (G-REC, G-DEP, L16, G-FAIR) and PLAN-CAP, about 1–2 days, no new features.
3. **S9 cut down for launch:** "Change…" reopens the plan; the full step-by-step version waits for customer demand.
4. **Off the launch path** (stay in the todo): AB3, I2, D7, 0.4, HF-BILL, S10.
5. **Open to ws 28 and ws 27 as the round finishes** (O9). The full gate applies to wider marketing only.
6. **Pass marks for wider marketing:** at least 7 of 10 the owner would publish after at most one change; none lost
   or charged twice; median time under 20 min; model cost per accepted video comfortably below the charge; then
   pricing and limits (O11) from the numbers.

**Every brief states pronunciations** (WyvStudio = "weave studio"; briefs 1, 2 and 10 said "wiv studio" by mistake and are being re-voiced); since 2026-10-07 a brief that says "say X as Y"
or "X (pronounced Y)" saves it for the workspace and every voice uses it.

### GTM-1 briefs (production, ws 1, about 2,500 credits)

| # | Format | Brief | Also proves | Run | Result |
|---|---|---|---|---|---|
| 1 | Motion graphics | WyvStudio launch promo, 20 s 9:16, kinetic type: "Branded short-form video without a shoot" | FS5, I7 | `2e8fa486` | 25 min · 734 cr (est. 245, 3×) · **owner: likes it** · checks flagged 2 blank moments and the wordmark on screen 0.8 s (a pro would notice); "finished video" phone and plan card nearly empty |
| 2 | Motion graphics | How WyvStudio works (brief → plan → video), 30 s 16:9 for the landing hero, Thorough | BG1, CR1 | `03cdde00` | Thorough · 23.5 min · 603 cr (est. 1,579) · all checks pass · reviewer ran 3 rounds (61 cr): BG1 proven · clean three-step story; the app window sits small in a lot of black, "The plan" card starts empty |
| 3 | Motion graphics | DTC product ad for a skincare serum, 15 s 9:16, product stills generated | AB4 | `d725704d` | 15 min · 735 cr (est. 280) · checks pass (18% of single words not made out: listen) · premium look, the generated bottle carries the label · the cache finding (COST) came from this build |
| 4 | Motion graphics | SaaS feature launch with numbers (odometer, gauge), 1:1 | FS5 | `8bba2c00` | 7.4 min · 245 cr · all checks pass · weak first second (empty card) · owner verdict pending |
| 5 | UGC | A creator's testimonial for WyvStudio, talking to camera, 20 s 9:16 | spoken brand name | `6c8643ae` | owner: change · last build lip-synced a female voice onto a male presenter ("a huge miss") · now re-planned as one native take saying "weave studio" (CHG-PLAN), waiting on the plan limit |
| 6 | UGC | Unboxing ad for the serum: hook, problem, solution | RF1 | | owner: publish |
| 7 | UGC | Split-screen reaction ad | E2 (generated presenter) | `c28c64c2` (v2) | owner: publish after one change (the phone view) |
| 8 | Footage edit | The WyvStudio demo recording cut into a 30 s promo with captions and zooms | E1 | `fd8bca53` | 31 min · 668 cr (est. 860) · all checks pass · clear step story from the real recording, zooms, captions, logo end card · app text small in 9:16 crops, two panels clipped at the left edge · the source recording shows an old brief "(pronounced weev-studio)" |
| 9 | Footage + own audio | The VSL's own voiceover over a new edit of the product | FS8 | `60600452` | 22 min · 176 cr · all checks pass · the presenter's own sentences word for word, karaoke captions over matching app screens, logo end card: a clean long-to-short repurpose · app screens dark and small at 16:9 |
| 10 | From scratch | "3 hooks that stop the scroll", a teaching video for marketers, WyvStudio end card | FS3, S11 | `5fd8e691` | 22 min · 578 cr · strong design and opening (big 3 + title at 0 s) · BLOCKED on reading time, mostly for text inside an example post (a prop, not meant to be read: the check is too strict there) · Hook 02 phone cut at the left edge, Hook 03 opens on a nearly empty orange frame |

## 1. Build next (no owner input needed)

| ID | Item | Status | Next | Detail |
|---|---|---|---|---|
| S9 | "Change…" from the video: "Change this moment" on the paused player (frame + one prompt + "✦ Suggest a change", billed like planning) and a short list of the video's parts with one Change button each (use my file free · make a new one, priced · describe it), music and voice, edit the words, one total with a breakdown; sent as one change, planned as an edit (`03be75cb`, `4b203d66`) | ready | deploy, then owner browser check | mockup `create-ui/change-from-video.html` |
| S10 | Pause and ask inside a step (at most 2 questions), resume without re-approval | open | build | archive: steps-asking-plan |
| FS3 | Finishing checks still to add: constant-speed motion, the same technique repeated, full-frame hit count, opening hero | partly | build | archive: from-scratch-plan |
| I7 | Tune the layout and reading checks on real videos: overlap and lopsided have not caught a real case yet; reading time blocks on text inside props (an example post's caption, GTM-1 #10) — props should be marked and excused | open | tune (launch must-have) | `cd5bb2cc`, GTM-1 |
| D7 | Pick the best seconds of a clip that is longer than its slot | partly | build | archive: generated-video-progress D7 |
| 0.4 | An image in flight when the worker dies is lost (generated video is already recovered) | partly | build | archive: generated-video-progress 0.4 |
| I5 | A brief refused by the 24-requirement limit on a re-plan | open | look at | archive: verify-and-teach-scope step 4 |
| AB3 | Stage WyvBear and the GSAP plugins into the builder | open | build | archive: generated-video-todo (art) |
| AB4 | The builder must search the art library when the brief asks for icons | partly | build, then a run that uses art | archive: generated-video-todo (art) |
| L16 | Restore drill from the private DB backups (`wyv-create-private/db-backups/`), plus a secrets review | open | run the drill | archive: go-live L16 |
| G-REC | Recovery drills on production: API restart during planning, worker killed mid-build, duplicate approval, cancel while queued and while running; no double charge, uncertain work stays held | open | run when idle | archive: go-live gates |
| G-DEP | A deploy builds images while Create works, pauses Create, waits up to an hour for running builds and plans (`create:drain quiet`), restarts, always resumes; still-running builds stop the deploy with the old version serving (`31618baf`). Still to do: a rollback drill | ready | first real deploy proves it; then a rollback drill | archive: go-live gates |
| ASK-FILE | A product ad with no product photo fell back to a bottle drawn in code (it did ask for the photo). Planner rule now: a generated product image is the fallback, the photo is still asked for (GTM-1 #3) | ready | push, then prove on a product brief | GTM-1 |
| SQUARE | "A W on an orange rounded square" set the square format | done 2026-10-07 `b2ed950f` | — | GTM-1 |
| PLAN-CAP | Planning ran one plan at a time (~2 min each): 12 queued plans waited up to ~25 min and tripped the health alert (GTM-1). Now 3 planning workers, at most 2 plans per workspace; a plan waiting on its own workspace is not alerted. Live since 2026-10-07. The 40-plans-a-day cap (left from when planning was free) is removed: billing and the balance bound it (owner, 2026-10-08) | ready | watch | GTM-1 |
| COST | Builds cost 2–3× their estimates. Found (GTM-1 traces): the builder re-read files it had just written (history shortening), and brief 1 was estimated as an "edit" because it was re-planned before any video existed. Fixed: an edit returns the file's current text (`674ad5da`); estimates are the middle half of real builds, shown "about X–Y", and a pre-video re-plan counts as a new build (`9dff02bb`). Margin is fine: real model cost ≈ $0.005 per credit sold at ≈ $0.015 Second finding (GTM-1 #3, with the read fix live): cache rebuilds were about half of the builder's cost, because earlier turns were rewritten (old frames dropped one by one, writes shortened a call late). Now earlier turns are never rewritten; old frames leave in a batch after 4 previews. | ready | deploy, compare cache writes on the next builds | GTM-1 |
| OPEN-1 | Weak first second (empty card, black frames). Now: a weak-opening check (nothing or almost nothing on screen in the first 0.3 s is an error the builder fixes), black at the start no longer excused, and the craft rule "frame 1 is the thumbnail" | ready | deploy, then watch | GTM-1 |
| SAY | Brand names said as saved ("weave studio"). Checked by ear, a mismatch blocks delivery (`2485c64f`, `96713de1`); a voiceover re-voices when a pronunciation changes (`7902459b`). Owner, 2026-10-07: lip-sync ONLY for the user's cloned voice; any other take keeps its baked-in voice and gets the name through its words (`8357f8de`; Gemini Omni has no audio input). Re-voice pass through the Change drawer: picks `292180c9`, `1c4484f0`, `540680bd` and briefs 1, 2, 10 (`ed276269` is in ws 2, not ours to change) | ready | listen to each re-voice, update the review page | GTM-1 |
| FONTS | Brand fonts the brief names (DM Sans, Space Mono) were not on the worker; Inter was used. Now fetched from Google Fonts for the build (`2c2eb022`) | ready | deploy | GTM-1 |
| CHG-PLAN | Real changes through the Change drawer (GTM-1 #5, #7, #9) exposed: unrelated clarifying questions (now skipped for drawer changes, `5a8d7bdc`); a bought voiceover over the user's own recorded voice (now own voice from a speech clip or a transcript match, `3caaf993`, `82b189e5`); the planner respelling "WyvStudio" as "Weave Studio" in the script (now put back, `25991fe8`); a change re-planning everything and drawing a new person (the made sheet is carried, `97ae2d8b`, `55643cce`); a take re-made in part with a second voice (a talking take is re-made whole, `8bb160dc`); the drawer's own heading "Change version N…" read as asking for a new person, dropping the sheet (`99a03427`). A voice-only change re-planned the beats with transitions the version never had, and the planned-move check made the build add them, so the picture changed (re-voices `02a3b508`, `4b0234af`); an edit now only holds the moves the version already has (`0d813afc`, worker deployed). Open: a re-voice still runs the builder (~107 cr) and holds up to 656 cr; a sound-only change could swap the audio and re-time without it. Principle (owner): find the one component meant, change only it; a baked component is redone whole | ready | re-plan #5 after midnight UTC, build, listen | GTM-1 |
| API-BAL | Our Anthropic account ran out mid-round (2026-10-07). Now: `php artisan create:model-balance 200` after each top-up; the health check estimates what is left from measured spend (+10% for unmetered calls) and emails below $40 (`CREATE_MODEL_BALANCE_WARN_USD`). Owner: turn on auto-reload in the Anthropic console | ready | record the current balance after deploy | GTM-1 |
| G-FAIR | Several workspaces at once: one busy workspace cannot hog the queue; cancelling frees a slot; waiting users see a useful status | open | test | archive: go-live gates |
| DEC | OpenAI Decisions API (`gpt-6-luna`, `POST /v1/decisions`, frames as images, $0.10/M input tokens). Bake-off 2026-10-07 on the 39 library videos (6 frames each, ~1 s a call, cents in total) against the owner's 19 landing picks: as a taste judge it fails ("landing worthy" AUC 0.33, "polish" 0.46, chance is 0.5; it rated nearly all of them 0.1–0.3). Narrow flags: weak opening right 4 of 4 (our own `weak_opening` check now covers it, free and exact); text cut off 3 of 3 flagged were 1 real (#10 phone), 1 mid-animation, 1 deliberate crop: a still frame cannot tell motion from a fault, our DOM checks can. Not adopted for HTML builds. Possible fit: generated clips and images (takes, shots, sheets), where we have no DOM: needs labelled rejects first. Script: scratchpad `dec/bake.py` | parked | collect labelled bad generated media, then re-test there | owner, 2026-10-07 |
| HF-BILL | Reconcile real provider invoices against our cost records (estimates are not invoices yet) | open | compare a week | archive: hyperframes-implementation-todo |
| HF-CASES | Hard test briefs: mismatched product photo (no false identity or invented endorsement), long text, missing claims, contradictory ask, footage too short, a new spoken hook | open | add to tests and the bench | archive: hyperframes-implementation-todo |
| I2 | Local stack: rebuild horizon and reverb (they still run old images) | open | rebuild | archive: generated-video-todo |

## 2. Built, waiting for a real run or a look

| ID | Item | Next |
|---|---|---|
| BG1 | Thorough builds get up to 4 reviewer rounds; only Thorough holds credits for the reviewer — proven on GTM-1 #2 (3 rounds, 61 cr) | done |
| CR1 | Quick and Thorough cost multipliers (Quick 0.4 confirmed; Thorough ~1.8× from one run) | more Thorough runs |
| CR2 | Estimates learned per video type and new-vs-change | needs 3 runs of a kind |
| CR4 | "Never more than" shows a real hold | check in the app |
| S11 | A vague change gets one question; the plan card shows what was assumed | check in the app |
| RF1 | Re-run the WyvStudio UGC brief (length, captions, proof, sound now confirmed in the plan) | the video build |
| RF2 | The reference study hears sound effects and the sound pass follows them | a reference with effects |
| RF3 | Captions in Details: Automatic, None, My exact text | check in the app |
| FS5 | 37 motion moves (14 new) | a build that uses one |
| FS7 | A screenshot of the current video is read as "change this moment" | try live |
| FS8 | The user's own voice, music and sounds used as given | a paid build with own audio (~250 credits) |
| E1 | App screens shown on in-world devices | a run with a screen |
| E2 | Split-screen UGC | needs O1 |

## 3. Waiting on the owner

| ID | Item | Unblocks |
|---|---|---|
| O1 | A consented photo of yourself | E2; bench B6, B7, B9 |
| O2 | A UGC reference video | bench B9 |
| O3 | A product photo (stock candle is the fallback) | bench B8 |
| O4 | Bench budget cap: 7,000 credits? | section 4 |
| O6 | 15–25 exemplar videos | FS4 exemplar library |
| O8 | Browser checks: a tab left open across a deploy (L3), the directions drawer (FS1), Details (FS9) | L3, FS1, FS9 |
| O9 | Go to open Create to the two real customers (ws 28, ws 27), after customer acceptance below | L8 |
| O10 | Customer acceptance: review outputs for a motion-graphics brief, a UGC brief and a footage edit (fidelity, consistency, text, audio, revisions, time, cost) | O9 |
| O11 | Pricing, customer limits and quality bar agreed before paid customer use | O9 |

## 4. The bench (blocked on O4 and the bench inputs)

| ID | Item | Status |
|---|---|---|
| G1 | Baseline round | blocked |
| F1 | Speed targets: plan ≤ 3 min, look ≤ 6, video ≤ 15 | blocked |
| G4 | Cost per accepted result, per route and engine | partly |
| H1 | Motion-graphics regression rendered and scored | blocked |
| G3 | Sonnet builds measured | partly |
| B2 | Bounded engine bake-off | blocked |
| B3 | Does a re-render keep the performance | blocked |
| B1 | Generated sequence route (only if B2 earns it), then D3 sequence coverage | open |
| F2 | Builder starts on code beats while clips render | deferred until after the bench |
| R1 | Local acceptance (8 of 9) | blocked |
| R2 | Wider audience behind an off switch, then the bench again before widening | open |
| — | After the pilot: watch first output, corrections, spend, recovery and repeat use; widen in stages | later |

## 5. Later (parked or proposed; revive with the owner)

- **Characters:** WyvBear mascot moments (RF4); ears part for mascots; Maya's layered artwork and prepared rig; a
  character-kit plan item (pose + expression sheets); generate-then-trace; image-to-3D for detailed characters
  (needs pricing).
- **Sound and editing:** licensed library music; speech cut points read from levels; crossfaded, frame-snapped joins;
  effects checked to land on their cuts; a motion rule per kind of object.
- **Rendering:** failed asset loads stop the render; re-render only what changed; re-compose each aspect ratio
  instead of cropping; 3D, shader and particle blocks.
- **Reuse:** save an approved result as a reusable, editable composition; input-only re-renders without a full build;
  feedback saved as workspace preferences; variation controls with seeds; batch variants from an approved result;
  a small library of proven components.
- **Measurement:** which skills and components lead to accepted outputs; reference-to-output motion comparison;
  the awesome-opus benchmark set (10–15 prompts).
- **Ops:** a limitations log with filters and evidence, proposals logged with human decisions.
- **HyperFrames depth (slice G, proposed):** the block registry, doctrine skills, capture and keyframes; upgrade
  from 0.8.82.
- **API/MCP:** Create through the public API and MCP after app acceptance, verified end to end before outreach.

## Done lately

- 2026-10-07: V8 vendor failures in the final look and reference reads (`b6e58aa5`); I6 test disks isolated
  (`dbe5f341`); BG1 reviewer held only on Thorough (`582da7b5`); 3 builds at once on production (L7); dropdowns
  inside drawers; FS1 direction cards, FS2 playbooks, FS5 moves, FS6 file roles, FS8 own audio, FS9 Details rules;
  private B2 storage, durable planning, worker ownership, drain, alerts (L2–L15); planning billed at half cost.
- 2026-10-07: V9 a real health alert reached the owner; I1 API image build no longer hangs.

**Failure ledger** (`create:failures --days=7`, 2026-10-06): 52 of 120 runs failed or were held since 2026-09-29;
16,249 credits spent on them; 41 later recovered.

## Closed 2026-10-08 (moved out when the todo was cut to open items only)

- GTM-1 round: 10 briefs built and judged; #5 fixed (one native Omni take, his own voice, "weave studio") and
  approved ("I love brief 5, natural"). Remaining re-voices moved to the todo as SAY.
- CHG-PLAN: the drawer heading no longer reads as a new-person request (`99a03427`); the same change can be sent
  again (`9f888fa3`); Change… on a restored version uses the build it restores (`4194e326`); an edit is not asked to
  add a move the version never had (`0d813afc`, `6c850f90`).
- PLAN-CAP: 3 planning workers, 2 per workspace; the 40-plans-a-day cap removed (`2993143c`).
- SAY (rule): a web address made from a saved name is said as the name (`135a0078`).
- Model test override for one conversation (`d4a8f773`, `5d013e47`); Haiku 5.5 tried on xhigh (GTM-1 #4: 61+ min,
  53 calls, $2.16 vs Opus 7.4 min, $1.00) and not adopted (owner).
- Worker test: every name the worker code uses is defined (`d1da5513`).
- BG1 proven (GTM-1 #2); RF1 (GTM-1 #6) and FS8 (GTM-1 #9) proven by published round videos.
- I7: words on screen while the voice says them are captions, not a reading-time fault (`5170d8bb`; worker deploy pending).
- L16 restore drill passed; social tokens encrypted at rest, revoke on disconnect, `social:revoke-tokens` (`75d08199`).
- Launch drills on production, 2026-10-08 (workspace 1, and the team's workspace 19 for fairness):
  - G-REC: duplicate approval gives one run and one hold; a second build in the same workspace is refused; cancel
    while queued ("Queued for your creation", then "Cancelled before starting", 0 charged, hold returned); cancel while
    running (clean, nothing unsettled); worker slot SIGKILLed mid-render: flagged in 90 s, stop recorded with the
    assignment, the interrupted render settled at zero cost (`5361242d`), charged only the 60 credits of calls made,
    nothing repeated; planning workers killed mid-plan: found recover-planning skipped for 24 h by a lock left by a
    restart, and the interrupted plan locking its conversation; fixed (`3c0b0cb6`), the retried plan charged once.
  - G-FAIR: both workspaces built at once, each claimed within a second; clear waiting statuses.
  - G-DEP: a marker commit pushed during a build waited for it (drained), deployed, then was rolled back by revert;
    Create reopened, site 200. Rule: never roll back past a data migration (e.g. `75d08199`) without its down().

