<script setup>
// Brand (canvas 3E3t2SZP8XhvMxAB1NikRj, "Brand"; classic Brand Kit): the video's channel and brand kit. Picking a
// channel fills in its brand kit (core watch); Save stores both on this video. Kits are made and edited in Settings.
import { computed } from "vue";
import UiSelect from "../../components/UiSelect.vue";
import { useCore } from "./core";

const core = useCore();
const {
  projectChannelId, channelOptions, projectBrandKitId, brandKitOptions, brandKits, selectedProjectChannel,
  saveProjectDefaults, projectDefaultsSaveState, projectDefaultsSaveError,
} = core;

const kit = computed(() => (brandKits.value || []).find((b) => String(b.id) === String(projectBrandKitId.value)) ?? null);
const swatches = computed(() => (kit.value ? [kit.value.primary_color, kit.value.secondary_color, kit.value.accent_color].filter(Boolean) : []));
</script>

<template>
  <div class="nx-ph">
    <h2>Brand</h2>
    <p>Applies to the whole video</p>
  </div>

  <div class="nx-sec">
    <span class="nx-label">Channel</span>
    <UiSelect v-model="projectChannelId" :options="channelOptions" label="Channel" />
    <p v-if="selectedProjectChannel" class="nx-hint">Picking a channel fills in its brand kit. Scenes already made keep their look.</p>
  </div>

  <div class="nx-sec">
    <span class="nx-label">Brand kit</span>
    <template v-if="brandKits.length">
      <UiSelect v-model="projectBrandKitId" :options="brandKitOptions" label="Brand kit" />
      <div v-if="kit" class="nx-kit">
        <span v-for="c in swatches" :key="c" class="nx-kit-sw" :style="{ background: c }" :title="c"></span>
        <span class="nx-list-main"><b>{{ kit.name }}</b><span>{{ [kit.font_primary, kit.font_secondary].filter(Boolean).join(" · ") || "Default fonts" }} · {{ kit.logo_asset_id ? "logo set" : "no logo" }}</span></span>
      </div>
      <a class="nx-link" href="/settings?section=brand" target="_blank" rel="noopener">Edit brand kits in Settings ↗</a>
    </template>
    <div v-else class="nx-empty">
      <div class="nx-kit"><span class="nx-kit-sw"></span><span class="nx-kit-sw"></span><span class="nx-kit-sw"></span><span class="nx-kit-logo is-empty">Logo</span></div>
      <b>No brand kit yet</b>
      <span>Set colours, fonts and a logo once. Captions and end cards pick them up on every video.</span>
      <a class="nx-btn" href="/settings?section=brand" target="_blank" rel="noopener">Create a brand kit ↗</a>
    </div>
  </div>

  <button class="nx-btn nx-outline nx-full" type="button" :disabled="projectDefaultsSaveState === 'saving'" @click="saveProjectDefaults">
    {{ projectDefaultsSaveState === "saving" ? "Saving…" : projectDefaultsSaveState === "saved" ? "Saved to this video" : "Save to this video" }}
  </button>
  <p v-if="projectDefaultsSaveError" class="nx-err">{{ projectDefaultsSaveError }}</p>
</template>
