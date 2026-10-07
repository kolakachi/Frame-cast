# Todo: vendor errors and admin alerts

Scoped and built 2026-10-06 after a character step stopped on Replicate's `ModelRateLimitError: Service is currently unavailable
due to high demand` (Nano Banana, capacity, not content) and a survey of how every vendor's errors are handled. 

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

## Todo (built 2026-10-06, local, uncommitted)

- [x] **One classifier** (`App\Services\Vendors\VendorError`): `content_refused`, `busy`, `vendor_credit`,
  `vendor_config`, `other`, read from each vendor's status and error text; fixtures of real bodies from Anthropic,
  Replicate, OpenAI and Google in the tests. The worker reads the kind the app tags (`[vendor:kind]`).
- [x] **Busy:** the build model is retried by the gateway (4 s, 8 s) and then waited out by the worker (20 s, 60 s,
  120 s); the planner retries twice (5 s, 15 s); voice, music, images and other bought media once after 20 s; a
  generated shot that failed busy says so. Still busy: "<service> is busy right now. Nothing was charged for it;
  press Retry in a minute."
- [x] **Content refused:** never retried; a moderation event for every vendor; the user is told it was declined and to
  change the wording or image (a declined shot still offers the next engine).
- [x] **Vendor credit or config:** "temporarily unavailable on our side; the team has been notified; nothing was
  charged"; the step stops retryable with nothing held for reconciliation; new plans and builds that need that
  vendor are refused for 10 minutes (`CREATE_VENDOR_HOLD_MINUTES`), or until a call to it works.
- [x] **Fix:** a Claude 400 is no longer retried as busy (the worker's `VENDOR_REFUSED`).
- [x] **Admin alerts:** `VendorAlertMail` to every super admin plus `ADMIN_ALERT_EMAILS` (default
  kolakachi@gmail.com): vendor, kind, the message, runs hit in the last hour, where to fix it. At most one per vendor
  and kind an hour; a "recovered" note on the first call that works. Slack too when the Slack bot token and channel
  are set.
- [x] **Daily:** `create:vendor-digest` at 09:05: counts by vendor and kind, runs hit, the latest message; quiet days
  send nothing. (A separate mail from the 09:00 moderation digest.)
- [x] **Tests:** classifier fixtures; our model account out of credit → one alert, the hold, the recovery note and the
  digest line; the worker does not retry a classified refusal.
- [x] **Checks and reference reads (2026-10-07, V8):** the reference study and transcription record vendor failures; the
  final look, `ReferenceAnalyzer` and `PageReferenceService` now do too, and alert when it is our account. A final
  look that could not run says why in the delivery check ("the checking model is unavailable on our side") instead
  of a bare "could not be looked at".

**Decided by default (2026-10-06):** the marketer partner is not on the list (add any address to `ADMIN_ALERT_EMAILS`); Slack gets the alerts only when its bot token and channel are set.

Not possible: warning before a vendor runs dry. Replicate and Anthropic expose no balance API, so the first failure
is the signal; alerting at once and holding new work keeps the damage to one step.
