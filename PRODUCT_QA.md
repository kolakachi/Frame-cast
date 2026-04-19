# Framecast Product QA

## How to use this file

Every item in each phase gate must pass before the phase is declared complete.
Mark each item `[x]` when verified. Add the tester name and date in the Notes column where relevant.
A phase gate is not passed until every item is `[x]` — no exceptions.

**Status key:**
- `[ ]` — not tested
- `[x]` — passed
- `[!]` — failed (add failure note inline)
- `[-]` — skipped / not applicable (add reason)

---

## Focused MVP Gate

The focused MVP scope and QA gate have been moved out of this roadmap QA file to avoid duplicated checklists.

- Scope: `PRODUCT_MVP_CORE.md`
- QA gate: `PRODUCT_MVP_CORE_QA.md`

Phase B work should not begin until `PRODUCT_MVP_CORE_QA.md` passes.

---

## Phase B Gate — Publish

Must pass before Phase C work begins.

---

### B1 — Social Account Connect

| # | Test | Expected Result | Status |
|---|---|---|---|
| B1.1 | Navigate to Settings → Connected Accounts | Section visible with "Connect" buttons per platform | `[ ]` |
| B1.2 | Click Connect YouTube → complete OAuth | Account appears in list with channel name and avatar | `[ ]` |
| B1.3 | Click Connect TikTok → complete OAuth | Account appears in list with display name | `[ ]` |
| B1.4 | Click Connect Instagram → complete OAuth | Account appears with page name | `[ ]` |
| B1.5 | Revoke the app permission on YouTube (via Google settings), then return to Framecast | Account shows as `expired` or `revoked` — user prompted to reconnect | `[ ]` |
| B1.6 | Click Disconnect on a connected account | Account removed from list — no orphaned tokens in DB | `[ ]` |
| B1.7 | Connect two accounts on the same platform (where plan allows) | Both appear and are independently selectable | `[ ]` |
| B1.8 | Free plan user tries to connect a second social account (limit is 1) | Blocked with upgrade prompt | `[ ]` |
| B1.9 | Check platform approval/audit status before enabling public auto-post | Platform is marked ready, private-only, or blocked in admin/config | `[ ]` |

---

### B2 — Schedule Post Flow

| # | Test | Expected Result | Status |
|---|---|---|---|
| B2.1 | Complete an export — check for "Schedule Post" button | Button appears on completed export card | `[ ]` |
| B2.2 | Open Schedule modal | Shows connected accounts, caption field, hashtag field, date/time picker | `[ ]` |
| B2.3 | Enter caption over character limit for TikTok (>2200) | Warning shown — save blocked until within limit | `[ ]` |
| B2.4 | Save as draft (no scheduled_at) | Post appears in calendar as grey draft | `[ ]` |
| B2.5 | Schedule a post for 2 minutes in the future | Post appears in calendar as blue scheduled | `[ ]` |
| B2.6 | Edit the caption on a scheduled post | Caption updated — scheduled_at unchanged | `[ ]` |
| B2.7 | Reschedule a post to a new time | Post moves to new time slot in calendar | `[ ]` |
| B2.8 | Cancel a scheduled post | Post disappears from calendar — DB record soft-deleted or status set to cancelled | `[ ]` |

---

### B3 — Auto-Publish

| # | Test | Expected Result | Status |
|---|---|---|---|
| B3.1 | Wait for a scheduled post time to pass | `PublishVideoJob` fires — post status changes to `published` | `[ ]` |
| B3.2 | Check the platform (YouTube/TikTok/Instagram) | Video appears on the connected account with correct title and caption | `[ ]` |
| B3.3 | Reverb event fires on successful publish | Toast notification in the app: "Your video was published to YouTube" | `[ ]` |
| B3.4 | Simulate a platform API failure on publish | Job retries up to 3× — on final failure, status is `failed` with reason | `[ ]` |
| B3.5 | Reverb event fires on publish failure | Toast notification: "Failed to publish — [reason]. Retry available." | `[ ]` |
| B3.6 | Click Retry on a failed post from the calendar | Job re-queued — publishes successfully on retry | `[ ]` |
| B3.7 | Post to YouTube — check title/description | Title matches project title; description matches caption | `[ ]` |
| B3.8 | Post with an expired token | Job detects expired token, marks account as `expired`, notifies user to reconnect | `[ ]` |
| B3.9 | Attempt auto-publish from a plan/export with watermark to a platform that rejects third-party branding | Publish is blocked or clean platform-compliant render is used | `[ ]` |

---

### B4 — Posting Calendar

