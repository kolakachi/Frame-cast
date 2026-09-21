# Subscription renewal audit — 2026-09-21

Scope: current checkout/renewal code after commit 281cf92. No application behavior, production balances, or provider configuration was changed. The executable reproductions use an isolated SQLite database with frozen time and fake email. They deliberately assert the defects to establish evidence; they are outside the regular regression suite and should be converted into desired-behavior tests during remediation.

Original reproductions were subsequently replaced by the fixed-behavior suite: `cd framecast-app/api && php artisan test --filter=SubscriptionRenewalTest`.

## Confirmed findings

### P1: Monthly credits can renew without payment
`app/Jobs/ResetMonthlyCreditsJob.php:25` selects overdue active workspaces, and `app/Services/CreditService.php:916` refills them based solely on the clock. No invoice or provider payment check occurs. A failed renewal can still receive a fresh allocation if local status remains active. The webhook dispatcher has no explicit failed-invoice handler; it handles only invoice.paid and subscription lifecycle events.

Reproduction: due Creator workspace with 100 monthly credits receives a new allocation without any payment event. Separately purchased top-ups remain unchanged in this test.

Fix direction: Kelviq allocations must follow unique paid invoice/period receipts. The scheduled job should reconcile paid invoices, not mint credits from timestamps. Explicitly separate manual/enterprise allocation policies.

### P1: Pending subscriptions receive paid entitlements and credits
`app/Services/KelviqService.php:246–262` writes the paid tier and refills on tier changes regardless of status. A pending subscription writes Creator and grants its monthly allocation. `CreditService::limitFor` reads only plan_tier, so the pending status does not close feature gates. Missing status also defaults to active.

Fix direction: separate requested plan from effective paid plan, and activate only from verified paid state. Define any supported trial/grace policy explicitly.

### P1: Cancellation never expires paid capabilities
`markCancelled` only writes plan_status=cancelled. `CreditService::limitFor` ignores status and expiry; authentication checks workspace status, which Kelviq cancellation leaves active. No cancellation-expiry job was found in the audited schedule. Stopping monthly refills does not stop paid feature access.

Reproduction: a cancelled Creator workspace whose renewal date is in the past still has UGC and social publishing permissions.

Fix direction: store the actual paid-through timestamp, preserve access until that point for scheduled cancellation, then enforce effective entitlements centrally. Keep paid top-up balances; don't blanket-suspend billing/account access.

### P1: Old subscriptions can mutate current subscriptions
`handleRenewal` and `markCancelled` resolve a workspace but never compare the event's subscription ID to kelviq_subscription_id. Old-subscription invoice events refill the current plan; old cancellation events mark the replacement cancelled. Event-ID deduplication cannot stop distinct, stale events.

Fix direction: match subscription identity and apply ordered lifecycle transitions. Record unmatched/stale events for reconciliation rather than silently mutating current access.

### P1: Missing invoice period permits repeat allocations
`periodEnd` falls back to now()->addMonth(). Processing the same invoice with distinct event IDs a minute apart produces different period ends and defeats the renewal-date guard. Checkout-object deduplication added previously does not cover invoice identities.

Reproduction: one invoice refills, its credits are spent, and the same invoice refills again one minute later through the renewal handler. This models separate event deliveries reaching the handler, not replay of an identical event ID.

Fix direction: permanent unique invoice/paid-period receipts. Fetch authoritative period data or leave an event retryable when it is absent; never invent the period from current time.

### P1: Stale scheduled resets can restore spent credits
`CreditService::resetMonthly` uses the caller's workspace snapshot without locking/reloading or checking whether the period was already advanced. A scheduler snapshot racing a webhook or another reset can overwrite newer balances.

Reproduction: keep a due snapshot, refill, deduct 50 credits, then reset with the old snapshot. The 50 spent credits reappear. SQLite test uses deterministic interleaving; it is not a live PostgreSQL concurrency test.

Fix direction: lock and reload the workspace; make allocation receipt, balance delta, ledger write, and period advancement one transaction. The scheduler and webhook must call the same allocation service.

