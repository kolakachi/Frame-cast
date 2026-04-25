<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import api from '../services/api'

const props = defineProps({
  mode: { type: String, required: true }, // 'visual' | 'music' | 'sound'
  visible: { type: Boolean, default: false },
  musicTracks: { type: Array, default: () => [] },
  selectedMusicTrackId: { default: undefined }, // undefined=unset, null=no music, number=id
  currentAssetId: { type: Number, default: null },
})

const emit = defineEmits(['close', 'select'])

const activeTab = ref('browse')
const searchQuery = ref('')
const visualFilter = ref('all') // 'all' | 'video' | 'image'

const selectedId = ref(undefined)
const selectedItem = ref(null) // { ...asset|track, _type: 'asset'|'track'|'no-music' }

const assets = ref([])
const assetsLoading = ref(false)

const uploadDragging = ref(false)
const uploading = ref(false)
const uploadError = ref('')
const uploadInput = ref(null)

let listAudio = null
const auditionId = ref(null)
const auditionPlaying = ref(false)

let previewAudio = null
const previewPlaying = ref(false)
const previewProgress = ref(0)
const previewCurrentTime = ref(0)

const VISUAL_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'video', label: 'Video' },
  { key: 'image', label: 'Image' },
]

const WAVE_HEIGHTS = ['40%', '70%', '90%', '55%', '100%', '65%', '45%', '80%']

const MODES = {
  visual: {
    title: 'Pick a Visual',
    searchPlaceholder: 'Search your library…',
    icon: '🖼',
    iconClass: 'icon-visual',
    footerLabel: 'visual',
    useLabel: 'Use Visual',
    uploadTitle: 'Drop files here or click to upload',
    uploadHint: 'MP4, MOV, JPG, PNG, WebP · Max <span>500MB</span>',
    uploadAccept: 'image/*,video/*',
  },
  music: {
    title: 'Pick Music',
    searchPlaceholder: 'Search music…',
    icon: '🎵',
    iconClass: 'icon-music',
    footerLabel: 'track',
    useLabel: 'Use Music',
    uploadTitle: 'Upload your own music',
    uploadHint: 'MP3, WAV, AAC, M4A · Max <span>50MB</span>',
    uploadAccept: 'audio/*',
  },
  sound: {
    title: 'Pick a Sound',
    searchPlaceholder: 'Search sounds…',
    icon: '🔊',
    iconClass: 'icon-sound',
    footerLabel: 'sound',
    useLabel: 'Use Sound',
    uploadTitle: 'Upload sound effects',
    uploadHint: 'MP3, WAV · Max <span>20MB</span>',
    uploadAccept: 'audio/*',
  },
}

const modeConfig = computed(() => MODES[props.mode] ?? MODES.visual)
const isAudioMode = computed(() => props.mode === 'music' || props.mode === 'sound')
const hasSelection = computed(() => selectedItem.value !== null)

const filteredMusicTracks = computed(() => {
  const q = searchQuery.value.toLowerCase()
  if (!q) return props.musicTracks
  return props.musicTracks.filter(
    t =>
      (t.title ?? '').toLowerCase().includes(q) ||
      (t.tags ?? []).some(tag => tag.toLowerCase().includes(q))
  )
})

const musicTrackGroups = computed(() => {
  const groups = {}
  for (const track of filteredMusicTracks.value) {
    const mood = track.tags?.find(t => t !== 'music') ?? 'other'
    if (!groups[mood]) groups[mood] = []
    groups[mood].push(track)
  }
  return Object.entries(groups).map(([mood, tracks]) => ({ mood, tracks }))
})

const filteredAssets = computed(() => {
  let list = assets.value
  if (props.mode === 'visual') {
    if (visualFilter.value === 'video') list = list.filter(a => isVideo(a))
    else if (visualFilter.value === 'image') list = list.filter(a => !isVideo(a))
  }
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(a => (a.title ?? '').toLowerCase().includes(q))
  }
  return list
})