| # | Test | Expected Result | Status |
|---|---|---|---|
| B4.1 | Navigate to Calendar | Loads without error — default month view | `[ ]` |
| B4.2 | Days with scheduled posts show a badge | Badge count matches scheduled post count for that day | `[ ]` |
| B4.3 | Switch to week view | Posts appear in correct time slots | `[ ]` |
| B4.4 | Click a post card | Detail modal opens: video title, platform, caption, status, scheduled time | `[ ]` |
| B4.5 | Filter calendar by platform | Only posts for that platform visible | `[ ]` |
| B4.6 | Filter calendar by status | Only posts with that status visible | `[ ]` |
| B4.7 | Navigate to next month | Calendar updates — no data from previous month bleeds in | `[ ]` |
| B4.8 | Click "Add to calendar" from an export | Opens Schedule modal pre-filled — saves to correct date | `[ ]` |
| B4.9 | Published post shows green on calendar | Color correct — `published` status reflected immediately after publish | `[ ]` |
| B4.10 | Failed post shows red on calendar | Color correct — detail modal shows failure reason and retry button | `[ ]` |

---

**Phase B gate passes when:** All items in B1–B4 are `[x]` or `[-]` with documented reason.

---

## Phase C Gate — Series and Full Calendar

Must pass before Phase D work begins.

---

### C1 — Series Creation

| # | Test | Expected Result | Status |
|---|---|---|---|
| C1.1 | Navigate to Series (dashboard or nav) | Series list or empty state visible | `[ ]` |
| C1.2 | Create a new series with name, niche, default voice, style, caption preset, music mood, aspect ratio | Series saved — appears in list with cover | `[ ]` |
| C1.3 | Edit series defaults | Changes persist — existing episodes unaffected | `[ ]` |
| C1.4 | Delete a series with no episodes | Series removed | `[ ]` |
| C1.5 | Attempt to delete a series with existing episodes | Confirmation required — on confirm, episodes orphaned or archived (not deleted) | `[ ]` |

---

### C2 — New Episode Flow

| # | Test | Expected Result | Status |
|---|---|---|---|
| C2.1 | Click "New Episode" on a series | Wizard opens with all series defaults pre-filled | `[ ]` |
| C2.2 | Verify pre-fill: voice profile, visual style, caption preset, music mood, aspect ratio all match series | All fields correct | `[ ]` |
| C2.3 | User only provides a script — no other changes needed | Generation triggers with correct defaults | `[ ]` |
| C2.4 | After generation completes | Episode appears in series episode list as Episode N | `[ ]` |
| C2.5 | Episode numbering | Episodes are auto-numbered in order of creation — gaps allowed (e.g., ep 1, 2, 5) | `[ ]` |
| C2.6 | Open episode in Editor | All series defaults are applied — voice, style, caption, music visible | `[ ]` |

---

### C3 — Batch Series Generation

| # | Test | Expected Result | Status |
|---|---|---|---|
| C3.1 | Paste 3 scripts into batch input on series page | 3 episodes queued — progress visible | `[ ]` |
| C3.2 | All 3 episodes generate successfully | All appear in series episode list | `[ ]` |
| C3.3 | One episode in batch fails (simulate error) | Other 2 complete — failed episode shows retry | `[ ]` |
| C3.4 | Retry failed batch episode | Retries independently without affecting siblings | `[ ]` |
| C3.5 | Upload CSV with 5 episode scripts | 5 episodes queued correctly | `[ ]` |

---

### C4 — Series Calendar View

| # | Test | Expected Result | Status |
|---|---|---|---|
| C4.1 | Schedule posts for 3 episodes of the same series | All appear in calendar | `[ ]` |
| C4.2 | Filter calendar by series | Only that series' posts visible | `[ ]` |
| C4.3 | Series posts visually distinct from non-series posts | Same color or label per series | `[ ]` |
| C4.4 | Click an episode post in calendar | Detail modal shows series name and episode number | `[ ]` |
| C4.5 | Series detail page shows episode schedule | Episodes show their scheduled publish dates | `[ ]` |

---

**Phase C gate passes when:** All items in C1–C4 are `[x]` or `[-]` with documented reason.

---

## Phase D Gate — Agency

Must pass before the product is considered full launch-ready for agency/business customers.

---

### D1 — Team Roles and Invites

| # | Test | Expected Result | Status |
|---|---|---|---|
| D1.1 | Owner invites a new member by email | Magic link email arrives — on verify, user joins the workspace | `[ ]` |
| D1.2 | Assign `editor` role to invited member | Member can edit scenes but cannot schedule or publish posts | `[ ]` |
| D1.3 | Assign `publisher` role | Member can schedule and publish but cannot edit scenes | `[ ]` |
| D1.4 | Assign `viewer` role | Member can view projects and exports but cannot edit or publish | `[ ]` |
| D1.5 | Remove a member | Member loses access immediately — existing work unaffected | `[ ]` |
| D1.6 | Member attempts action outside their role | 403 response — clear "you don't have permission" message | `[ ]` |
| D1.7 | Owner transfers ownership to another member | New owner has full access — previous owner becomes editor | `[ ]` |

---

### D2 — Client Approval Link

