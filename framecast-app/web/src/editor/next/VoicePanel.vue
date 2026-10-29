<script setup>
// Voice: the selected scene's narration. Copying it to every scene is one button.
import { computed } from "vue";
import { cr, useCore, usePrices } from "./core";

const core = useCore();
const {
  activeScene, activeSceneIndex, scenes, sceneScriptDraft,
  voiceTab, activeVoiceName, activeVoiceSpeed, activeSceneAudioUrl, isAudioLoading, isAudioPlaying, toggleAudioPlayback,
  openVoiceModal, VOICE_SPEED_OPTIONS, voiceSpeedDraft, VOICE_STABILITY_OPTIONS, voiceStabilityDraft,
  sceneVoiceVolume, scheduleSceneVoiceVolumeSave, activeVoiceIsGemini, voiceDirectionDraft,
  activeVoiceOutdated, regenerateVoice, voiceRegeneratePending, voiceRegenerateError, activeSceneVoiceError,
  applyVoiceToAllScenes, applyVoiceAllState, applyVoiceAllError,
  bulkVoiceOpen, openBulkVoice, bulkVoiceLoading, bulkVoiceError, bulkVoicePreview, submitBulkVoice, bulkVoiceSubmitting,
  voiceProfileSaveOpen, voiceProfileSaveName, voiceProfileSaving, saveVoiceProfile, voiceSaveCopy, voiceSaveState,
  voiceUploadStatus, handleVoiceFileUpload, voiceRecording, voiceRecordSeconds, startVoiceRecording, stopVoiceRecording,
  voicePreviewRef, voicePreviewUrl, voicePreviewName, onVoicePreviewTime, voicePreviewPlaying, toggleVoicePreviewPlay,
  onVoicePreviewSeek, voicePreviewCurrent, voicePreviewDuration, fmtVoicePreviewTime, confirmUploadFromPreview, cancelVoiceUpload,
  voiceUploadAsset, applyVoiceUpload, voiceUploadError,
  customAudioAssets, customAudioLoading, loadCustomAudioAssets, customAudioSearch, customAudioAssetsFiltered, assignCustomAudio,
} = core;
const { voiceCost } = usePrices(core);

// Stability, said the way it sounds.
const DELIVERY = { low: "Expressive", medium: "Balanced", high: "Steady" };
const CUES = ["laughs", "whispering", "excited", "sighs"];
// A cue goes at the end of this scene's script; the voice reads it as direction, not words.
function addCue(cue) {
  const text = (sceneScriptDraft.value || "").trimEnd();
  sceneScriptDraft.value = `${text}${text ? " " : ""}[${cue}]`;
}
const ownAudio = computed(() => !!activeScene.value?.voice_settings?.custom_audio);
</script>

