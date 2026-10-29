<script setup>
// A new character from the Media panel's character picker: a face to keep consistent across scenes.
import { useCore } from "./core";

const {
  createCharacterOpen, closeCharacterModal, createCharacterPreviewUrl, pickCharacterFile, clearCharacterFile,
  createCharacterName, createCharacterDescription, createCharacterError, createCharacterSaving, createCharacterFile, createCharacter,
} = useCore();
</script>

<template>
  <div v-if="createCharacterOpen" class="nx-modal-bg" @click.self="closeCharacterModal">
    <div class="nx-modal" role="dialog" aria-modal="true" aria-labelledby="nx-char-title">
      <h2 id="nx-char-title">New character</h2>
      <span class="nx-label">Photo <span class="nx-dim">(optional)</span></span>
      <label v-if="!createCharacterPreviewUrl" class="nx-drop">
        <input type="file" accept="image/*" hidden @change="pickCharacterFile" />
        <b>Upload a photo</b><span>PNG or JPG, up to 10 MB</span>
      </label>
      <div v-else class="nx-drop-preview">
        <img :src="createCharacterPreviewUrl" alt="" />
        <button class="nx-link" type="button" @click="clearCharacterFile">Remove</button>
      </div>
      <label class="nx-label" for="nx-char-name">Name</label>
      <input id="nx-char-name" v-model="createCharacterName" class="nx-input" placeholder="Marcus the detective" maxlength="120" />
      <label class="nx-label" for="nx-char-desc">Description <span class="nx-dim">(optional)</span></label>
      <textarea id="nx-char-desc" v-model="createCharacterDescription" class="nx-input" rows="3" maxlength="2000" placeholder="A weathered 50-year-old detective in a worn trench coat"></textarea>
      <p class="nx-hint">Used in every picture prompt for this character, so they look the same across scenes.</p>
      <p v-if="createCharacterError" class="nx-err">{{ createCharacterError }}</p>
      <div class="nx-row nx-end">
        <button class="nx-link" type="button" @click="closeCharacterModal">Cancel</button>
        <button class="nx-btn nx-primary" type="button" :disabled="createCharacterSaving" @click="createCharacter">
          {{ createCharacterSaving ? (createCharacterFile ? "Uploading…" : "Saving…") : "Create" }}
        </button>
      </div>
    </div>
  </div>
</template>