| # | Test | Expected Result | Status |
|---|---|---|---|
| D2.1 | Click "Send for Approval" on a completed export | Approval request created — link copied or sent by email | `[ ]` |
| D2.2 | Open approval link in incognito (no login) | Public approval page loads — video plays | `[ ]` |
| D2.3 | Client clicks Approve | Status set to `approved` — workspace notified via Reverb | `[ ]` |
| D2.4 | Client clicks Reject with note | Status set to `rejected` — rejection note visible in workspace | `[ ]` |
| D2.5 | Approval link used after 7 days | Page shows "This link has expired" | `[ ]` |
| D2.6 | Same link used twice (approve, then try again) | Second action rejected — already decided | `[ ]` |
| D2.7 | Pre-select a platform before sending for approval | On approval, post auto-schedules to that platform | `[ ]` |

---

### D3 — White-Label Export

| # | Test | Expected Result | Status |
|---|---|---|---|
| D3.1 | Export on Free plan | Watermark visible in output | `[ ]` |
| D3.2 | Export on Creator plan | Watermark visible (Creator plan includes watermark) | `[ ]` |
| D3.3 | Export on Studio plan | No watermark in output | `[ ]` |
| D3.4 | Export on Agency plan | No watermark in output | `[ ]` |
| D3.5 | Admin upgrades workspace mid-export | Next export reflects new plan's watermark setting | `[ ]` |

---

### D4 — Analytics Pull-Back

| # | Test | Expected Result | Status |
|---|---|---|---|
| D4.1 | 24 hours after a YouTube post publishes | Analytics job has run — views/likes/comments stored | `[ ]` |
| D4.2 | Open post detail modal in calendar | Analytics panel shows views, likes, comments, watch time | `[ ]` |
| D4.3 | Series detail page shows aggregate analytics | Total views and avg watch time across all published episodes | `[ ]` |
| D4.4 | Platform API returns 403 (revoked token) | Analytics job skips gracefully — marks account as expired | `[ ]` |

---

### D5 — Agency Workspace

| # | Test | Expected Result | Status |
|---|---|---|---|
| D5.1 | Agency plan owner creates a sub-workspace (client brand) | Sub-workspace created — isolated from other workspaces | `[ ]` |
| D5.2 | Switch between client workspaces from top nav | Switch works — correct workspace data loads | `[ ]` |
| D5.3 | Billing for sub-workspace | Charges appear under the agency workspace's Paddle account | `[ ]` |
| D5.4 | Sub-workspace limit enforcement | Sub-workspace respects agency plan limits | `[ ]` |

---

**Phase D gate passes when:** All items in D1–D5 are `[x]` or `[-]` with documented reason.

---

## Regression Checklist

Run after every phase gate before declaring it passed. These cover the core product regardless of phase.

| # | Test | Expected Result | Status |
|---|---|---|---|
| R1 | Create a new project from script → generation completes | All 5 pipeline stages fire and complete | `[ ]` |
| R2 | Open Editor — all scenes visible, voice/visual assigned | No blank scenes, no broken asset URLs | `[ ]` |
| R3 | Edit scene script → autosave fires | "Saving…" → "Saved" within 3 seconds | `[ ]` |
| R4 | Swap visual on a scene | New visual appears — old visual not still shown | `[ ]` |
| R5 | Regenerate voice on a scene | New audio plays — outdated state cleared | `[ ]` |
| R6 | Export from Editor | MP4 renders and is downloadable | `[ ]` |
| R7 | Create 3 variants → batch export | All 3 exports complete — downloadable individually | `[ ]` |
| R8 | Localize project to 2 languages | 2 localized projects created with correct TTS | `[ ]` |
| R9 | Upload asset to library | Asset appears in library with correct type and thumbnail | `[ ]` |
| R10 | Create channel + brand kit → new project uses them | Channel and brand kit pre-filled in new project wizard | `[ ]` |

---

## Automated Regression Targets

Manual QA must be backed by repeatable tests before public launch.

| # | Target | Expected Coverage | Status |
|---|---|---|---|
| T1 | Golden project fixture | Known project with scenes, visuals, voice, captions, music, and export | `[ ]` |
| T2 | Export smoke test | Confirms MP4 has video stream, audio stream, expected duration, and expected dimensions | `[ ]` |
| T3 | Provider mock tests | OpenAI/TTS/image failures tested without spending real API budget | `[ ]` |
| T4 | Queue retry tests | Failed jobs retry without duplicate assets or duplicate usage records | `[ ]` |
| T5 | Signed media URL tests | Valid, expired, wrong-workspace, and wrong-bucket URLs covered | `[ ]` |
| T6 | Playwright creation flow | Wizard → generation progress → editor → export happy path | `[ ]` |
| T7 | Screenshot regression | Editor sidebar, caption panel, preview, and generation progress screens | `[ ]` |
