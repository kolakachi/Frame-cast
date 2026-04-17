# Framecast — Deferred Ideas & Gaps

Items that have been identified as valuable but are not in the current build plan. Review before planning each new phase. Do not implement without scoping first.

---

## Product Gaps

### 1. Bulk Generation from Topic List
**What:** Operator pastes 20 topics (or uploads a CSV), gets 20 videos queued and processed in batch — same niche preset, same settings, different topics.
**Why it matters:** The CSV source type is already specced but buried. This is the highest-leverage workflow feature for operators running at volume. The difference between 1 video/day and 20 videos/day is this feature.
**When to scope:** After Niche Wizard ships. Batch creation only makes sense once presets are reliable.

---

### 2. Auto-Export / Publish Bridge
**What:** After export, the operator still downloads manually and uploads to YouTube/TikTok. Options in order of complexity:
- Copy direct download link (trivial)
- Zapier/Make webhook on export complete (low effort, high leverage)
- Native YouTube/TikTok upload (significant — OAuth, API quotas, platform rules)
**Why it matters:** The last mile of the workflow is still manual. Even a webhook removes the operator from having to check back.
**When to scope:** After core export is stable. Start with webhook, evaluate native upload for a later tier.

---

### 3. Template Stamping Across Projects
**What:** Once an operator has a working niche setup (brand kit + channel + caption preset + music + motion style), they should be able to apply that exact configuration to any new project in one click — beyond what brand kit currently covers.
**Why it matters:** Brand kit covers colors and fonts. It doesn't cover motion defaults, music mood, caption animation style, or hook scoring preferences. Operators rebuilding this per project is waste.
**When to scope:** After Phase 6 ships. Requires all the new fields (motion, music, image style) to be stable first.

---

### 4. Performance Feedback Loop
**What:** Hook scoring tells operators which hook AI predicts will perform. There's no mechanism to feed real performance data (views, CTR, watch time) back into future scoring or hook generation.
**Why it matters:** This is the ceiling of the product. Without it, AI scoring is static and doesn't improve per operator or per niche over time.
**When to scope:** Post-MVP. Requires operators to be publishing consistently and willing to connect analytics. Consider YouTube Data API or manual input as a first pass.

---

## Notes

- These gaps do not block any current phase.
- None of these require architectural changes — the data model and provider adapter pattern already support them.
- Bulk generation (#1) is the highest priority of this list when the time comes.
