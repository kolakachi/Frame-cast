# ChatGPT app directory submission — WyvStudio

Drafted 26 September 2026 against MCP 1.8.1 on production. Everything here is
taken from the live server, the deployed docs or the marketing site; items
marked **[owner]** need a decision or an asset only the owner can supply.
Section 0 gives the portal steps; sections 1–7 hold the values for each
portal tab.

## 0. How to submit (OpenAI's plugin submission portal)

Source: developers.openai.com/plugins/deploy/submission and the app
submission guidelines (developers.openai.com/apps-sdk/app-submission-guidelines).

1. **Verify the publisher identity** at platform.openai.com → organization
   settings: business verification for the company name, or individual
   verification to publish under a personal name. The submitter's Platform
   role needs "Apps Management: Write" (owners have it).
2. Open **platform.openai.com/plugins** → **Create plugin** → **With MCP**.
3. **Info tab:** plugin name, short and long descriptions, Developer Identity,
   logo and category, website / support / privacy / terms URLs (§1).
4. **MCP tab:** URL type **Universal**, MCP Server URL
   `https://app.wyvstudio.com/mcp`; authentication OAuth (the server
   publishes its metadata, §2); demo credentials = the reviewer account (§3);
   no UI domains (no widget), so the content security policy stays empty.
   Domain verification is required, not optional: nginx serves the portal's
   token at `https://app.wyvstudio.com/.well-known/openai-apps-challenge`
   (`nginx/default.conf`; listed by name because `.well-known` has no
   catch-all here). Then **Scan Tools**. Every tool states all three of
   readOnlyHint, openWorldHint and destructiveHint (§5) — the portal refuses
   a tool that leaves any of the three unsaid, and the reads carried no
   destructiveHint until 26 September.
5. **Starter prompts** (§4.1). **Test cases** (§4.2): the portal wants
   **exactly** five positive and three negative. Its published schema says
   `minItems`, but a sixth positive case is rejected outright — confirmed on
   26 September. §4.2 carries more than that on purpose; the JSON ships the
   five and three that cover the credit, consent and external-post gates.

   Rather than typing the 153 tool justifications into the form by hand,
   upload `chatgpt-app-submission.json` (beside this file) on the MCP tab.
   It fills App Info, the per-tool justifications and Testing in one go, and
   validates clean against OpenAI's published schema. Regenerate it whenever
   the tool set changes — its annotations must match what Scan Tools reads
   back, or the review sees two different stories.
6. **Global availability:** pick countries **[owner]**.
7. **Release notes** (§4.3), confirm the policy attestations, **Submit for
   Review**. Timelines vary; community reports range from days to weeks.
8. After approval, **publish from the portal** when ready. Later, tool
   additions and edits reach users after an automated re-scan; changes to the
   listing, credentials or test cases need a new version and review.

## 1. Listing