<template>
  <div class="nx-ph nx-row">
    <div>
      <h2>Voice</h2>
      <p>Scene {{ activeSceneIndex + 1 }}{{ ownAudio ? " · your recording" : "" }}</p>
    </div>
    <span class="nx-tag">This scene</span>
  </div>

  <div class="nx-seg nx-seg-full" role="tablist" aria-label="Voice source">
    <button type="button" role="tab" :aria-selected="voiceTab === 'default'" :class="voiceTab === 'default' ? 'on' : ''" @click="voiceTab = 'default'">AI voice</button>
    <button type="button" role="tab" :aria-selected="voiceTab === 'custom'" :class="voiceTab === 'custom' ? 'on' : ''" @click="voiceTab = 'custom'">Your recording</button>
  </div>

  <template v-if="voiceTab === 'default'">
    <div class="nx-voice">
      <button class="nx-voice-play" type="button" :disabled="!activeSceneAudioUrl" :aria-label="isAudioPlaying ? 'Pause' : 'Play this scene\'s voice'" @click="toggleAudioPlayback">
        <span v-if="isAudioLoading" class="nx-spin"></span>
        <svg v-else-if="isAudioPlaying" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
      </button>
      <span class="nx-voice-text"><b>{{ activeVoiceName }}</b><span>{{ (activeScene?.voice_settings?.language || "en").toUpperCase() }} · {{ activeVoiceSpeed }}x</span></span>
      <button class="nx-mini" type="button" @click="openVoiceModal">Change</button>
    </div>

    <div v-if="activeVoiceOutdated" class="nx-callout">
      <span>The script changed since this was recorded.</span>
      <button class="nx-mini" type="button" :disabled="voiceRegeneratePending" @click="regenerateVoice">{{ voiceRegeneratePending ? "Recording…" : `Re-record${cr(voiceCost)}` }}</button>
    </div>
    <p v-if="voiceRegenerateError || activeSceneVoiceError" class="nx-err">{{ voiceRegenerateError || activeSceneVoiceError }}</p>

    <section class="nx-sec">
      <span class="nx-label">Speed</span>
      <div class="nx-seg nx-seg-full" role="group" aria-label="Speed">
        <button v-for="o in VOICE_SPEED_OPTIONS" :key="o.value" type="button" :class="Number(voiceSpeedDraft) === Number(o.value) ? 'on' : ''" @click="voiceSpeedDraft = o.value">{{ o.label.replace("x", "×") }}</button>
      </div>
    </section>
    <section class="nx-sec">
      <span class="nx-label">Delivery</span>
      <div class="nx-seg nx-seg-full" role="group" aria-label="Delivery">
        <button v-for="o in VOICE_STABILITY_OPTIONS" :key="o.value" type="button" :class="voiceStabilityDraft === o.value ? 'on' : ''" @click="voiceStabilityDraft = o.value">{{ DELIVERY[o.value] || o.label }}</button>
      </div>
    </section>
    <section class="nx-sec">
      <div class="nx-row"><label class="nx-label" for="nx-vol">Volume</label><span class="nx-num">{{ sceneVoiceVolume }}%</span></div>
      <input id="nx-vol" v-model.number="sceneVoiceVolume" type="range" class="nx-range" min="0" max="200" @input="scheduleSceneVoiceVolumeSave" />
    </section>
    <section v-if="activeVoiceIsGemini" class="nx-sec">
      <label class="nx-label" for="nx-direction">Direction <span class="nx-dim">(optional)</span></label>
      <textarea id="nx-direction" v-model="voiceDirectionDraft" class="nx-input" rows="2" placeholder="Warm and upbeat, like welcoming a friend"></textarea>
      <span class="nx-label">Add a cue to the script</span>
      <div class="nx-chips">
        <button v-for="c in CUES" :key="c" type="button" class="nx-chip" @click="addCue(c)">{{ c }}</button>
      </div>
    </section>
    <p v-if="voiceSaveCopy()" :class="voiceSaveState === 'error' ? 'nx-err' : 'nx-hint'">{{ voiceSaveCopy() }}</p>

    <section v-if="scenes.length > 1" class="nx-sec nx-sec-top">
      <button class="nx-btn nx-outline nx-full" type="button" :disabled="applyVoiceAllState === 'saving'" @click="applyVoiceToAllScenes">
        {{ applyVoiceAllState === "saving" ? "Copying…" : `Use this voice for all ${scenes.length} scenes` }}
      </button>
      <p :class="applyVoiceAllState === 'error' ? 'nx-err' : 'nx-hint'">
        {{ applyVoiceAllState === "saved" ? "Copied. Each scene re-records when you finish the video." : applyVoiceAllState === "error" ? applyVoiceAllError : `Copies the voice, speed, delivery${activeVoiceIsGemini ? " and direction" : ""}.` }}
      </p>
      <button class="nx-btn nx-outline nx-full" type="button" @click="bulkVoiceOpen ? (bulkVoiceOpen = false) : openBulkVoice()">
        {{ bulkVoiceOpen ? "Hide" : "Re-record all scenes" }}
      </button>
      <div v-if="bulkVoiceOpen" class="nx-quote">
        <p v-if="bulkVoiceLoading" class="nx-hint">Working out the price…</p>
        <p v-else-if="bulkVoiceError" class="nx-err">{{ bulkVoiceError }}</p>
        <template v-else-if="bulkVoicePreview">
          <p v-if="!bulkVoicePreview.eligible_count" class="nx-hint">No scene has a script to record yet.</p>
          <template v-else>
            <div class="nx-row"><span>{{ bulkVoicePreview.eligible_count }} scene{{ bulkVoicePreview.eligible_count === 1 ? "" : "s" }}</span><b :class="{ 'nx-short': !bulkVoicePreview.affordable }">{{ bulkVoicePreview.total_cost }} credits</b></div>
            <div class="nx-row nx-dim"><span>Your balance</span><span>{{ bulkVoicePreview.balance }} credits</span></div>
            <p class="nx-hint">Each scene re-records its own script with its own voice.</p>
            <p v-if="!bulkVoicePreview.affordable" class="nx-err">You're {{ bulkVoicePreview.shortage }} credits short.</p>
            <ul v-if="bulkVoicePreview.skipped.length" class="nx-skips">
              <li v-for="sk in bulkVoicePreview.skipped" :key="sk.scene_id">Scene {{ sk.order }} skipped: {{ sk.reason }}</li>
            </ul>
            <button class="nx-btn nx-primary nx-full" type="button" :disabled="bulkVoiceSubmitting || !bulkVoicePreview.affordable" @click="submitBulkVoice">
              {{ bulkVoiceSubmitting ? "Starting…" : `Re-record ${bulkVoicePreview.eligible_count} scenes · ${bulkVoicePreview.total_cost} cr` }}
            </button>
          </template>
        </template>
      </div>
    </section>

    <div class="nx-row">
      <template v-if="!voiceProfileSaveOpen">
        <button class="nx-link" type="button" @click="voiceProfileSaveOpen = true">+ Save as voice profile</button>
      </template>
      <template v-else>
        <input v-model="voiceProfileSaveName" class="nx-input nx-input-sm" placeholder="Profile name" maxlength="80" aria-label="Profile name"
          @keydown.enter="saveVoiceProfile" @keydown.escape="voiceProfileSaveOpen = false; voiceProfileSaveName = ''" />
        <button class="nx-mini" type="button" :disabled="voiceProfileSaving || !voiceProfileSaveName.trim()" @click="saveVoiceProfile">{{ voiceProfileSaving ? "Saving…" : "Save" }}</button>
        <button class="nx-link" type="button" @click="voiceProfileSaveOpen = false; voiceProfileSaveName = ''">Cancel</button>
      </template>
    </div>
  </template>

  <!-- Your own recording: upload or record, then it is transcribed for the captions. -->
  <template v-else>
    <p class="nx-hint">Upload an audio file or record yourself. The captions are written from it.</p>

    <div v-if="voiceUploadStatus === 'idle'" class="nx-actions">
      <label class="nx-btn">
        <input type="file" accept="audio/*" hidden @change="handleVoiceFileUpload" />Upload
      </label>
      <button type="button" :class="['nx-btn', voiceRecording ? 'nx-rec' : '']" @click="voiceRecording ? stopVoiceRecording() : startVoiceRecording()">
        <template v-if="voiceRecording">Stop · {{ Math.floor(voiceRecordSeconds / 60) }}:{{ String(voiceRecordSeconds % 60).padStart(2, "0") }}</template>
        <template v-else>Record</template>
      </button>
    </div>

    <div v-else-if="voiceUploadStatus === 'previewing'" class="nx-quote">
      <span class="nx-label">{{ voicePreviewName }}</span>
      <audio ref="voicePreviewRef" :src="voicePreviewUrl" preload="metadata" @timeupdate="onVoicePreviewTime" @loadedmetadata="onVoicePreviewTime"
        @play="voicePreviewPlaying = true" @pause="voicePreviewPlaying = false" @ended="voicePreviewPlaying = false"></audio>
      <div class="nx-player">
        <button class="nx-voice-play" type="button" :aria-label="voicePreviewPlaying ? 'Pause' : 'Play'" @click="toggleVoicePreviewPlay">
          <svg v-if="voicePreviewPlaying" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
          <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
        </button>
        <div class="nx-player-track" @click="onVoicePreviewSeek"><i :style="{ width: (voicePreviewDuration ? (voicePreviewCurrent / voicePreviewDuration) * 100 : 0) + '%' }"></i></div>
        <span class="nx-num">{{ fmtVoicePreviewTime(voicePreviewCurrent) }} / {{ fmtVoicePreviewTime(voicePreviewDuration) }}</span>
      </div>
      <div class="nx-actions">
        <button class="nx-btn nx-primary" type="button" @click="confirmUploadFromPreview">Use this</button>
        <button class="nx-btn" type="button" @click="cancelVoiceUpload">Redo</button>
      </div>
    </div>

    <p v-else-if="voiceUploadStatus === 'uploading'" class="nx-hint">Uploading…</p>
    <div v-else-if="voiceUploadStatus === 'transcribing'" class="nx-row nx-hint">
      <span>Writing the captions from your audio…</span>
      <button class="nx-link" type="button" @click="cancelVoiceUpload">Cancel</button>
    </div>
    <div v-else-if="voiceUploadStatus === 'ready'" class="nx-quote">
      <div class="nx-row"><b>Ready</b><button class="nx-link" type="button" @click="cancelVoiceUpload">Discard</button></div>
      <p v-if="voiceUploadAsset?.transcript_text" class="nx-hint">“{{ voiceUploadAsset.transcript_text.slice(0, 220) }}{{ voiceUploadAsset.transcript_text.length > 220 ? "…" : "" }}”</p>
      <button class="nx-btn nx-primary nx-full" type="button" @click="applyVoiceUpload({ updateScript: true })">Use it and update the script</button>
      <button class="nx-btn nx-full" type="button" @click="applyVoiceUpload({ updateScript: false })">Use the audio only</button>
    </div>
    <div v-else-if="voiceUploadStatus === 'error'" class="nx-row">
      <span class="nx-err">{{ voiceUploadError }}</span>
      <button class="nx-link" type="button" @click="cancelVoiceUpload">Dismiss</button>
    </div>

    <section class="nx-sec nx-sec-top">
      <span class="nx-label">Uploaded before</span>
      <button v-if="customAudioAssets.length === 0 && !customAudioLoading" class="nx-btn nx-full" type="button" @click="loadCustomAudioAssets">Show your audio</button>
      <template v-else>
        <input v-model="customAudioSearch" class="nx-input nx-input-sm" placeholder="Search audio" aria-label="Search audio" />
        <p v-if="customAudioLoading" class="nx-hint">Loading…</p>
        <p v-else-if="customAudioAssetsFiltered.length === 0" class="nx-hint">No audio found.</p>
        <div v-else class="nx-list">
          <button v-for="asset in customAudioAssetsFiltered" :key="asset.id" type="button"
            :class="['nx-list-row', activeScene?.voice_settings?.audio_asset_id === asset.id && activeScene?.voice_settings?.custom_audio ? 'on' : '']"
            @click="assignCustomAudio(asset)">
            <span class="nx-list-main"><b>{{ asset.title || "Untitled" }}</b></span>
          </button>
        </div>
      </template>
    </section>
  </template>
</template>
