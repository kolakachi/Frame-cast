# ChatGPT app directory submission — WyvStudio

Drafted 26 September 2026 against MCP 1.8.1 on production. Everything here is
taken from the live server, the deployed docs or the marketing site; items
marked **[owner]** need a decision or an asset only the owner can supply.
Field names follow OpenAI's developer submission form in spirit; map them to
the form's exact labels when filing.

## 1. Listing

| Field | Value |
|---|---|
| App name | WyvStudio |
| Tagline (≤ 80 chars) | Branded short-form video without a shoot, from a chat. |
| Short description | Make, edit, preview and publish short vertical videos and UGC ads from ChatGPT. Every credit spend is quoted first and only runs after you say yes. |
| Long description | See §1.1 |
| Category | Productivity / Marketing **[owner: pick from the directory's list]** |
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
5. **"Show me the first scene."** → `get_scene_preview` returns the picture
   in the chat, or as `preview_url` when the client shows images by link.
   No spend. **[owner: note in the submission whether ChatGPT rendered it
   inline or as a link]**
6. **"Re-record the narration of scene 1."** → `propose_edits` (3 credits
   shown) → approval → `apply_edits`. Charged: 3.
7. **"Give me a public link to watch it."** → `share_video` returns a
   `/sample/…` link that plays without login; **"turn the link off"** makes
   the same URL return 404.
8. **"Post it to TikTok."** → `list_social_accounts` reports no connected
   account and points to the app; any attempt to publish without an explicit
   confirmation is refused with `confirmation_required`. Nothing leaves the
   workspace.
9. **"Plan a 10-second UGC ad for a standing desk with an AI presenter and
   tell me what it costs."** → `plan_ugc` then `estimate_ugc` (about 210
   credits for one draft take). The reviewer may stop here; approving spends
   the quote and produces a one-take video.
10. **Disconnect.** Remove the app in ChatGPT or revoke it under Settings →
    API & Apps; the next call fails with `api_key_revoked`.

## 5. Tools and annotations

46 tools; the full table with costs is at
https://docs.wyvstudio.com/api-and-connectors/mcp-tools. Summary for the review:

| Class | Tools | Annotation |
|---|---|---|
| Read | capabilities, options, lists, project read/schema, status, result, exports, previews, operation status, posts, social accounts | `readOnlyHint: true` |
| Estimate (writes a 10-minute quote row, spends nothing, invisible in the app) | `estimate_video`, `estimate_ugc`, `estimate_character_image`, `estimate_character_reference_edit`, `estimate_presenter_preview`, `estimate_retry`, `propose_edits`, `plan_ugc`, `analyze_ugc_reference` | `readOnlyHint: true`, `idempotentHint: false` |
| Spend / create (only with a quote id the user approved) | `create_video`, `create_ugc`, `create_character_image`, `create_presenter_preview`, `apply_edits`, `apply_assistant_plan`, `retry_video`, `preview_voice`, `clone_voice`, `export_video` | `readOnlyHint: false`, `destructiveHint: false` |
| Other writes | `upload_asset`, `create_character`, `update_character`, `save_voice`, `ask_wyvstudio_assistant` (persists an assistant conversation) | `readOnlyHint: false`, `destructiveHint: false` |
| Leaves WyvStudio, reversible | `share_video` | `openWorldHint: true` |
| Leaves WyvStudio, irreversible | `publish_video` (requires `confirm: true` after the user approved account, caption and time) | `destructiveHint: true`, `openWorldHint: true` |
| Irreversible inside WyvStudio | `cancel_operation` | `destructiveHint: true` |

No tool deletes anything. Billing, members and settings are not reachable.

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

1. A two-minute screen recording of steps 2–7 above in ChatGPT.
2. Screenshots: the consent screen, an estimate awaiting approval, a finished
   result with the MP4 link, a scene preview in the chat, the share link
   playing in a logged-out browser.
3. The connection listed under Settings → API & Apps, and the revoke button.

## 9. Before filing

- [x] Walkthrough run once in ChatGPT on 26 September (owner's workspace);
      re-run step 5 after the preview_url deploy and note the rendering.
- [ ] Confirm the legal name, category, logo and screenshots.
- [ ] Rotate the reviewer password after the review; keep the workspace.