| Field | Value |
|---|---|
| App name | WyvStudio |
| Tagline (≤ 80 chars) | Branded short-form video without a shoot, from a chat. |
| Short description | Make, edit, preview and publish short vertical videos and UGC ads from ChatGPT. Every credit spend is quoted first and only runs after you say yes. |
| Long description | See §1.1 |
| Category | PRODUCTIVITY (OpenAI's enum offers no Marketing option) |
| Developer | Kollignton Kay Technologies Limited (per wyvstudio.com/terms) **[owner: confirm the legal name as it should appear]** |
| Website | https://wyvstudio.com |
| Documentation | https://docs.wyvstudio.com/api |
| Privacy policy | https://wyvstudio.com/privacy |
| Terms of service | https://wyvstudio.com/terms |
| Data deletion | https://wyvstudio.com/data-deletion |
| Acceptable use / synthetic media / AI safety | https://wyvstudio.com/acceptable-use · https://wyvstudio.com/synthetic-media · https://wyvstudio.com/ai-safety |
| Support contact | hello@wyvstudio.com |
| Logo | **[owner: 512×512 PNG, from marketing/logo-export.html]** |
| Screenshots | **[owner: 3–5 captures of the ChatGPT conversation: estimate → approval → result, a scene preview, a share link]** |

### 1.1 Long description

WyvStudio turns a prompt, a script, an article or your own product images into
a finished vertical video: script, scenes, stock or AI visuals, narration in
your chosen voice, captions and an MP4. It also plans and makes UGC-style ads
with AI presenters or your own consented characters.

Connected to ChatGPT, the assistant can do nearly everything you do in the app:
make a video and watch it render, read a video scene by scene and change any
of it (re-voice, swap or generate visuals, animate, captions, music), show you
a scene or a character as a picture in the chat, export in any ratio, share a
public watch link, and publish or schedule to a connected YouTube, TikTok,
Instagram or Facebook account after you confirm the exact post.

Spending is never a surprise. Anything that costs credits is estimated first,
the estimate is shown to you with your balance, and the work only starts after
you approve that specific quote. The same plan limits, credits and consent
rules as the app apply.

## 2. Server and authentication

| Field | Value |
|---|---|
| MCP endpoint | https://app.wyvstudio.com/mcp (Streamable HTTP, stateless) |
| Health | https://app.wyvstudio.com/mcp/healthz (answers 200 through nginx, checked 26 September) |
| Authorization server metadata | https://app.wyvstudio.com/.well-known/oauth-authorization-server |
| Protected resource metadata | https://app.wyvstudio.com/.well-known/oauth-protected-resource/mcp |
| Authentication | OAuth 2.1, authorization code with PKCE (S256), dynamic client registration (`/api/v1/oauth/register`), no client secret (`token_endpoint_auth_methods_supported: none`) |
| Scopes | `videos` (the only scope; it maps to the workspace the user picks on the consent screen) |
| Tokens | Access tokens last one hour; refresh tokens rotate on every use; the grant is backed by a hidden 90-day connection key scoped to one workspace |
| Consent screen | Sign in, pick a workspace you own or administer, "**ChatGPT** is asking to make videos in your workspace" → Allow / Deny |
| Revocation | Settings → API & Apps in WyvStudio (the connection appears under the client's name), the OAuth revocation endpoint, or removing the app in ChatGPT; a removed member or suspended workspace ends access too |
| Who can connect | Workspace owners and admins on any plan |

## 3. Test account for reviewers

| | |
|---|---|
| Sign-in URL | https://app.wyvstudio.com |
| Email | reviewer@wyvstudio.com |
| Password | **[owner: paste from the chat where it was issued; rotate after review]** |
| Workspace | "WyvStudio Review" (id 76), owner role, Creator plan, 500 credits |
| Not available in this account | Publishing to a real social account (none is connected; connecting one is a human OAuth step in Settings → Social accounts). `list_social_accounts`, the `confirmation_required` refusal and `share_video` work without one. |

The account was created the way a signup is, with a sample project and
workspace defaults. It is a real production workspace; nothing it makes
reaches other users.

## 4. Reviewer walkthrough (about 60 credits of the 500)

Each step names the tool ChatGPT will call and what the reviewer should see.

1. **Connect.** Settings → Plugins (Developer mode on) → Create → name
   "WyvStudio", server URL `https://app.wyvstudio.com/mcp`, OAuth with no
   client id → Sign in with WyvStudio → Allow. The connection then appears in
   WyvStudio under Settings → API & Apps.
2. **"What can you do with my WyvStudio account?"** → `get_capabilities`:
   plan Creator, balance 500, limits, per-scene prices. No spend.
3. **"Make a 20-second vertical video from this script: A standing desk pays
   for itself. You sit less, you focus more, and your back stops complaining.
   Stock footage is fine."** → `estimate_video` shows 24–30 credits and asks
   for approval. Nothing is charged until the reviewer says yes.
4. **"Yes, go ahead."** → `create_video`, then `get_video_status` every 15 s
   (script → scenes → visuals → narration → export; about two minutes) and
   `get_video_result` with a private MP4 link. Charged: 12 credits (four
   narrations), visible in the app's credit history.
5. **"Re-record the narration of scene 1."** → `propose_edits` (3 credits
   shown) → approval → `apply_edits`. Charged: 3.
6. **"Give me a public link to watch it."** → `share_video` returns a
   `/sample/…` link that plays without login; **"turn the link off"** makes
   the same URL return 404.
7. **"Post it to TikTok."** → `list_social_accounts` reports no connected
   account and points to the app; any attempt to publish without an explicit
   confirmation is refused with `confirmation_required`. Nothing leaves the
   workspace.
8. **"Plan a 10-second UGC ad for a standing desk with an AI presenter and
   tell me what it costs."** → `plan_ugc` then `estimate_ugc` (about 210
   credits for one draft take). The reviewer may stop here; approving spends
   the quote and produces a one-take video.
9. **Disconnect.** Remove the app in ChatGPT or revoke it under Settings →
    API & Apps; the next call fails with `api_key_revoked`.

### 4.1 Starter prompts

- "Make a 30-second vertical video: three reasons a standing desk pays for itself. Stock footage is fine."
- "Turn this product description into a 20-second ad with an AI presenter: …"
- "Read my latest WyvStudio video and make the narration more energetic."
- "Re-record scene 2 with the Kore voice and export in 1:1 for Instagram."
- "Give me a public link to watch my latest video."
- "Estimate a 10-second UGC ad for a standing desk with a described presenter, no character."

### 4.2 Test cases (portal format)

All cases use the reviewer account (§3). "Result shape" is what the tool
returns; the assistant paraphrases it.

**Positive** — P1 is folded into P2's case in the submission JSON (the
assistant calls `get_capabilities` before quoting anyway), since only five
fit.

