# Editor redesign: plan and parity list

Owner, 2026-10-29. Design: https://claude.ai/artifact/3E3t2SZP8XhvMxAB1NikRj. The new editor sits beside the classic one;
both run on one brain (`web/src/editor/useEditorCore.js`), so saving, playback, export, realtime updates, undo/redo
(⌘Z) and the leave-page guard behave the same in both. The new editor is only a new layout.

Rules (owner): staff only first; a "new user" is an account created at or after the deploy that opens it; classic
retires once the old users fully accept the new one.

## Steps

| Step | What | State |
|---|---|---|
| 1 | The switch: per-person choice, staff only, "Try the new editor" / "Classic editor" | live (dd8e324c) |
| 2 | The shared core; new layout with Script panel, frame, toolbar, timeline, top bar | live (dd8e324c) |
| 3 | Media, Voice, Captions panels and their toolbars, plus the missing page states | next |
| 4 | Audio, Brand, Assistant, Project panels; the insert popover between scenes; the "Ask the assistant" bar | |
| 5 | Phone layout (bottom tools and sheets); until then phones open classic | |
| 6 | Parity check against the list below, then open to new users (`EDITOR_NEW_OPEN=true`, `EDITOR_NEW_USERS_FROM`=deploy time) | |

How each step ships: built in the new view only, using core functions (no second copy of any logic). Prices are the
server's, never the canvas's placeholders. Pushed after the prod-idle check, then a quick run on prod as staff. Paid
actions are tested only with a cost estimate first.

## Parity list

Everything the classic editor does, where it lives in the new one. ✓ = done in the new editor, ◐ = partly, blank = to do.
The step column says when it lands.

### Page states

| Classic | New editor | Step | |
|---|---|---|---|
| Loading skeleton, load error | same | 2 | ✓ |
| "Some changes could not be saved" dialog when leaving | **missing: leaving after a failed save hangs** | 3 (first) | |
| Generation failed: reason, edit the source, Try again, "Read it with AI" for scanned PDFs, Start a different video | shows the reason only, sends to classic | 3 | ◐ |
| Whole-video UGC take: player + Open take review | player, sends to classic | 3 | ◐ |
| Failed-scenes banner + Resume failed | same; the "~75 credits" in classic is a guess, show the real price or none | 3 | ◐ |
| Clip shorter than narration note | same | 2 | ✓ |
| Post-export feedback card (once per user) | | 4 | |
| Toasts | same | 2 | ✓ |

### Top bar

| Classic | New editor | Step | |
|---|---|---|---|
| Back, project name | same | 2 | ✓ |
| Export status pill + failure reason | status dot; add the failure reason | 3 | ◐ |
| Undo / redo with labels | same | 2 | ✓ |
| Share menu: checks, ratio, open, download, schedule, approval, copy link | same | 2 | ✓ |
| Export formats menu | same | 2 | ✓ |
| Variants | same | 2 | ✓ |
| Notifications bell | | 4 | |
| Credits balance (phone bar) | show in the top bar | 4 | |

### Scenes and Script panel

| Classic | New editor | Step | |
|---|---|---|---|
| Scene list: number, type, script, visual tag, style, length | scene cards in Script + timeline thumbnails | 2 | ✓ |
| Select, reorder up/down, duplicate, delete with confirm | toolbar + timeline | 2 | ✓ |
| Voice outdated + Regenerate voice per scene | re-record row | 2 | ✓ |
| Script text, autosaved | same | 2 | ✓ |
| Duration of a silent scene | | 3 | |
| Rewrite with AI: edit chips, tone chips, custom instruction, preview, accept/reject | | 3 | |
| Hook options: generate, scores, use | in Script | 2 | ✓ |
| Add scene: type, script, Draft with AI | inline form | 2 | ✓ |
| Add scene picture source: stock video (type, query), stock image, AI (character, style, custom style, prompt), library | | 4 (insert popover) | |
| Insert between scenes | | 4 (insert popover) | |

### Media panel (classic: Visual Source + Motion)

