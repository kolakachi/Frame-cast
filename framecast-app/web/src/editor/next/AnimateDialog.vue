<script setup>
// Animate a still: model, quality, length, motion, optionally many scenes at once. Every number is the server's.
import { useCore } from "./core";

const {
  animateModalOpen, closeAnimateModal, spokespersonOutdated, animateTier, ANIMATE_TIER_ORDER, ANIMATE_TIER_META,
  ANIMATE_TIER_DURATIONS, ANIMATE_TIER_COSTS_5S, creditCosts, tierSlow, lipsyncEngines, lipsyncEngine,
  animateQualityOptions, animateQuality, animateDurations, animateDuration, activeSceneHasVoice,
  ANIMATE_PROMPT_SUGGESTIONS, animateMotionPrompt,
  bulkAnimateMode, toggleBulkAnimate, bulkAnimateFirstLoad, bulkAnimatePreview, bulkAnimateSourceId, bulkAnimateReplacedCount,
  bulkAnimateSelectableCount, bulkAnimateRows, toggleBulkScene, bulkAnimateSelection, bulkAnimateSelectAll, bulkAnimateLoading,
  needsSpokespersonConsent, spokespersonConsent, animateError, animateCost, animateSubmitting, submitAnimate, submitBulkAnimate,
} = useCore();

// A tier's cheapest short clip, as the server prices it.
function tierPrice(key) {
  const tier = creditCosts.value?.video_tiers?.[key];
  const served = Object.values(tier?.qualities || {});
  return served.length ? Math.min(...served) : (tier?.credits ?? ANIMATE_TIER_COSTS_5S[key]);
}
</script>