| # | User prompt | Expected behaviour | Result shape |
|---|---|---|---|
| P1 | "What can you do with my WyvStudio account?" | Calls `get_capabilities`; reports plan Creator, balance, limits, prices. No spend. | `data.capabilities` with `plan`, `balance`, `limits`, `prices` |
| P2 | "Make a 20-second vertical video from this script: A standing desk pays for itself. You sit less, you focus more, and your back stops complaining. Stock footage is fine." | Calls `estimate_video`, shows 24–30 credits and the balance, asks for approval, does not create. | `data.quote_id`, `credits.min/max`, `balance`, `can_afford` |
| P3 | "Yes, go ahead." (after P2) | Calls `create_video` with the quote id, then polls `get_video_status` until `completed`, then `get_video_result` with a private MP4 link. About two minutes; 12 credits. | `data.video.id`, `status`, `credits.spent`; result `download_url` |
| P4 | "Re-record the narration of scene 1." | Reads the project, calls `propose_edits` (3 credits), asks, then `apply_edits` on approval. | proposal `credits.max: 3`; apply `applied[0].ok: true` |
| P5 | "Give me a public link to watch it, then turn it off." | `share_video` returns a `/sample/…` URL that plays logged out; `share_video` with `enabled: false` disables it. | `shared: true, share_url`; then `shared: false, share_url: null` |
| P6 | "Plan a 10-second UGC ad for a standing desk with a described presenter and tell me the cost." | `plan_ugc` then `estimate_ugc`; reports about 210 credits and asks. Nothing generated. | `data.quote_id`, `credits`, `pricing.takes: 1` |

**Negative** — only N1, N2 and N3 ship in the submission JSON; three is the
cap. N4 and N5 are still worth running by hand before filing.

| # | Scenario | Expected refusal / fallback | Why |
|---|---|---|---|
| N1 | "Make the video" without a quote, or with a quote older than ten minutes | `create_video` is refused (`quote_expired` / no quote); the assistant re-estimates and asks again. | Spending is bound to an approved, current quote. |
| N2 | "Post it to TikTok now." with no connected account | `list_social_accounts` reports none; `publish_video` is refused `confirmation_required` without `confirm: true`, and cannot proceed without an active account. Nothing is posted. | Publishing is external and irreversible; needs an account and explicit confirmation. |
| N3 | "Make a UGC ad using this photo of my colleague" without stating consent | `create_character` / `estimate_ugc` refuse `consent_required`. | Real-person likeness needs the user's stated consent. |
| N4 | "Delete my last video." | The assistant says deletion is not available through the connector and points to the app. | No delete tool exists. |
| N5 | A revoked or expired connection (revoke under Settings → API & Apps, then ask anything) | Every call fails `api_key_revoked` / `api_key_expired`; the assistant asks the user to reconnect. | Access ends immediately on revocation. |

### 4.3 Release notes (initial submission)

WyvStudio lets ChatGPT make, edit, share and publish short vertical videos
and UGC ads in the user's WyvStudio workspace. Initial submission, MCP
server 1.8.x at https://app.wyvstudio.com/mcp, OAuth 2.1 with PKCE and
dynamic registration. Every credit spend is estimated and approved first;
publishing requires explicit confirmation. Demo credentials: the reviewer
account in the credentials field (Creator plan, 500 credits, no MFA, no
social account connected, so publishing tests end at the refusal). Full
tool reference: https://docs.wyvstudio.com/api-and-connectors/mcp-tools.

## 5. Tools and annotations

51 tools; the full table with costs is at
https://docs.wyvstudio.com/api-and-connectors/mcp-tools. Summary for the review:

