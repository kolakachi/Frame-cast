<script setup>
// Assistant (canvas 3E3t2SZP8XhvMxAB1NikRj, "Assistant"; classic Cruise Control chat). The chat, its action cards
// (price, Apply / Skip / Undo / Retry, Apply all), the theme brief and the defaults all run through the shared core:
// the same conversation and the same spending rules as the classic editor.
import { ref } from "vue";
import UiSelect from "../../components/UiSelect.vue";
import { useCore } from "./core";

const core = useCore();
const {
  router, mePayload, isAdmin,
  cruiseScopeValue, cruiseScopeOptions, cruiseMessages, cruiseResetConversation, cruiseChatScrollRef, cruiseResolving,
  cruiseTotalCost, cruiseActionTitle, cruiseStageLabel, cruiseCanUndo, cruiseUndoAction, cruiseFriendlyError, cruiseRetryAction,
  cruiseApplying, cruiseSkipAction, cruiseApplyAction, cruiseUserBalance, cruiseProposedCount, cruiseCanApplyAll, cruiseSkipAll,
  cruiseApplyAll, CRUISE_QUICK_PROMPTS, cruiseUseQuickPrompt, cruiseInputText, cruiseSubmitIntent,
  cruiseToggleBrief, cruiseBriefOpen, cruiseBriefLocked, cruiseBrief, cruiseBriefBusy, cruiseRefreshBrief, cruiseSaveBrief,
  cruiseTogglePrefs, cruisePrefsOpen, cruisePrefs, setCruisePref,
  CRUISE_IMAGE_MODEL_OPTIONS, CRUISE_ANIMATION_TIER_OPTIONS, CRUISE_VISUAL_SOURCE_OPTIONS,
  cruiseAutoApply, setCruiseAutoApply,
} = core;

// "New chat" asks first: the conversation goes, the video's changes stay.
const confirmNew = ref(false);
function newChat() {
  cruiseResetConversation();
  confirmNew.value = false;
}
// Saving the brief closes it once the save is done.
async function saveBrief() {
  await cruiseSaveBrief();
  cruiseBriefOpen.value = false;
}
const initial = () => (mePayload.value?.name?.[0] ?? "Y").toUpperCase();
</script>

