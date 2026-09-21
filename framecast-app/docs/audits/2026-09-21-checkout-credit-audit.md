# Checkout confirmation and credit audit — 2026-09-21

## Changes in this patch

- Checkout creates a durable, workspace-scoped attempt ID and passes it to Kelviq metadata and the success URL. The confirmation endpoint trusts only the signed webhook path, not URL parameters or a pre-existing paid tier.
- One-time checkout is confirmed after its credit handler commits. Monthly checkout additionally waits for the subscription handler to activate the selected tier. Both webhook arrival orders work.
- The browser waits approximately one minute, then offers a status check and support rather than suggesting another payment. Sign-in resumes a pending confirmation.
- Webhook event claims, checkout-object deduplication, grants, and confirmation receipt commit together. Processing failures return HTTP 500 so Kelviq can retry. Unresolvable paid one-time purchases now fail instead of silently returning success.
- CreditService::grant updates the balance and writes the ledger atomically under a workspace lock. Ledger failures now roll back credits.
- UGC pass issuance writes one grant:ugc_pass entry instead of two monetary ledger entries for one balance movement. Existing ledger records are not modified.
- Welcome emails wait until the enclosing transaction commits.

## Outstanding findings

1. **Monthly renewal is not payment-backed.** ResetMonthlyCreditsJob refills any active, due workspace using the local clock; it does not verify an invoice was paid. Failed/missing subscription updates could permit an unpaid refill. The job also uses a workspace snapshot without locking/rechecking the due date; a concurrent invoice webhook or spend can race it. Replace this with provider reconciliation for Kelviq subscriptions; retain explicit rules for manually managed enterprise allocations.
2. **Monthly changes have incomplete ledger coverage.** applySubscription, handleRenewal and CreditService::resetMonthly directly set credits_monthly without recording the delta in credit_ledger. Summing the ledger cannot reconstruct those balances. Add locked, atomic monthly-allocation entries for each period and tier transition, including expiration/reset deltas.
3. **Lifetime upgrades do not actually preserve separately purchased credits.** applyLifetimePurchase subtracts min(previous plan's original allocation, current credits_topup). Once the original allocation has been spent and separately topped up, this can remove paid top-ups. It needs allocation provenance or an explicitly additive purchase policy. Buying the same lifetime plan again can also be accepted by checkout but skipped by the handler's same-tier guard. Restrict unsupported repeat purchases and define upgrade pricing/credit semantics before changing historical balances.
4. **Subscription lifecycle still needs payment/state reconciliation.** applySubscription trusts lifecycle payload status (default active), changes tier and refills on tier changes, without invoice verification or stale-event ordering. A pending or out-of-order event can affect entitlements. The confirmation screen protects its own navigation; it is not a replacement for entitlement enforcement throughout the app.
5. **Historical deduplication is limited.** New checkout-object receipts prevent future duplicate event IDs for one checkout; old processed events were stored without checkout-object identity. Replaying old purchases under newly generated event IDs requires reconciliation first.
6. **Email delivery is not a durable outbox.** Queue failures are reported/rescued. A successful purchase does not guarantee a confirmation email was queued; a retry/outbox mechanism remains useful.

## Read-only production snapshot

Ran aggregate queries in a read-only transaction. No production records changed.

- Last 14 days: 6 Kelviq deliveries marked processed; 0 logged Kelviq processing errors.
- No negative monthly/top-up workspace balances.
- No active monthly workspace past its renewal timestamp at the time checked.
- No grant:ugc_pass, grant:ugc_pass_credits or grant:ugc_pass_duplicate rows.

These checks do not establish that every provider order has a matching grant. Full reconciliation needs the provider's paid order/invoice records joined to workspace/ledger records. No historical credit repair or webhook replay was performed.

## Rollout and validation

Apply the additive billing_checkout_attempts migration before serving the new API/frontend. Restart workers with the new code. Existing hosted checkout URLs retain their old success URL; only newly created sessions use the confirmation route.

Automated checks cover grant rollback and retry, duplicate checkout objects with different event IDs, one-pass/one-ledger-entry accounting, workspace-scoped confirmation, delayed subscription activation, and frontend polling/sign-in recovery. Tests use isolated SQLite databases and mocked provider requests; no real payments or emails are sent. PostgreSQL concurrency and live provider metadata round-trip still require deployment verification.
