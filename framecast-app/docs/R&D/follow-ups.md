# Engineering follow-ups found during R&D work

- **Settle a never-sent provider call at zero** (next). `create:reconcile-attempt` refuses calls without a provider
  receipt, even when the call was never dispatched (no send time, no prediction id): the Dan rebuild's reused take was
  one. Such a call is provably free.
- **Release a closed run's held credits** (next). `OperationAccounting::reserved()` sums `reserved_credits` whatever the
  operation's status, and closing a stuck run did not zero it, so a run that ever got stuck held credits until cleared
  by hand (four local runs, 2026-10-09).
- **Document pages and the 20-file limit** (idea). A conversation holds 20 files; a document's pages count toward it, so
  a reference video plus 10 pages leaves 9. Pages could count separately.
- **Tier limits for documents** (idea, with pricing). `PLAN_LIMITS` has `pdf_page_limit` per tier (Starter 20, Creator
  50, Agency unlimited) for Classic; documents in Weave read up to 50 pages for everyone today.
