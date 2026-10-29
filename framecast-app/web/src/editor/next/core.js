// The new editor's panels share the view's one useEditorCore() instance; a second call would start a second editor.
import { computed, inject, provide } from "vue";

const KEY = Symbol("editorCore");

export function provideEditorCore(core) {
  provide(KEY, core);
}

export function useCore() {
  const core = inject(KEY);
  if (!core) throw new Error("useCore() outside the new editor");
  return core;
}

// Prices shown on buttons, all from the server's /credit-costs (creditCosts). Null when it hasn't answered: the button
// then names no number rather than a wrong one.
export function usePrices(core) {
  const { creditCosts, activeCharacter, availableImageModelsEditor, aiImageModelKey, clonedVoices, voiceProfileKey, activeVoiceIsGemini } = core;

  // A character with a reference photo is priced the same on every model (CreditService::AI_CHARACTER).
  const characterPriced = computed(() => !!activeCharacter.value?.reference_asset);
  function imageModelCost(key) {
    if (characterPriced.value) return creditCosts.value.ai_character ?? null;
    return creditCosts.value.image_models?.[key]?.credits
      ?? availableImageModelsEditor.value.find((m) => m.key === key)?.cost
      ?? null;
  }
  const imageCost = computed(() => imageModelCost(aiImageModelKey.value));
  const imageEditCost = computed(() => creditCosts.value.image_edit ?? null);
  // Re-recording one scene: the engine follows the voice (cloned, Gemini, or OpenAI's standard voices).
  const voiceCost = computed(() => {
    const c = creditCosts.value;
    if ((clonedVoices.value || []).some((v) => v.provider_voice_key === voiceProfileKey.value)) return c.tts_clone ?? null;
    return activeVoiceIsGemini.value ? (c.tts ?? null) : (c.tts_standard ?? null);
  });

  return { characterPriced, imageModelCost, imageCost, imageEditCost, voiceCost };
}

export const cr = (n) => (n == null ? "" : ` · ${n} cr`);
