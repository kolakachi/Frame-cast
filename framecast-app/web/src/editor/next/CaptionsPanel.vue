<script setup>
// Captions. Settings are saved on the selected scene (as in the classic editor); "Use on all scenes" copies them.
import { computed, ref } from "vue";
import CaptionPreview from "../../components/CaptionPreview.vue";
import { useCore } from "./core";

const core = useCore();
const {
  activeScene, activeSceneIndex, scenes, captionSaveState, saveUgcHeadline,
  captionEnabledDraft, captionPanelRow, captionAnimationDraft, selectCaptionAnimation, captionEffectModalOpen,
  captionHighlightColorDraft, captionHighlightDraft, CAPTION_HIGHLIGHT_OPTIONS, captionPositionDraft, CAPTION_POSITION_OPTIONS,
  captionSizeDraft, captionBasePx, captionSizeIsCustom, captionStyleDraft, CAPTION_FONT_GROUPS, captionFontDraft,
  selectedCaptionFont, selectCaptionFont, fontFamilyValue, captionColorDraft, CAPTION_COLOR_SWATCHES,
  captionHighlightStyleDraft, CAPTION_HIGHLIGHT_STYLE_OPTIONS, captionBackdropChecked, captionPanelColorDraft, CAPTION_PANEL_SWATCHES,
  captionPresets, applyCaptionPreset, deleteCaptionPreset, captionPresetDeleting, captionPresetSaveOpen, captionPresetSaveName,
  saveCaptionPreset, captionPresetSaving, applyAllState, applyCaptionsToAllScenes, captionSaveCopy,
} = core;

const HIGHLIGHT_SHORT = { keywords: "Keywords", word_by_word: "Each word", line_by_line: "Each line", none: "None" };
const POSITION_SHORT = { top_third: "Top", center: "Middle", bottom_third: "Bottom" };
const SIZES = [["small", "S"], ["medium", "M"], ["large", "L"], ["xlarge", "XL"]];
const positions = computed(() => ["top_third", "center", "bottom_third"].map((v) => CAPTION_POSITION_OPTIONS.find((o) => o.value === v)).filter(Boolean));
const customize = ref(false);
const fontOpen = ref(false);
const hasHeadline = computed(() => !!activeScene.value?.caption_settings_json?.ugc_headline);
</script>

