# Steps and asking: plan

Drafted 2026-10-06. Nothing here is built. Covers S9 to S11 on the pending sheet. **Decide** marks the owner's choices.

## Where we are

- **Steps are durable.** Plan → Character → Storyboard → Video, each approved once. A plan freezes on approval; a
  built plan is read-only. Retry continues a failed step without re-approval.
- **After the video, the only way to change it is to chat.** The change is planned as an edit of the whole video. There
  is no "go back to the character" or "redraw the storyboard" from the result.
- **A build that needs to ask ends.** The builder's `needs_input` stops the run with status `needs_input`; the
  question shows only under "Earlier attempts" ("Your input is needed"), and nothing resumes from the answer. Two runs
  ended this way in the last week.
- **Questions come only before a new video.** The clarifier asks up to 3 questions for a new creative brief. A
  change request ("make it better", "different vibe") is planned straight away on a guess, and the planner's own
  unknowns become guesses, not questions.

## S9. Change… from the video

- **On the result:** a **Change…** action with the steps that exist for this video: Plan (copy, voice, look),
  Character, Storyboard. Picking one reopens that step's drawer, the same one used before the video.
- **Reopening makes a new plan version:**
  - it copies the frozen plan, with approvals kept for the steps before the one reopened and cleared for it and
    everything after it;
  - the old plan stays viewable, not actionable.
- **Only later steps are redone.** Images and clips that do not change are reused by content (as today), so redrawing
  one character re-buys only that character, its panels and the shots that use them.
- **Price shown before anything runs:** each step's estimate, as now; nothing is charged for reopening.
- **Done when:** after a video, changing the character redraws only that character and the panels and shots that show
  it, keeps everything else, and builds a new version, with no re-approval of unchanged steps.

## S10. Pause and ask inside a step

- **The builder's question pauses the step.** It is kept: its draft, its files, its calls so far.
  - The question appears in the chat as a question card, with the builder's suggested answers when it gave any.
  - The answer resumes the same step from where it paused, under the approval already given.
  - Credits already reserved are kept for it, and no new quote is made.
- **Limits:**
  - at most 2 questions per step;
  - a third need, or no answer within 24 hours, delivers the best checked draft with the open question noted (or
    fails cleanly if there is no draft);
  - questions never count as calls without progress (the no-progress guard is paused while waiting).
- **When the builder may ask:** only for what the plan and the files cannot settle, such as a missing fact, a
  contradiction in the brief or a file it cannot use. Never about taste: it decides those itself.
- **"Known gap" lines** in summaries become questions when they block the brief, and notes when they do not.
- **Done when:** a build that needs a missing price asks, the user answers, and the same run finishes without
  re-approval, charged once.

## S11. Ask, don't assume

- **Change requests are checked like new briefs.** Before planning a change, the clarifier decides whether it is clear
  enough to act on ("make it better", "different vibe", "fix it" are not), and if not asks one question: which part,
  or what kind of change, with 2 to 4 suggested answers.
- **The planner's unknowns become questions.** The plan output gets an `unknowns` list (what it would otherwise guess:
  a price, a claim, which product). Unknowns that change the plan are asked one at a time before the plan is shown;
  small ones stay as visible assumptions on the plan card ("Assumed: free trial, no price shown").
- **Limits:** questions before a plan stay at 3 in total; a skip always plans on the best guess and says so.
- **Done when:** "make it better" on a finished video asks which part or what kind of change before planning, and a
  brief without a price never shows an invented one.

## Order and cost

1. **S11** first: a prompt and clarifier change, no new surface beyond the existing question cards. Small.
2. **S10**: worker and run state (pause, resume under the same approval), the question card, tests for the limits.
   Medium.
3. **S9**: plan versions from a step, the Change… action and drawers, reuse proof on a real run. Medium to large.

None of these adds model cost beyond one cheap question check on change requests (Sonnet, about 1 credit).

## Decide

- **S9:** should Change… also offer "Start over from this brief" (a new plan, keeping the files)?
- **S10:** wait 24 hours for an answer, or deliver the draft sooner?
- **S11:** show small assumptions on the plan card (recommended), or keep them in the drawer?
