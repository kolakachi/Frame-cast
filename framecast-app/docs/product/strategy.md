# WyvStudio growth strategy

Owner, 2026-10-16: "we need to have a strategy doc". This is how WyvStudio gets seen and bought. Who we sell to and how
we say it are in `positioning-roadmap.md`; this doc does not repeat them. The work it creates lives as rows in `todo.md`
(the one list). Update this doc when a decision changes, not when a task is done.

## 1. Where we are (2026-10-16)

- **Product:** Create (the Creative Director makes branded short videos), UGC Ads (presenter videos, 10 types), 39
  public templates, a ChatGPT/Claude connector and a developer API.
- **On sale:**
  - the $9 Test Pass;
  - packs: Starter $47 for 2,500 credits, Creator $157 for 9,000, Agency $299 for 20,000;
  - top-ups;
  - the yearly Creator plan ($590) on sales.wyvstudio.com for our own traffic;
  - AppSumo tiers, for new licences only.
- **Traffic:** small. Sign-ups in the last 30 days: 15. By source: 5 AppSumo, 2 direct, 8 unknown. The marketer's
  email brought visits but no sign-ups.
- **Biggest leak:** every way in asks for payment before the visitor sees a result. 8 of 18 AppSumo sales were refunded;
  the reasons were "harder and slower than expected" and "doesn't fit my workflow".
- **People:** the owner builds and stays behind the scenes. The marketer is the public face. No ad budget is set yet.

## 2. The goal for the next 90 days

**Reach 30 paying teams with a second purchase or top-up.**

- A second purchase is the signal that a team needs ads every week, which is our whole business.
- It is also the trigger for a monthly plan (positioning-roadmap, Phase 3).

On the way, two checkpoints:

| By | What | Target |
|---|---|---|
| Day 30 | Visitors who make a free first video | 100 |
| Day 60 | Free first video → paid | 10% |
| Day 90 | Paying teams that buy again | 30 |

## 3. The loop

Everything below feeds one loop:

1. **Template post:** a short post shows a template video and offers it ("comment TEMPLATE and I'll send you this ad for
   your brand").
2. **Template page:** the link opens that template's public page: its video, what you can change, and "Make this for
   your product".
3. **Free first video:** the visitor signs up and makes one video from the template with their text, colours and photo,
   free and watermarked. It costs us cents (no AI builder).
4. **Paid:** the next video uses AI generation (presenters, shots, voice) and needs a pack or the Test Pass.
5. **Share:** every shared video carries "Made with WyvStudio" and "Make a video like this", which opens the template
   behind it and brings the next visitor into step 2.

The loop needs three things built before any channel is worth paying for: template pages (TPL-PAGES), the free first
video (FREE-FIRST) and template links on shares (SHARE-TPL). Until they exist, every channel lands people on a payment
wall.

## 4. Channels, in order

Each channel gets a test with a stop rule, so nothing runs on hope.

| # | Channel | Why it fits | Test | Stop rule |
|---|---|---|---|---|
| 1 | **LinkedIn, the marketer's profile and the company page** | Lean brand and marketing teams are there | 30 days: one template post a day, "comment for the template"; Claude drafts each reply and link, a person posts them | Under 20 free first videos in 30 days |
| 2 | **Template pages in search** | 39 pages people search for ("product offer ad template") | Pages live with sitemap and share previews; watch impressions in Search Console | No impressions after 60 days: fix titles before writing more |
| 3 | **Connector directories** | Marketers using ChatGPT and Claude find us where they already work | List in MCP registries, the ChatGPT and Claude directories, and skills.marketing | Free; keep them listed |
| 4 | **Short-form social (TikTok, Reels, X)** | The product is video, so show the videos | Weekly template and "made from one line" videos, made by the Creative Director | No sign-ups from them after 6 weeks: drop to monthly |
| 5 | **Paid creators** | Borrow audiences that match ours | After channel 1 converts: pay 3–5 marketing creators to share template posts, tracked by UTM | Cost per paying team above one Creator pack ($157) |
| 6 | **Google Ads** | People already searching for ad videos | One landing page per keyword group (ADS-LP); budget per AD-BUDGET | Cost per paying team above $157 after $500 spent |
| 7 | **Outreach** | Book demos with teams already running video ads | A free ad made from the team's own product (PROOF), then 2–3 case studies | Under 2 replies per 50 sent |

Not now:

- Bulk cold outreach tools: they need the free first video to point at.
- More marketplace launches like AppSumo: refunds ran at 47%, and AppSumo credits never refill.
- Comparing ourselves to avatar tools.

## 5. Content

- **Lead magnets are our templates.** Each one is a real ad a team can make for their brand. Add playbooks only when a
  template needs explaining ("3 hooks for a product drop").
- **Posts show the video first.** One template a post: the ad, what it's for, and the offer. No feature lists.
- **Proof beats claims:** real ads made in WyvStudio, case studies with real numbers once we have them.
- **Voice:** plain words. We make the video; teams post it or run it themselves. Never say or imply that we run ads.
  Never mention Series. Say WyvStudio as "weave studio" in anything spoken.

## 6. Money

| Stage | Spend | On |
|---|---|---|
| Days 1–30 | Under $100 | Our own credits for posts and template videos; listings are free |
| Days 31–60 | Up to $1,000 | Paid creators, only if LinkedIn converts |
| Days 61–90 | Per AD-BUDGET ($1,000–1,500 recommended) | Google Ads and more creators, put on what works |

Revenue from each stage pays for the next one. Free first videos cost us cents each; cap them at one per verified
workspace.

## 7. What we measure

Every link carries `utm_source` (and the template's id where there is one). `workspaces.acquisition_channel` records
where each sign-up came from, and God Mode's by-source table shows sales by source.

| Metric | Tells us |
|---|---|
| Template page visit → sign-up | The template and its page land |
| Sign-up → free first video | The product delivers in the first session |
| Free first video → paid | The free video sells the paid one |
| Paid → second purchase in 30 days | There is a weekly need |
| Refund rate by channel | Whether a channel brings the right people |
| Cost per paying team, by channel | Where the next dollar goes |

Review every two weeks: keep, change, or stop each channel by its stop rule.

## 8. Who does what

- **Owner:** decisions, the product, and this doc. Stays out of public posts.
- **Marketer:** the LinkedIn profile, posting, replies, creator deals, the email list.
- **Claude:**
  - builds the loop (pages, free video, share links) and the listings;
  - drafts posts and replies;
  - makes template and social videos (asking before paid generation);
  - reports the numbers.

## 9. Open decisions

| Decision | Recommendation |
|---|---|
| FREE-FIRST limits | One template video per verified workspace, watermarked, download allowed; removing the mark needs a purchase |
| Daily posting | One good post a day on one profile, not two per profile |
| AD-BUDGET | $1,000–1,500 for the first Google Ads test, after the loop is built |
| Paid creators | Only after LinkedIn shows free first videos turning into sales |