<template>
  <div class="nx-ph nx-row">
    <div>
      <h2>Captions</h2>
      <p>Scene {{ activeSceneIndex + 1 }}</p>
    </div>
    <label class="nx-switch">
      <input v-model="captionEnabledDraft" type="checkbox" />
      <span>{{ captionEnabledDraft ? "On" : "Off" }}</span>
    </label>
  </div>

  <section v-if="hasHeadline" class="nx-sec">
    <label class="nx-label" :for="`nx-headline-${activeScene.id}`">Headline on screen <span class="nx-dim">(not spoken)</span></label>
    <textarea :id="`nx-headline-${activeScene.id}`" :key="activeScene.id" :data-scene-id="activeScene.id" class="nx-input" rows="2" maxlength="180"
      :value="activeScene.caption_settings_json.ugc_headline.text" :disabled="captionSaveState === 'saving'" @change="saveUgcHeadline"></textarea>
    <p class="nx-hint">Saves when you click away. Nothing is regenerated.</p>
  </section>

  <section class="nx-sec">
    <span class="nx-label">Style</span>
    <div class="nx-cap-grid">
      <button v-for="anim in captionPanelRow" :key="anim.key" type="button" :class="['nx-cap', captionAnimationDraft === anim.key ? 'on' : '']" :title="anim.desc" @click="selectCaptionAnimation(anim.key)">
        <span class="nx-cap-sample"><CaptionPreview :demo="true" :settings="{ animation: anim.key, highlight_color: captionHighlightColorDraft }" /></span>
        <span class="nx-cap-name">{{ anim.name }}</span>
      </button>
      <button type="button" class="nx-cap nx-cap-more" @click="captionEffectModalOpen = true">
        <span class="nx-cap-sample">All</span>
        <span class="nx-cap-name">All styles</span>
      </button>
    </div>
  </section>

  <section class="nx-sec">
    <span class="nx-label">Highlight</span>
    <div class="nx-chips" role="group" aria-label="Highlight">
      <button v-for="o in CAPTION_HIGHLIGHT_OPTIONS" :key="o.value" type="button" :class="['nx-chip', captionHighlightDraft === o.value ? 'on' : '']" @click="captionHighlightDraft = o.value">{{ HIGHLIGHT_SHORT[o.value] || o.label }}</button>
    </div>
  </section>

  <section class="nx-sec">
    <span class="nx-label">Position</span>
    <div class="nx-seg nx-seg-full" role="group" aria-label="Position">
      <button v-for="o in positions" :key="o.value" type="button" :class="captionPositionDraft === o.value ? 'on' : ''" @click="captionPositionDraft = o.value">{{ POSITION_SHORT[o.value] }}</button>
    </div>
  </section>

  <section class="nx-sec">
    <div class="nx-row"><span class="nx-label">Size</span><span class="nx-num">{{ captionSizeIsCustom ? `Custom · ${captionSizeDraft}` : captionBasePx(captionSizeDraft) }}</span></div>
    <div class="nx-seg nx-seg-full" role="group" aria-label="Size">
      <button v-for="sz in SIZES" :key="sz[0]" type="button" :class="captionSizeDraft === sz[0] ? 'on' : ''" @click="captionSizeDraft = sz[0]">{{ sz[1] }}</button>
    </div>
    <input type="range" class="nx-range" min="8" max="48" step="1" aria-label="Exact caption size" :value="captionBasePx(captionSizeDraft)" @input="captionSizeDraft = String($event.target.value)" />
  </section>

  <section class="nx-sec">
    <span class="nx-label">Highlight color</span>
    <div class="nx-swatches">
      <button v-for="s in CAPTION_COLOR_SWATCHES" :key="'h' + s" type="button" :class="['nx-swatch', captionHighlightColorDraft === s ? 'on' : '']" :style="{ background: s }" :aria-label="`Highlight ${s}`" @click="captionHighlightColorDraft = s"></button>
      <label class="nx-swatch nx-swatch-custom" :style="{ background: captionHighlightColorDraft }" aria-label="Your own highlight color"><input v-model="captionHighlightColorDraft" type="color" /></label>
    </div>
  </section>

  <section class="nx-sec">
    <button class="nx-disclosure" type="button" :aria-expanded="customize" @click="customize = !customize">
      <span>Customize</span><span class="nx-dim">font, colors, backdrop</span>
      <svg viewBox="0 0 24 24" aria-hidden="true" :class="customize ? 'is-open' : ''"><path d="M6 9l6 6 6-6" /></svg>
    </button>
    <template v-if="customize">
      <span class="nx-label">Font</span>
      <div class="nx-pop-wrap">
        <button type="button" class="nx-select" :aria-expanded="fontOpen" @click="fontOpen = !fontOpen">
          <span :style="{ fontFamily: fontFamilyValue(selectedCaptionFont.font) }">{{ selectedCaptionFont.font }}</span>
          <span class="nx-dim">{{ selectedCaptionFont.group }}</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div v-if="fontOpen" class="nx-pop nx-pop-scroll">
          <template v-for="group in CAPTION_FONT_GROUPS" :key="group.label">
            <span class="nx-menu-label">{{ group.label }}</span>
            <button v-for="font in group.fonts" :key="font" type="button" :class="['nx-menu-item', captionFontDraft === font ? 'on' : '']"
              :style="{ fontFamily: fontFamilyValue(font) }" @click="selectCaptionFont(font); fontOpen = false">{{ font }}</button>
          </template>
        </div>
      </div>

      <span class="nx-label">Base look</span>
      <div class="nx-seg nx-seg-full" role="group" aria-label="Base look">
        <button v-for="b in [['impact', 'Impact'], ['editorial', 'Editorial'], ['hacker', 'Hacker']]" :key="b[0]" type="button" :class="captionStyleDraft === b[0] ? 'on' : ''" @click="captionStyleDraft = b[0]">{{ b[1] }}</button>
      </div>

      <span class="nx-label">Text color</span>
      <div class="nx-swatches">
        <button v-for="s in CAPTION_COLOR_SWATCHES" :key="'t' + s" type="button" :class="['nx-swatch', captionColorDraft === s ? 'on' : '']" :style="{ background: s }" :aria-label="`Text ${s}`" @click="captionColorDraft = s"></button>
        <label class="nx-swatch nx-swatch-custom" :style="{ background: captionColorDraft }" aria-label="Your own text color"><input v-model="captionColorDraft" type="color" /></label>
      </div>

      <span class="nx-label">How the spoken word shows</span>
      <div class="nx-list">
        <button v-for="o in CAPTION_HIGHLIGHT_STYLE_OPTIONS" :key="o.value" type="button" :class="['nx-list-row', captionHighlightStyleDraft === o.value ? 'on' : '']" @click="captionHighlightStyleDraft = o.value">
          <span class="nx-list-main"><b>{{ o.label }}</b></span>
        </button>
      </div>

      <label class="nx-switch nx-switch-row">
        <span>Backdrop behind the text</span>
        <input v-model="captionBackdropChecked" type="checkbox" />
      </label>
      <div v-if="captionBackdropChecked" class="nx-swatches">
        <button v-for="s in CAPTION_PANEL_SWATCHES" :key="'p' + (s || 'auto')" type="button" :class="['nx-swatch', (captionPanelColorDraft || '') === s ? 'on' : '', s ? '' : 'nx-swatch-auto']"
          :style="s ? { background: s } : {}" :aria-label="s ? `Backdrop ${s}` : 'Backdrop: the style\'s own'" @click="captionPanelColorDraft = s">{{ s ? "" : "Auto" }}</button>
      </div>
    </template>
  </section>

  <section class="nx-sec">
    <span class="nx-label">Presets</span>
    <div v-if="captionPresets.length" class="nx-chips">
      <span v-for="p in captionPresets" :key="p.id" class="nx-chip nx-chip-x">
        <button type="button" @click="applyCaptionPreset(p)">{{ p.name }}</button>
        <button type="button" :aria-label="`Delete preset ${p.name}`" :disabled="captionPresetDeleting === p.id" @click="deleteCaptionPreset(p.id)">×</button>
      </span>
    </div>
    <div class="nx-row">
      <template v-if="!captionPresetSaveOpen">
        <button class="nx-link" type="button" @click="captionPresetSaveOpen = true">+ Save these settings as a preset</button>
      </template>
      <template v-else>
        <input v-model="captionPresetSaveName" class="nx-input nx-input-sm" placeholder="Preset name" maxlength="80" aria-label="Preset name"
          @keydown.enter="saveCaptionPreset" @keydown.escape="captionPresetSaveOpen = false; captionPresetSaveName = ''" />
        <button class="nx-mini" type="button" :disabled="captionPresetSaving || !captionPresetSaveName.trim()" @click="saveCaptionPreset">{{ captionPresetSaving ? "Saving…" : "Save" }}</button>
        <button class="nx-link" type="button" @click="captionPresetSaveOpen = false; captionPresetSaveName = ''">Cancel</button>
      </template>
    </div>
  </section>

  <section v-if="scenes.length > 1" class="nx-sec nx-sec-top">
    <button class="nx-btn nx-outline nx-full" type="button" :disabled="applyAllState === 'applying'" @click="applyCaptionsToAllScenes">
      {{ applyAllState === "applying" ? "Copying…" : applyAllState === "done" ? `Used on all ${scenes.length} scenes` : applyAllState === "error" ? "That failed. Try again" : `Use on all ${scenes.length} scenes` }}
    </button>
    <p class="nx-hint">Changes here apply to scene {{ activeSceneIndex + 1 }}. This copies them to every scene.</p>
  </section>
  <p v-if="captionSaveCopy()" :class="captionSaveState === 'error' ? 'nx-err' : 'nx-hint'">{{ captionSaveCopy() }}</p>
</template>
