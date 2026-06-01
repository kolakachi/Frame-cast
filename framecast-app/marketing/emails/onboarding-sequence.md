# WyvStudio — Onboarding email sequence

Three transactional emails, sent automatically after a user signs up. Goal is **first-video conversion** by day 7. Drafts in plain text + minimal HTML — wire into Resend / Postmark / SES when SMTP deliverability lands (E18 deferred dependency).

Voice: warm, founder-first-person, no marketing fluff. Match the landing trust line — *"no credit games, no surprise renewals."* Each email is **one ask**.

---

## Day 1 — within 60 seconds of signup

**Subject:** Your first 30-second video, with a recurring character

**Preview text:** Skip the empty editor — start from a finished sample, swap the script, ship.

---

Hey {{ first_name | "there" }},

You're in. Three things to know in 60 seconds:

**1. You have 200 free credits.** That's enough for one full 30-second video with a recurring character — script → AI visuals → voice → captions → export. No card on file. Watermark on the export until you upgrade, otherwise everything works.

**2. The fastest path to "wow" is cloning the sample project.** When you open the app, you'll see a finished supplement-ad project waiting in your workspace. Open it. Hit play. Then swap the script for whatever brand or topic you actually care about. The character, the style, the pacing — all of that stays. You get a finished video in 5 minutes instead of staring at an empty editor.

**3. The unlock is the character feature.** Most AI video tools give you stock actors or stiff talking-head avatars. WyvStudio lets you upload one photo and use that same recognizable face in every scene, every video. It's the thing nobody else does well — try it on the sample first.

When you're ready: [open the editor →](https://app.wyvstudio.com/videos)

If you get stuck, hit the chat widget bottom-right or reply to this email. I read every one.

— Kola
Founder, WyvStudio

P.S. The first 14 days are full-refund — no questions. If WyvStudio doesn't earn its keep, just reply and we'll refund the whole subscription.

---

## Day 3 — only if user hasn't created a project yet

**Subject:** Stuck? Here's what most people get wrong in WyvStudio

**Preview text:** Three things that trip new users up — and how to skip them.

---

Hey {{ first_name | "there" }},

Noticed you signed up a few days ago and haven't kicked off a project yet. No pressure — that's exactly the time to send this email, because three things consistently trip new users up:

**1. The empty editor problem.** If you opened the app and saw a blank workspace, you might be looking for the sample project I mentioned. It's in the **Videos** section, labeled "Supplement Ad — Vitalume." That's the one to start from. Open it. Replay it. Swap the script for your topic. Done.

**2. Picking a recurring character.** When you create a project, you'll see a "Recurring character" chip in the wizard. **Pick one.** Even if you don't have a brand spokesperson — go to /characters first, upload a photo (or generate one with ✦), and use that face. Skipping this is the #1 reason people don't see why WyvStudio is different.

**3. The first 30 seconds are slow.** Generation takes ~3-6 minutes (it's running real AI image gen + TTS for each scene). The progress bar is honest — go grab coffee. When you come back, you'll have a finished video in the editor.

If something specific is blocking you, I'd really like to know what. Just hit reply with the screen you got stuck on — I'll help directly, and your answer probably saves the next 100 users the same friction.

[open the app →](https://app.wyvstudio.com)

— Kola

P.S. The **Help** page covers most of the questions I see: [wyvstudio.com/help](https://wyvstudio.com/help)

---

## Day 7 — only if user hasn't exported their first video

**Subject:** Want me to ship your first WyvStudio video for you?

**Preview text:** Send me the script + a photo. I'll make the video and send it back by EOD.

---

Hey {{ first_name | "there" }},

Most founder-led tools don't say this, but here goes: **if you haven't gotten a finished video out of WyvStudio yet, I'll make one for you, no charge.**

Send me back this email with:

1. A 30-60 second script (or just a topic if you don't have one — I'll write one)
2. One photo of the on-screen character you want — your face, your founder's, or a fictional one
3. The vibe: ad / podcast promo / explainer / story / something else

I'll run it through WyvStudio personally and send you the MP4 by end of day, plus a link to the project so you can keep iterating in your own workspace.

Two reasons I'm doing this:
- If WyvStudio doesn't earn its keep in *your* hands, I want to know why — and the fastest way to find that out is to do it together
- The unlock for most people is seeing their *own* finished video, not someone else's sample. So let's just make that happen

Reply when ready. Up to one per workspace.

— Kola
Founder, WyvStudio

P.S. If you'd rather just cancel, that's fine too — [Settings → Billing → Cancel](https://app.wyvstudio.com/settings). 14-day full refund if you've already paid. No hard feelings, no follow-up emails. The unsubscribe link is below.

---

## Implementation notes (for the dev who wires these up)

**Trigger logic:**

- **Day 1:** sent automatically immediately after the first successful `users.created` event. Always sent — even if user already created a project in the first 60s. (We assume *no* activity yet at send time.)
- **Day 3:** only sent if `projects.count(workspace_id) === 0` at send time. Skip otherwise.
- **Day 7:** only sent if `export_jobs.where(status='completed').count(workspace_id) === 0` at send time. Skip otherwise.

**Scheduling:** Laravel queue with `delay()` — push the job at signup time, condition runs at delivery time. Cancel-aware: if the user upgrades or deletes their account, the queued job no-ops cleanly.

**Variables to populate:**

- `{{ first_name }}` — pulled from `users.name`, falls back to `'there'` when blank
- All links are absolute, no tracking params for now (add UTM later only if Sentry / PostHog need them)
- From address: `Kola <kola@wyvstudio.com>` — personal from, not `noreply@`. Reply-to same.

**Subject A/B candidates to test once a baseline lands:**

- Day 1 alt: *"Your first WyvStudio video starts here"* (more direct, less specific)
- Day 3 alt: *"Quick check — anything blocking you?"* (lighter, more conversational)
- Day 7 alt: *"Last one from me. Want help getting your first video out?"* (commits to closure)

**Do NOT send if:**

- User has `unsubscribed_at IS NOT NULL`
- User has explicitly deleted their account
- User's last login was within 24h AND they've exported ≥ 1 video (they're already activated; emails are noise)

**Deliverability gates:**

- DKIM + SPF + DMARC must be green on the sending domain before any of these go live
- Hit Postmark / Resend's first-100-send warmup window before mass-enabling
- Monitor bounce rate < 2% and complaint rate < 0.1% in the first cohort

— Drafted 2026-06-01. Update when copy is approved or when SMTP swap (deferred Tier 1 item) lands.
