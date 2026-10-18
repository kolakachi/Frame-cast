# Cookbook: prices that feel fair (PRICE-FEEL)

Owner, 2026-10-18: prices stay as they are; fix how they *feel*. A buyer said "I do not want to waste any credits until I
know what it can be used for" (cmo-evidence §5, ws60). Two changes, written so an agent can build them later without
rediscovery. Not built yet; the CMO plan comes first. Before building: read this, confirm the line numbers still match,
and show the owner the exact wording.

Background numbers (margin check, 2026-10-18, prod, 45 days): planning charged about 6,100 credits (about $85 at direct
prices) for about $5 of model cost, the highest markup we have, and it is charged even when nothing is built.

## 1. Lead with the likely price, everywhere

**Already done in the app (Create):** `web/src/views/CreateView.vue` `priceText` / `ceilingText` (~line 1520) show
"about 200–400 cr · never more than 1,600" from the quote's `estimate_range`, `estimate` and `ceiling`. Keep it.

**To do:**

| Where | Today | Change |
|---|---|---|
| Connector, Create (`approve_video_plan` / `weave_approve`; `mcp/server.js`, `WeaveController::quote` ~line 147) | Returns `credits_max` and `credits_estimate`; the description says "credits_max is the most it can cost" | Return `estimate_range` too. Description: "Tell the user the likely price first (credits_estimate or the range), then that it will never cost more than credits_max." Same rule in `APP_INSTRUCTIONS` and `INSTRUCTIONS`. Regenerate the submission record (`mcp/scripts/submission.mjs`) |
| Connector, Classic (`estimate_video`) | min/max range | Description: show "about <midpoint> credits, never more than <max>" |
| UGC pitch (UGC director, app and `ugc_brief`) | Shows the credits | Check it shows the likely figure first and the hold second; same wording as Create |
| Can't-afford message (`WeaveController` `can_afford`, ConversationService creditAvailability) | Compares the balance with `credits_max` | Keep the check (the hold needs the max), but say why: "This video likely costs about 250 credits; to start it, 1,600 must be free while it runs. Unused credits come back." Offer `if_short` as today |

Tests: the connector contract test (`mcp/tests/editor-contract.mjs`) for the new field and description text; an API test
that the quote carries `estimate_range`.

## 2. Planning counts toward the build

**Today:** each plan charges `planning_charge.charge` credits when it is saved (`api/app/Services/Create/PlanService.php`
~line 286: `CreditService::deduct(..., 'create_planning', ['conversation_id', 'plan_id'])`), recorded on the plan as
`planning_charge.charged`; the free first ad waives it. The app's bill lists it as "Planning: N cr (half price)"
(`CreateView.vue` `spendRows`).

**Change:** when a build of the conversation finishes and is charged, give back the planning charges of that
conversation's plans that have not been counted yet, up to what the build charged.

1. Where builds settle (the run's final charge; look for where `composition_runs` gets its spent credits and the
   quote's hold is released), after the charge succeeds:
   - sum `planning_charge.charged` of the conversation's plans not yet marked `credited`;
   - refund `min(that sum, the build's charge)` with operation `refund:planning_toward_build` and metadata
     `{conversation_id, run_id, plan_ids}`;
   - mark those plans `planning_charge.credited = <amount>` in `plan_json`.
   - Idempotent per run (a retry or replay must not refund twice): key the refund on the run id.
2. A build that fails or is refunded credits nothing. Plans made after the last build wait for the next build.
3. Bill (`spendRows`): "Planning: 50 cr, counted toward the build" once credited; before: "Planning: 50 cr (comes off
   the build if you make it)". Drop "half price".
4. Quote text (app and connector): "Planning (50 cr) comes off this price."
5. Connector and instructions: "Planning uses a few credits, which come off the price if the video is built."
6. Reports: `refund:` operations are already excluded from usage by God Mode and the growth scoreboard; check
   Customer economics treats it as a refund.

Tests: plan then build, planning refunded once; two plans then one build; a build cheaper than the planning (refund
capped); a failed build (no refund); a replayed settlement (no double refund); the free first ad (nothing to refund).

**Cost to us:** at today's volume, at most about $85 per 45 days, and only for videos that get built.

## Order and size

Each part is about half a day with tests; part 2 first (it is the one buyers feel). Push with the usual checks (prod idle,
deploy the worker first if the worker changed, verify the running containers).
