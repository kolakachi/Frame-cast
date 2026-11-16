<script setup>
// Audio (canvas 3E3t2SZP8XhvMxAB1NikRj, "Audio · Music" and "Audio · Sound effects"; classic Music + Sounds). Music
// plays under every scene; a sound effect belongs to the selected scene. Everything runs through the shared core, so
// saving, preview and undo behave as in the classic editor.
import { computed, ref } from "vue";
import { cr, useCore } from "./core";

const emit = defineEmits(["browse-sounds", "select-scene"]);
const core = useCore();
const {
  scenes, activeScene, activeSceneId, activeSceneIndex,
  activeMusicTrack, selectedMusicTrackId, selectMusicTrack, musicTrackGroups, musicMoodFilter, moodLabel, trackMoodLabel,
  formatTrackDuration, toggleMusicAudition, auditionMusicTrackId, openMediaPicker,
  musicVolume, musicDuckVolume, musicFadeInMs, musicLoop, musicDuckDuringVoice, scheduleMusicSave, syncPreviewMusicVolume,
  musicSaveError, musicSaveState,
  AI_MUSIC_MOODS, aiMusicMood, aiMusicCost, aiMusicPending, aiMusicError, regenerateAIMusic,
  activeSceneSoundAsset, assignSceneSound, sceneSoundVolume, syncSceneSoundVolume, scheduleSceneSoundVolumeSave,
} = core;

const tab = ref("music");
const source = ref("library");
const MOODS = ["all", "calm", "upbeat", "dark", "epic"];
const FADES = [[0, "Off"], [500, "0.5s"], [1000, "1s"], [2000, "2s"]];
// Quick picks: each opens the free sound library searching for that sound (words checked against prod's library,
// 2026-11-16: whoosh 4, applause 3, impact 2, keyboard 1).
const QUICK_SOUNDS = [["Whoosh", "whoosh"], ["Applause", "applause"], ["Impact", "impact"], ["Typing", "keyboard"]];

const tracks = computed(() => musicTrackGroups.value.flatMap((g) => g.tracks.map((t) => ({ ...t, group: g.mood }))));

function onMix() {
  scheduleMusicSave();
  syncPreviewMusicVolume();
}
function setFade(ms) {
  musicFadeInMs.value = ms;
  scheduleMusicSave();
}
function onSoundVolume() {
  syncSceneSoundVolume();
  scheduleSceneSoundVolumeSave();
}
// Other scenes' sounds, so a sound is easy to find again; a row opens its scene.
const otherSounds = computed(() =>
  scenes.value.map((s, i) => ({ s, i })).filter(({ s }) => s.id !== activeSceneId.value && s.sound_asset)
);
</script>