watch(
  () => props.visible,
  async val => {
    if (!val) {
      stopAllAudio()
      return
    }
    activeTab.value = 'browse'
    searchQuery.value = ''
    visualFilter.value = 'all'
    uploadError.value = ''
    stopAllAudio()

    if (props.mode === 'music') {
      if (props.selectedMusicTrackId === null) {
        selectedId.value = null
        selectedItem.value = { _type: 'no-music', id: null, title: 'No music' }
      } else if (props.selectedMusicTrackId !== undefined) {
        selectedId.value = props.selectedMusicTrackId
        const track = props.musicTracks.find(t => t.id === props.selectedMusicTrackId)
        selectedItem.value = track ? { ...track, _type: 'track' } : null
      } else {
        selectedId.value = undefined
        selectedItem.value = null
      }
    } else if (props.currentAssetId) {
      selectedId.value = props.currentAssetId
      selectedItem.value = null
    } else {
      selectedId.value = undefined
      selectedItem.value = null
    }

    await loadAssets()

    if (props.currentAssetId && !selectedItem.value) {
      const found = assets.value.find(a => a.id === props.currentAssetId)
      if (found) selectedItem.value = { ...found, _type: 'asset' }
    }
  }
)

async function loadAssets() {
  if (assetsLoading.value) return
  assetsLoading.value = true
  try {
    if (props.mode === 'visual') {
      const [imgResp, vidResp] = await Promise.all([
        api.get('/assets', { params: { asset_type: 'image', per_page: 60 } }),
        api.get('/assets', { params: { asset_type: 'video', per_page: 60 } }),
      ])
      const imgs = imgResp.data?.data?.assets ?? []
      const vids = vidResp.data?.data?.assets ?? []
      assets.value = [...vids, ...imgs].sort(
        (a, b) => new Date(b.updated_at) - new Date(a.updated_at)
      )
    } else {
      const type = props.mode === 'music' ? 'music' : 'sound'
      const resp = await api.get('/assets', { params: { asset_type: type, per_page: 60 } })
      assets.value = resp.data?.data?.assets ?? []
    }
  } catch {
    assets.value = []
  } finally {
    assetsLoading.value = false
  }
}

function selectItem(asset) {
  selectedId.value = asset.id
  selectedItem.value = { ...asset, _type: 'asset' }
  if (isAudioMode.value) startPreviewAudio(asset.storage_url, asset.duration_seconds)
}

function selectTrack(track) {
  selectedId.value = track.id
  selectedItem.value = { ...track, _type: 'track' }
  startPreviewAudio(track.storage_url, track.duration_seconds)
}

function selectNoMusic() {
  selectedId.value = null
  selectedItem.value = { _type: 'no-music', id: null, title: 'No music' }
  stopPreviewAudio()
}

function isSelected(id) {
  return selectedId.value === id
}

function handleUse() {
  if (!hasSelection.value) return
  emit('select', { mode: props.mode, item: selectedItem.value })
  emit('close')
}

function closeModal() {
  stopAllAudio()
  emit('close')
}

function triggerUpload() {
  uploadInput.value?.click()
}

async function handleFileChange(e) {
  const file = e.target?.files?.[0]
  if (!file) return
  await doUpload(file)
  e.target.value = ''
}

function handleDrop(e) {
  uploadDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) doUpload(file)
}