| Classic | Step | |
|---|---|---|
| Stock video: type, search, Swap | 3 | |
| Stock image: search, Swap, Animate | 3 | |
| AI image: character chip (bind, remove, + New character modal), current result, style picker with samples, custom style (autosaved), model picker with price, prompt override, Generate / Regenerate | 3 | |
| Edit this image: instruction, suggestion chips, Apply edit | 3 | |
| Animate modal: model (price, slow-now warning), lip-sync engine, quality, duration, motion prompt chips, apply to many scenes with server price per scene, consent for talking people | 3 | |
| Cancel animation, back to the still, versions strip | 3 | |
| Lip-sync out of date: Regenerate lip-sync | 3 | |
| Apply this style to all scenes (server price, balance, skips) | 3 | |
| Library: current asset, Change, Open library (media picker), Animate | 3 | |
| Audiogram: design, colour + custom, background, Apply; live preview in the frame | 3 (the frame shows a placeholder today) | |
| Motion for stills: effect, intensity | 3 | |
| Toolbar on picture select: Swap, Regenerate (price), Animate (price), Edit, Motion | 3 | |

### Voice panel

| Classic | Step | |
|---|---|---|
| Current voice, play, language, speed label | 3 | |
| Volume, speed, stability | 3 | |
| Voice picker: personalities, your cloned voices, expressive, standard, preview each | 3 | |
| Clone a voice (modal) | 3 | |
| Voice direction (Gemini) with cue hints | 3 | |
| Apply this voice to all scenes | 3 | |
| Re-record all scenes (server price, balance, skips) | 3 | |
| Script changed: Regenerate | 3 | |
| Save voice as profile | 3 | |
| Custom audio: upload, record with timer, preview player, transcribe, use with or without the transcript, discard; previously uploaded list with search | 3 | |
| Toolbar on voice select: voice name, speed, Re-record (price) | 3 | |

### Captions panel

| Classic | Step | |
|---|---|---|
| UGC headline text (this scene) | 3 | |
| Presets: apply, save, delete | 3 | |
| On / off | 3 | |
| Effect row + "View all" gallery | 3 | |
| Highlight style, highlight mode, backdrop panel + colour | 3 | |
| Base style (Impact, Editorial, Hacker) | 3 | |
| Position, font picker | 3 | |
| Text colour, highlight colour (+ custom) | 3 | |
| Size S/M/L/XL + custom slider | 3 | |
| Apply to all scenes | 3 | |
| Typing in the caption edits the script (already) | 2 ✓ | |
| Toolbar on caption select: effect, size, highlight colour, position, sharing state with the panel | 3 | |

### Audio panel (classic: Music + Sounds)

| Classic | Step | |
|---|---|---|
| Now playing | 4 | |
| Library with mood filter, audition, No music | 4 | |
| My uploads (media picker) | 4 | |
| AI music: mood chips or own words, price, Generate | 4 | |
| Volume, duck volume, fade in, loop, duck under voice | 4 | |
| Scene sound effect: pick, change, volume, remove | 4 | |

### Brand panel

| Classic | Step | |
|---|---|---|
| Channel, brand kit, Save defaults | 4 | |

### Assistant panel

| Classic | Step | |
|---|---|---|
| Scope (which scenes), New chat with confirm | 4 | |
| Messages, thinking state, quick prompts | 4 | |
| Action cards: cost, diff, Apply / Skip, running checklist, Undo, Retry, unknown state, top-up when short, Apply all / Skip all | 4 | |
| Theme & brief popover (save and lock, refresh) | 4 | |
| Assistant defaults: image model, animation tier, visual source | 4 | |
| Auto-apply low-risk, balance | 4 | |
| "Ask the assistant" bar under the frame (new) | 4 | |

### Project panel

| Classic | Step | |
|---|---|---|
| Name (rename), aspect ratio | 4 | |
| Variants link | 4 | |

### Timeline

| Classic | New editor | Step | |
|---|---|---|---|
| Scene / Full video, scrubber with scene marks, play, prev/next, time | timeline with lanes | 2 | ✓ |
| Music indicator on the frame | music lane | 2 | ✓ |
| Drag to reorder (classic's hidden timeline) | | 4 | |
| Collapse the timeline (new) | | 4 | |

### Phone

| Classic | Step | |
|---|---|---|
| Phone bar, dock (Scenes, Config, Assistant, Finish), sheets, project sheet | 5 | |

## Step 6: the parity check and opening

- Walk this list on prod as staff; every row ✓ or a written reason it was dropped (owner approves drops).
- The three-task test from the design review (change a caption, swap a picture, add a scene) with 3 people.
- Then set `EDITOR_NEW_OPEN=true` and `EDITOR_NEW_USERS_FROM` to the deploy time.
- Acceptance, for retiring classic: read from `users.preferences_json.editor` and `editor_chosen_at` (who tried the new
  one, who went back and when). Classic retires when the owner judges every old user has accepted it.