### P2: Monthly allocations have no ledger entries
Both activation and renewal/reset directly update credits_monthly. The tests confirm a credit increase with zero ledger rows. A ledger sum cannot reconcile those balances, even though one-time grant writes were made atomic in the previous patch.

Fix direction: record allocation, rollover, and expiry/reset deltas, tied to their invoice/period receipt. Preserve credits_topup separately.

## Implementation order

1. Establish immutable invoice/period receipts and authoritative paid-through state; make one atomic allocation service with ledger entries.
2. Move Kelviq scheduled refills to payment reconciliation; retain explicitly configured non-Kelviq allocations.
3. Enforce pending, past-due/grace, scheduled cancellation, and expired access consistently across feature gates.
4. Add subscription identity and stale-event guards, then test reversed webhook order, duplicate invoice events, concurrent spend/refill, payment recovery, and replacement subscriptions.
5. Reconcile provider invoices against existing accounts before any historical balance repair. This audit proves code paths, not that particular customers exploited them or lost money.

Seven reproduction tests passed with 13 assertions, meaning all seven defects were reproduced. No fixes were deployed by this audit.


## Remediation implemented locally

All seven findings above now have regression coverage in `api/tests/Feature/SubscriptionRenewalTest.php`; the original defect-characterization tests have been replaced. Run `php artisan test --filter=SubscriptionRenewalTest` from `framecast-app/api`.

- `SubscriptionRenewal` reads the current Kelviq subscription and paginated paid invoices. It uses the provider period, plan, subscription identity, and invoice creation time to associate the payment with the current cycle. A five-minute boundary allowance covers invoice creation immediately before the provider starts the period. Missing/invalid periods or provider failures throw retryable errors rather than inventing dates. Unmatched invoices do not grant credits.
- Both invoice events (paid and failed) and subscription lifecycle events use this service. Authoritative provider state, subscription creation ordering, and per-subscription state versions prevent old events from changing replacement subscriptions.
- Invoice IDs have permanent unique receipts. A workspace lock protects receipts, monthly balance changes, paid-through updates, and ledger entries. Same-period adjustments grant only the increase above the highest allocation already given that period. Top-ups are untouched.
- The hourly job queues independent, retryable per-workspace reconciliations. Only the explicitly configured manual tier (Enterprise by default) can receive a clock-based refill, with a lock, due-date recheck, and ledger entry.
- Workspace capabilities use effective subscription access, including agency client workspaces. Paid access and spendable monthly credits stop at the paid-through/end date. No additional unpaid grace period is introduced. Top-ups remain available; stored rollover is retained and becomes available again following a verified paid renewal. One-time lifetime/AppSumo access is not overwritten by an old subscription.
- Checkout confirmation continues to wait for the webhook receipt and effective active tier. New subscription identities are retained for reconciliation even if invoice creation is delayed. Welcome email and referral conversion are retained.

### Deployment and limits

Apply `2026_09_22_000000_add_verified_subscription_allocations` before restarting API/workers. It adds two nullable workspace timestamps and a receipt table; it does not recalculate or remove existing balances. Existing paid-through dates act as a baseline so reconciling an already-granted legacy cycle does not grant it again.

Ensure the Kelviq endpoint subscribes to `invoice.paid`, `invoice.payment_failed`, `subscription.created`, `subscription.updated`, `subscription.plan_changed`, and `subscription.cancelled`. Invoice/subscription reads must be allowed by the server API key. Provider outages are retryable; no payment is charged by reconciliation.

This implementation reconciles the current authoritative cycle; historical invoice/balance repair remains a separate read-and-reconcile operation. Tests are isolated SQLite and mocked provider API tests, including deterministic spend/refill interleaving, not live PostgreSQL concurrency or a real payment. No production mutation was performed in this remediation.

Provider schema references: https://docs.kelviq.com/guides/webhooks , https://docs.kelviq.com/api-reference/invoices/list-invoices , https://docs.kelviq.com/api-reference/subscriptions/retrieve-a-subscription .

Final local validation: 188 tests passed with 590 assertions across subscription renewals, lifetime/pass billing, registration, affiliates, welcome mail, plan gates, and agency/client permissions. No commit, push, or deployment was performed in this remediation turn.