async function doUpload(file) {
  uploadError.value = ''
  uploading.value = true
  try {
    const assetType =
      props.mode === 'visual'
        ? file.type.startsWith('video/')
          ? 'video'
          : 'image'
        : props.mode === 'music'
          ? 'music'
          : 'sound'

    const fd = new FormData()
    fd.append('title', file.name.replace(/\.[^.]+$/, ''))
    fd.append('asset_type', assetType)
    fd.append('asset_file', file)

    const resp = await api.post('/assets', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const asset = resp.data?.data?.asset
    if (asset) {
      assets.value.unshift(asset)
      selectItem(asset)
    }
  } catch (err) {
    uploadError.value =
      err?.response?.data?.error?.message || err.message || 'Upload failed.'
  } finally {
    uploading.value = false
  }
}

function auditionTrack(track, e) {
  e.stopPropagation()
  if (auditionId.value === track.id) {
    stopListAudio()
    return
  }
  stopListAudio()
  if (!track.storage_url) return
  auditionId.value = track.id
  auditionPlaying.value = true
  listAudio = new Audio(track.storage_url)
  listAudio.addEventListener('ended', () => {
    auditionId.value = null
    auditionPlaying.value = false
    listAudio = null
  })
  listAudio.play().catch(() => {
    auditionId.value = null
    auditionPlaying.value = false
  })
}

function auditionAsset(asset, e) {
  auditionTrack(asset, e)
}

function stopListAudio() {
  listAudio?.pause()
  listAudio = null
  auditionId.value = null
  auditionPlaying.value = false
}

function startPreviewAudio(url, duration) {
  stopPreviewAudio()
  if (!url) return
  previewAudio = new Audio(url)
  previewAudio.addEventListener('timeupdate', onPreviewTimeUpdate)
  previewAudio.addEventListener('ended', () => {
    previewPlaying.value = false
    previewProgress.value = 0
    previewCurrentTime.value = 0
  })
  previewAudio.play().catch(() => {})
  previewPlaying.value = true
}

function togglePreviewAudio() {
  if (!previewAudio && selectedItem.value?.storage_url) {
    startPreviewAudio(selectedItem.value.storage_url, selectedItem.value.duration_seconds)
    return
  }
  if (!previewAudio) return
  if (previewPlaying.value) {
    previewAudio.pause()
    previewPlaying.value = false
  } else {
    previewAudio.play().catch(() => {})
    previewPlaying.value = true
  }
}

function onPreviewTimeUpdate() {
  if (!previewAudio) return
  previewCurrentTime.value = previewAudio.currentTime
  const dur = previewAudio.duration
  previewProgress.value = dur ? (previewAudio.currentTime / dur) * 100 : 0
}

function stopPreviewAudio() {
  if (previewAudio) {
    previewAudio.pause()
    previewAudio.removeEventListener('timeupdate', onPreviewTimeUpdate)
    previewAudio = null
  }
  previewPlaying.value = false
  previewProgress.value = 0
  previewCurrentTime.value = 0
}

function stopAllAudio() {
  stopListAudio()
  stopPreviewAudio()
}

onBeforeUnmount(stopAllAudio)

function isVideo(asset) {
  return asset.asset_type === 'video' || String(asset.mime_type ?? '').startsWith('video/')
}

function assetThumbUrl(asset) {
  return asset.thumbnail_url || asset.storage_url
}

function formatDuration(seconds) {
  if (!seconds) return '—'
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}:${s.toString().padStart(2, '0')}`
}

function moodEmoji(mood) {
  return { dark: '🌑', upbeat: '⚡', calm: '🌊', epic: '🔥', other: '🎵' }[mood] ?? '🎵'
}

function moodLabel(mood) {
  return mood ? mood.charAt(0).toUpperCase() + mood.slice(1) : 'Other'
}

function trackMoodLabel(track) {
  const mood = (track.tags ?? []).find(t => t !== 'music')
  return mood ? `${moodLabel(mood)} · Royalty-free` : 'Royalty-free'
}
</script>

<template>
  <Teleport to="body">
    <Transition name="mp-fade">
      <div v-if="visible" class="mp-overlay" @mousedown.self="closeModal">
        <div class="mp-modal">

          <!-- HEADER -->
          <div class="mp-header">
            <div class="mp-title-row">
              <div class="mp-title">
                <div :class="['mp-type-icon', modeConfig.iconClass]">{{ modeConfig.icon }}</div>
                <span>{{ modeConfig.title }}</span>
              </div>
              <button class="mp-close" @click="closeModal">✕</button>
            </div>

            <div class="mp-search-tabs">
              <div class="mp-search-box">
                <span class="mp-search-icon">🔍</span>
                <input
                  v-model="searchQuery"
                  type="text"
                  :placeholder="modeConfig.searchPlaceholder"
                  class="mp-search-input"
                />
              </div>
              <div class="mp-tabs">
                <div
                  :class="['mp-tab', activeTab === 'browse' ? 'active' : '']"
                  @click="activeTab = 'browse'"
                >Browse</div>
                <div
                  :class="['mp-tab', activeTab === 'uploads' ? 'active' : '']"
                  @click="activeTab = 'uploads'"
                >My Uploads</div>
              </div>
            </div>
          </div>

          <!-- FILTERS (visual only) -->
          <div v-if="mode === 'visual'" class="mp-filters">
            <button
              v-for="chip in VISUAL_FILTERS"
              :key="chip.key"
              :class="['mp-chip', visualFilter === chip.key ? 'active' : '']"
              @click="visualFilter = chip.key"
            >{{ chip.label }}</button>
          </div>

          <!-- BODY -->
          <div class="mp-body">

            <!-- GRID PANE -->
            <div class="mp-grid-pane">

              <!-- BROWSE TAB -->
              <template v-if="activeTab === 'browse'">

                <!-- Visual browse -->
                <template v-if="mode === 'visual'">
                  <div v-if="assetsLoading" class="mp-loading">Loading…</div>
                  <div v-else-if="filteredAssets.length === 0" class="mp-empty">
                    <div class="mp-empty-icon">🖼</div>
                    <div class="mp-empty-title">No visuals in your library</div>
                    <div class="mp-empty-sub">Upload images or videos in "My Uploads"</div>
                  </div>
                  <template v-else>
                    <div class="mp-source-label">Your Library</div>
                    <div class="mp-visual-grid">
                      <div
                        v-for="asset in filteredAssets"
                        :key="asset.id"
                        :class="['mp-visual-card', isSelected(asset.id) ? 'selected' : '']"
                        :title="asset.title"
                        @click="selectItem(asset)"
                      >
                        <img
                          v-if="assetThumbUrl(asset)"
                          :src="assetThumbUrl(asset)"
                          :alt="asset.title"
                          loading="lazy"
                        />
                        <div v-else class="mp-visual-placeholder"></div>
                        <div class="mp-card-type">{{ isVideo(asset) ? 'VIDEO' : 'IMG' }}</div>
                        <div v-if="isSelected(asset.id)" class="mp-check">✓</div>
                        <div v-if="isVideo(asset)" class="mp-play-overlay">
                          <div class="mp-play-btn">▶</div>
                        </div>
                      </div>
                    </div>
                  </template>
                </template>

                <!-- Music browse: built-in library -->
                <template v-else-if="mode === 'music'">
                  <div v-if="musicTrackGroups.length === 0" class="mp-empty">
                    <div class="mp-empty-icon">🎵</div>
                    <div class="mp-empty-title">No tracks found</div>
                  </div>
                  <template v-else>
                    <div class="mp-source-label">Framecast Music Library</div>
                    <div class="mp-audio-list">
                      <!-- No music option -->
                      <div
                        :class="['mp-audio-row', selectedId === null ? 'selected' : '']"
                        @click="selectNoMusic"
                      >
                        <button class="mp-audio-play" disabled @click.stop>🚫</button>
                        <div class="mp-audio-info">
                          <div class="mp-audio-name">No music</div>
                          <div class="mp-audio-meta">Silence</div>
                        </div>
                        <div class="mp-audio-spacer"></div>
                        <div class="mp-audio-check">{{ selectedId === null ? '✓' : '' }}</div>
                      </div>
                      <template v-for="group in musicTrackGroups" :key="group.mood">
                        <div class="mp-source-label" style="margin-top:14px;">
                          {{ moodEmoji(group.mood) }} {{ moodLabel(group.mood) }}
                        </div>
                        <div
                          v-for="track in group.tracks"
                          :key="track.id"
                          :class="['mp-audio-row', isSelected(track.id) ? 'selected' : '']"
                          @click="selectTrack(track)"
                        >
                          <button
                            class="mp-audio-play"
                            @click.stop="auditionTrack(track, $event)"
                          >{{ auditionId === track.id && auditionPlaying ? '⏸' : '▶' }}</button>
                          <div class="mp-audio-info">
                            <div class="mp-audio-name">{{ track.title }}</div>
                            <div class="mp-audio-meta">{{ trackMoodLabel(track) }}</div>
                          </div>
                          <div class="mp-audio-wave">
                            <span
                              v-for="(h, i) in WAVE_HEIGHTS"
                              :key="i"
                              :style="{ height: h }"
                            ></span>
                          </div>
                          <div v-if="track.duration_seconds" class="mp-audio-duration">
                            {{ formatDuration(track.duration_seconds) }}
                          </div>
                          <div class="mp-audio-check">{{ isSelected(track.id) ? '✓' : '' }}</div>
                        </div>
                      </template>
                    </div>
                  </template>
                </template>

                <!-- Sound browse: user's sound assets -->
                <template v-else-if="mode === 'sound'">
                  <div v-if="assetsLoading" class="mp-loading">Loading…</div>
                  <div v-else-if="filteredAssets.length === 0" class="mp-empty">
                    <div class="mp-empty-icon">🔊</div>
                    <div class="mp-empty-title">No sounds yet</div>
                    <div class="mp-empty-sub">Upload sound effects in "My Uploads"</div>
                  </div>
                  <template v-else>
                    <div class="mp-source-label">Your Sounds</div>
                    <div class="mp-audio-list">
                      <div
                        v-for="asset in filteredAssets"
                        :key="asset.id"
                        :class="['mp-audio-row', isSelected(asset.id) ? 'selected' : '']"
                        @click="selectItem(asset)"
                      >
                        <button
                          class="mp-audio-play"
                          @click.stop="auditionAsset(asset, $event)"
                        >{{ auditionId === asset.id && auditionPlaying ? '⏸' : '▶' }}</button>
                        <div class="mp-audio-info">
                          <div class="mp-audio-name">{{ asset.title }}</div>
                          <div class="mp-audio-meta">Uploaded</div>
                        </div>
                        <div class="mp-audio-wave">
                          <span
                            v-for="(h, i) in WAVE_HEIGHTS"
                            :key="i"
                            :style="{ height: h }"
                          ></span>
                        </div>
                        <div class="mp-audio-check">{{ isSelected(asset.id) ? '✓' : '' }}</div>
                      </div>
                    </div>
                  </template>
                </template>

              </template>

              <!-- MY UPLOADS TAB -->
              <template v-else-if="activeTab === 'uploads'">
                <div
                  :class="['mp-upload-zone', uploadDragging ? 'dragging' : '']"
                  @click="triggerUpload"
                  @dragover.prevent="uploadDragging = true"
                  @dragleave.prevent="uploadDragging = false"
                  @drop.prevent="handleDrop"
                >
                  <div class="mp-upload-icon">{{ uploading ? '⏳' : modeConfig.icon }}</div>
                  <div class="mp-upload-title">{{ uploading ? 'Uploading…' : modeConfig.uploadTitle }}</div>
                  <!-- eslint-disable-next-line vue/no-v-html -->
                  <div class="mp-upload-hint" v-html="modeConfig.uploadHint"></div>
                  <div v-if="uploadError" class="mp-upload-error">{{ uploadError }}</div>
                </div>
                <input
                  ref="uploadInput"
                  type="file"
                  :accept="modeConfig.uploadAccept"
                  style="display:none"
                  @change="handleFileChange"
                />

                <!-- Visual uploads list -->
                <template v-if="mode === 'visual'">
                  <div v-if="assetsLoading" class="mp-loading">Loading…</div>
                  <template v-else-if="filteredAssets.length > 0">
                    <div class="mp-source-label">Your Uploads</div>
                    <div class="mp-visual-grid">
                      <div
                        v-for="asset in filteredAssets"
                        :key="asset.id"
                        :class="['mp-visual-card', isSelected(asset.id) ? 'selected' : '']"
                        :title="asset.title"
                        @click="selectItem(asset)"
                      >
                        <img
                          v-if="assetThumbUrl(asset)"
                          :src="assetThumbUrl(asset)"
                          :alt="asset.title"
                          loading="lazy"
                        />
                        <div v-else class="mp-visual-placeholder"></div>
                        <div class="mp-card-type">{{ isVideo(asset) ? 'MY VID' : 'MY IMG' }}</div>
                        <div v-if="isSelected(asset.id)" class="mp-check">✓</div>
                      </div>
                    </div>
                  </template>
                  <div v-else-if="!assetsLoading" class="mp-empty-small">No uploads yet</div>
                </template>

                <!-- Audio uploads list (music or sound) -->
                <template v-else>
                  <template v-if="filteredAssets.length > 0">
                    <div class="mp-source-label">Your Uploads</div>
                    <div class="mp-audio-list">
                      <div
                        v-for="asset in filteredAssets"
                        :key="asset.id"
                        :class="['mp-audio-row', isSelected(asset.id) ? 'selected' : '']"
                        @click="selectItem(asset)"
                      >
                        <button
                          class="mp-audio-play"
                          @click.stop="auditionAsset(asset, $event)"
                        >{{ auditionId === asset.id && auditionPlaying ? '⏸' : '▶' }}</button>
                        <div class="mp-audio-info">
                          <div class="mp-audio-name">{{ asset.title }}</div>
                          <div class="mp-audio-meta">Uploaded</div>
                        </div>
                        <div class="mp-audio-wave">
                          <span
                            v-for="(h, i) in WAVE_HEIGHTS"
                            :key="i"
                            :style="{ height: h }"
                          ></span>
                        </div>
                        <div class="mp-audio-check">{{ isSelected(asset.id) ? '✓' : '' }}</div>
                      </div>
                    </div>
                  </template>
                  <div v-else-if="!assetsLoading" class="mp-empty-small">No uploads yet</div>
                </template>
              </template>

            </div><!-- end grid pane -->

            <!-- PREVIEW PANE -->
            <div v-if="selectedItem" class="mp-preview-pane">
              <div class="mp-preview-label">Preview</div>

              <!-- No-music selected -->
              <template v-if="selectedItem._type === 'no-music'">
                <div class="mp-preview-no-music">🚫</div>
                <div class="mp-preview-name">No music</div>
                <div class="mp-preview-meta">Silence for the full video</div>
              </template>

              <!-- Visual preview -->
              <template v-else-if="!isAudioMode">
                <div class="mp-preview-thumb">
                  <img
                    v-if="assetThumbUrl(selectedItem)"
                    :src="assetThumbUrl(selectedItem)"
                    :alt="selectedItem.title"
                  />
                  <div v-else class="mp-preview-placeholder"></div>
                </div>
                <div class="mp-preview-name">{{ selectedItem.title }}</div>
                <div class="mp-preview-meta">{{ isVideo(selectedItem) ? 'Video' : 'Image' }}</div>
                <div :class="['mp-preview-tag', isVideo(selectedItem) ? 'tag-video' : 'tag-image']">
                  {{ isVideo(selectedItem) ? 'VIDEO' : 'IMAGE' }}
                </div>
              </template>

              <!-- Audio preview -->
              <template v-else>
                <div class="mp-preview-audio-player">
                  <div class="mp-preview-audio-row">
                    <button class="mp-preview-play-btn" @click="togglePreviewAudio">
                      {{ previewPlaying ? '⏸' : '▶' }}
                    </button>
                    <div class="mp-preview-waveform">
                      <div
                        class="mp-preview-waveform-fill"
                        :style="{ width: previewProgress + '%' }"
                      ></div>
                    </div>
                  </div>
                  <div class="mp-preview-time">
                    {{ formatDuration(previewCurrentTime) }}
                    <template v-if="selectedItem.duration_seconds">
                      / {{ formatDuration(selectedItem.duration_seconds) }}
                    </template>
                  </div>
                </div>
                <div class="mp-preview-name">{{ selectedItem.title }}</div>
                <div class="mp-preview-meta">
                  {{ selectedItem._type === 'track' ? trackMoodLabel(selectedItem) : 'Uploaded' }}
                </div>
                <div :class="['mp-preview-tag', mode === 'music' ? 'tag-music' : 'tag-sound']">
                  {{ mode === 'music' ? 'MUSIC' : 'SFX' }}
                </div>
              </template>

              <div class="mp-preview-spacer"></div>
            </div>

          </div><!-- end body -->

          <!-- FOOTER -->
          <div class="mp-footer">
            <div class="mp-footer-info">
              <template v-if="selectedItem">
                <span>{{ selectedItem._type === 'no-music' ? 'No music' : '1 ' + modeConfig.footerLabel }}</span>
                {{ selectedItem._type === 'no-music' ? ' selected' : ' selected' }}
              </template>
              <template v-else>No {{ modeConfig.footerLabel }} selected</template>
            </div>
            <div class="mp-footer-actions">
              <button class="mp-btn mp-btn-ghost" @click="closeModal">Cancel</button>
              <button
                class="mp-btn mp-btn-primary"
                :disabled="!hasSelection"
                @click="handleUse"
              >{{ modeConfig.useLabel }}</button>
            </div>
          </div>

        </div><!-- end modal -->
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* Transition */
.mp-fade-enter-active,
.mp-fade-leave-active { transition: opacity .18s ease; }
.mp-fade-enter-from,
.mp-fade-leave-to { opacity: 0; }
.mp-fade-enter-active .mp-modal,
.mp-fade-leave-active .mp-modal { transition: transform .18s ease; }
.mp-fade-enter-from .mp-modal { transform: translateY(10px); }
.mp-fade-leave-to .mp-modal { transform: translateY(10px); }

/* Overlay */
.mp-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, .72);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 24px;
}

/* Modal shell */
.mp-modal {
  background: var(--color-bg-panel);
  border: 1px solid var(--color-border-active);
  border-radius: 14px;
  width: 100%;
  max-width: 860px;
  height: min(680px, 90vh);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0, 0, 0, .6);
}

/* Header */
.mp-header {
  padding: 18px 20px 0;
  flex-shrink: 0;
}

.mp-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}

.mp-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-primary);
}

.mp-type-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}
.icon-visual { background: rgba(74, 127, 202, .15); }
.icon-music  { background: rgba(155, 109, 255, .15); }
.icon-sound  { background: rgba(52, 199, 123, .15); }

.mp-close {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  transition: .15s;
}
.mp-close:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }

/* Search + tabs */
.mp-search-tabs {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 0;
}

.mp-search-box {
  flex: 1;
  position: relative;
}

.mp-search-input {
  width: 100%;
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 8px 12px 8px 34px;
  color: var(--color-text-primary);
  font-family: inherit;
  font-size: 13px;
  outline: none;
  transition: .15s;
}
.mp-search-input:focus { border-color: var(--color-accent); }
.mp-search-input::placeholder { color: var(--color-text-muted); }

.mp-search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-text-muted);
  font-size: 13px;
  pointer-events: none;
}

.mp-tabs {
  display: flex;
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 3px;
  gap: 2px;
  flex-shrink: 0;
}

.mp-tab {
  padding: 6px 12px;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: var(--color-text-muted);
  transition: .15s;
  white-space: nowrap;
  user-select: none;
}
.mp-tab.active { background: var(--color-bg-panel); color: var(--color-text-primary); }
.mp-tab:hover:not(.active) { color: var(--color-text-primary); }

/* Filters */
.mp-filters {
  display: flex;
  gap: 6px;
  padding: 12px 20px 0;
  flex-shrink: 0;
}

.mp-chip {
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-muted);
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: .15s;
  font-family: inherit;
}
.mp-chip.active {
  border-color: var(--color-accent);
  background: rgba(255, 107, 53, .1);
  color: var(--color-accent);
}
.mp-chip:hover:not(.active) { border-color: var(--color-border-active); color: var(--color-text-primary); }

/* Body */
.mp-body {
  display: flex;
  flex: 1;
  overflow: hidden;
  margin-top: 12px;
}

/* Grid pane */
.mp-grid-pane {
  flex: 1;
  overflow-y: auto;
  padding: 0 20px 16px;
}
.mp-grid-pane::-webkit-scrollbar { width: 5px; }
.mp-grid-pane::-webkit-scrollbar-track { background: transparent; }
.mp-grid-pane::-webkit-scrollbar-thumb { background: var(--color-border-active); border-radius: 99px; }

/* Source label */
.mp-source-label {
  font-family: var(--font-mono, monospace);
  font-size: 9px;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.mp-source-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--color-border);
}

/* Visual grid */
.mp-visual-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 8px;
  margin-bottom: 20px;
}

.mp-visual-card {
  border-radius: 8px;
  overflow: hidden;
  position: relative;
  aspect-ratio: 9/16;
  background: var(--color-bg-elevated);
  border: 2px solid transparent;
  cursor: pointer;
  transition: border-color .15s, transform .15s;
}
.mp-visual-card:hover { border-color: var(--color-border-active); transform: scale(1.02); }
.mp-visual-card.selected { border-color: var(--color-accent); }
.mp-visual-card img { width: 100%; height: 100%; object-fit: cover; display: block; }

.mp-visual-placeholder {
  width: 100%;
  height: 100%;
  background: var(--color-bg-card);
}

.mp-card-type {
  position: absolute;
  top: 5px;
  left: 5px;
  background: rgba(0, 0, 0, .7);
  border-radius: 4px;
  padding: 2px 5px;
  font-size: 9px;
  font-weight: 700;
  font-family: var(--font-mono, monospace);
  color: var(--color-text-muted);
}

.mp-check {
  position: absolute;
  top: 5px;
  right: 5px;
  width: 18px;
  height: 18px;
  background: var(--color-accent);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  color: #fff;
  font-weight: 700;
}

.mp-play-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, .3);
  opacity: 0;
  transition: .15s;
}
.mp-visual-card:hover .mp-play-overlay { opacity: 1; }

.mp-play-btn {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(255, 107, 53, .9);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

/* Audio list */
.mp-audio-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 20px;
}

.mp-audio-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid transparent;
  cursor: pointer;
  background: var(--color-bg-elevated);
  transition: .15s;
}
.mp-audio-row:hover { border-color: var(--color-border-active); }
.mp-audio-row.selected {
  border-color: var(--color-accent);
  background: rgba(255, 107, 53, .08);
}

.mp-audio-play {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  cursor: pointer;
  font-size: 11px;
  transition: .15s;
  background: var(--color-bg-panel);
  color: var(--color-text-muted);
}
.mp-audio-row.selected .mp-audio-play { background: var(--color-accent); color: #fff; }
.mp-audio-play:hover:not(:disabled) { background: var(--color-border-active); color: var(--color-text-primary); }
.mp-audio-play:disabled { opacity: .5; cursor: default; }

.mp-audio-info { flex: 1; min-width: 0; }
.mp-audio-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.mp-audio-meta { font-size: 11px; color: var(--color-text-muted); margin-top: 1px; }

.mp-audio-spacer { flex: 1; }

.mp-audio-duration {
  font-family: var(--font-mono, monospace);
  font-size: 11px;
  color: var(--color-text-muted);
  flex-shrink: 0;
}

.mp-audio-wave {
  width: 70px;
  height: 22px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 1.5px;
}
.mp-audio-wave span {
  flex: 1;
  border-radius: 2px;
  background: var(--color-border-active);
  transition: background .15s;
}
.mp-audio-row.selected .mp-audio-wave span { background: var(--color-accent); opacity: .6; }
.mp-audio-row.selected .mp-audio-wave span:nth-child(3),
.mp-audio-row.selected .mp-audio-wave span:nth-child(5) { opacity: 1; }

.mp-audio-check {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 1.5px solid var(--color-border);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  transition: .15s;
  font-weight: 700;
}
.mp-audio-row.selected .mp-audio-check {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}

/* Upload zone */
.mp-upload-zone {
  border: 2px dashed var(--color-border-active);
  border-radius: 12px;
  padding: 32px 20px;
  text-align: center;
  cursor: pointer;
  transition: .2s;
  margin-bottom: 20px;
}
.mp-upload-zone:hover,
.mp-upload-zone.dragging {
  border-color: var(--color-accent);
  background: rgba(255, 107, 53, .06);
}

.mp-upload-icon { font-size: 26px; margin-bottom: 8px; }
.mp-upload-title { font-size: 14px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.mp-upload-hint { font-size: 12px; color: var(--color-text-muted); }
.mp-upload-hint :deep(span) { color: var(--color-accent); }
.mp-upload-error { font-size: 11px; color: #ef4444; margin-top: 8px; }

/* States */
.mp-loading {
  font-size: 12px;
  color: var(--color-text-muted);
  padding: 24px 0;
  text-align: center;
}

.mp-empty {
  text-align: center;
  padding: 40px 20px;
  color: var(--color-text-muted);
}
.mp-empty-icon { font-size: 30px; margin-bottom: 10px; }
.mp-empty-title { font-size: 14px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.mp-empty-sub { font-size: 12px; }

.mp-empty-small {
  font-size: 12px;
  color: var(--color-text-muted);
  text-align: center;
  padding: 16px 0;
}

/* Preview pane */
.mp-preview-pane {
  width: 200px;
  flex-shrink: 0;
  border-left: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  padding: 16px;
  overflow: hidden;
}

.mp-preview-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  margin-bottom: 10px;
}

.mp-preview-no-music {
  font-size: 36px;
  text-align: center;
  padding: 20px 0 10px;
}

.mp-preview-thumb {
  width: 100%;
  aspect-ratio: 9/16;
  background: var(--color-bg-elevated);
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 12px;
}
.mp-preview-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mp-preview-placeholder { width: 100%; height: 100%; background: var(--color-bg-card); }

.mp-preview-name {
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-primary);
  margin-bottom: 3px;
  word-break: break-word;
}
.mp-preview-meta { font-size: 11px; color: var(--color-text-muted); margin-bottom: 6px; }

.mp-preview-tag {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 700;
  font-family: var(--font-mono, monospace);
  margin-bottom: 12px;
}
.tag-video { background: rgba(74, 127, 202, .15); color: #4a7fca; }
.tag-image { background: rgba(155, 109, 255, .15); color: #9b6dff; }
.tag-music { background: rgba(155, 109, 255, .15); color: #9b6dff; }
.tag-sound { background: rgba(52, 199, 123, .15); color: #34c77b; }

.mp-preview-audio-player {
  background: var(--color-bg-elevated);
  border-radius: 8px;
  padding: 10px;
  margin-bottom: 12px;
}
.mp-preview-audio-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }

.mp-preview-play-btn {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--color-accent);
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 10px;
  flex-shrink: 0;
}

.mp-preview-waveform {
  flex: 1;
  height: 28px;
  background: var(--color-border);
  border-radius: 4px;
  overflow: hidden;
  position: relative;
}
.mp-preview-waveform-fill {
  position: absolute;
  inset: 0;
  background: rgba(255, 107, 53, .3);
  border-right: 2px solid var(--color-accent);
  transition: width .1s linear;
}
.mp-preview-time {
  font-family: var(--font-mono, monospace);
  font-size: 10px;
  color: var(--color-text-muted);
  text-align: right;
}

.mp-preview-spacer { flex: 1; }

/* Footer */
.mp-footer {
  padding: 12px 20px;
  border-top: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
}

.mp-footer-info {
  font-size: 12px;
  color: var(--color-text-muted);
}
.mp-footer-info span { color: var(--color-text-primary); font-weight: 600; }

.mp-footer-actions { display: flex; gap: 8px; }

.mp-btn {
  padding: 8px 16px;
  border-radius: 8px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  transition: .15s;
}
.mp-btn-ghost {
  background: transparent;
  border-color: var(--color-border);
  color: var(--color-text-muted);
}
.mp-btn-ghost:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.mp-btn-primary { background: var(--color-accent); color: #fff; }
.mp-btn-primary:hover { background: var(--color-accent-hover); }
.mp-btn-primary:disabled { opacity: .4; cursor: default; }
</style>
