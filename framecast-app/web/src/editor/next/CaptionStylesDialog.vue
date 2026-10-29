<script setup>
// Every caption style, played on a sample. Opened from the Captions panel and the caption toolbar.
import CaptionPreview from "../../components/CaptionPreview.vue";
import { useCore } from "./core";

const {
  captionEffectModalOpen, CAPTION_ANIMATIONS, captionAnimationDraft, selectCaptionAnimation, captionHighlightColorDraft, captionPanelColorDraft,
} = useCore();
</script>

<template>
  <div v-if="captionEffectModalOpen" class="nx-modal-bg" @click.self="captionEffectModalOpen = false">
    <div class="nx-modal nx-modal-wide" role="dialog" aria-modal="true" aria-labelledby="nx-capstyles-title">
      <div class="nx-row">
        <h2 id="nx-capstyles-title">Caption styles</h2>
        <button class="nx-ib" type="button" aria-label="Close" @click="captionEffectModalOpen = false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
      </div>
      <p>Words move on your voice's real timing. Your font, colors and position apply on top of any style.</p>
      <div class="nx-modal-scroll nx-cap-grid nx-cap-grid-lg">
        <button v-for="anim in CAPTION_ANIMATIONS" :key="anim.key" type="button" :class="['nx-cap', captionAnimationDraft === anim.key ? 'on' : '']" :title="anim.desc"
          @click="selectCaptionAnimation(anim.key); captionEffectModalOpen = false">
          <span class="nx-cap-sample"><CaptionPreview :demo="true" :settings="{ animation: anim.key, highlight_color: captionHighlightColorDraft, panel_color: captionPanelColorDraft || null }" /></span>
          <span class="nx-cap-name">{{ anim.name }}<span v-if="anim.badge" class="nx-pill">{{ anim.badge }}</span></span>
        </button>
      </div>
    </div>
  </div>
</template>
