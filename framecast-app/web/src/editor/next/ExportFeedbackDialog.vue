<script setup>
// After an export, once per person ever (owner, 2026-11-17: classic's ask, in the new editor too). The state and the
// moment it opens live in the shared core; closing counts as answered, as in classic.
import { useCore } from "./core";

const core = useCore();
const {
  exportFbOpen, exportFbRating, exportFbOptions, exportFbComment, exportFbSending, exportFbDone, exportFbChips,
  exportFbToggle, exportFbSubmit,
} = core;
function rate(n) {
  exportFbRating.value = n;
  exportFbOptions.value = [];
}
</script>

<template>
  <div v-if="exportFbOpen" class="nx-modal-bg" @click.self="exportFbSubmit(true)">
    <div class="nx-modal nx-fb" role="dialog" aria-modal="true" aria-labelledby="nx-fb-title" @keydown.esc="exportFbSubmit(true)">
      <template v-if="exportFbDone">
        <h2 id="nx-fb-title">Thank you</h2>
        <p>This goes straight to the founders.</p>
      </template>
      <template v-else>
        <div class="nx-row">
          <h2 id="nx-fb-title">Your video is ready. How was making it?</h2>
          <button class="nx-ib" type="button" aria-label="Close" @click="exportFbSubmit(true)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
        </div>
        <div class="nx-fb-stars" role="radiogroup" aria-label="Rating">
          <button v-for="n in 5" :key="n" type="button" role="radio" :aria-checked="exportFbRating === n" :aria-label="`${n} star${n > 1 ? 's' : ''}`"
            :class="['nx-fb-star', n <= exportFbRating ? 'on' : '']" @click="rate(n)">★</button>
        </div>
        <template v-if="exportFbRating > 0">
          <span class="nx-label">{{ exportFbRating >= 4 ? "What stood out?" : "What fell short?" }} <span class="nx-muted">Pick any</span></span>
          <div class="nx-chips">
            <button v-for="c in exportFbChips" :key="c" type="button" :class="['nx-chip', exportFbOptions.includes(c) ? 'on' : '']" @click="exportFbToggle(c)">{{ c }}</button>
          </div>
          <textarea v-model="exportFbComment" class="nx-input" rows="3" maxlength="2000" aria-label="Anything else" placeholder="Anything else? (optional)"></textarea>
          <button class="nx-btn nx-primary nx-full" type="button" :disabled="exportFbSending" @click="exportFbSubmit(false)">{{ exportFbSending ? "Sending…" : "Send feedback" }}</button>
        </template>
        <p v-else class="nx-fb-note">We only ask once.</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.nx-fb { max-width: 460px; gap: 14px; }
.nx-fb h2 { line-height: 1.35; }
.nx-fb-stars { display: flex; justify-content: center; gap: 6px; }
.nx-fb-star { width: 44px; height: 44px; border: 0; border-radius: 10px; background: transparent; font-size: 30px; line-height: 1; color: #3a3a42; cursor: pointer; transition: color 0.12s, transform 0.12s; }
.nx-fb-star:hover { transform: scale(1.08); }
.nx-fb-star.on { color: var(--accent); }
.nx-fb-note { text-align: center; }
.nx-muted { color: var(--muted); font-weight: 400; margin-left: 4px; }
</style>