| Class | Tools | Annotation |
|---|---|---|
| Read | capabilities, options, lists, project read/schema, status, result, exports, previews (image plus a signed link; shown inline by clients that render MCP image content), operation status, posts, social accounts | `readOnlyHint: true` |
| Estimate (writes a 10-minute quote row, spends nothing, invisible in the app) | `estimate_video`, `estimate_ugc`, `estimate_character_image`, `estimate_character_reference_edit`, `estimate_presenter_preview`, `estimate_retry`, `propose_edits`, `plan_ugc`, `analyze_ugc_reference` | `readOnlyHint: true`, `idempotentHint: false` |
| Spend / create (only with a quote id the user approved) | `create_video`, `create_ugc`, `create_character_image`, `create_presenter_preview`, `apply_edits`, `apply_assistant_plan`, `retry_video`, `preview_voice`, `clone_voice`, `export_video` | `readOnlyHint: false`, `destructiveHint: false` |
| Other writes | `upload_asset`, `create_character`, `update_character`, `save_voice`, `ask_wyvstudio_assistant` (persists an assistant conversation) | `readOnlyHint: false`, `destructiveHint: false` |
| Leaves WyvStudio, reversible | `share_video` | `openWorldHint: true` |
| Leaves WyvStudio, irreversible | `publish_video` (requires `confirm: true` after the user approved account, caption and time) | `destructiveHint: true`, `openWorldHint: true` |
| Irreversible inside WyvStudio | `cancel_operation` | `destructiveHint: true` |

No tool deletes anything. Billing, members and settings are not reachable.

All 51 tools state readOnlyHint, destructiveHint and openWorldHint explicitly.
On a read destructiveHint is trivially false, but the portal treats an unstated
hint as a blocker rather than a default, so none is left out.

## 6. Data handling answers

- **What the app accesses:** the connected workspace's videos, scenes, exports,
  library assets, characters, voices, brand kits, channels, credit balance and
  plan limits; the names and ids of connected social accounts; posts made
  through the API. Only the workspace chosen at consent.
- **What the user sends:** prompts, scripts, URLs, uploaded images/audio/video,
  captions for posts. These are processed the same way as in the app,
  including by the third-party AI providers named in the privacy policy, to
  generate the requested media.
- **What the MCP server stores:** nothing. It is a stateless sidecar that
  forwards the user's bearer token to the WyvStudio API on every call and
  holds no credentials. Its logs record, per call, the tool name, HTTP
  status, latency and a 12-character hash prefix of the token; never the
  token, never prompt or media content.
- **What WyvStudio stores because of the connector:** the OAuth client
  registration, the grant and its hidden 90-day key (visible and revocable in
  Settings → API & Apps), quotes (10-minute expiry) and operation records
  (which key made which spend), plus everything the user asked it to make,
  exactly as if made in the app. Retention and deletion follow the privacy
  policy and the data-deletion page.
- **Secrets:** none are shared with OpenAI; ChatGPT holds only the OAuth
  tokens it obtained for the user.
- **Third parties:** the AI and stock providers already used by the app (see
  privacy policy) and, only when the user publishes, the social platform the
  user connected. **[owner: confirm the privacy policy names the current
  providers, including Gemini TTS, Replicate/Chatterbox and Seedance]**
- **Children / sensitive data:** not directed at children; no health,
  financial or biometric data is requested. Uploaded likenesses and voices
  require the user's stated consent (`consent: true`) before use; see the
  synthetic-media page.

## 7. Safety and content answers

- Every credit spend is quoted and requires the user's approval of that
  quote; a quote is bound to one exact request and expires in ten minutes.
- Publishing is refused unless the assistant passes `confirm: true`, which
  the tool description instructs it to do only after showing the user the
  account, caption and time; the same export cannot be posted twice to the
  same account within ten minutes by accident.
- Sharing is reversible and the public page plays only the latest finished
  export.
- Real-person likeness and voice cloning require explicit consent flags and
  follow the app's content review; the same acceptable-use and synthetic
  media policies apply.
- Rate limits per key: 60 reads and 10 writes a minute (120 / 20 per
  workspace), at most 3 videos generating at once.
- Keys and grants can be revoked instantly by the user or by WyvStudio.

## 8. Demo material to record **[owner]**

1. A two-minute screen recording of steps 2–6 above in ChatGPT.
2. Screenshots: the consent screen, an estimate awaiting approval, a finished
   result with the MP4 link, the share link playing in a logged-out browser.
3. The connection listed under Settings → API & Apps, and the revoke button.

## 9. Before filing

- [x] Walkthrough run once in ChatGPT on 26 September (owner's workspace).
      Previews are left out of the reviewer script: ChatGPT renders neither MCP
      image content nor outside image links; an Apps SDK widget is the route if
      wanted later.
- [ ] Confirm the legal name, logo and screenshots. Category is PRODUCTIVITY.
- [ ] Deploy before scanning: the `nginx` and `mcp` images both need a rebuild
      (`nginx/default.conf` and `mcp/server.js` are baked in, not mounted).
      Then **Verify Domain**, then **Scan Tools** — in that order.
- [ ] Rotate the reviewer password after the review; keep the workspace.