<template>
  <div v-if="animateModalOpen" class="nx-modal-bg" @click.self="closeAnimateModal">
    <div class="nx-modal nx-modal-wide" role="dialog" aria-modal="true" aria-labelledby="nx-anim-title">
      <div class="nx-row">
        <h2 id="nx-anim-title">Animate</h2>
        <button class="nx-ib" type="button" aria-label="Close" @click="closeAnimateModal"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
      </div>

      <p v-if="spokespersonOutdated" class="nx-callout">The voice changed, so this talking clip is synced to the old one. Pick Spokesperson to sync the lips again.</p>

      <span class="nx-label">Model</span>
      <div class="nx-list nx-list-scroll">
        <button v-for="key in ANIMATE_TIER_ORDER" :key="key" type="button" :class="['nx-list-row', animateTier === key ? 'on' : '']" @click="animateTier = key">
          <span class="nx-list-main">
            <b>{{ ANIMATE_TIER_META[key].name }}</b>
            <span>{{ ANIMATE_TIER_META[key].sub }} · {{ ANIMATE_TIER_META[key].quality }} · {{ ANIMATE_TIER_META[key].render }}<template v-if="key !== 'spokesperson'"> · {{ ANIMATE_TIER_DURATIONS[key].join("/") }}s</template><template v-if="tierSlow(key)"> · <span class="nx-warn-text">slow now, about {{ Math.round(creditCosts.video_tiers[key].recent_minutes) }} min</span></template></span>
          </span>
          <span class="nx-num">{{ key === "spokesperson" ? "per second" : `from ${tierPrice(key)} cr` }}</span>
        </button>
      </div>

      <template v-if="animateTier === 'spokesperson'">
        <template v-if="lipsyncEngines.length > 1">
          <span class="nx-label">Lip-sync model</span>
          <div class="nx-seg nx-seg-full" role="group" aria-label="Lip-sync model">
            <button v-for="e in lipsyncEngines" :key="e.key" type="button" :class="lipsyncEngine === e.key ? 'on' : ''" @click="lipsyncEngine = e.key">{{ e.label }} · {{ e.credits_per_second }} cr/s</button>
          </div>
        </template>
        <p class="nx-hint">The person lip-syncs to this scene's voice, so the clip is as long as the voice.<b v-if="!activeSceneHasVoice"> Record the voice first.</b></p>
      </template>
      <template v-else>
        <template v-if="animateQualityOptions.length">
          <span class="nx-label">Quality</span>
          <div class="nx-seg nx-seg-full" role="group" aria-label="Quality">
            <button v-for="q in animateQualityOptions" :key="q.value" type="button" :class="animateQuality === q.value ? 'on' : ''" @click="animateQuality = q.value">{{ q.label }} · {{ q.cr }} cr</button>
          </div>
        </template>
        <span class="nx-label">Length</span>
        <div class="nx-seg nx-seg-full" role="group" aria-label="Length">
          <button v-for="d in animateDurations" :key="d" type="button" :class="animateDuration === d ? 'on' : ''" @click="animateDuration = d">{{ d }}s</button>
        </div>
        <p v-if="animateDurations.some((d) => d >= 10)" class="nx-hint">The 10 second clip costs twice as much.</p>
      </template>

      <label class="nx-label" for="nx-anim-motion">Motion <span class="nx-dim">(optional)</span></label>
      <div class="nx-chips">
        <button v-for="s in ANIMATE_PROMPT_SUGGESTIONS" :key="s.label" type="button" :class="['nx-chip', animateMotionPrompt === s.text ? 'on' : '']" @click="animateMotionPrompt = s.text">{{ s.label }}</button>
      </div>
      <textarea id="nx-anim-motion" v-model="animateMotionPrompt" class="nx-input" rows="2" maxlength="1000" placeholder="slow push-in, hair moving, drifting fog"></textarea>

      <label class="nx-switch nx-switch-row">
        <span>Animate more scenes <span class="nx-dim">with these settings</span></span>
        <input type="checkbox" :checked="bulkAnimateMode" @change="toggleBulkAnimate" />
      </label>
      <div v-if="bulkAnimateMode" class="nx-quote">
        <p v-if="bulkAnimateFirstLoad" class="nx-hint">Working out the price…</p>
        <template v-else-if="bulkAnimatePreview">
          <p class="nx-hint">
            Every scene animates its own picture{{ animateTier === "spokesperson" ? ", lip-synced to its own voice" : "" }}.
            {{ animateMotionPrompt.trim() ? "The motion above applies to all of them." : "With no motion given, each moves naturally." }}
          </p>
          <p v-if="bulkAnimateSourceId" class="nx-callout">
            <b>Every ticked scene will use this scene's picture.</b>
            Each keeps its own script and voice.
            <template v-if="bulkAnimateReplacedCount"> {{ bulkAnimateReplacedCount }} scene{{ bulkAnimateReplacedCount === 1 ? "" : "s" }} will lose {{ bulkAnimateReplacedCount === 1 ? "its" : "their" }} own picture; you can switch back afterwards.</template>
          </p>
          <p v-if="!bulkAnimateSelectableCount" class="nx-hint">None of these scenes can be animated; the reasons are below.</p>
          <div class="nx-list">
            <button v-for="row in bulkAnimateRows" :key="row.scene_id" type="button" :class="['nx-list-row', row.included ? 'on' : '']" :disabled="row.blocked" @click="toggleBulkScene(row)">
              <span class="nx-tick" aria-hidden="true">{{ row.included ? "✓" : row.blocked ? "–" : "" }}</span>
              <span class="nx-list-main"><b>Scene {{ row.order }}</b>
                <span v-if="row.blocked">{{ row.reason }}</span>
                <span v-else-if="row.replaces_own">picture replaced{{ row.shares_with ? ", shares a clip, free" : "" }}</span>
                <span v-else-if="row.shares_with">shares scene {{ row.shares_with }}'s clip, free</span>
              </span>
              <span v-if="!row.blocked && !row.shares_with" class="nx-num">{{ row.cost }} cr</span>
            </button>
          </div>
          <button v-if="bulkAnimateSelection !== null" class="nx-link" type="button" @click="bulkAnimateSelectAll">Tick all {{ bulkAnimateSelectableCount }} that can animate</button>
          <template v-if="bulkAnimatePreview.eligible_count">
            <div class="nx-row"><span>{{ bulkAnimatePreview.eligible_count }} of {{ bulkAnimateRows.length }} scenes</span>
              <b v-if="bulkAnimateLoading" class="nx-dim">updating…</b>
              <b v-else :class="{ 'nx-short': !bulkAnimatePreview.affordable }">{{ bulkAnimatePreview.total_cost }} credits</b>
            </div>
            <p v-if="bulkAnimatePreview.saved > 0" class="nx-hint">Scenes on the same picture share one clip: {{ bulkAnimatePreview.render_count }} render{{ bulkAnimatePreview.render_count === 1 ? "" : "s" }}, {{ bulkAnimatePreview.saved }} credits saved.</p>
            <div class="nx-row nx-dim"><span>Your balance</span><span>{{ bulkAnimatePreview.balance }} credits</span></div>
            <p v-if="!bulkAnimatePreview.affordable" class="nx-err">You're {{ bulkAnimatePreview.shortage }} credits short. Untick a few scenes, or top up.</p>
          </template>
        </template>
      </div>

      <label v-if="needsSpokespersonConsent || (bulkAnimateMode && animateTier === 'spokesperson')" class="nx-consent">
        <input v-model="spokespersonConsent" type="checkbox" />
        <span>I have the rights and consent to make this person appear to speak. <a href="/synthetic-media" target="_blank" rel="noopener">Synthetic media policy</a></span>
      </label>

      <p v-if="animateError" class="nx-err">{{ animateError }}</p>

      <div class="nx-row">
        <span class="nx-total"><b>{{ bulkAnimateMode && bulkAnimatePreview ? bulkAnimatePreview.total_cost : animateCost }} credits</b>
          <span class="nx-dim">{{ bulkAnimateMode ? "for every ticked scene" : animateTier === "spokesperson" ? "for this scene's voice length" : `${animateDuration}s clip` }}</span></span>
        <span class="nx-row">
          <button class="nx-link" type="button" :disabled="animateSubmitting" @click="closeAnimateModal">Cancel</button>
          <button v-if="bulkAnimateMode" class="nx-btn nx-primary" type="button"
            :disabled="animateSubmitting || bulkAnimateLoading || !bulkAnimatePreview?.eligible_count || !bulkAnimatePreview?.affordable || (animateTier === 'spokesperson' && !spokespersonConsent)"
            @click="submitBulkAnimate">{{ animateSubmitting ? "Starting…" : `Animate ${bulkAnimatePreview?.eligible_count ?? ""} scenes` }}</button>
          <button v-else class="nx-btn nx-primary" type="button" :disabled="animateSubmitting || (needsSpokespersonConsent && !spokespersonConsent)" @click="submitAnimate">
            {{ animateSubmitting ? "Starting…" : "Animate" }}
          </button>
        </span>
      </div>
    </div>
  </div>
</template>
