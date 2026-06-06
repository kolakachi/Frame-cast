<script setup>
// Daily Streak modal — shows the 7-day calendar grid, today's prize, and
// the streak count. Opens from the dashboard chip OR auto-opens once per
// day on first dashboard visit (parent component owns the trigger).

import { computed, ref, onMounted, watch } from 'vue'
import api from '../services/api'

const props = defineProps({
  visible: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'claimed'])

const state = ref(null)
const loading = ref(false)
const claiming = ref(false)
const error = ref('')
const lastGranted = ref(null) // cr awarded on the most recent claim

const days = computed(() => Array.from({ length: state.value?.streak_length ?? 7 }, (_, i) => i + 1))

async function load() {
  loading.value = true
  try {
    const res = await api.get('/daily-streak')
    state.value = res?.data?.data ?? null
  } catch (e) {
    error.value = 'Could not load streak.'
  } finally {
    loading.value = false
  }
}

async function claim() {
  if (!state.value?.can_claim || claiming.value) return
  claiming.value = true
  error.value = ''
  try {
    const res = await api.post('/daily-streak/claim')
    state.value = res?.data?.data ?? state.value
    lastGranted.value = res?.data?.data?.granted ?? 0
    emit('claimed', { granted: lastGranted.value, day: state.value.current_day })
  } catch (e) {
    if (e?.response?.status === 409) {
      // Already claimed elsewhere — refresh state.
      await load()
    } else {
      error.value = e?.response?.data?.error?.message ?? 'Claim failed.'
    }
  } finally {
    claiming.value = false
  }
}

watch(() => props.visible, (open) => { if (open) load() })
onMounted(() => { if (props.visible) load() })

function close() { emit('close') }
</script>

<template>
  <div v-if="visible" class="ds-overlay" @click.self="close">
    <div class="ds-modal" role="dialog">
      <div class="ds-header">
        <div>
          <div class="ds-title">🔥 Daily Streak</div>
          <div class="ds-sub">{{ state ? `Day ${state.current_day} of ${state.streak_length}` : 'Loading…' }}</div>
        </div>
        <button class="ds-close" type="button" @click="close">✕</button>
      </div>

      <div v-if="!state && loading" class="ds-state">Loading…</div>

      <template v-else-if="state">
        <!-- Calendar grid -->
        <div class="ds-grid">
          <div v-for="d in days" :key="d"
            :class="['ds-day',
                     d < state.current_day ? 'past' : '',
                     d === state.current_day ? 'today' : '',
                     d === state.current_day && state.claimed_today ? 'claimed' : '',
                     d > state.current_day ? 'upcoming' : '']">
            <div class="ds-day-num">Day {{ d }}</div>
            <div class="ds-day-prize">{{ state.prize_ladder[d] }} cr</div>
            <div class="ds-day-status">
              <span v-if="d < state.current_day">✓</span>
              <span v-else-if="d === state.current_day && state.claimed_today">✓</span>
              <span v-else-if="d === state.current_day">today</span>
              <span v-else>·</span>
            </div>
          </div>
        </div>

        <!-- Last claim feedback -->
        <div v-if="lastGranted" class="ds-grant-banner">
          +{{ lastGranted }} credits added to your balance 🎉
        </div>

        <!-- Action button -->
        <button v-if="state.can_claim" class="ds-claim-btn" type="button" :disabled="claiming" @click="claim">
          {{ claiming ? 'Claiming…' : `🎁 Claim Day ${state.current_day} · ${state.today_prize} credits` }}
        </button>
        <div v-else class="ds-already">
          ✓ You've claimed today. Come back tomorrow to keep your streak going.
        </div>

        <div v-if="error" class="ds-error">{{ error }}</div>

        <div class="ds-foot">
          Miss a day and the streak resets to Day 1.
          Streak count: <strong>{{ state.streak_count }}</strong>.
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ds-overlay { position: fixed; inset: 0; z-index: 220; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; padding: 16px; backdrop-filter: blur(6px); }
.ds-modal { width: min(520px, calc(100vw - 32px)); background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 14px; padding: 24px; box-shadow: 0 30px 70px rgba(0,0,0,0.5); }
.ds-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; }
.ds-title { font-size: 18px; font-weight: 700; color: var(--color-text-primary); }
.ds-sub { font-size: 12px; color: var(--color-text-muted); margin-top: 3px; }
.ds-close { background: none; border: none; color: var(--color-text-muted); font-size: 18px; cursor: pointer; padding: 4px 8px; border-radius: 6px; }
.ds-close:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }

.ds-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; margin-bottom: 18px; }
.ds-day { padding: 10px 4px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); text-align: center; transition: all 0.2s; }
.ds-day-num { font-size: 10px; color: var(--color-text-muted); font-family: "Space Mono", monospace; letter-spacing: 0.3px; }
.ds-day-prize { font-size: 13px; font-weight: 700; color: var(--color-text-primary); margin-top: 4px; }
.ds-day-status { font-size: 10px; color: var(--color-text-muted); margin-top: 4px; min-height: 13px; }
.ds-day.past { border-color: rgba(52,211,153,0.3); background: rgba(52,211,153,0.04); }
.ds-day.past .ds-day-status { color: #34d399; font-weight: 700; }
.ds-day.today { border-color: var(--color-accent); background: rgba(255,107,53,0.08); transform: scale(1.04); box-shadow: 0 0 0 2px rgba(255,107,53,0.18); }
.ds-day.today .ds-day-prize { color: var(--color-accent); }
.ds-day.today.claimed { border-color: rgba(52,211,153,0.4); background: rgba(52,211,153,0.06); box-shadow: none; transform: none; }
.ds-day.today.claimed .ds-day-prize { color: #34d399; }
.ds-day.upcoming { opacity: 0.55; }

.ds-grant-banner { padding: 10px 14px; border-radius: 8px; background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.3); color: #34d399; font-size: 13px; font-weight: 600; text-align: center; margin-bottom: 14px; }
.ds-claim-btn { display: block; width: 100%; padding: 14px; border-radius: 10px; border: none; background: var(--color-accent); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; transition: transform 0.18s; }
.ds-claim-btn:hover:not(:disabled) { transform: translateY(-1px); }
.ds-claim-btn:disabled { opacity: 0.6; cursor: default; }
.ds-already { padding: 14px; border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); font-size: 12.5px; text-align: center; }

.ds-error { margin-top: 10px; padding: 8px 12px; border-radius: 6px; background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: #f87171; font-size: 12px; }
.ds-foot { margin-top: 18px; font-size: 11px; color: var(--color-text-muted); text-align: center; line-height: 1.55; }

.ds-state { padding: 40px; text-align: center; color: var(--color-text-muted); font-size: 13px; }
</style>