<template>
  <div class="nx-ph nx-row">
    <div>
      <h2>Assistant</h2>
      <p>You'll see the cost before anything runs</p>
    </div>
    <template v-if="cruiseMessages.length">
      <button v-if="!confirmNew" class="nx-mini" type="button" title="Start a new chat; your video's changes stay" @click="confirmNew = true">New chat</button>
      <span v-else class="nx-row nx-confirm">
        <button class="nx-link" type="button" @click="confirmNew = false">Keep</button>
        <button class="nx-mini" type="button" @click="newChat">Clear chat</button>
      </span>
    </template>
  </div>

  <div class="nx-row nx-scope">
    <span class="nx-label">Working on</span>
    <UiSelect v-model="cruiseScopeValue" :options="cruiseScopeOptions" label="What the assistant works on" align="left" />
  </div>

  <div ref="cruiseChatScrollRef" class="nx-chat">
    <div v-if="!cruiseMessages.length" class="nx-empty">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z" /><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z" /></svg>
      <b>What should I change?</b>
      <span>Describe it in plain words. I'll work out which scene, voice or track it touches.</span>
    </div>

    <div v-for="m in cruiseMessages" :key="m.id" :class="['nx-msg', `is-${m.role}`]">
      <span class="nx-msg-who" aria-hidden="true">{{ m.role === "user" ? initial() : "✦" }}</span>
      <div class="nx-msg-body">
        <p class="nx-msg-text">{{ m.text }}</p>

        <div v-if="(m.actions || []).length" class="nx-acts">
          <p v-if="m.actions.length > 1" class="nx-hint">Plan: {{ m.actions.length }} changes · about {{ cruiseTotalCost(m) }} cr in all</p>
          <div v-for="(card, idx) in m.actions" :key="idx" class="nx-act">
            <div class="nx-row">
              <b>{{ m.actions.length > 1 ? `${idx + 1}. ` : "" }}{{ cruiseActionTitle(card.tool) }}</b>
              <span class="nx-num">about {{ card.estimated_cost }} cr</span>
            </div>
            <ul v-if="(card.diff_lines || []).length" class="nx-act-diff"><li v-for="(line, i) in card.diff_lines" :key="i">{{ line }}</li></ul>
            <p v-if="card.notice && card.status === 'proposed'" class="nx-warn-text">{{ card.notice }}</p>

            <div v-if="card.status === 'running'" class="nx-act-state">
              <ul v-if="(card.expected_stages || []).length > 1" class="nx-act-steps">
                <li v-for="stage in card.expected_stages" :key="stage" :class="(card.completed_stages || []).includes(stage) ? 'is-done' : ''">
                  <span v-if="(card.completed_stages || []).includes(stage)" aria-hidden="true">✓</span><span v-else class="nx-spin nx-spin-soft" aria-hidden="true"></span>
                  {{ cruiseStageLabel(stage, (card.completed_stages || []).includes(stage)) }}
                </li>
              </ul>
              <span v-else class="nx-row nx-row-start"><span class="nx-spin nx-spin-soft" aria-hidden="true"></span>{{ card.progress_text || "Working…" }}</span>
            </div>
            <div v-else-if="card.status === 'applied'" class="nx-row nx-act-state is-good">
              <span>✓ {{ card.progress_text || "Applied" }} · spent {{ card.credits || card.estimated_cost }} cr</span>
              <button v-if="cruiseCanUndo(card)" class="nx-mini" type="button" :disabled="card._undoing" @click="cruiseUndoAction(m, idx)">{{ card._undoing ? "Undoing…" : "Undo" }}</button>
            </div>
            <p v-else-if="card.status === 'undone'" class="nx-hint">Undone</p>
            <!-- We stopped watching; the server may not have stopped. No Retry here: it would pay for the same output twice. -->
            <p v-else-if="card.status === 'unknown'" class="nx-warn-text">{{ card.error }}</p>
            <div v-else-if="card.status === 'failed'" class="nx-act-state">
              <div class="nx-row">
                <span class="nx-err">{{ cruiseFriendlyError(card.error) }}</span>
                <button class="nx-mini" type="button" :disabled="card._retrying || cruiseApplying" @click="cruiseRetryAction(m, idx)">{{ card._retrying ? "Retrying…" : "Retry" }}</button>
              </div>
              <p v-if="isAdmin && card.error" class="nx-hint nx-mono">{{ card.error }}</p>
            </div>
            <p v-else-if="card.status === 'skipped'" class="nx-hint">Skipped</p>
            <div v-else-if="cruiseUserBalance < (card.estimated_cost ?? 0)" class="nx-row">
              <span class="nx-warn-text">Needs {{ card.estimated_cost }} cr · you have {{ cruiseUserBalance }}</span>
              <button class="nx-btn nx-primary" type="button" @click="router.push({ name: 'settings', query: { section: 'billing' } })">Top up</button>
            </div>
            <div v-else class="nx-row nx-end">
              <button class="nx-link" type="button" @click="cruiseSkipAction(m, idx)">Skip</button>
              <button class="nx-btn nx-primary" type="button" :disabled="!!cruiseApplying" @click="cruiseApplyAction(m, idx)">{{ cruiseApplying === `${m.id}:${idx}` ? "Applying…" : "Apply" }}</button>
            </div>
          </div>
          <div v-if="cruiseProposedCount(m) > 1 && cruiseCanApplyAll(m)" class="nx-row nx-end">
            <button class="nx-link" type="button" :disabled="!!cruiseApplying" @click="cruiseSkipAll(m)">Skip all</button>
            <button class="nx-btn nx-primary" type="button" :disabled="!!cruiseApplying" @click="cruiseApplyAll(m)">Apply all ({{ cruiseProposedCount(m) }})</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="cruiseResolving" class="nx-msg is-ai">
      <span class="nx-msg-who" aria-hidden="true">✦</span>
      <div class="nx-msg-body"><p class="nx-msg-text nx-dim" role="status">Thinking…</p></div>
    </div>
  </div>

  <div v-if="!cruiseMessages.length" class="nx-chips">
    <button v-for="qp in CRUISE_QUICK_PROMPTS.slice(0, 4)" :key="qp" type="button" class="nx-chip" @click="cruiseUseQuickPrompt(qp)">{{ qp.charAt(0).toUpperCase() + qp.slice(1) }}</button>
  </div>

  <div class="nx-pbox nx-ask">
    <label class="nx-label" for="nx-ask">Message the assistant</label>
    <textarea id="nx-ask" v-model="cruiseInputText" class="nx-pbox-input" rows="2" placeholder="Describe what you want to change…"
      @keydown.meta.enter.prevent="cruiseSubmitIntent" @keydown.ctrl.enter.prevent="cruiseSubmitIntent"></textarea>
    <div class="nx-pbox-bar">
      <button type="button" :class="['nx-pdrop', cruiseBriefOpen ? 'on' : '']" :aria-expanded="cruiseBriefOpen" title="Theme and creative brief" @click="cruiseToggleBrief">
        Brief<span v-if="cruiseBriefLocked" class="nx-dot" title="Locked: you edited it"></span>
      </button>
      <button type="button" :class="['nx-pdrop', cruisePrefsOpen ? 'on' : '']" :aria-expanded="cruisePrefsOpen" title="Assistant defaults" @click="cruiseTogglePrefs">Defaults</button>
      <span class="nx-grow"></span>
      <button class="nx-pdrop nx-send" type="button" :disabled="!cruiseInputText.trim() || cruiseResolving" title="Send (⌘⏎)" @click="cruiseSubmitIntent">Send</button>
    </div>
  </div>

  <!-- Theme and brief: keeps new scenes and regenerations on theme unless a message says otherwise. -->
  <div v-if="cruiseBriefOpen && cruiseBrief" class="nx-modal-bg" @click.self="cruiseBriefOpen = false">
  <div class="nx-modal nx-modal-form" role="dialog" aria-modal="true" aria-labelledby="nx-brief-title" @keydown.esc="cruiseBriefOpen = false">
    <div class="nx-row"><h2 id="nx-brief-title">Theme and brief{{ cruiseBriefLocked ? " · locked" : "" }}</h2><button class="nx-ib" type="button" aria-label="Close" @click="cruiseBriefOpen = false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button></div>
    <p>Keeps new scenes and regenerations on theme unless you say otherwise in a message. Saving locks it, so it stops following the scenes.</p>
    <label class="nx-label" for="nx-b-theme">Theme</label><input id="nx-b-theme" v-model="cruiseBrief.theme" class="nx-input" maxlength="200" placeholder="hand-drawn doodle explainer" />
    <label class="nx-label" for="nx-b-topic">Topic</label><input id="nx-b-topic" v-model="cruiseBrief.topic" class="nx-input" maxlength="200" placeholder="what the video is about" />
    <label class="nx-label" for="nx-b-style">Visual style</label><input id="nx-b-style" v-model="cruiseBrief.visual_style" class="nx-input" maxlength="200" placeholder="doodle, marker on whiteboard" />
    <label class="nx-label" for="nx-b-tone">Tone</label><input id="nx-b-tone" v-model="cruiseBrief.tone" class="nx-input" maxlength="200" placeholder="friendly, educational" />
    <label class="nx-label" for="nx-b-subj">Recurring subject</label><input id="nx-b-subj" v-model="cruiseBrief.recurring_subject" class="nx-input" maxlength="200" placeholder="a hand drawing on a whiteboard" />
    <div class="nx-row nx-end">
      <button class="nx-btn" type="button" :disabled="cruiseBriefBusy" @click="cruiseRefreshBrief">Refresh from scenes</button>
      <button class="nx-btn nx-primary" type="button" :disabled="cruiseBriefBusy" @click="saveBrief">Save and lock</button>
    </div>
  </div>
  </div>

  <!-- Defaults the assistant leans on when a message doesn't name a model or source. -->
  <div v-if="cruisePrefsOpen" class="nx-modal-bg" @click.self="cruisePrefsOpen = false">
  <div class="nx-modal nx-modal-form" role="dialog" aria-modal="true" aria-labelledby="nx-prefs-title" @keydown.esc="cruisePrefsOpen = false">
    <div class="nx-row"><h2 id="nx-prefs-title">Assistant defaults</h2><button class="nx-ib" type="button" aria-label="Close" @click="cruisePrefsOpen = false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button></div>
    <p>What the assistant uses when a message doesn't say.</p>
    <span class="nx-label">Image model</span>
    <UiSelect :model-value="cruisePrefs.image_model || ''" :options="CRUISE_IMAGE_MODEL_OPTIONS" label="Image model" @update:model-value="(v) => setCruisePref('image_model', v || null)" />
    <span class="nx-label">Video model</span>
    <UiSelect :model-value="cruisePrefs.animation_tier || ''" :options="CRUISE_ANIMATION_TIER_OPTIONS" label="Video model" @update:model-value="(v) => setCruisePref('animation_tier', v || null)" />
    <span class="nx-label">Pictures from</span>
    <UiSelect :model-value="cruisePrefs.visual_source || 'auto'" :options="CRUISE_VISUAL_SOURCE_OPTIONS" label="Pictures from" drop="up" @update:model-value="(v) => setCruisePref('visual_source', v)" />
    <p class="nx-hint">These are hints: "use Kling for this one" in a message still wins.</p>
    <div class="nx-row nx-end"><button class="nx-btn nx-primary" type="button" @click="cruisePrefsOpen = false">Done</button></div>
  </div>
  </div>

  <div class="nx-row">
    <label class="nx-switch" :title="cruiseAutoApply ? 'Cheap, reversible changes apply at once; paid or structural ones still ask.' : 'Every change waits for Apply.'">
      <input type="checkbox" :checked="cruiseAutoApply" @change="setCruiseAutoApply($event.target.checked)" />
      <span>Apply small, undoable changes at once</span>
    </label>
    <span class="nx-num">{{ cruiseUserBalance }} cr</span>
  </div>
</template>
