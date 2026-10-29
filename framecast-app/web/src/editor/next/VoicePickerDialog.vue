<script setup>
// Pick the scene's voice: personalities, your cloned voices, then the library. Each can be heard first.
import { useCore } from "./core";

const {
  showVoiceModal, closeVoiceModal, VOICE_PERSONAS, applyPersona, clonedVoices, geminiVoices, otherVoices,
  voiceProfileKey, previewVoice, previewPlayingKey, previewLoadingKey, selectVoice, voiceDescription, openCloneInEditor,
} = useCore();
</script>

<template>
  <div v-if="showVoiceModal" class="nx-modal-bg" @click.self="closeVoiceModal">
    <div class="nx-modal nx-modal-wide" role="dialog" aria-modal="true" aria-labelledby="nx-voice-title">
      <div class="nx-row">
        <h2 id="nx-voice-title">Choose a voice</h2>
        <button class="nx-ib" type="button" aria-label="Close" @click="closeVoiceModal"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
      </div>
      <div class="nx-modal-scroll">
        <span class="nx-label">Personalities</span>
        <div class="nx-personas">
          <button v-for="p in VOICE_PERSONAS" :key="p.key" type="button" class="nx-persona" @click="applyPersona(p)">
            <b>{{ p.label }}</b><span>{{ p.desc }}</span>
          </button>
        </div>

        <span class="nx-label">Your voices</span>
        <div v-for="v in clonedVoices" :key="v.id" :class="['nx-vrow', voiceProfileKey === v.provider_voice_key ? 'on' : '']">
          <button class="nx-voice-play" type="button" :aria-label="previewPlayingKey === v.provider_voice_key ? `Stop ${v.name}` : `Hear ${v.name}`" @click="previewVoice(v)">
            <span v-if="previewLoadingKey === v.provider_voice_key" class="nx-spin"></span>
            <svg v-else-if="previewPlayingKey === v.provider_voice_key" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
            <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
          </button>
          <span class="nx-list-main"><b>{{ v.name }}</b><span>Your cloned voice</span></span>
          <button class="nx-mini" type="button" @click="selectVoice(v)">{{ voiceProfileKey === v.provider_voice_key ? "In use" : "Use" }}</button>
        </div>
        <button class="nx-btn nx-outline nx-full" type="button" @click="openCloneInEditor">+ Clone a voice: record or upload a sample</button>

        <span class="nx-label">Expressive voices</span>
        <div v-for="v in geminiVoices" :key="v.id" :class="['nx-vrow', voiceProfileKey === v.provider_voice_key ? 'on' : '']">
          <button class="nx-voice-play" type="button" :aria-label="previewPlayingKey === v.provider_voice_key ? `Stop ${v.name}` : `Hear ${v.name}`" @click="previewVoice(v)">
            <span v-if="previewLoadingKey === v.provider_voice_key" class="nx-spin"></span>
            <svg v-else-if="previewPlayingKey === v.provider_voice_key" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
            <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
          </button>
          <span class="nx-list-main"><b>{{ v.name }}</b><span>{{ voiceDescription(v) }}</span></span>
          <button class="nx-mini" type="button" @click="selectVoice(v)">{{ voiceProfileKey === v.provider_voice_key ? "In use" : "Use" }}</button>
        </div>

        <template v-if="otherVoices.length">
          <span class="nx-label">Standard voices</span>
          <div v-for="v in otherVoices" :key="v.id" :class="['nx-vrow', voiceProfileKey === v.provider_voice_key ? 'on' : '']">
            <button class="nx-voice-play" type="button" :aria-label="previewPlayingKey === v.provider_voice_key ? `Stop ${v.name}` : `Hear ${v.name}`" @click="previewVoice(v)">
              <span v-if="previewLoadingKey === v.provider_voice_key" class="nx-spin"></span>
              <svg v-else-if="previewPlayingKey === v.provider_voice_key" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
              <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
            </button>
            <span class="nx-list-main"><b>{{ v.name }}</b><span>{{ voiceDescription(v) }}</span></span>
            <button class="nx-mini" type="button" @click="selectVoice(v)">{{ voiceProfileKey === v.provider_voice_key ? "In use" : "Use" }}</button>
          </div>
        </template>
      </div>
      <div class="nx-row">
        <span class="nx-hint">Personalities shift the delivery of a built-in voice; they don't re-pitch it.</span>
        <button class="nx-btn" type="button" @click="closeVoiceModal">Done</button>
      </div>
    </div>
  </div>
</template>
