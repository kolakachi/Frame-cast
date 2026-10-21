# TREND-LIB: what's working, for the planner (narrow version)

Scope for the owner, 2026-10-21. Nothing here is built until approved.

Mockup and flow: `trend-library-mockup.html` (weekly job, Create plan line, UGC hook chips, God Mode, connector, pilot rating).

## Why

Ava Studio (launched 2026-10-20) markets "400,000+ TikToks analyzed for what's working": its agent picks breakout
videos that fit the product, labels why they worked (hook, format, proof) and writes the script from them. Our planner
writes hooks from the brief and the product page only; it has never seen what is getting views this week.

The crawl is not something MCP does: it is a data pipeline on their servers, and the agent queries the results. We can
run the same thing ourselves, smaller and aimed at our customer: small sellers running paid social.

## What the user gets

- **Better hooks without asking.** For a candle shop's ad, the planner sees "this week in candles and home: these 5 hook
  styles and 3 formats are breaking out" and writes the script from them (never copying anyone's words).
- **The reason on the plan card.** One line: "Hook style breaking out in home and candles this week: instant
  before/after reveal (7 of the top 40)."
- **Through the connector.** A `trends` tool in our MCP server, so an agent can ask "what's working for jewellery
  sellers this week?" (the answer to Ava's pitch, from a tool we already ship).

Not in this version: a page of trending videos to browse, posting results back, "make more like the winner".

## Data: where it comes from

| Source | What | Price (third-party figures; to confirm on each vendor's page before buying) | Notes |
|---|---|---|---|
| **ScrapeCreators** (recommended to start) | TikTok search by keyword and hashtag, profiles, a creator's videos, **transcripts** | $47 for 25,000 credits, $497 for 500,000; most calls 1 credit; credits never expire; cache hits free | One vendor for every step, transcripts included, no monthly floor |
| Apify TikTok actors | Videos by keyword, hashtag or profile | about $0.25–$4 per 1,000 results, depending on the actor | Cheapest per item; community-run, reliability varies |
| EnsembleData | Same coverage | $100 a month for 1,500 calls a day and up | A monthly floor whether used or not |
| TikTok Commercial Content API (official) | Paid ads shown in the EU | Free, by application (1–2 weeks) | Official; EU ads only |
| Meta Ad Library API (official) | Ads, including commercial ones, shown in the EU | Free; identity check | Official; outside the EU only political ads come back |
| TikTok Creative Center Top Ads | Top ads by industry | No official API; scraping actors from about $1.50 per 1,000 | Public site, terms unclear: not used in this version |

**Terms.** Pulling public posts through a data vendor may still breach a platform's terms. We keep only metadata, our own
labels and a link (no video files kept, nothing re-published, no one else's video shown inside WyvStudio), and we apply
for the two official ad sources in parallel.

## What we collect (weekly)

- **7 categories** our sellers are in: beauty and skincare, jewellery and accessories, candles and home, food and drink,
  apparel, fitness, apps and software. About 30–40 keywords and hashtags each, set once with the owner.
- **About 20,000 posts a week** from the last 30 days: views, likes, comments, shares, caption, sound, length, post date,
  creator, link.
- **Breakouts:** a post's views over its creator's usual views (the median of their last 15 posts). The creator's
  baseline is fetched only for posts over 100,000 views, about 2,000 creators a week.
- **The top 2% (about 400 a week) are labelled:** the first 5 seconds of the transcript (the hook), 3 frames read by a
  small vision model (format: talking head, product reveal, before/after, demo, skit; proof: numbers, reaction,
  result; CTA), length and pacing. The video is fetched for the frames and deleted at once.
- **Each week, per category:** the hook styles and formats breaking out, with counts and 2–3 example links, written to a
  `trend_patterns` row the planner and the MCP tool read.

## Cost

| Step | Per week (estimate) |
|---|---|
| 20,000 posts (ScrapeCreators search, assuming a page of results per credit; Apify as a fallback at $0.25–$1.50 per 1,000) | $5–30 |
| 2,000 creator baselines | $2–4 |
| 400 transcripts | under $1 |
| 400 vision labels (Haiku 4.5, 3 frames each) | about $1–2 |
| **Total** | **about $10–40 a week ($40–160 a month)** |

A pilot on the $47 ScrapeCreators pack (25,000 credits) covers the first weeks. Running it costs nothing per video
made: the planner reads a few hundred words from a table.

## Build (about a week)

1. Tables: `trend_posts`, `trend_creators`, `trend_labels`, `trend_patterns` (Postgres; a few GB a year).
2. A weekly job (scheduler): collect, score breakouts, label the top 2%, write the week's patterns per category.
3. The planner: the brief's category (from the product page or brand kit) picks that week's patterns; a short "what's
   working" block joins the planner's context with the rule "use the pattern, never the words".
4. The plan card's one line; the `trends` MCP tool; a God Mode page with the week's breakouts and the job's cost.

## How we know it helps

Before scaling: 10 in-house briefs across the categories, planned with and without the patterns; the owner rates the
hooks without knowing which is which. Live: hooks drawn from a pattern against the rest, by downloads and shares
(Friday scoreboard). It grows past 20,000 a week only if hooks rate better.

## Decisions for the owner

1. Provider: **ScrapeCreators** to start (the $47 pack), Apify as the cheaper fallback for volume.
2. The 7 categories and their keywords (a first list from the ICP tracker and the outreach sellers, for your edit).
3. Apply now for the two official ad sources (TikTok Commercial Content API, Meta Ad Library API): free, EU ads only.
4. Start after the Friday funnel read (strategy.md puts the funnel first).

## Not in this version

- Hundreds of thousands of posts, Instagram Reels, YouTube Shorts.
- Showing trending videos inside the app.
- Reading back our users' own ad results and "make more like the winner" (needs Meta ad insights; a separate scope).