<template>
  <div class="nx-ph">
    <h2>Audio</h2>
    <p>{{ tab === "music" ? "Music plays under all scenes" : `Sound effects · Scene ${activeSceneIndex + 1}` }}</p>
  </div>
  <div class="nx-seg nx-seg-full" role="tablist" aria-label="Audio">
    <button type="button" role="tab" :aria-selected="tab === 'music'" :class="tab === 'music' ? 'on' : ''" @click="tab = 'music'">Music</button>
    <button type="button" role="tab" :aria-selected="tab === 'sounds'" :class="tab === 'sounds' ? 'on' : ''" @click="tab = 'sounds'">Sound effects</button>
  </div>

  <!-- Music -->
  <template v-if="tab === 'music'">
    <div class="nx-now">
      <button v-if="activeMusicTrack" type="button" class="nx-now-play" :aria-label="auditionMusicTrackId === activeMusicTrack.id ? `Stop ${activeMusicTrack.title}` : `Play ${activeMusicTrack.title}`"
        @click="toggleMusicAudition(activeMusicTrack)">
        <svg v-if="auditionMusicTrackId === activeMusicTrack.id" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
      </button>
      <span v-else class="nx-now-play is-off" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 18V6l10-2v12" /><circle cx="6" cy="18" r="3" /><circle cx="16" cy="16" r="3" /></svg></span>
      <span class="nx-list-main">
        <span>Now in this video</span>
        <b>{{ activeMusicTrack ? activeMusicTrack.title : "No music" }}</b>
      </span>
      <button v-if="activeMusicTrack" type="button" class="nx-ib" aria-label="Remove music" title="No music" @click="selectMusicTrack(null)">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
    </div>

    <div class="nx-seg nx-seg-full" role="tablist" aria-label="Where the music comes from">
      <button type="button" role="tab" :aria-selected="source === 'library'" :class="source === 'library' ? 'on' : ''" @click="source = 'library'">Library</button>
      <button type="button" role="tab" :aria-selected="false" @click="openMediaPicker('music')">Uploads</button>
      <button type="button" role="tab" :aria-selected="source === 'ai'" :class="source === 'ai' ? 'on' : ''" @click="source = 'ai'">Generate</button>
    </div>

    <template v-if="source === 'library'">
      <div class="nx-chips" role="group" aria-label="Mood">
        <button v-for="m in MOODS" :key="m" type="button" :class="['nx-chip', musicMoodFilter === m ? 'on' : '']" @click="musicMoodFilter = m">{{ m === "all" ? "All" : moodLabel(m) }}</button>
      </div>
      <div class="nx-list nx-list-scroll">
        <div v-for="t in tracks" :key="t.id" :class="['nx-list-row', 'nx-track', selectedMusicTrackId === t.id ? 'on' : '']">
          <button type="button" class="nx-track-play" :aria-label="auditionMusicTrackId === t.id ? `Stop ${t.title}` : `Listen to ${t.title}`" @click="toggleMusicAudition(t)">
            <svg v-if="auditionMusicTrackId === t.id" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
            <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
          </button>
          <button type="button" class="nx-track-pick" :aria-pressed="selectedMusicTrackId === t.id" @click="selectMusicTrack(t.id)">
            <span class="nx-list-main"><b>{{ t.title }}</b><span>{{ trackMoodLabel(t) }}</span></span>
            <span v-if="t.duration_seconds" class="nx-num">{{ formatTrackDuration(t.duration_seconds) }}</span>
          </button>
        </div>
        <p v-if="!tracks.length" class="nx-hint">No tracks in this mood.</p>
      </div>
      <p class="nx-hint">Play to listen; click a track to use it.</p>
    </template>

    <template v-else>
      <span class="nx-label">Mood or genre</span>
      <div class="nx-chips">
        <button v-for="m in AI_MUSIC_MOODS" :key="m" type="button" :class="['nx-chip', aiMusicMood === m ? 'on' : '']" @click="aiMusicMood = m">{{ m }}</button>
      </div>
      <input v-model="aiMusicMood" class="nx-input" maxlength="100" placeholder="Or your own words: tense neon synth wave" aria-label="Mood or genre" />
      <button class="nx-btn nx-primary nx-full nx-cta" type="button" :disabled="aiMusicPending || !aiMusicMood.trim()" @click="regenerateAIMusic">
        {{ aiMusicPending ? "Making the music…" : `Make music${cr(aiMusicCost)}` }}
      </button>
      <p class="nx-hint">About 30 seconds. It replaces this video's music.</p>
      <p v-if="aiMusicError" class="nx-err">{{ aiMusicError }}</p>
    </template>

    <section class="nx-sec nx-sec-top">
      <span class="nx-label">Mix</span>
      <div class="nx-row"><label for="nx-mvol">Volume</label><span class="nx-num">{{ musicVolume }}%</span></div>
      <input id="nx-mvol" v-model.number="musicVolume" type="range" class="nx-range" min="0" max="100" @input="onMix" />
      <label class="nx-switch nx-switch-row">
        <span>Lower while the voice plays</span>
        <input type="checkbox" :checked="musicDuckDuringVoice" @change="musicDuckDuringVoice = $event.target.checked; scheduleMusicSave()" />
      </label>
      <template v-if="musicDuckDuringVoice">
        <div class="nx-row"><label for="nx-mduck">Lower to</label><span class="nx-num">{{ musicDuckVolume }}%</span></div>
        <input id="nx-mduck" v-model.number="musicDuckVolume" type="range" class="nx-range" min="0" max="50" @input="onMix" />
      </template>
      <div class="nx-row"><span>Fade in</span></div>
      <div class="nx-seg nx-seg-full" role="group" aria-label="Fade in">
        <button v-for="[ms, label] in FADES" :key="ms" type="button" :class="musicFadeInMs === ms ? 'on' : ''" @click="setFade(ms)">{{ label }}</button>
      </div>
      <label class="nx-switch nx-switch-row">
        <span>Loop if the video is longer</span>
        <input type="checkbox" :checked="musicLoop" @change="musicLoop = $event.target.checked; scheduleMusicSave()" />
      </label>
      <p v-if="musicSaveError" class="nx-err">{{ musicSaveError }}</p>
      <p v-else-if="musicSaveState === 'saved'" class="nx-hint">Saved</p>
    </section>
  </template>

  <!-- Sound effects: this scene's -->
  <template v-else>
    <template v-if="activeSceneSoundAsset">
      <div class="nx-now">
        <span class="nx-now-play is-off" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7" /></svg></span>
        <span class="nx-list-main"><span>On this scene</span><b>{{ activeSceneSoundAsset.title }}</b></span>
        <button type="button" class="nx-ib" :aria-label="`Remove ${activeSceneSoundAsset.title}`" title="Remove" @click="assignSceneSound(null)">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </div>
      <div class="nx-row"><label for="nx-svol">Volume</label><span class="nx-num">{{ sceneSoundVolume }}%</span></div>
      <input id="nx-svol" v-model.number="sceneSoundVolume" type="range" class="nx-range" min="0" max="200" @input="onSoundVolume" />
      <button class="nx-btn nx-outline nx-full" type="button" @click="emit('browse-sounds', '')">Change sound</button>
    </template>
    <div v-else class="nx-empty">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5L6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7" /><path d="M18.5 5.5a9 9 0 0 1 0 13" /></svg>
      <b>No sound on this scene</b>
      <span>Plays alongside the voice. Try one:</span>
      <div class="nx-chips">
        <button v-for="[label, q] in QUICK_SOUNDS" :key="q" type="button" class="nx-chip" @click="emit('browse-sounds', q)">{{ label }}</button>
      </div>
      <button class="nx-btn nx-full" type="button" @click="emit('browse-sounds', '')">Browse sounds</button>
    </div>

    <section v-if="otherSounds.length" class="nx-sec nx-sec-top">
      <span class="nx-label">Elsewhere in this video</span>
      <div class="nx-list">
        <button v-for="{ s, i } in otherSounds" :key="s.id" type="button" class="nx-list-row" @click="emit('select-scene', s.id)">
          <span class="nx-scene-chip">S{{ i + 1 }}</span>
          <span class="nx-list-main"><b>{{ s.sound_asset.title }}</b><span>Scene {{ i + 1 }}: open it to change or remove</span></span>
        </button>
      </div>
    </section>
  </template>
</template>
