# CMO brief: building WyvStudio's go-to-market from evidence

Owner, 2026-10-17: "go through all the marketing materials we have found… we need to start implementing this
immediately… Now you are my CMO." The owner asked for a deeper version of the task, not a bigger one. This page keeps
that answer and the rewritten brief, so it isn't lost in chat. The plan it produces updates `strategy.md`; the story is
in `brand-story.md`; the research and reviewed posts are in `study.md`; tasks are rows in `todo.md`.

## 1. What a top CMO would do that the original ask didn't

The original ask was to go through the collected marketing material and implement it. Those posts are other people's
tactics for other people's products. A strong CMO would instead:

- **Start from customers, not posts:** who actually bought, why, what they made, and why 8 of 18 AppSumo buyers
  refunded, before choosing a tactic.
- **Fix the first session before sending traffic:** measure sign-up → first finished video. If most people drop before
  their first ad, every channel pours water into a leaky bucket.
- **Commit to one buyer, one offer, one message and one channel,** each with a written hypothesis and what would prove
  it wrong.
- **Run two-week experiments with a scoreboard,** not a 90-day plan: each test with a baseline, a target and a
  keep-or-kill rule. This is also the answer to the worry that AI moves faster than a 90-day plan.
- **Set up measurement before launching:** tagged links, the funnel, and the source of every sign-up, so we know what
  worked.

## 2. The rawest material to start from

Not the posts or their summaries, but:

- **Our database:** every brief typed into Create and the UGC chat (customers' own words for what they want), which
  videos were finished, downloaded or shared, where each sign-up came from, refund reasons, feedback widget messages,
  support chats.
- **Customers themselves:** 20-minute conversations with Bob, neimar, ws28 and the AppSumo refunders.
- **First sessions as they happened:** PostHog session replays of new users (if replay is on).
- **The market's own words:** real threads in the communities checked in `study.md` §2, verbatim.
- **What competitors actually run:** live ads in the Meta Ad Library, their landing and price pages.
- **Search data:** Search Console queries people typed before landing on wyvstudio.com.

## 3. Methods and tools

| Method | Used for |
|---|---|
| Jobs-to-be-done and "why did you leave" interviews, by The Mom Test's rules (past behaviour, never pitch) | Why people buy and why they refund |
| Customer-words analysis: tag every brief, review and thread by pain, desired result, objection and buyer type, then count | The message, in their words |
| Funnel and cohort analysis: SQL on our database, PostHog funnels and replays | Where the first session breaks |
| April Dunford's positioning canvas, then a messaging house (one line, three pillars, proof under each) | The one message |
| Traction's Bullseye method: rank channels, cheap tests on the top three, commit to one | The one channel |
| ICE scoring (impact, confidence, ease) | Which experiment first |
| Cheap message tests: $5–20 boosts on two hooks, headline A/B on template pages, 5-second tests with real buyers | Which words work |
| Competitor teardowns: Meta Ad Library, landing pages, price pages | What the market already runs |
| A weekly growth meeting: one goal metric, the few numbers that drive it, decisions written down | Keeping the loop running |

## 4. What shows the result is right (other than the owner or Claude)

- **Customer behaviour in our data:** more first sessions reaching a finished video (target 80%), free → paid, second
  purchases within 30 days, fewer refunds.
- **Market response:** outreach replies, click-through on boosted hooks, template page → sign-up, Search Console
  impressions, AI assistants naming us.
- **Customers repeating our words back:** in interviews, buyers describe WyvStudio in the message's words without
  prompting.
- **Outside eyes:** five target buyers who can say what we do after 5 seconds on the homepage; G2 reviews from real
  customers.

Never judged by how good the plan reads.

## 5. The brief

> **Act as WyvStudio's CMO. Don't summarise the posts we collected; build our go-to-market from raw evidence and start
> the first two-week test now.**
>
> **1. Evidence (read-only, no spending).**
> - From our own database: the first-session funnel (sign-up → started → plan approved → video delivered → downloaded
>   or shared); where each sign-up came from; refunds and their reasons; every brief and chat message tagged by pain,
>   desired result, objection and buyer type, with counts.
> - From the market: 20 live competitor ads from the Meta Ad Library; real threads from the five checked communities,
>   in their exact words.
> - Write a 20-minute interview script and the list of who the owner should talk to (Bob, neimar, ws28, refunders),
>   with the message that invites them.
>
> **2. Decisions, each backed by that evidence and each with what would prove it wrong:** one buyer, one offer, one
> message (a messaging house: one line, three pillars, proof under each), one main channel (Bullseye method).
>
> **3. A two-week plan:** experiments ranked by ICE, each with a hypothesis, the metric and its current baseline, the
> target, and the keep-or-kill rule. Set up tracking first: tagged links, sign-up source, the funnel.
>
> **4. A scoreboard:** the goal metric (teams buying again), the numbers that drive it, and a weekly review.
>
> **5. Start immediately** on everything that needs no decision from the owner; list the decisions that do, each with
> a recommendation.
>
> **Judge success only by outside signals:** more first sessions reaching a finished video, free → paid, second
> purchases, fewer refunds, outreach replies, message-test click-through, and customers repeating our words back in
> interviews. Never by how good the plan reads.

## 6. Status

| Step | Status |
|---|---|
| 1. Evidence | Done 2026-10-17: `cmo-evidence.md` (interviews wait on the owner's invitations) |
| 2. Decisions | Drafted 2026-10-17: `cmo-decisions.md`; waits on the owner's five decisions (§5 there) |
| 3. Two-week plan | Waits on step 2 |
| 4. Scoreboard | Waits on step 2 |
| 5. Start | Waits on the owner's go |
