# Todo: vendor errors and admin alerts

Scoped 2026-10-06 after a character step stopped on Replicate's `ModelRateLimitError: Service is currently unavailable
due to high demand` (Nano Banana, capacity, not content) and a survey of how every vendor's errors are handled. Not
built yet. **Decide** marks what the owner still chooses.

## Where we are

| Vendor | Content refused | Busy / temporary | Our vendor account out of credit |
|---|---|---|---|
| Claude, build agent (`AnthropicGateway.php`) | `stop_reason: refusal` ignored | 429/529 retried 2× (4 s, 8 s) | not detected; a 400 "credit balance is too low" is tagged NOT_SENT and retried 3 more times as "busy" (`anthropic-gateway.mjs:23`, `composition-agent.mjs` `whenModelFree`) |
| Claude, planner and checks (`AnthropicPlanner`, `Clarifier`, `AttachmentRoles`, `ReferenceAnalyzer`, `ReferenceStudy`, `FinalLook`) | n/a | connection retries only; the checks skip quietly | not detected |
| Replicate, generated shots (`PlanMediaExecutor::pollJob`) | E005/E006 caught; the next-best engine is offered | failed jobs restarted once | not detected ("did not finish: 402") |
| Replicate, images (`drawAll`) | not detected | retried once after 20 s (2026-10-06) | not detected |
| Gemini voice, ElevenLabs music, Luma (via Replicate) | not detected | no retry | not detected |
| OpenAI images, older tools (`DalleImageAdapter`) | detected | detected | detected, `report()` to Sentry: the only one |
| Stock (Pexels, Pixabay) | n/a | silent fallback | silent fallback |

Alerts today: Sentry gets some `report()` calls but has no alert rules; `MODERATION_DIGEST_EMAIL` gets the 09:00
abuse digest and the credit-ledger alert, nothing about vendors; the Slack log channel exists but is off.

## Todo

- [ ] **One classifier** for every vendor error, on the API and in the worker: `content_refused` (E005/E006, Claude
  `refusal`, OpenAI policy), `busy` (429, 503, 529, overloaded, "high demand", rate limits), `vendor_credit` (Replicate
  402 / "Insufficient credit", Anthropic "credit balance is too low", OpenAI `insufficient_quota`, Google billing),
  `vendor_config` (401, a bad key), `other`.
- [ ] **Busy:** retried with waits wherever it happens (planner, checks, voice, music, images, shots); if still
  failing, the user sees "the model is busy · Retry" and nothing is charged.
- [ ] **Content refused:** never retried silently; the user is told what was declined and offered a way round
  (rephrase, another engine, skip). Recorded in `moderation_events` for every vendor, not only the older tools.
- [ ] **Vendor credit or config (our problem):** the user sees "temporarily unavailable on our side; the team has been
  notified; nothing was charged"; the step pauses and is retryable; new steps needing that vendor are held for a short
  while so failures do not pile up.
- [ ] **Fix:** a Claude 400 is no longer retried as busy.
- [ ] **Admin alerts:** an email to every super admin plus kolakachi@gmail.com (`ADMIN_ALERT_EMAILS`, a list) the first
  time a vendor reports `vendor_credit` or `vendor_config`: vendor, kind, exact message, runs hit, where to fix it
  (for example replicate.com/account/billing). At most one per vendor and kind per hour; a short note when it
  recovers. The same to the Slack webhook when it is on.
- [ ] **Daily:** a vendor-failure summary in the 09:00 digest (counts by vendor and kind, refusals, retries).
- [ ] **Tests:** each vendor's real error bodies (fixtures) classify correctly; an out-of-credit error sends one alert,
  pauses the step and holds new ones; a busy error retries.

**Decide:** does the marketer partner also get the alerts? Slack as well as email?

Not possible: warning before a vendor runs dry. Replicate and Anthropic expose no balance API, so the first failure
is the signal; alerting at once and holding new work keeps the damage to one step.
