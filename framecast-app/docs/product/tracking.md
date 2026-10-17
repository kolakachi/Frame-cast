# Tracking: links and the weekly scoreboard

For the two-week plan (`cmo-plan.md`, experiment 1). Every link we post carries tags, so each sign-up records where it
came from (`workspaces.acquisition_channel` and `acquisition_json`, read by `App\Services\Analytics\Acquisition`).
Without the cookie consent, wyvstudio.com still hands the first visit to the app in the sign-up link, so tags survive
"Essential only".

## The link convention

Base: `https://wyvstudio.com/` (or a template page once TPL-PAGES exists). Add:

| Where the link goes | utm_source | utm_medium | utm_content | Shows in the scoreboard as |
|---|---|---|---|---|
| Layla's posts and bio | `tiktok`, `instagram`, `youtube`, `x` | `social` | the post (e.g. `layla-003`) | social |
| Community answers | `reddit`, `discord`, `facebook` | `community` | the community (e.g. `r-smallbusiness`) | community |
| OUTREACH-5 messages | `outreach` | `outreach` | the batch and brand (e.g. `b1-shopname`) | outreach |
| Listings | `g2`, `saashub`, `alternativeto`, `futuretools`, `producthunt` | `listing` | — | listing |
| Emails we send (lists, not one-to-one) | the list (e.g. `aweber`) | `email` | the email | email |
| Boosted posts (paid) | `instagram`, `facebook`, `tiktok` | `paid_social` | the ad | paid_social |

Always add `utm_campaign=cmo-oct` for the two weeks from 2026-10-19. Example:

`https://wyvstudio.com/?utm_source=reddit&utm_medium=community&utm_campaign=cmo-oct&utm_content=r-smallbusiness`

**A link to one template's free ad (TPL-LINK).** When a post, answer or outreach message shows a template ad, link
straight to that template: sign-up is free and lands on it (someone already signed in goes straight there). Put the
template id in `utm_content` too, so the scoreboard shows which templates bring sign-ups:

`https://app.wyvstudio.com/register?free=1&template=txy6gnhc6k&utm_source=tiktok&utm_medium=social&utm_campaign=cmo-oct&utm_content=txy6gnhc6k`

The ten free templates (2026-10-17): tm4y46f991 new drop, tqfeux4cjm sale countdown, tchfvlldst three reasons,
taftdexczo problem then fix, t43ec0ghtj review card, tsalojjvj9 app on a phone, trn2ci5jiw local shop, txy6gnhc6k
food, tbdvabrrxr whiteboard explainer, lbaf5605f "Describe it." promo.

Arrivals from a listing site with no tags (g2.com, alternativeto.net, producthunt.com and others) are counted as a
listing by their referring site.

## The scoreboard

`php artisan growth:scoreboard` (on prod: `docker compose exec -T api php artisan growth:scoreboard`), read-only. One
row a week: sign-ups, how many made a video of their own on their first day, purchases, second purchases, refunds, and
sign-ups by channel and by utm_source; and the goal (teams that bought again, to date). `--since=2026-10-19` sets the
first day; `--json` prints JSON.

Staff, the owner's workspace and the auto-made sample project ("Wyvstudio Ad") are left out.

**Baseline (2026-08-17 to 2026-10-17):** 48 sign-ups; 14 (29%) made a video of their own on their first day, none of
the 3 in the latest week; 1 team has bought again. Refunds are dated by when the licence was deactivated, so treat that
column as approximate.
