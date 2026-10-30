// The editor's brain: project state, saving, playback, generation and the assistant.
// Moved out of EditorView (2026-10-29) so the classic and the new editor share one set of behaviour;
// each view supplies only its own layout.
import { voiceDescription } from "../lib/voices.js";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter, onBeforeRouteLeave } from "vue-router";
import { editorReadiness } from "../lib/editorReadiness";
import api from "../services/api";
import { getEcho } from "../services/echo";
import { useAuthStore } from "../stores/auth";
import { useSidebarStore } from "../stores/sidebar";
import { useWorkspaceStore } from "../stores/workspace";
import { useExportActionGuard } from "../composables/useExportActionGuard";
import { exportToolbarState } from "../composables/exportToolbarState";
import { CAPTION_ANIMATIONS, animationByKey, panelRowAnimations, syntheticTimedWords } from "../composables/captionPresets";

export function useEditorCore() {
  const route = useRoute();
  const workspaceStore = useWorkspaceStore();
  const router = useRouter();
  const authStore = useAuthStore();
  const sidebarStore = useSidebarStore();

  const projectId = computed(() => route.params.projectId);
  const loading = ref(true);
  const error = ref("");
  const project = ref(null);
  const scenes = ref([]);

  // Cruise Control — Phase 1A scaffolding. Flips the right rail between the
  // existing accordion ("Config") and the chat shell ("Assistant"). LLM glue
  // lands in Phase 1B. cruiseAssistantPending is the blue dot on the
  // Assistant tab — fires when a resolve completes while the user is on
  // Config. Keyboard shortcut: Cmd/Ctrl+J toggles.
  const cruiseTab = ref('config')
  const cruiseAssistantPending = ref(false)
  function onCruiseKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'j') {
      e.preventDefault()
      cruiseTab.value = cruiseTab.value === 'config' ? 'assistant' : 'config'
      if (cruiseTab.value === 'assistant') cruiseAssistantPending.value = false
    }
  }

  // Cruise Control Phase 1B — chat state. Conversation is purely client-side
  // in 1B; persistence lands in 1C. Each message has:
  //   { id, role: 'user'|'assistant', text, action?, action_status?, action_credits? }
  const cruiseMessages = ref([])
  const cruiseInputText = ref('')
  const cruiseResolving = ref(false)
  const cruiseApplying = ref(null) // message id currently being applied
  const cruiseScopeSceneId = ref(null) // null = follow activeScene; otherwise explicit
  const cruiseChatScrollRef = ref(null)
  const cruisePulseSection = ref(null) // 'voice' | 'visual' | 'music' | 'motion' (drives the pulse animation)
  const cruiseToast = ref('')
  let cruiseToastTimer = null
  // Workspace-level pref — when true, confirmation_class='auto' tools
  // apply immediately on resolve without showing the Apply button.
  const cruiseAutoApply = ref(true)
  // Workspace-level Cruise prefs. Hydrated from /me. null = no bias
  // (LLM picks per turn). visual_source is special: 'auto' renders as
  // "no bias" but is the explicit on-the-wire sentinel.
  const cruisePrefs = ref({ image_model: null, animation_tier: null, visual_source: 'auto' })
  const cruisePrefsOpen = ref(false)

  // Project brief — the synthesised creative direction the assistant follows.
  // Editable + lockable; refreshable from the current scenes.
  const cruiseBrief = ref(null)        // { theme, topic, visual_style, tone, recurring_subject }
  const cruiseBriefLocked = ref(false)
  const cruiseBriefOpen = ref(false)
  const cruiseBriefBusy = ref(false)   // saving or refreshing
  const cruiseResetConfirmOpen = ref(false)
  const cruiseResetting = ref(false)
  // Frequently-used prompts shown above the input.
  const CRUISE_QUICK_PROMPTS = [
    // The assistant now sees each scene's pace, length and problems (2026-10-28), so a review is concrete.
    'review my video and fix what is weak',
    'make the hook stronger',
    'change voice on this scene',
    'use upbeat music',
    'swap visual to stock',
    'rewrite this script punchier',
    'animate this scene',
    'add a CTA scene',
  ]
  // Credits live at response.data.data.credits — sibling to user, not nested
  // inside it. We mirror them into a dedicated ref so the action card's
  // top-up gate reads truth. Set by loadMe() / refreshed after each apply.
  const cruiseCreditsPayload = ref(null)
  const cruiseUserBalance = computed(() => cruiseCreditsPayload.value?.balance ?? 0)

  const cruiseScopeLabel = computed(() => {
    // 'project' = explicit whole project; a scene id = that scene; null =
    // auto (follow the active scene, else whole project).
    if (cruiseScopeSceneId.value === 'project') return 'whole project'
    const id = cruiseScopeSceneId.value && cruiseScopeSceneId.value !== 'active'
      ? cruiseScopeSceneId.value
      : activeScene.value?.id
    const s = scenes.value.find((x) => x.id === id)
    return s ? `Scene ${s.scene_order}` : 'whole project'
  })

  // v-model for the scope dropdown. Reflects the EFFECTIVE scope (so when a
  // scene is active and scope is still auto, the dropdown truthfully shows
  // that scene). Writing 'project' pins whole project; a scene id pins it.
  const cruiseScopeValue = computed({
    get() {
      if (cruiseScopeSceneId.value === 'project') return 'project'
      if (cruiseScopeSceneId.value && cruiseScopeSceneId.value !== 'active') return String(cruiseScopeSceneId.value)
      return activeScene.value ? String(activeScene.value.id) : 'project'
    },
    set(val) {
      cruiseScopeSceneId.value = val === 'project' ? 'project' : Number(val)
    },
  })

  function cruiseEffectiveSceneId() {
    // What we actually send to the backend as scope_scene_id.
    if (cruiseScopeSceneId.value === 'project') return null
    if (cruiseScopeSceneId.value && cruiseScopeSceneId.value !== 'active') return cruiseScopeSceneId.value
    return activeScene.value?.id ?? null
  }

  function cruiseActionIcon(section) {
    return {
      voice: '🎤', visual: '🎨', music: '🎵', motion: '🎬',
      scene: '＋', script: '📝', captions: '💬', sounds: '🔊', brand: '🎨',
    }[section] ?? '✦'
  }
  function cruiseActionTitle(tool) {
    return {
      rerecord_voice: 'Re-record voice',
      change_voice_everywhere: 'Change narrator (whole video)',
      duplicate_scene: 'Duplicate scene',
      swap_visual_from_library: 'Swap visual',
      change_music: 'Regenerate music',
      regenerate_image: 'Regenerate image',
      animate_scene: 'Animate scene',
      add_scene: 'Add a new scene',
      lock_subject: 'Lock the look',
      find_stock_video: 'Find stock video',
      find_stock_image: 'Find stock image',
      pick_library_music: 'Pick library music',
      set_audiogram_visual: 'Set audiogram',
      update_scene_script: 'Edit script',
      reorder_scene: 'Reorder scene',
      update_captions: 'Update captions',
      apply_brand_kit: 'Apply brand kit',
      add_sound_effect: 'Add sound effect',
    }[tool] ?? tool
  }

  // Compact, model-facing note of what a prior assistant turn actually did,
  // keyed by scene, so the resolver doesn't repeat work or target the wrong
  // scene next turn. Uses raw tool names (the model knows them) + scene_order
  // AND id so it can match the SCENES list either way.
  function cruiseActionsSummary(msg) {
    const cards = msg.actions || []
    if (!cards.length) return ''
    const parts = cards.map((c) => {
      let target = 'project-level'
      if (c.params?.scene_id) {
        const s = scenes.value?.find?.((x) => x.id === c.params.scene_id)
        target = s ? `scene ${s.scene_order} (id ${c.params.scene_id})` : `scene id ${c.params.scene_id}`
      } else if (c.params?.position) {
        target = `new scene at position ${c.params.position}`
      }
      const st = (c.status === 'applied' || c.status === 'running') ? 'done'
        : c.status === 'skipped' ? 'skipped by user'
        : c.status === 'failed' ? 'failed'
        : 'proposed'
      return `${c.tool} → ${target} [${st}]`
    })
    return ` [actions already taken: ${parts.join('; ')}]`
  }

  async function cruiseSubmitIntent() {
    const text = cruiseInputText.value.trim()
    if (!text || cruiseResolving.value || !projectId.value) return
    // Optimistic push with a temp id; we replace with backend's id once
    // the response lands so apply() can reference the persisted message.
    const tempUserId = `u-tmp-${Date.now()}`
    cruiseMessages.value.push({ id: tempUserId, role: 'user', text })
    cruiseInputText.value = ''
    cruiseResolving.value = true
    await nextTick(); cruiseScrollChatToBottom()
    try {
      // Replay PRIOR ACTIONS, not just reply text. Without this the model is
      // amnesic about what it already did — it re-regenerated a scene it had
      // just regenerated, and leaked new-scene content onto an existing scene.
      // Appending a compact "[actions already taken …]" note per assistant
      // turn lets the resolver see which scene each tool already hit.
      const recent = cruiseMessages.value
        .slice(0, -1)
        .slice(-6)
        .map((m) => ({
          role: m.role === 'user' ? 'user' : 'assistant',
          text: m.role === 'user' ? m.text : `${m.text}${cruiseActionsSummary(m)}`,
        }))
      const res = await api.post('/cruise/resolve', {
        project_id: projectId.value,
        intent: text,
        scope_scene_id: cruiseEffectiveSceneId(),
        history: recent,
      })
      const data = res?.data?.data ?? {}
      // Replace the temp user message with the persisted id from backend.
      const tempIdx = cruiseMessages.value.findIndex((m) => m.id === tempUserId)
      if (tempIdx >= 0 && data.user_message_id) {
        cruiseMessages.value[tempIdx] = { ...cruiseMessages.value[tempIdx], id: data.user_message_id }
      }
      // Normalise to actions[] regardless of the backend response shape.
      // New backend always returns actions[]; older clients may still see
      // the singular 'action' on the persisted side.
      const rawActions = Array.isArray(data.actions)
        ? data.actions
        : (data.action ? [data.action] : [])
      const assistantMsg = {
        id: data.assistant_message_id ?? `a-${Date.now()}`,
        role: 'assistant',
        text: data.reply_to_user ?? 'Okay.',
        actions: rawActions.map((a) => cruiseInitActionState(a, 'proposed')),
        action_status: rawActions.length ? 'proposed' : null,
      }
      cruiseMessages.value.push(assistantMsg)
      if (cruiseTab.value !== 'assistant') cruiseAssistantPending.value = true
      // Fire-and-forget the auto-apply path for any low-risk actions.
      maybeAutoApply(assistantMsg)
    } catch (e) {
      const msg = e?.response?.data?.error?.message ?? 'Assistant is unavailable. Try again.'
      cruiseMessages.value.push({ id: `a-${Date.now()}`, role: 'assistant', text: msg })
    } finally {
      cruiseResolving.value = false
      await nextTick(); cruiseScrollChatToBottom()
    }
  }

  async function cruiseSkipAction(msg, actionIndex = 0) {
    const card = cruiseGetCard(msg, actionIndex)
    if (!card || card.status === 'skipped') return
    card.status = 'skipped'
    cruiseRecomputeMessageStatus(msg)
    try {
      await api.post('/cruise/skip', {
        project_id: projectId.value,
        message_id: msg.id,
        action_index: actionIndex,
      })
    } catch (_) { /* optimistic — refresh will reconcile */ }
  }

  // Skip every still-proposed card in this message in one click.
  async function cruiseSkipAll(msg) {
    const cards = msg.actions || []
    for (let i = 0; i < cards.length; i++) {
      if (cards[i].status === 'proposed') await cruiseSkipAction(msg, i)
    }
  }

  // Apply ONE card by index. Same flow as before but writes onto the
  // indexed card, not the message.
  async function cruiseApplyAction(msg, actionIndex = 0) {
    const card = cruiseGetCard(msg, actionIndex)
    if (!card || cruiseApplying.value) return
    cruiseApplying.value = `${msg.id}:${actionIndex}`
    try {
      const res = await api.post('/cruise/apply', {
        project_id: projectId.value,
        tool: card.tool,
        params: card.params,
        message_id: msg.id,
        action_index: actionIndex,
        // The price the card showed: the server refuses if the real price differs, so nothing is charged unseen.
        expected_credits: Number(card.estimated_cost ?? 0),
      })
      const out = res?.data?.data
      card.credits = out?.credits_spent ?? 0
      card.progress_text = cruiseInitialProgressText(card.tool)
      card.expected_stages = cruiseExpectedStages(card.tool, card.params)
      card.completed_stages = []
      card.affected_scene_id = out?.affected_scene_id ?? card.params?.scene_id ?? null
      card.status = cruiseToolHasAsyncWork(card.tool) ? 'running' : 'applied'
      cruiseRecomputeMessageStatus(msg)
      cruisePulseSection.value = out?.affected_section ?? null
      window.setTimeout(() => { cruisePulseSection.value = null }, 1800)
      cruiseShowToast(`${out?.summary} · spent ${out?.credits_spent ?? 0} cr`)
      loadMe?.()
      // Output tools hand off to a UI surface. schedule_post opens the composer
      // (the user still picks account + date + now/later/draft = consent).
      if (out?.navigate?.type === 'schedule') {
        await requestExportAction('schedule')
      } else if (out?.navigate?.type === 'export') {
        // Reflect the assistant-triggered export in the UI right away — the
        // Export button flips to "Exporting…" and the progress pill appears
        // without a page refresh (loadExportJobs picks up the new job; the
        // queued-export watcher starts the progress poll + websocket updates).
        queuedExportJobId.value = out.navigate.export_job_id ?? null
        try { await loadExportJobs() } catch (_) {}
      }
      // Structural tools shuffle MORE than the one affected scene — reorder
      // changes sibling orders + labels, add_scene shifts everything after the
      // insertion point. A single-scene fetch would leave siblings stale, so
      // re-pull the whole project (merges in place, keeps the active scene).
      if (cruiseToolTouchesProject(card.tool)) {
        // Re-pull the whole project (flicker-safe in-place merge) so swapped
        // visuals, library music, sound effects and reorders show immediately
        // — not just after a manual refresh. For async project tools
        // (change_music) this is a no-op until the job lands; the completion
        // handlers refresh again once it does.
        try { await refreshProjectPayload() } catch (_) {}
        // After the assistant undoes or redoes, the open scene's drafts reload from what was put back.
        // …unless the user is typing: then their text stays and the scene reloads when they switch to it.
        if (['undo_last_edit', 'redo_last_edit'].includes(card.tool) && !savesInFlight()) await resyncSceneDrafts();
      } else if (card.affected_scene_id) {
        // One-shot scene fetch so the editor picks up the in_progress flag
        // (regenerate_image / animate) or the new voice/script.
        try {
          const r = await api.get(`/scenes/${card.affected_scene_id}/preview`)
          const fresh = r?.data?.data?.scene
          if (fresh) replaceSceneInCollection(normalizeScenePayload(fresh))
        } catch (_) {}
      }
      if (card.status === 'running') {
        cruiseStartProgressLoop(card)
        cruiseStartPollingFallback(card)
      }
    } catch (e) {
      const err = e?.response?.data?.error
      if (err?.code === 'estimate_changed' && Number.isFinite(Number(err.estimated_credits))) {
        // Show the real price on the card and leave it ready to apply again.
        card.estimated_cost = Number(err.estimated_credits)
        card.notice = err.message
        card.status = 'proposed'
      } else {
        card.status = 'failed'
        card.error = err?.message ?? 'Apply failed.'
      }
      cruiseRecomputeMessageStatus(msg)
    } finally {
      cruiseApplying.value = null
    }
  }

  // Run every proposed card in this message back-to-back. Stops on the
  // first failure so the user can decide whether to retry / skip the rest.
  async function cruiseApplyAll(msg) {
    const cards = msg.actions || []
    for (let i = 0; i < cards.length; i++) {
      if (cards[i].status !== 'proposed') continue
      await cruiseApplyAction(msg, i)
      // Wait for the action to terminate (applied / failed / skipped).
      // Running actions complete async via Reverb / polling — we don't
      // block the queue waiting for the generation to finish, but we DO
      // wait for the apply call itself before firing the next one.
      // A card still proposed after applying had its price change: stop so the user sees the new price first.
      if (cards[i].status === 'failed' || cards[i].status === 'proposed') break
    }
  }

  function cruiseGetCard(msg, idx) {
    return (msg.actions && msg.actions[idx]) || null
  }

  function cruiseProposedCount(msg) {
    return (msg.actions || []).filter((c) => c.status === 'proposed').length
  }

  function cruiseTotalCost(msg) {
    return (msg.actions || []).reduce((sum, c) => sum + (c.estimated_cost ?? 0), 0)
  }

  // Disable "Apply all" when the user can't cover the combined cost.
  function cruiseCanApplyAll(msg) {
    const need = (msg.actions || [])
      .filter((c) => c.status === 'proposed')
      .reduce((sum, c) => sum + (c.estimated_cost ?? 0), 0)
    return cruiseUserBalance.value >= need
  }

  // Aggregate per-card statuses into the legacy message-level
  // action_status so older code paths (the Apply-tab pending dot,
  // rehydrate, etc.) keep working.
  function cruiseRecomputeMessageStatus(msg) {
    const statuses = (msg.actions || []).map((a) => a.status)
    if (statuses.includes('running')) { msg.action_status = 'running'; return }
    if (statuses.length && statuses.every((s) => s === 'applied' || s === 'skipped')) {
      msg.action_status = statuses.includes('failed') ? 'failed' : 'applied'
      return
    }
    if (statuses.includes('failed')) { msg.action_status = 'failed'; return }
    msg.action_status = statuses.includes('proposed') ? 'proposed' : null
  }

  // Rotating "Claude Code-style" progress text. Cycles every 2.5s while
  // the action is 'running' so the chat looks alive even between Reverb
  // events. Phrases are per-tool so the user sees something specific.
  const CRUISE_PROGRESS_PHRASES = {
    regenerate_image: [
      'Generating image…',
      'Composing the prompt…',
      'Painting pixels…',
      'Almost there…',
    ],
    animate_scene: [
      'Animating scene…',
      'Setting up motion…',
      'Rendering frames…',
      'Polishing the clip…',
    ],
    rerecord_voice: [
      'Generating voice…',
      'Warming up the model…',
      'Synthesising audio…',
      'Almost there…',
    ],
    change_music: [
      'Composing music…',
      'Picking a vibe…',
      'Mixing tracks…',
      'Almost there…',
    ],
    add_scene: [
      'Adding scene…',
      'Writing the script…',
      'Generating image…',
      'Recording voice…',
    ],
  }

  // All progress + polling now operates on a CARD (one entry from
  // msg.actions[]), not the message. Callers pass card; the card holds
  // its own status, progress_text, expected_stages, _progressTimer, etc.
  function cruiseStartProgressLoop(card) {
    if (card._progressTimer) return
    const phrases = CRUISE_PROGRESS_PHRASES[card.tool] || ['Working…']
    let i = 0
    card.progress_text = phrases[0]
    card._progressTimer = window.setInterval(() => {
      if (card.status !== 'running') { cruiseStopProgressLoop(card); return }
      // Skip rotating once Reverb has set a multi-stage status like
      // 'Voice ready · generating image…' — let the event truth win.
      if (card.progress_text && card.progress_text.includes('·')) return
      i = (i + 1) % phrases.length
      card.progress_text = phrases[i]
    }, 2500)
  }
  function cruiseStopProgressLoop(card) {
    if (card._progressTimer) {
      window.clearInterval(card._progressTimer)
      card._progressTimer = null
    }
  }

  // Polling fallback: every 5s while running, hit /scenes/{id}/preview
  // to detect completion. Reverb is primary; this catches misses (lost
  // websocket, ad blockers, network blip). Gives up after 5 minutes so a
  // truly-stuck job doesn't poll forever.
  const CRUISE_POLL_INTERVAL_MS = 5000
  // Must outlast the longest job a Cruise action can be waiting on —
  // AnimateSceneJob is 600s and GenerateTTSJob 900s. At five minutes this
  // marked work failed while the server was still running it, and the error
  // copy then invited a retry, which is how a customer bought the same piece
  // of music twice.
  const CRUISE_POLL_TIMEOUT_MS = 16 * 60 * 1000

  function cruiseStartPollingFallback(card) {
    if (card._pollTimer) return
    const sceneId = Number(card.affected_scene_id ?? card.params?.scene_id ?? 0)
    if (!sceneId) return
    const startedAt = Date.now()
    let attempts = 0
    const MAX_ATTEMPTS = Math.floor(CRUISE_POLL_TIMEOUT_MS / CRUISE_POLL_INTERVAL_MS) + 5
    // Snapshot the scene's pre-apply state so we can detect a real change.
    // For add_scene the scene didn't exist yet — baseline is "no asset",
    // any non-null asset id counts as a completion.
    const baselineScene = scenes.value?.find?.((s) => s.id === sceneId) || {}
    // Scene's per-stage asset locations:
    //   image     -> scene.visual_asset_id (top-level column)
    //   animation -> scene.image_generation_settings.animation_video_asset_id
    //   tts       -> scene.voice_settings.audio_asset_id (nested)
    // The frontend used to look at fresh.video_asset_id / fresh.audio_asset_id
    // which DO NOT EXIST on the scene model — TTS polling never finished.
    const baseline = {
      visual_asset_id:           baselineScene.visual_asset_id ?? null,
      animation_video_asset_id:  baselineScene.image_generation_settings?.animation_video_asset_id ?? null,
      audio_asset_id:            baselineScene.voice_settings?.audio_asset_id ?? null,
    }
    card._pollTimer = window.setInterval(async () => {
      if (card.status !== 'running') { cruiseStopPolling(card); return }
      attempts++
      if (attempts > MAX_ATTEMPTS || Date.now() - startedAt > CRUISE_POLL_TIMEOUT_MS) {
        // Deliberately not 'failed': we stopped watching, the server did not
        // necessarily stop working. Saying failed here invites a retry that can
        // pay for the same output twice.
        card.status = 'unknown'
        card.error = 'Still running after 16 minutes. Reopen this project shortly to see where it landed — do not re-run it yet, or you may be charged twice.'
        cruiseStopPolling(card)
        cruiseStopProgressLoop(card)
        return
      }
      try {
        const res = await api.get(`/scenes/${sceneId}/preview`)
        const freshRaw = res?.data?.data?.scene
        if (!freshRaw) return
        const fresh = normalizeScenePayload(freshRaw)
        const expected = card.expected_stages || []
        const completed = card.completed_stages || []
        const newlyDone = []
        const stageDoneByAsset = {
          ai_image:  fresh.visual_asset_id && fresh.visual_asset_id !== baseline.visual_asset_id,
          animation: fresh.image_generation_settings?.animation_video_asset_id
                     && fresh.image_generation_settings.animation_video_asset_id !== baseline.animation_video_asset_id,
          tts:       fresh.voice_settings?.audio_asset_id
                     && fresh.voice_settings.audio_asset_id !== baseline.audio_asset_id,
        }
        const stageFailedByState = {
          ai_image:  !!fresh.image_generation_settings?.last_error,
          animation: !!fresh.image_generation_settings?.animation_last_error,
          tts:       !!fresh.voice_settings?.last_error,
        }
        const failedStage = expected.find(stage => stageFailedByState[stage])
        if (failedStage) {
          card.status = 'failed'
          card.error = failedStage === 'animation'
            ? (fresh.image_generation_settings?.animation_last_error || 'Animation failed.')
            : failedStage === 'tts'
              ? (fresh.voice_settings?.last_error || 'Voice generation failed.')
              : (fresh.image_generation_settings?.last_error || 'Generation failed.')
          try { replaceSceneInCollection(normalizeScenePayload(fresh)) } catch (_) {}
          cruiseStopPolling(card)
          cruiseStopProgressLoop(card)
          return
        }
        for (const stage of expected) {
          if (!completed.includes(stage) && stageDoneByAsset[stage]) newlyDone.push(stage)
        }
        if (!newlyDone.length) return
        card.completed_stages = [...completed, ...newlyDone]
        const allDone = expected.every(s => card.completed_stages.includes(s))
        if (allDone) {
          card.status = 'applied'
          card.progress_text = cruiseSuccessText(card.tool, null)
          try { replaceSceneInCollection(normalizeScenePayload(fresh)) } catch (_) {}
          if (cruiseToolTouchesProject(card.tool)) refreshProjectPayload().catch(() => {})
          cruiseStopPolling(card)
          cruiseStopProgressLoop(card)
        } else {
          card.progress_text = cruiseStagePendingText(card.tool, expected, card.completed_stages)
          try { replaceSceneInCollection(normalizeScenePayload(fresh)) } catch (_) {}
        }
      } catch (_) { /* keep polling */ }
    }, CRUISE_POLL_INTERVAL_MS)
  }
  function cruiseStopPolling(card) {
    if (card._pollTimer) {
      window.clearInterval(card._pollTimer)
      card._pollTimer = null
    }
  }

  // Per-action state used by every render path (chat card, polling,
  // progress checklist). raw = the {tool, params, diff_lines, ...} blob
  // from the backend. We layer per-card status fields on top so the
  // frontend can drive each action independently in a multi-action turn.
  function cruiseInitActionState(raw, status = 'proposed') {
    return {
      ...raw,
      status,                    // 'proposed' | 'running' | 'applied' | 'failed' | 'skipped'
      credits: 0,                // populated on apply
      progress_text: null,       // single-line spinner phrase
      expected_stages: [],       // filled at apply time
      completed_stages: [],
      affected_scene_id: null,
      error: null,
    }
  }

  // Tools whose work is synchronous (no GenerationProgressed event will
  // fire) — stamp 'applied' immediately. Everything else dispatches a job
  // and we wait for the Reverb 'completed' event before stamping ✓.
  function cruiseToolHasAsyncWork(tool) {
    return [
      'regenerate_image', 'animate_scene', 'rerecord_voice', 'change_voice_everywhere',
      'update_scene_script', 'change_music', 'add_scene',
    ].includes(tool)
  }

  // Tools whose effect the single-scene preview fetch can't reliably surface,
  // so the post-apply (and post-completion) refresh must re-pull the whole
  // project. Two reasons:
  //   - structural: reorder shuffles siblings, add_scene shifts everything after.
  //   - asset/project-level: music lives on the PROJECT (no scene_id), and
  //     swapped library/stock visuals + sound effects bind assets the scene
  //     preview's asset map doesn't always include yet. These were the changes
  //     that "only took effect after a manual refresh".
  function cruiseToolTouchesProject(tool) {
    return [
      'reorder_scene', 'add_scene',                          // structural
      'swap_visual_from_library', 'find_stock_video', 'find_stock_image',
      'pick_library_music', 'change_music', 'add_sound_effect',
      'apply_brand_kit',                                      // asset / project-level
      'lock_subject',                                         // binds a character + regenerates several scenes
      'undo_last_edit', 'redo_last_edit',                     // the edit history: any scene or setting may change
    ].includes(tool)
  }

  // Async stages we expect each tool to fire, so we know not to mark
  // 'applied' until ALL of them complete. add_scene dispatches both an
  // image and a voice job; chained regenerate_image fires ai_image AND
  // then animation. Without this, the chat said "Voice ready" while the
  // image was still cooking.
  function cruiseExpectedStages(tool, params) {
    const isChained = !!params?.chain_animate_tier
    switch (tool) {
      case 'regenerate_image': return isChained ? ['ai_image', 'animation'] : ['ai_image']
      case 'animate_scene':    return ['animation']
      case 'rerecord_voice':   return ['tts']
      case 'change_voice_everywhere': return ['tts']
      case 'update_scene_script': return ['tts']
      case 'change_music':     return ['ai_music']
      case 'add_scene': {
        // animate_tier is the add_scene-specific param name (different from
        // regenerate_image's chain_animate_tier). When set, the image job
        // dispatches an animation job after the image lands — we need to
        // wait on both stages before stamping the card applied.
        const stages = ['ai_image', 'tts']
        if (params?.animate_tier) stages.push('animation')
        return stages
      }
      default:                 return []
    }
  }

  // Progress text for a partial-completion (one stage done, others
  // pending). "Voice ready · generating image…" reads better than
  // flipping to green ✓ before the work is done.
  function cruiseStagePendingText(tool, expected, completed) {
    const pending = expected.filter(s => !completed.includes(s))
    if (!pending.length) return cruiseSuccessText(tool, null)
    const doneLabel = completed.map(s => ({
      ai_image: 'Image ready', animation: 'Animation ready',
      tts: 'Voice ready', ai_music: 'Music ready',
    }[s] || s)).join(', ')
    const pendingLabel = pending.map(s => ({
      ai_image: 'generating image', animation: 'animating',
      tts: 'recording voice', ai_music: 'composing music',
    }[s] || s)).join(' + ')
    return `${doneLabel} · ${pendingLabel}…`
  }

  // First line shown the instant Apply lands, before any event fires.
  function cruiseInitialProgressText(tool) {
    switch (tool) {
      case 'regenerate_image':  return 'Generating image…'
      case 'animate_scene':     return 'Animating scene…'
      case 'rerecord_voice':    return 'Generating voice…'
      case 'change_voice_everywhere': return 'Re-recording every scene…'
      case 'duplicate_scene':   return 'Duplicating scene…'
      case 'update_scene_script': return 'Generating voice…'
      case 'change_music':      return 'Composing music…'
      case 'add_scene':         return 'Adding scene…'
      default:                  return 'Working…'
    }
  }

  // Match an incoming generation.progress event to the most recent cruise
  // message whose action covers this work. Stage→tool map:
  //   ai_image -> regenerate_image | add_scene
  //   animation -> animate_scene (or chained regenerate_image)
  //   tts -> rerecord_voice | add_scene
  //   ai_music -> change_music
  // Scene scoping: when payload.scene_id is set, prefer a message whose
  // action.params.scene_id matches. Falls back to the most recent matching
  // tool when scene_id is absent from the event (e.g. ai_image 'processing'
  // hasn't been dispatched with scene_id).
  // Returns {msg, card} when the event belongs to a cruise card that's
  // running/proposed; null otherwise. Walks every message's actions[]
  // because a single message can carry multiple in-flight cards.
  function cruiseFindCardForProgress(payload) {
    const stage = payload.stage
    const sceneId = payload.scene_id ? Number(payload.scene_id) : null
    const stageTools = {
      ai_image:  ['regenerate_image', 'add_scene'],
      // add_scene with animate_tier chains into AnimateSceneJob too, so an
      // 'animation' event can legitimately belong to an add_scene card.
      animation: ['animate_scene', 'regenerate_image', 'add_scene'],
      tts:       ['rerecord_voice', 'update_scene_script', 'add_scene'],
      ai_music:  ['change_music'],
    }[stage] || []
    if (!stageTools.length) return null
    for (let i = cruiseMessages.value.length - 1; i >= 0; i--) {
      const m = cruiseMessages.value[i]
      if (!Array.isArray(m.actions) || !m.actions.length) continue
      // Newest action first inside a message so the most-recently-Applied
      // card wins on ambiguous matches (e.g. two regenerate_image cards
      // in one turn, only one in flight).
      for (let j = m.actions.length - 1; j >= 0; j--) {
        const card = m.actions[j]
        if (!stageTools.includes(card.tool)) continue
        if (card.status !== 'running') continue
        if (sceneId) {
          const cardSceneId = Number(card.affected_scene_id ?? card.params?.scene_id ?? 0)
          if (cardSceneId && cardSceneId !== sceneId) continue
        }
        return { msg: m, card }
      }
    }
    return null
  }

  // Drive a running CARD's progress text + checklist from the Reverb
  // event. Resolves to {msg, card} via cruiseFindCardForProgress so each
  // in-flight card in a multi-action turn updates independently.
  function cruiseHandleProgressEvent(payload) {
    const found = cruiseFindCardForProgress(payload)
    if (!found) return
    const { msg, card } = found
    const status = String(payload.status || '')
    if (status === 'processing') {
      card.progress_text = card.completed_stages?.length
        ? cruiseStagePendingText(card.tool, card.expected_stages || [], card.completed_stages)
        : cruiseInitialProgressText(card.tool)
      return
    }
    if (status === 'completed') {
      const expected = card.expected_stages || []
      const completed = card.completed_stages || []
      if (!completed.includes(payload.stage)) completed.push(payload.stage)
      card.completed_stages = completed
      const allDone = expected.length > 0 && expected.every(s => completed.includes(s))
      if (allDone) {
        card.status = 'applied'
        card.progress_text = cruiseSuccessText(card.tool, payload.stage)
        cruiseStopProgressLoop(card)
        cruiseStopPolling(card)
        cruiseRecomputeMessageStatus(msg)
        // Project-level results (e.g. change_music) aren't on any scene the
        // poller watches, so nothing else would pull them in — refresh here.
        if (cruiseToolTouchesProject(card.tool)) refreshProjectPayload().catch(() => {})
      } else {
        card.progress_text = cruiseStagePendingText(card.tool, expected, completed)
      }
      return
    }
    if (status === 'failed') {
      card.status = 'failed'
      card.error = payload.message || 'Generation failed.'
      cruiseStopProgressLoop(card)
      cruiseStopPolling(card)
      cruiseRecomputeMessageStatus(msg)
    }
  }

  // Stage label for the bullet checklist. "done" form strikes through
  // past-tense; "pending" form is present participle so the user sees
  // what's happening right now.
  function cruiseStageLabel(stage, done) {
    const labels = {
      ai_image:  done ? 'Image generated'    : 'Generating image',
      animation: done ? 'Animation rendered' : 'Animating scene',
      tts:       done ? 'Voice recorded'     : 'Recording voice',
      ai_music:  done ? 'Music composed'     : 'Composing music',
    }
    return labels[stage] ?? stage
  }

  function cruiseSuccessText(tool, stage) {
    if (stage === 'ai_image')  return 'Image ready'
    if (stage === 'animation') return 'Animation ready'
    if (stage === 'tts')       return 'Voice ready'
    if (stage === 'ai_music')  return 'Music ready'
    // Fallback for polling path (no stage)
    switch (tool) {
      case 'regenerate_image': return 'Image ready'
      case 'animate_scene':    return 'Animation ready'
      case 'rerecord_voice':   return 'Voice ready'
      case 'update_scene_script': return 'Voice ready'
      case 'change_music':     return 'Music ready'
      case 'add_scene':        return 'Scene added'
      default:                 return 'Done'
    }
  }

  function cruiseShowToast(text) {
    cruiseToast.value = text
    if (cruiseToastTimer) window.clearTimeout(cruiseToastTimer)
    cruiseToastTimer = window.setTimeout(() => { cruiseToast.value = '' }, 4500)
  }

  function cruiseScrollChatToBottom() {
    const el = cruiseChatScrollRef.value
    if (el) el.scrollTop = el.scrollHeight
  }

  // Load the persisted conversation for this project. Fires after the
  // editor knows its project_id (loadProject succeeds).
  async function loadCruiseConversation() {
    if (!projectId.value) return
    try {
      const res = await api.get(`/cruise/conversation/${projectId.value}`)
      const data = res?.data?.data ?? {}
      cruiseBrief.value = data.brief ?? null
      cruiseBriefLocked.value = !!data.brief_locked
      const msgs = data.messages
      if (Array.isArray(msgs) && msgs.length) {
        cruiseMessages.value = msgs.map((m) => {
          // Backwards compat: older messages stored a single action; new
          // ones store actions[]. Promote singular to array on hydrate.
          const rawActions = Array.isArray(m.actions) && m.actions.length
            ? m.actions
            : (m.action ? [{ ...m.action, status: m.action_status ?? 'proposed', credits: m.action_credits ?? 0, affected_scene_id: m.affected_scene_id ?? null }] : [])
          const out = {
            id: m.id,
            role: m.role,
            text: m.text,
            actions: rawActions.map((a) => {
              const card = cruiseInitActionState(a, a.status ?? 'proposed')
              // Restore the bits that drive hydrate verification.
              card.credits = a.credits ?? 0
              card.affected_scene_id = a.affected_scene_id ?? null
              card.expected_stages = Array.isArray(a.expected_stages) ? a.expected_stages : []
              card.completed_stages = Array.isArray(a.completed_stages) ? a.completed_stages : []
              card.error = a.error ?? null
              return card
            }),
          }
          cruiseRecomputeMessageStatus(out)
          return out
        })
        await nextTick(); cruiseScrollChatToBottom()
        // Resume polling for anything that's still cooking on the backend.
        // Runs after the messages array is committed so the message refs
        // we hand to cruiseStartPollingFallback are reactive.
        await cruiseResumeInflightAfterHydrate()
      }
    } catch { /* silent — empty conversation is fine */ }
  }

  // --- Project brief (creative direction the assistant follows) ---
  function cruiseToggleBrief() {
    if (!cruiseBrief.value) {
      cruiseBrief.value = { theme: null, topic: null, visual_style: null, tone: null, recurring_subject: null }
    }
    cruiseBriefOpen.value = !cruiseBriefOpen.value
    // Theme + Settings are mutually exclusive — opening one closes the other.
    if (cruiseBriefOpen.value) cruisePrefsOpen.value = false
  }

  function cruiseTogglePrefs() {
    cruisePrefsOpen.value = !cruisePrefsOpen.value
    if (cruisePrefsOpen.value) cruiseBriefOpen.value = false
  }

  async function cruiseSaveBrief() {
    if (!projectId.value || cruiseBriefBusy.value) return
    cruiseBriefBusy.value = true
    try {
      const b = cruiseBrief.value || {}
      const res = await api.patch(`/cruise/brief/${projectId.value}`, {
        theme: b.theme ?? null,
        topic: b.topic ?? null,
        visual_style: b.visual_style ?? null,
        tone: b.tone ?? null,
        recurring_subject: b.recurring_subject ?? null,
        locked: true,
      })
      cruiseBrief.value = res?.data?.data?.brief ?? cruiseBrief.value
      cruiseBriefLocked.value = !!res?.data?.data?.brief_locked
      cruiseShowToast('Brief saved · the assistant will follow it')
    } catch (_) { cruiseShowToast('Could not save the brief.') }
    finally { cruiseBriefBusy.value = false }
  }

  async function cruiseRefreshBrief() {
    if (!projectId.value || cruiseBriefBusy.value) return
    cruiseBriefBusy.value = true
    try {
      const res = await api.post(`/cruise/brief/${projectId.value}/refresh`)
      cruiseBrief.value = res?.data?.data?.brief ?? cruiseBrief.value
      cruiseBriefLocked.value = !!res?.data?.data?.brief_locked
    } catch (_) { cruiseShowToast('Could not refresh the brief.') }
    finally { cruiseBriefBusy.value = false }
  }

  // Wipe the chat thread to start over. Project changes are untouched
  // (those are reverted per-action via Undo). Opens a confirm modal first.
  function cruiseResetConversation() {
    if (!projectId.value) return
    cruiseResetConfirmOpen.value = true
  }

  async function cruiseConfirmReset() {
    if (!projectId.value || cruiseResetting.value) return
    cruiseResetting.value = true
    try {
      await api.post(`/cruise/conversation/${projectId.value}/reset`)
      cruiseMessages.value = []
      cruiseAssistantPending.value = false
      cruiseResetConfirmOpen.value = false
      cruiseShowToast('Started a new chat')
    } catch (_) { cruiseShowToast('Could not reset the chat.') }
    finally { cruiseResetting.value = false }
  }

  // Undoable = an applied card whose tool we can revert (all except
  // apply_brand_kit). Undo restores prior state; it does NOT refund credits.
  function cruiseCanUndo(card) {
    return card?.status === 'applied' && card.tool !== 'apply_brand_kit'
  }

  async function cruiseUndoAction(msg, actionIndex = 0) {
    const card = cruiseGetCard(msg, actionIndex)
    if (!card || card._undoing) return
    card._undoing = true
    try {
      await api.post('/cruise/undo', {
        project_id: projectId.value,
        message_id: msg.id,
        action_index: actionIndex,
      })
      card.status = 'undone'
      cruiseRecomputeMessageStatus(msg)
      cruiseShowToast('Undone')
      try { await refreshProjectPayload() } catch (_) {}
      loadMe?.()
    } catch (e) {
      cruiseShowToast(e?.response?.data?.error?.message ?? 'Could not undo that.')
    } finally {
      card._undoing = false
    }
  }

  // Map a raw provider/exception string to a calm, user-facing line. The raw
  // text itself is only shown to admins (see the failed-card template), so
  // regular users never see "cURL error 28 …" and friends.
  function cruiseFriendlyError(raw) {
    const s = String(raw || '').toLowerCase()
    if (!s) return 'Something went wrong. Tap Retry to try again.'
    if (s.includes('timed out') || s.includes('timeout') || s.includes('error 28')) {
      return 'That took too long to generate — the provider was slow. Check the scene before retrying: if the result already landed, retrying pays for it again.'
    }
    // Match real rate-limit phrases only — NOT the bare substring "rate",
    // which also matches "gene-rate"/"generation" and mislabeled ordinary
    // generation errors as rate limits.
    if (s.includes('rate limit') || s.includes('rate-limit') || s.includes('ratelimit')
        || s.includes('rate_limited') || s.includes('429') || s.includes('too many')
        || s.includes('quota') || s.includes('resource_exhausted') || s.includes('resource exhausted')) {
      return "We're being rate-limited right now — wait a moment, then tap Retry."
    }
    if (s.includes('policy') || s.includes('safety') || s.includes('moderation') || s.includes('nsfw') || s.includes('content filter')) {
      return 'That was blocked by the content filter — try rephrasing the prompt.'
    }
    if (s.includes('insufficient') || s.includes('credit') || s.includes('402')) {
      return 'Not enough credits to finish this — top up and try again.'
    }
    return 'Something went wrong generating this. Tap Retry to try again.'
  }

  // Re-run a failed action: re-POST apply (re-dispatches the job); the card
  // flows back through running -> applied/failed like a fresh apply.
  async function cruiseRetryAction(msg, actionIndex = 0) {
    const card = cruiseGetCard(msg, actionIndex)
    if (!card || card._retrying || cruiseApplying.value) return
    card._retrying = true
    card.error = null
    try {
      await cruiseApplyAction(msg, actionIndex)
    } finally {
      card._retrying = false
    }
  }

  // On hydrate, walk every applied card with an affected_scene_id and
  // verify the scene actually has the assets the action was supposed to
  // produce. If anything's still pending, flip the card back to 'running'
  // and resume the polling fallback + spinner. This is what makes a page
  // refresh mid-generation pick up where the user left off.
  async function cruiseResumeInflightAfterHydrate() {
    // Dedup: one scene fetch per affected_scene_id even if multiple
    // cards share it.
    const toCheck = new Map()
    for (const m of cruiseMessages.value) {
      for (const card of (m.actions || [])) {
        if (!['running', 'applied'].includes(card.status)) continue
        if (!card.affected_scene_id) continue
        const expected = cruiseExpectedStages(card.tool, card.params)
        if (!expected.length) continue
        // Cache scene fetch by id; pin (msg, card, expected) for processing.
        const bucket = toCheck.get(card.affected_scene_id) || []
        bucket.push({ msg: m, card, expected })
        toCheck.set(card.affected_scene_id, bucket)
      }
    }
    if (!toCheck.size) return
    await Promise.all([...toCheck.entries()].map(async ([sceneId, items]) => {
      try {
        const res = await api.get(`/scenes/${sceneId}/preview`)
        const sceneRaw = res?.data?.data?.scene
        if (!sceneRaw) return
        const scene = normalizeScenePayload(sceneRaw)
        const stageDone = {
          ai_image:  !!scene.visual_asset_id && !scene.image_generation_settings?.in_progress,
          animation: !!scene.image_generation_settings?.animation_video_asset_id
                     && !scene.image_generation_settings?.animation_in_progress,
          tts:       !!scene.voice_settings?.audio_asset_id
                     && !scene.voice_settings?.is_outdated,
          ai_music:  true, // project-level, can't verify from scene
        }
        const stageFailed = {
          ai_image:  !!scene.image_generation_settings?.last_error,
          animation: !!scene.image_generation_settings?.animation_last_error,
          tts:       !!scene.voice_settings?.last_error,
          ai_music:  false,
        }
        for (const { msg, card, expected } of items) {
          const failedStage = expected.find(s => stageFailed[s])
          if (failedStage) {
            card.status = 'failed'
            card.error = failedStage === 'animation'
              ? (scene.image_generation_settings?.animation_last_error || 'Animation failed.')
              : failedStage === 'tts'
                ? (scene.voice_settings?.last_error || 'Voice generation failed.')
              : (scene.image_generation_settings?.last_error || 'Generation failed.')
            cruiseStopProgressLoop(card)
            cruiseStopPolling(card)
            cruiseRecomputeMessageStatus(msg)
            continue
          }

          const allDone = expected.every(s => stageDone[s])
          if (allDone) {
            card.status = 'applied'
            card.completed_stages = [...expected]
            card.progress_text = cruiseSuccessText(card.tool, expected[expected.length - 1] || null)
            cruiseStopProgressLoop(card)
            cruiseStopPolling(card)
            cruiseRecomputeMessageStatus(msg)
            continue
          }
          // Still cooking. Flip back to running, record what's already
          // done, kick off progress loop + polling so the user sees it
          // resume even though Reverb missed the last event.
          card.status = 'running'
          card.expected_stages = expected
          card.completed_stages = expected.filter(s => stageDone[s])
          card.progress_text = card.completed_stages.length
            ? cruiseStagePendingText(card.tool, expected, card.completed_stages)
            : cruiseInitialProgressText(card.tool)
          cruiseStartProgressLoop(card)
          cruiseStartPollingFallback(card)
          cruiseRecomputeMessageStatus(msg)
        }
      } catch (_) { /* silent — leave card as 'applied' */ }
    }))
  }

  // Pulse a Config panel-section after an apply. The CSS class
  // .panel-section.cruise-pulse drives the animation.
  const cruisePulseClass = (sectionKey) => cruisePulseSection.value === sectionKey ? 'cruise-pulse' : ''
  const hookOptions = ref([]);
  const mePayload = ref(null);
  // Costs come from the server. Typing them into the template is how the music
  // panel came to advertise three credits on one line, five on the next, and
  // charge two.
  const creditCosts = ref({});
  async function loadCreditCosts() {
    try {
      const res = await api.get('/credit-costs');
      creditCosts.value = res.data?.data?.costs ?? {};
    } catch {
      // Leave it empty; the labels fall back to naming no number at all rather
      // than naming a wrong one.
    }
  }
  const aiMusicCost = computed(() => creditCosts.value.ai_music ?? null);
  const isAdmin = computed(() => ["super_admin", "platform_admin"].includes(mePayload.value?.role ?? authStore.user?.role));
  const activeSceneId = ref(null);
  const notificationDrawerOpen = ref(false);
  const notifications = ref([]);
  const notificationToasts = ref([]);
  const exportJobs      = ref([]);
  const scheduleModalOpen = ref(false);
  const deliveryExport = ref(null);

  // Approval link state
  const approvalModalOpen = ref(false);
  const approvalForm = ref({ email: '', name: '', message: '', expires_in_days: 7 });
  const approvalSubmitting = ref(false);
  const approvalError = ref('');
  const approvalCreated = ref(null); // { public_url, expires_at, reviewer_email }
  async function submitApproval() {
    if (approvalSubmitting.value) return;
    if (!approvalForm.value.email) { approvalError.value = 'Reviewer email is required.'; return; }
    approvalSubmitting.value = true;
    approvalError.value = '';
    try {
      const res = await api.post('/approvals', {
        project_id:      Number(route.params.projectId),
        export_job_id:   deliveryExport.value?.id ?? latestExportJob.value?.id ?? null,
        reviewer_email:  approvalForm.value.email.trim(),
        reviewer_name:   approvalForm.value.name.trim() || null,
        comment:         approvalForm.value.message.trim() || null,
        expires_in_days: approvalForm.value.expires_in_days,
      });
      approvalCreated.value = {
        public_url:     res.data?.data?.public_url,
        approval:       res.data?.data?.approval,
        warning:        res.data?.warning ?? null,
      };
    } catch (err) {
      approvalError.value = err?.response?.data?.error?.message || 'Could not send approval link.';
    } finally {
      approvalSubmitting.value = false;
    }
  }
  function closeApprovalModal() {
    approvalModalOpen.value = false;
    approvalForm.value = { email: '', name: '', message: '', expires_in_days: 7 };
    approvalError.value = '';
    approvalCreated.value = null;
  }
  function copyApprovalUrl() {
    if (!approvalCreated.value?.public_url) return;
    navigator.clipboard?.writeText(approvalCreated.value.public_url);
  }
  let workspaceChannelName = null;

  const audioRef = ref(null);
  const musicAudioRef = ref(null);
  const soundAudioRef = ref(null);
  const musicAuditionRef = ref(null);
  const isAudioPlaying = ref(false);
  const isAudioLoading = ref(false);
  // Music has no preload cache (it's project-level, loaded by the live <audio>),
  // so we track its readiness here. true when no music or it can play.
  const musicReady = ref(true);
  // Set when the user hits play before the scene's assets finish loading — we
  // show the loading overlay and auto-start once everything's ready.
  const pendingScenePlay = ref(false);
  const auditionMusicTrackId = ref(null);
  const currentVisualUrl = ref(null);
  const previewVideoRef = ref(null);
  const previewVideoBackgroundRef = ref(null);
  const previewVideoDuration = ref(0);
  const visualLoadFailed = ref(false);
  const mediaCache = ref({
    visual: {},
    audio: {},
  });
  const mediaPreloaders = new Map();
  const MEDIA_ELEMENT_CROSS_ORIGIN = "anonymous";
  const API_ORIGIN = new URL(api.defaults.baseURL, window.location.origin).origin;

  const addScenePanelPosition = ref("");
  const selectedSceneType = ref("Narration");
  const selectedAddSceneVisualSource = ref("Stock Clip");
  // '' = no source picked yet (UI shows tabs without one pre-selected, scene
  // gets created with visual_type='placeholder' so nothing is auto-attached).
  // User has to actively click a tab — Video / Image / AI / Assets — before
  // the scene picks up that source.
  const addSceneVisualMode = ref("");
  const addSceneStockSubType = ref("stock_clip");
  const addSceneVisualStyle = ref(null);
  const addSceneCustomVisualStyle = ref("");
  const addSceneVisualQuery = ref("");
  // Asset picked via the MediaPickerModal while the Assets tab is active.
  // Cleared on cancel/reset; consumed when createScene() builds the POST.
  const addScenePickedAsset = ref(null);
  // Optional per-scene character (used in AI mode for an ad-hoc reference image).
  // Null = no character bound; visual brief generates without a reference.
  const addSceneCharacterId = ref(null);
  // Drives the rich character picker (thumbnail grid) inside the add-scene panel —
  // mirrors `characterPopoverOpen` on the right-hand scene panel.
  const addSceneCharPopoverOpen = ref(false);
  const selectedSwapVisualSource = ref("Stock Clip");
  const newSceneScript = ref("");
  const rewriteToolsVisible = ref(false); // rewrite section collapsed by default
  const rewritePreviewVisible = ref(false);
  const rewritePreviewCopy = ref("");
  const rewriteCustomInstruction = ref("");
  const rewriteMode = ref("");
  const rewritePending = ref(false);
  const rewriteApplyPending = ref(false);
  const rewriteError = ref("");
  const sceneDurationDraft = ref("");
  const sceneDurationSaving = ref(false);
  const sceneDurationError = ref("");
  const captionPresets = ref([]);
  const captionPresetSaveOpen = ref(false);
  const captionPresetSaveName = ref("");
  const captionPresetSaving = ref(false);
  const captionPresetDeleting = ref(null);
  const voiceProfileSaveOpen = ref(false);
  const voiceProfileSaveName = ref("");
  const voiceProfileSaving = ref(false);
  const voiceRegeneratePending = ref(false);
  const voiceRegenerateError = ref("");
  const addSceneGeneratePending = ref(false);
  const addSceneGenerateError = ref("");
  const sceneReorderPendingId = ref(null);
  const deleteScenePending = ref(false);
  const deleteSceneTarget = ref(null);
  const voiceProfiles = ref([]);
  const channels = ref([]);
  const brandKits = ref([]);
  const projectChannelId = ref("");
  const projectBrandKitId = ref("");
  const projectDefaultsSaveState = ref("idle");
  const projectDefaultsSaveError = ref("");
  const musicTracks = ref([]);
  const selectedMusicTrackId = ref(null);
  // My Asset image browser
  const myImageAssets = ref([]);
  const myImageSearch = ref('');
  const myImageLoading = ref(false);
  // Custom audio picker
  const customAudioAssets = ref([]);
  const customAudioSearch = ref('');
  const customAudioLoading = ref(false);
  // Voice tab + upload / recording state
  const voiceTab = ref('default'); // 'default' | 'custom'
  const voiceUploadStatus = ref('idle'); // idle | previewing | uploading | transcribing | ready | error
  const voicePreviewBlob = ref(null);
  const voicePreviewUrl = ref(null);
  const voicePreviewName = ref('');
  const voicePreviewRef = ref(null);
  const voicePreviewPlaying = ref(false);
  const voicePreviewCurrent = ref(0);
  const voicePreviewDuration = ref(0);
  const voiceUploadAsset = ref(null);
  const voiceUploadError = ref('');
  const voiceRecording = ref(false);
  const voiceRecorder = ref(null);
  const voiceRecordedChunks = ref([]);
  const voiceRecordSeconds = ref(0);
  let voiceRecordTimer = null;
  let voiceTranscribePoller = null;
  const mediaPickerVisible = ref(false);
  const mediaPickerMode = ref("visual"); // 'visual' | 'music' | 'sound'
  // When the add-scene panel opened the picker, route selections into the
  // add-scene buffer instead of assigning to the currently-active scene.
  const addSceneAssetPickerActive = ref(false);
  const musicPanelTab = ref("library"); // 'library' | 'uploads' | 'ai'

  // AI music regen — calls /scenes/{id}/regenerate-music which fires
  // GenerateAIMusicJob and swaps the scene's sound_asset_id when complete.
  const aiMusicMood = ref("");
  const aiMusicPending = ref(false);
  const aiMusicError = ref("");
  const AI_MUSIC_MOODS = [
    "calm acoustic",
    "upbeat indie pop",
    "cinematic ambient",
    "lo-fi chill",
    "tense electronic",
    "inspiring orchestral",
    "warm folk",
    "energetic synth",
    "hopeful piano",
  ];

  async function regenerateAIMusic() {
    if (!activeScene.value?.id || aiMusicPending.value) return;
    const mood = aiMusicMood.value.trim();
    if (!mood) return;
    aiMusicPending.value = true;
    aiMusicError.value = "";
    try {
      await api.post(`/scenes/${activeScene.value.id}/regenerate-music`, {
        mood,
        duration_seconds: Math.max(3, Math.min(30, Math.round(activeScene.value.duration_seconds || 8))),
      });
      // pollSceneUntilVisual watches the scene's image_generation state
      // (in_progress / animation_in_progress / visual_asset) — not music.
      // Music lands on project.music_asset_id, so spawn a parallel poller
      // that refreshes the project + music-tracks list until the id flips.
      const beforeId = project.value?.music_asset_id ?? null;
      pollProjectMusicUntilNew(beforeId);
      pushToast({ id: `ai-music-queued-${activeScene.value.id}-${Date.now()}`,
                  title: '✦ Generating music', message: `Mood: "${mood}". Ready in ~30s.` });
    } catch (e) {
      aiMusicError.value = e.response?.data?.error?.message ?? 'Music generation failed.';
      aiMusicPending.value = false;
    }
    // NOTE: don't clear aiMusicPending here — the music job is async.
    // pollProjectMusicUntilNew clears it when the new music_asset_id lands
    // (or after the timeout). Clearing here would re-enable the Generate
    // button while the job is still running.
  }

  // Poll the project until music_asset_id changes from its pre-regen value
  // (or we hit the ceiling). When it changes: refresh musicTracks so the
  // new "AI Music — …" track appears in the picker, point the active
  // selection at it, and toast the user.
  //
  // The ceiling has to outlast GenerateAIMusicJob's own 240s timeout. It used to
  // stop at ~140s, so a slow-but-successful run was reported as a failure while
  // the server was still working: a customer was told his music had failed,
  // retried as invited, and bought the same track twice. Stopping before the
  // server does turns every slow success into a double charge.
  //
  // 4 x 2.5s + 56 x 5s = 290s, comfortably past the job's timeout.
  async function pollProjectMusicUntilNew(beforeId, attempt = 0) {
    const MAX = 60;
    if (attempt >= MAX) {
      aiMusicPending.value = false;
      // Not a failure, and deliberately not an invitation to retry: past this
      // point the job has hit its own timeout and nothing was charged.
      pushToast({ id: `ai-music-timeout-${Date.now()}`,
                  title: 'Music is still generating',
                  message: 'It is taking unusually long. Reopen this project shortly — if it arrived you will not be charged again.' });
      return;
    }
    window.setTimeout(async () => {
      try {
        const res = await api.get(`/projects/${project.value.id}`);
        const proj = res.data?.data?.project;
        const newId = proj?.music_asset_id ?? null;
        if (proj && newId && newId !== beforeId) {
          // New music asset is live. Refresh the picker + bind to it so the
          // editor shows the new track immediately.
          project.value = { ...project.value, music_asset_id: newId, music_settings_json: proj.music_settings_json };
          await loadMusicTracks();
          selectedMusicTrackId.value = newId;
          aiMusicPending.value = false;
          pushToast({ id: `ai-music-done-${newId}`,
                      title: '✦ Music ready',
                      message: 'New AI track loaded — preview to hear it.' });
          return;
        }
      } catch {
        // Transient — keep polling.
      }
      pollProjectMusicUntilNew(beforeId, attempt + 1);
    }, attempt < 4 ? 2500 : 5000);
  }
  const musicVolume = ref(30);
  const musicDuckVolume = ref(8);
  const musicFadeInMs = ref(500);
  const musicLoop = ref(true);
  const musicDuckDuringVoice = ref(true);
  let musicSaveTimer = null;
  const musicSaveState = ref("idle");
  const musicSaveError = ref("");
  // Per-scene voice + sound volumes (0-100, default 100)
  const sceneVoiceVolume = ref(100);
  const sceneSoundVolume = ref(100);
  let sceneVoiceVolumeSaveTimer = null;
  const volumeSavesPending = ref(0);
  const volumeSaveError = ref('');
  let sceneSoundVolumeSaveTimer = null;
  // Audiogram settings
  const audiogramStyle = ref("bars");
  const audiogramColor = ref("#ff6b35");
  const audiogramBg = ref("dark");
  let audiogramSaveTimer = null;
  const audiogramSaveState = ref("idle");

  const AUDIOGRAM_STYLES = [
    { key: "bars",    label: "Classic" },
    { key: "mirror",  label: "Mirror"  },
    { key: "circle",  label: "Radial"  },
    { key: "minimal", label: "Minimal" },
  ];
  const AUDIOGRAM_COLORS = [
    "#ff6b35", "#60a5fa", "#34d399", "#a78bfa", "#f472b6", "#fbbf24", "#ffffff",
  ];
  const AUDIOGRAM_BACKGROUNDS = [
    { key: "dark",   label: "Dark",   css: "linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)" },
    { key: "black",  label: "Black",  css: "#000" },
    { key: "purple", label: "Purple", css: "linear-gradient(135deg,#1a0a2e 0%,#0d0d2b 50%,#14102a 100%)" },
    { key: "ocean",  label: "Ocean",  css: "linear-gradient(135deg,#0a1628 0%,#0d1f3c 50%,#0a0e1a 100%)" },
  ];
  const voiceProfileKey = ref("Kore");
  const voiceSpeedDraft = ref("1.0");
  const voiceStabilityDraft = ref("medium");
  const voiceDirectionDraft = ref("");
  const voiceSaveState = ref("idle");
  const voiceSaveError = ref("");
  const visualQueryDraft = ref("");
  const visualSwapPending = ref(false);
  const visualSwapError = ref("");
  // AI Image generation
  const aiImagePromptOverride = ref("");
  const aiImagePending = ref(false);
  // Image model picker for the editor's regen modal. Fetched from
  // /api/v1/image-models on mount; static fallback so the dropdown
  // still renders if the API call fails.
  const aiImageModelKey = ref('gpt-image-1');
  const availableImageModelsEditor = ref([
    { key: 'gpt-image-1',    label: 'GPT Image 1',    sub: 'OpenAI photoreal',   cost: 16, render: '~20s', requires_reference: false },
    { key: 'gpt-image-2',    label: 'GPT Image 2',    sub: 'OpenAI · newer',      cost: 43, render: '~30s', requires_reference: false },
    { key: 'nano-banana',    label: 'Nano Banana',    sub: 'Google · cheap',      cost: 10, render: '~10s', requires_reference: false },
    { key: 'nano-banana-pro', label: 'Nano Banana Pro', sub: 'Google · best identity', cost: 35, render: '~30s', requires_reference: false },
    { key: 'flux-schnell',   label: 'Flux Schnell',   sub: 'cheapest',            cost:  1, render: '~5s',  requires_reference: false },
    { key: 'sdxl-lightning', label: 'SDXL Lightning', sub: 'stylish',             cost:  1, render: '~5s',  requires_reference: false },
  ]);
  const activeImageModelMeta = computed(
    () => availableImageModelsEditor.value.find((m) => m.key === aiImageModelKey.value) ?? null,
  );
  const activeSceneHasCharacter = computed(() => Boolean(activeScene.value?.character_id));
  const aiImageError = ref("");

  // Animate (i2v rung 4) state.
  const animateModalOpen = ref(false);
  const animateModelOpen = ref(false);       // the model dropdown's open state
  const animateTier = ref("quick");          // quick | balanced | premium | seedance_lite | seedance_pro
  const animateDuration = ref(5);            // value depends on tier — see ANIMATE_TIER_DURATIONS
  const animateMotionPrompt = ref("");
  const animateSubmitting = ref(false);
  const animateError = ref("");
  // Credits per short clip; long clip doubles. Mirror the backend cost calc exactly.
  const ANIMATE_TIER_COSTS_5S = { quick: 50, balanced: 35, premium: 100, seedance_lite: 30, seedance_pro: 125, veo_fast: 80, seedance_25: 105, spokesperson: 0 };
  // User-selectable quality per tier — mirrors CreditService::VIDEO_PRICING.
  // Each option: { value (sent as `quality`), label, cr (base-clip credits) }.
  const ANIMATE_QUALITY_OPTIONS = {
    quick:         [{ value: "480p", label: "480p", cr: 50 }, { value: "720p", label: "720p", cr: 100 }, { value: "1080p", label: "1080p", cr: 150 }],
    seedance_lite: [{ value: "480p", label: "480p", cr: 15 }, { value: "720p", label: "720p", cr: 30 }, { value: "1080p", label: "1080p", cr: 60 }],
    balanced:      [{ value: "768p", label: "768p", cr: 35 }, { value: "1080p", label: "1080p", cr: 60 }],
    seedance_pro:  [{ value: "480p", label: "480p", cr: 25 }, { value: "720p", label: "720p", cr: 50 }, { value: "1080p", label: "1080p", cr: 125 }],
    premium:       [{ value: "standard", label: "Standard", cr: 55 }, { value: "pro", label: "Pro", cr: 100 }],
    // Veo bills per second regardless of resolution — 1080p costs the same.
    veo_fast:      [{ value: "720p", label: "720p", cr: 80 }, { value: "1080p", label: "1080p", cr: 80 }],
    seedance_25:   [{ value: "480p", label: "480p", cr: 105 }, { value: "720p", label: "720p", cr: 235 }],
  };
  const ANIMATE_QUALITY_DEFAULT = { quick: "480p", seedance_lite: "720p", balanced: "768p", seedance_pro: "1080p", premium: "pro", veo_fast: "720p", seedance_25: "480p" };
  const animateQuality = ref("480p");
  // Lip-sync engines and their per-second credit rate come from the server.
  // This used to be a copy of the pricing formula, which silently disagreed with
  // the server the moment the server's changed.
  const lipsyncEngines = ref([]);
  const lipsyncEngine = ref("");
  const lipsyncMinSeconds = ref(5);

  async function loadLipsyncEngines() {
    try {
      const { data } = await api.get("/lipsync-engines");
      lipsyncEngines.value = data?.data?.engines ?? [];
      lipsyncMinSeconds.value = data?.data?.min_seconds ?? 5;
      if (!lipsyncEngine.value) lipsyncEngine.value = data?.data?.default ?? "";
    } catch {
      // The picker simply doesn't render; the server still applies its default.
    }
  }

  function spokespersonCost(seconds) {
    const engine =
      lipsyncEngines.value.find((e) => e.key === lipsyncEngine.value) ||
      lipsyncEngines.value[0];
    if (!engine) return 0;
    const s = Math.max(lipsyncMinSeconds.value, Number(seconds) || lipsyncMinSeconds.value);
    return Math.ceil(s * engine.credits_per_second);
  }
  // Valid durations per tier — each upstream model accepts only specific values.
  //   Wan 2.5 (quick)         → 5 or 10
  //   Hailuo 2.3-fast (balanced) → 6 or 10 (NOT 5; sending 5 returns Replicate 422)
  //   Kling 2.1 (premium)     → 5 or 10
  const ANIMATE_TIER_DURATIONS = { quick: [5, 10], balanced: [6, 10], premium: [5, 10], seedance_lite: [5, 10], seedance_pro: [5, 10], veo_fast: [4, 8], seedance_25: [5, 10], spokesperson: [5] };
  // Spokesperson lip-syncs to the scene's voiceover — only offer it once voice exists.
  const activeSceneHasVoice = computed(() => !!activeScene.value?.voice_settings?.audio_asset_id);
  // The talking video was synced to an earlier voice; the voice has since changed
  // (new TTS) so the lips no longer match — prompt a re-render.
  const spokespersonOutdated = computed(() =>
    activeScene.value?.image_generation_settings?.animation_tier === 'spokesperson'
    && !!activeScene.value?.image_generation_settings?.animation_outdated
  );
  // Likeness attestation for lip-sync. Asked once per scene — a scene that has
  // already been made to speak carries the record, so re-rendering after a script
  // or voice change doesn't re-prompt.
  const spokespersonConsent = ref(false);
  // Mirrors the server: an explicit record, or a scene already lip-synced before
  // the gate shipped. Keeps the modal from demanding a tick the API won't ask for.
  const activeSceneConsented = computed(() => {
    const s = activeScene.value?.image_generation_settings ?? {};
    return !!s.spokesperson_consent?.at || s.animation_tier === 'spokesperson';
  });
  const needsSpokespersonConsent = computed(
    () => animateTier.value === 'spokesperson' && !activeSceneConsented.value
  );

  const animateDurations = computed(() => ANIMATE_TIER_DURATIONS[animateTier.value] || [5, 10]);
  const animateShortDuration = computed(() => animateDurations.value[0]);
  // Quality options for the current tier (empty for spokesperson, which has no picker).
  // The server's price for each quality wins over the copy above once /credit-costs has answered.
  const animateQualityOptions = computed(() => {
    const served = creditCosts.value?.video_tiers?.[animateTier.value]?.qualities;
    return (ANIMATE_QUALITY_OPTIONS[animateTier.value] || []).map((o) => (served?.[o.value] != null ? { ...o, cr: served[o.value] } : o));
  });
  // Reset quality to the tier's default whenever the model/tier changes.
  watch(animateTier, (tier) => {
    if (ANIMATE_QUALITY_DEFAULT[tier]) animateQuality.value = ANIMATE_QUALITY_DEFAULT[tier];
  }, { immediate: true });
  const animateCost = computed(() => {
    // Spokesperson (lip-sync) length follows the voiceover — price by the scene's
    // audio duration (scene.duration_seconds tracks the voiceover after TTS).
    if (animateTier.value === 'spokesperson') {
      return spokespersonCost(activeScene.value?.duration_seconds);
    }
    // i2v: cost = chosen-quality base credits × (10s = 2×).
    const opt = animateQualityOptions.value.find((o) => o.value === animateQuality.value)
      || animateQualityOptions.value[0];
    const base = opt ? opt.cr : ANIMATE_TIER_COSTS_5S[animateTier.value];
    // Doubles from ten seconds, as CreditService::animationCost does (Veo's 8 s clip is not doubled).
    return base * (Number(animateDuration.value) >= 10 ? 2 : 1);
  });
  // Animation models. We expose actual model names (Wan/Hailuo/Kling/Seedance)
  // instead of generic tier labels so power users know what they're picking.
  // `key` still maps to the backend tier so the API doesn't need to change.
  const ANIMATE_TIER_META = {
    quick:         { name: "Wan 2.5",       sub: "Fast · cheap",      quality: "Good",       render: "~30s" },
    seedance_lite: { name: "Seedance Lite", sub: "ByteDance · cheap", quality: "Strong",     render: "~45s" },
    balanced:      { name: "Hailuo 2.3",    sub: "Best for most",     quality: "Strong",     render: "~90s" },
    seedance_pro:  { name: "Seedance Pro",  sub: "ByteDance · sharp", quality: "Very high",  render: "~2 min" },
    premium:       { name: "Kling 2.1",     sub: "Cinematic",         quality: "Top",        render: "~3 min" },
    veo_fast:      { name: "Veo 3.1 Fast",  sub: "Google · sharp motion", quality: "Very high", render: "~2 min" },
    seedance_25:   { name: "Seedance 2.5",  sub: "ByteDance flagship",    quality: "Top",       render: "~3 min" },
    spokesperson:  { name: "Spokesperson",  sub: "Lip-sync · your voice", quality: "Talking", render: "~3 min" },
  };
  // Dropdown order: cheapest/fastest → most cinematic, then the talking option.
  const ANIMATE_TIER_ORDER = ['quick', 'seedance_lite', 'balanced', 'veo_fast', 'seedance_25', 'seedance_pro', 'premium', 'spokesperson'];

  // When the user switches tier, snap the chosen duration to one the new tier
  // actually supports. Prevents the 422 ("duration must be one of: 6, 10") that
  // fired when balanced inherited the 5s default from quick.
  watch(animateTier, () => {
    if (!animateDurations.value.includes(animateDuration.value)) {
      animateDuration.value = animateDurations.value[0];
    }
  });
  // Quick-pick prompts for the animate modal — covers the motion patterns most
  // faceless creators reach for. Click sets the prompt; user can still edit.
  const ANIMATE_PROMPT_SUGGESTIONS = [
    { label: "Subtle motion",   text: "subtle natural motion, gentle breathing, hair drifting" },
    { label: "Slow push-in",    text: "slow camera push-in, cinematic depth, dramatic lighting" },
    { label: "Dolly back",      text: "slow dolly back revealing the scene, smooth camera move" },
    { label: "Pan left",        text: "smooth camera pan from right to left, parallax background" },
    { label: "Wind & weather",  text: "wind blowing through hair and fabric, drifting fog or dust" },
    { label: "Dramatic zoom",   text: "fast dramatic zoom-in on the subject, intense lighting" },
  ];

  // Workspace-level characters: name + description + optional reference image.
  // Selecting one binds it to the active scene; the AI image prompt picks up the description.
  // Reference image: shown as the chip/grid thumbnail today; used by IP-Adapter when wired later.
  const characters = ref([]);
  const characterPopoverOpen = ref(false);
  const createCharacterOpen = ref(false);
  const createCharacterName = ref("");
  const createCharacterDescription = ref("");
  const createCharacterFile = ref(null);
  const createCharacterPreviewUrl = ref("");
  const createCharacterSaving = ref(false);
  const createCharacterError = ref("");
  const AI_IMAGE_STYLES = [
    { key: "cinematic",     label: "Cinematic",     icon: "🎬" },
    { key: "dark",          label: "Dark",           icon: "🌑" },
    { key: "documentary",   label: "Documentary",    icon: "📽️" },
    { key: "anime",         label: "Anime",          icon: "🌸" },
    { key: "minimalist",    label: "Minimalist",     icon: "◽" },
    { key: "realistic",     label: "Realistic",      icon: "📸" },
    { key: "vintage",       label: "Vintage",        icon: "🌅" },
    { key: "neon",          label: "Neon",           icon: "⚡" },
    { key: "photorealistic",label: "Photorealistic", icon: "📷" },
    { key: "cyberpunk_80s", label: "Cyberpunk 80s",  icon: "🕹️" },
    { key: "anime_80s",     label: "Anime 80s",      icon: "🌟" },
    { key: "anime_90s",     label: "Anime 90s",      icon: "💫" },
    { key: "dark_fantasy",  label: "Dark Fantasy",   icon: "🐉" },
    { key: "fantasy_retro", label: "Fantasy Retro",  icon: "🧙" },
    { key: "comic",         label: "Comic",          icon: "💥" },
    { key: "film_noir",     label: "Film Noir",      icon: "🎩" },
    { key: "line_drawing",  label: "Line Drawing",   icon: "✏️" },
    { key: "watercolor",    label: "Watercolor",     icon: "🎨" },
    { key: "paper_cutout",  label: "Paper Cutout",   icon: "✂️" },
    { key: "cartoon",       label: "Cartoon",        icon: "🎭" },
    { key: "3d_animated",  label: "3D Animated",    icon: "🎬" },
    // Custom — last so users see the preset library first. Picking this
    // reveals a textarea where they type the descriptor that replaces the
    // preset string in every scene's image prompt for this scene.
    { key: "custom",       label: "Custom",         icon: "✦" },
  ];

  // Style catalog from /api/v1/image-styles — same keys + labels + a
  // sample_url thumbnail rendered by `php artisan generate:style-samples`
  // and stored in B2 at style-samples/<key>.jpg. Static AI_IMAGE_STYLES
  // stays as a fallback when the API call fails or hasn't returned yet.
  const richImageStyles = ref([]);
  const imageStylesByKey = computed(() => {
    const map = new Map();
    for (const s of richImageStyles.value) map.set(s.key, s);
    // Layer the static list on top so 'custom' (not in META) still renders.
    for (const s of AI_IMAGE_STYLES) {
      if (!map.has(s.key)) map.set(s.key, { ...s, sample_url: null, description: '' });
    }
    return map;
  });
  const stylePickerRows = computed(() => Array.from(imageStylesByKey.value.values()));

  async function loadImageStyleCatalog() {
    try {
      const res = await api.get('/image-styles');
      const styles = res?.data?.data?.styles;
      if (Array.isArray(styles)) richImageStyles.value = styles;
    } catch { /* keep static fallback */ }
  }

  // Open-state refs for the three style-picker dropdowns:
  //   stylePickerOpen           – main editor's AI image panel
  //   topAddStylePickerOpen     – "Add scene" panel at the top of the editor
  //   perSceneAddStyleOpenId    – inline "Add scene" rendered under each scene
  //                                (per-scene because there are N of them)
  const stylePickerOpen = ref(false);
  const topAddStylePickerOpen = ref(false);
  const perSceneAddStyleOpenId = ref(null);

  const exportPending = ref(false);

  // C10 — batch export: pick one or more aspect ratios; each queues a render that
  // re-composites the same generated assets (no AI re-gen, 0 extra credits).
  const exportMenuOpen = ref(false);
  const exportAspectRatios = ref([]);
  const ASPECT_RATIO_OPTIONS = [
    { value: "9:16", label: "9:16", sub: "Reels · TikTok · Shorts" },
    { value: "1:1", label: "1:1", sub: "Feed" },
    { value: "4:5", label: "4:5", sub: "IG portrait" },
    { value: "16:9", label: "16:9", sub: "YouTube" },
  ];
  function openExportMenu() {
    if (!exportAspectRatios.value.length) {
      exportAspectRatios.value = [project.value?.aspect_ratio || "9:16"];
    }
    exportMenuOpen.value = !exportMenuOpen.value;
  }
  function toggleExportRatio(r) {
    const i = exportAspectRatios.value.indexOf(r);
    if (i >= 0) exportAspectRatios.value.splice(i, 1);
    else exportAspectRatios.value.push(r);
  }

  // Public share state for the export header's "🔗 Share publicly" button.
  // project.is_shared + project.share_url come from the project serializer.
  // First click enables share + copies; subsequent clicks just re-copy.
  const shareTogglePending = ref(false);
  // The Share menu: closes after an action, on a click outside it, or on Escape; the copy keeps it open to show "copied".
  const shareMenuOpen = ref(false);
  const shareWrap = ref(null);
  function shareAction(action) {
    if (action !== 'share') shareMenuOpen.value = false;
    requestExportAction(action);
  }
  function closeShareMenu(event) {
    if (!shareMenuOpen.value) return;
    if (event.type === 'keydown' ? event.key === 'Escape' : !shareWrap.value?.contains(event.target)) shareMenuOpen.value = false;
  }
  onMounted(() => { document.addEventListener('pointerdown', closeShareMenu); document.addEventListener('keydown', closeShareMenu); });
  onBeforeUnmount(() => { document.removeEventListener('pointerdown', closeShareMenu); document.removeEventListener('keydown', closeShareMenu); });
  const shareCopiedToast = ref('');
  let shareCopiedTimer = null;
  async function toggleShareLink() {
    if (!project.value?.id || shareTogglePending.value) return;
    shareTogglePending.value = true;
    try {
      // Idempotent: if already shared, this is a no-op server-side and
      // returns the existing share_url for us to copy.
      const res = await api.post(`/projects/${project.value.id}/share`, { enabled: true });
      // With exports in several ratios, the link plays the one chosen in the export bar (?r=9x16).
      const base = res.data?.data?.share_url;
      const url = base && exportsByRatio.value.length > 1 && activeExportJob.value?.aspect_ratio
        ? `${base}?r=${activeExportJob.value.aspect_ratio.replace(':', 'x')}` : base;
      if (url) {
        // Mutate the local project state so the button label flips
        // immediately, then copy.
        project.value = { ...project.value, is_shared: true, share_token: res.data.data.share_token, share_url: base };
        try {
          await navigator.clipboard.writeText(url);
          shareCopiedToast.value = 'Copied!';
        } catch {
          // Some browsers gate clipboard behind permissions; show URL inline
          // so the user can copy manually.
          shareCopiedToast.value = url;
        }
        if (shareCopiedTimer) window.clearTimeout(shareCopiedTimer);
        shareCopiedTimer = window.setTimeout(() => { shareCopiedToast.value = ''; }, 3000);
      }
    } catch (e) {
      pushToast({ id: `share-fail-${Date.now()}`, title: 'Could not generate share link', message: e?.response?.data?.error?.message ?? 'Try again in a moment.' });
    } finally {
      shareTogglePending.value = false;
    }
  }
  const exportState = ref("idle");
  const timelineOpen = ref(false);
  watch(timelineOpen, (open) => {
    if (open) sidebarStore.collapse();
    else sidebarStore.restore();
  });
  const previewMode = ref("scene");
  const stockVideoSubType = ref("stock_clip");
  const playProgress = ref(0);
  const isPreviewPlaying = ref(false);
  let previewPlayTimer = null;
  let pendingPreviewAudioOffset = 0;
  const musicMoodFilter = ref("all");
  const queuedExportJobId = ref(null);
  let exportPollTimer = null;
  const scriptSaveState = ref("idle");
  const scriptSaveError = ref("");
  const captionEnabledDraft = ref(true);
  const captionStyleDraft = ref("impact");
  const captionHighlightDraft = ref("keywords");
  const captionPositionDraft = ref("bottom_third");
  const DEFAULT_CAPTION_FONT = "Bebas Neue";
  const DEFAULT_CAPTION_SETTINGS = Object.freeze({
    enabled: true,
    style_key: "impact",
    highlight_mode: "keywords",
    position: "bottom_third",
    font: DEFAULT_CAPTION_FONT,
    highlight_color: "#ff6b35",
    color: "#ffffff",
    size: "medium",
    preset_id: null,
    // Animation layer — absent/plain on every pre-existing project.
    animation: "plain",
    highlight_style: "color",
    panel_color: null,
    backdrop: null, // null = the preset decides (panel presets on, others off)
  });
  const captionFontDraft = ref(DEFAULT_CAPTION_FONT);
  const captionColorDraft = ref("#ffffff");
  const captionSizeDraft = ref("medium");
  const captionHighlightColorDraft = ref("#ff6b35");
  const captionAnimationDraft = ref("plain");
  const captionHighlightStyleDraft = ref("color");
  const captionPanelColorDraft = ref("");
  const captionBackdropDraft = ref(null);
  const captionEffectModalOpen = ref(false);
  const CAPTION_COLOR_SWATCHES = ["#ffffff","#ffff00","#ff6b35","#ff4444","#44ff88","#44aaff","#cc88ff","#000000"];
  const CAPTION_PANEL_SWATCHES = ["", "rgba(0,0,0,0.62)", "#ffffff", "#7c5cff", "#0b2a12", "#ffd21e", "#c62828", "#0d2b52"];

  // --- Caption animation presets (panel row + modal + live preview) ---
  const captionPanelRow = computed(() => panelRowAnimations(captionAnimationDraft.value));
  const activeCaptionAnimation = computed(() => animationByKey(captionAnimationDraft.value));
  const captionBackdropChecked = computed({
    get: () =>
      captionBackdropDraft.value === null
        ? activeCaptionAnimation.value.panel
        : captionBackdropDraft.value !== false,
    set: (value) => {
      captionBackdropDraft.value = value;
    },
  });

  function selectCaptionAnimation(key) {
    const preset = animationByKey(key);
    captionAnimationDraft.value = preset.key;
    // Preset default font lands in the picker so the user sees + can change it.
    if (preset.font) captionFontDraft.value = preset.font;
    // Panel presets carry a panel by design — auto-enable the backdrop switch.
    if (preset.panel) captionBackdropDraft.value = true;
    captionEffectModalOpen.value = false;
  }

  const captionPreviewSettings = computed(() => ({
    animation: captionAnimationDraft.value,
    highlight_mode: captionHighlightDraft.value,
    highlight_style: captionHighlightStyleDraft.value,
    color: captionColorDraft.value,
    highlight_color: captionHighlightColorDraft.value,
    panel_color: captionPanelColorDraft.value || null,
    backdrop: captionBackdropDraft.value === null ? undefined : captionBackdropDraft.value,
  }));

  const captionPreviewTimedWords = computed(() => {
    const timed = captionTimingWords(activeScene.value);
    if (timed.length > 0) return timed;
    return syntheticTimedWords(
      sceneScriptDraft.value || activeScene.value?.script_text,
      Number(activeScene.value?.duration_seconds) || 0
    );
  });

  // Animated presets need a smooth playhead; the debounced playProgress ticks
  // are too coarse. rAF only runs while playing AND an animation is selected.
  const captionClock = ref(0);
  let captionClockRaf = null;
  function captionClockTick() {
    captionClock.value = currentCaptionSeconds();
    captionClockRaf = requestAnimationFrame(captionClockTick);
  }
  watch([isPreviewPlaying, captionAnimationDraft], ([playing, animation]) => {
    if (captionClockRaf) {
      cancelAnimationFrame(captionClockRaf);
      captionClockRaf = null;
    }
    if (playing && animation !== "plain") captionClockTick();
    else captionClock.value = currentCaptionSeconds();
  });
  watch(playProgress, () => {
    if (!isPreviewPlaying.value) captionClock.value = currentCaptionSeconds();
  });
  const leaveSaveDialog = ref(false);
  const stayInEditorButton = ref(null);
  watch(leaveSaveDialog, async open => {
    if (open) { await nextTick(); stayInEditorButton.value?.focus(); }
  });
  let resolveLeave = null;
  function chooseLeave(discard) {
    leaveSaveDialog.value = false;
    resolveLeave?.(discard);
    resolveLeave = null;
  }
  onBeforeRouteLeave(async () => {
    if (!hasPendingExportChanges()) return true;
    if (await flushActiveSceneDrafts()) return true;
    leaveSaveDialog.value = true;
    return new Promise(resolve => { resolveLeave = resolve; });
  });

  onBeforeUnmount(() => {
    window.removeEventListener('resize', onViewportResize);
    window.visualViewport?.removeEventListener('resize', onViewportResize);
    if (captionClockRaf) cancelAnimationFrame(captionClockRaf);
  });
  const CAPTION_SIZE_MAP = { small: "13px", medium: "17px", large: "23px", xlarge: "30px" };
  // A caption size in preview pixels on the 480px-tall frame: S/M/L/XL, or a custom number (8 to 60) from the slider.
  // The export uses the same numbers (RendersExportScenes::captionPreviewPx).
  const captionBasePx = (size) => (/^\d+$/.test(String(size ?? "")) ? Math.max(8, Math.min(60, Number(size))) : parseFloat(CAPTION_SIZE_MAP[size] || "17px"));
  const fontDropdownOpen = ref(false);
  const captionSaveState = ref("idle");
  const captionSaveError = ref("");
  const CAPTION_FONT_GROUPS = [
    { label: "Bold Display", fonts: ["Bebas Neue", "Days One", "Passion One", "Fredoka One", "Luckiest Guy", "New Rocker", "Aladin"] },
    { label: "Sans-serif", fonts: ["Montserrat", "Raleway", "Lato", "Nunito", "Quicksand", "Noto Sans", "Liberation Sans", "Nimbus Sans"] },
    { label: "Serif", fonts: ["Playfair Display", "Roboto Slab", "Libre Baskerville", "Liberation Serif", "Nimbus Roman", "Century Schoolbook L"] },
    { label: "Script", fonts: ["Dancing Script", "Sacramento", "Satisfy", "Shadows Into Light"] },
    { label: "Mono", fonts: ["Roboto Mono", "Source Code Pro", "Orbitron", "Liberation Mono", "Nimbus Mono PS"] },
    { label: "Handwritten", fonts: ["Permanent Marker", "Amatic SC", "Indie Flower", "Rock Salt", "Calligraffitti"] },
  ];
  const motionEffectDraft = ref("zoom_in");
  const motionIntensityDraft = ref("moderate");
  const MOTION_EFFECT_OPTIONS = [
    { value: "zoom_in", label: "Zoom In" },
    { value: "zoom_out", label: "Zoom Out" },
    { value: "pan_left", label: "Pan Left" },
    { value: "pan_right", label: "Pan Right" },
    { value: "pan_up", label: "Pan Up" },
    { value: "pan_down", label: "Pan Down" },
    { value: "pan_zoom", label: "Pan + Zoom" },
    { value: "static", label: "Static (no motion)" },
  ];
  const MOTION_INTENSITY_OPTIONS = [
    { value: "subtle", label: "Subtle" },
    { value: "moderate", label: "Moderate" },
    { value: "dramatic", label: "Dramatic" },
  ];
  const VOICE_SPEED_OPTIONS = [
    // Floor set to 0.6 by founder ear-test against a real cloned voice.
    // 0.7 reads as natural deliberate speech; 0.6 is the acceptable edge.
    // (Applied via pitch-preserving atempo on our side for Gemini/Chatterbox,
    // natively by OpenAI for its voices — same technique either way.)
    { value: "0.6", label: "0.6x" },
    { value: "0.7", label: "0.7x" },
    { value: "0.8", label: "0.8x" },
    { value: "1.0", label: "1.0x" },
    { value: "1.1", label: "1.1x" },
    { value: "1.2", label: "1.2x" },
  ];
  const VOICE_STABILITY_OPTIONS = [
    { value: "low", label: "Low" },
    { value: "medium", label: "Medium" },
    { value: "high", label: "High" },
  ];
  const voiceProfileOptions = computed(() => {
    const list = voiceProfiles.value || [];
    // Cloned voices first (the user's own), then the built-in catalog. gender_label
    // carries the Gemini delivery character (Firm/Warm/Bright…); clones show as
    // "Your voice" so they're easy to spot.
    const cloned = list.filter((p) => p.is_cloned);
    const builtin = list.filter((p) => !p.is_cloned);
    return [...cloned, ...builtin].map((p) => ({
      value: p.provider_voice_key,
      label: p.is_cloned
        ? `${p.name} · Your voice`
        : (p.gender_label ? `${p.name} · ${p.gender_label}` : p.name),
    }));
  });
  // Voice direction + inline [tags] only apply to Gemini voices. Detect by the
  // seeded profile's provider, falling back to "not one of OpenAI's 6 voices".
  const OPENAI_VOICE_KEYS = ["alloy", "echo", "fable", "onyx", "nova", "shimmer"];
  const activeVoiceIsGemini = computed(() => {
    const p = (voiceProfiles.value || []).find(
      (v) => v.provider_voice_key === voiceProfileKey.value
    );
    if (p) return p.provider === "google";
    return !OPENAI_VOICE_KEYS.includes(String(voiceProfileKey.value).toLowerCase());
  });

  // ── Voice picker modal: descriptions, personas, preview ──────────────
  // One-click personalities — set the best-matching Gemini voice + a delivery
  // direction (voice_prompt). Best-effort: shifts age/character feel, not pitch.
  const VOICE_PERSONAS = [
    { key: "narrator",  emoji: "🧑", label: "Narrator",  voice: "Charon",       prompt: "",                                                                                  desc: "Clear, steady" },
    { key: "teen_girl", emoji: "👧", label: "Teen girl", voice: "Leda",         prompt: "Speak like an upbeat teenage girl — youthful, bright, and energetic.",              desc: "Young, bright" },
    { key: "teen_boy",  emoji: "👦", label: "Teen boy",  voice: "Puck",         prompt: "Speak like an energetic teenage boy — casual and upbeat.",                           desc: "Young, casual" },
    { key: "child",     emoji: "🧒", label: "Child",     voice: "Leda",         prompt: "Speak like a cheerful young child — small, playful, and excited.",                    desc: "Small, playful" },
    { key: "old_man",   emoji: "👴", label: "Old man",   voice: "Gacrux",       prompt: "Speak slowly like a wise elderly man — warm, measured, a little gravelly.",          desc: "Older, warm" },
    { key: "old_woman", emoji: "👵", label: "Old woman", voice: "Vindemiatrix", prompt: "Speak gently like a kind elderly woman — soft, warm, and unhurried.",                desc: "Older, gentle" },
    { key: "hype",      emoji: "📢", label: "Hype",      voice: "Fenrir",       prompt: "High-energy, hyped delivery like an excited announcer — fast and punchy.",           desc: "Loud, fast" },
    { key: "calm",      emoji: "😌", label: "Calm",      voice: "Sulafat",      prompt: "Calm, soothing, and relaxed — gentle and reassuring.",                               desc: "Soft, soothing" },
  ];

  const showVoiceModal = ref(false);
  const previewLoadingKey = ref("");
  const previewPlayingKey = ref("");
  let previewAudioEl = null;

  const clonedVoices = computed(() => (voiceProfiles.value || []).filter((v) => v.is_cloned));
  const geminiVoices = computed(() => (voiceProfiles.value || []).filter((v) => !v.is_cloned && v.provider === "google"));
  const otherVoices = computed(() => (voiceProfiles.value || []).filter((v) => !v.is_cloned && v.provider !== "google"));


  function openVoiceModal() { showVoiceModal.value = true; }
  function closeVoiceModal() {
    stopPreview();
    showVoiceModal.value = false;
  }
  function stopPreview() {
    try { previewAudioEl?.pause(); } catch {}
    previewPlayingKey.value = "";
  }
  async function previewVoice(p) {
    if (previewPlayingKey.value === p.provider_voice_key) { stopPreview(); return; }
    stopPreview();
    previewLoadingKey.value = p.provider_voice_key;
    try {
      const res = await api.post("/voice-profiles/preview", { voice_profile_id: p.id });
      const url = res.data?.data?.preview_url;
      if (!url) throw new Error("no preview url");
      if (!previewAudioEl) previewAudioEl = new Audio();
      previewAudioEl.src = url;
      previewAudioEl.onended = () => { previewPlayingKey.value = ""; };
      await previewAudioEl.play();
      previewPlayingKey.value = p.provider_voice_key;
    } catch (e) {
      /* preview is best-effort; ignore */
    } finally {
      previewLoadingKey.value = "";
    }
  }
  function selectVoice(p) {
    voiceProfileKey.value = p.provider_voice_key;
    if (p.provider !== "google") voiceDirectionDraft.value = ""; // direction is Gemini-only
    closeVoiceModal();
  }
  function applyPersona(persona) {
    voiceProfileKey.value = persona.voice;
    voiceDirectionDraft.value = persona.prompt;
    closeVoiceModal();
  }
  function goToVoices() {
    stopPreview();
    router.push({ name: "voices" });
  }

  // Clone a voice without leaving the editor (shared VoiceCloneModal).
  const showCloneModal = ref(false);
  function openCloneInEditor() {
    stopPreview();
    showCloneModal.value = true;
  }
  async function onVoiceCloned(profile) {
    showCloneModal.value = false;
    await loadVoiceProfiles();
    if (profile?.provider_voice_key) {
      voiceProfileKey.value = profile.provider_voice_key;
      voiceDirectionDraft.value = ""; // clones don't use Gemini direction
      closeVoiceModal();
    }
  }
  onBeforeUnmount(() => stopPreview());
  const ADD_SCENE_STOCK_OPTIONS = [
    { value: "stock_clip", label: "Clip" },
    { value: "background_loop", label: "BG Loop" },
    { value: "text_card", label: "Text Only" },
  ];
  const STOCK_VIDEO_SUBTYPE_OPTIONS = [
    { value: "stock_clip", label: "Clip" },
    { value: "background_loop", label: "BG Loop" },
  ];
  const CAPTION_HIGHLIGHT_OPTIONS = [
    { value: "keywords", label: "Keywords" },
    { value: "word_by_word", label: "Word-by-word" },
    { value: "line_by_line", label: "Line-by-line" },
    { value: "none", label: "None" },
  ];
  const CAPTION_HIGHLIGHT_STYLE_OPTIONS = [
    { value: "color", label: "Color the spoken word" },
    { value: "underline", label: "Color + underline" },
    { value: "plain", label: "Motion only, no color" },
  ];
  const CAPTION_POSITION_OPTIONS = [
    { value: "bottom_third", label: "Bottom third" },
    { value: "center", label: "Center" },
    { value: "top_third", label: "Top third" },
  ];
  // Model and tier lists carry their description only. The price beside each
  // one comes from /credit-costs at runtime — typed in, every one of the five
  // animation tiers had drifted: quick advertised 60 credits and charged 50,
  // seedance lite advertised 100 and charged 30, seedance pro 200 against 125,
  // premium 240 against 100.
  const CRUISE_IMAGE_MODELS = [
    { value: "nano-banana-pro", note: "best identity (default for refs)" },
    { value: "nano-banana", note: "Google fast" },
    { value: "gpt-image-1", note: "photoreal" },
    { value: "gpt-image-2", note: "OpenAI refs" },
    { value: "flux-schnell", note: "fastest" },
    { value: "sdxl-lightning", note: "stylish" },
  ];
  const CRUISE_ANIMATION_TIERS = [
    { value: "quick", label: "quick · Wan 2.5" },
    { value: "seedance_lite", label: "seedance lite" },
    { value: "balanced", label: "balanced · Hailuo" },
    { value: "seedance_pro", label: "seedance pro" },
    { value: "premium", label: "premium · Kling" },
  ];

  /** "· 35 cr" when the server has told us, nothing at all when it has not. */
  function crSuffix(credits) {
    return Number.isFinite(credits) ? ` · ${credits} cr` : "";
  }

  const CRUISE_IMAGE_MODEL_OPTIONS = computed(() => [
    { value: "", label: "Let me pick per turn" },
    ...CRUISE_IMAGE_MODELS.map((m) => ({
      value: m.value,
      label: `${m.value}${crSuffix(creditCosts.value.image_models?.[m.value]?.credits)} · ${m.note}`,
    })),
  ]);
  // A model that has been backed up lately (its recent clips took far longer than usual), from the costs endpoint.
  function tierSlow(key) { return !!creditCosts.value?.video_tiers?.[key]?.slow }
  const CRUISE_ANIMATION_TIER_OPTIONS = computed(() => [
    { value: "", label: "Let me pick per turn" },
    ...CRUISE_ANIMATION_TIERS.map((t) => {
      const tier = creditCosts.value.video_tiers?.[t.value];
      return {
        value: t.value,
        label: `${t.label}${crSuffix(tier?.credits)}${tier?.quality ? ` · ${tier.quality}` : ""}`,
      };
    }),
  ]);
  const CRUISE_VISUAL_SOURCE_OPTIONS = [
    { value: "auto", label: "Auto — assistant decides" },
    { value: "ai_image", label: "AI image" },
    { value: "stock_video", label: "Stock video" },
    { value: "stock_image", label: "Stock image" },
    { value: "audiogram", label: "Audiogram" },
  ];
  const APPROVAL_EXPIRES_OPTIONS = [
    { value: 3, label: "3 days" },
    { value: 7, label: "7 days" },
    { value: 14, label: "14 days" },
    { value: 30, label: "30 days" },
  ];
  const channelOptions = computed(() => [
    { value: "", label: "No channel" },
    ...(channels.value || []).map((c) => ({ value: String(c.id), label: c.name })),
  ]);
  const brandKitOptions = computed(() => [
    { value: "", label: "No brand kit" },
    ...(brandKits.value || []).map((b) => ({ value: String(b.id), label: b.name })),
  ]);
  const cruiseScopeOptions = computed(() => [
    { value: "project", label: "whole project" },
    ...(scenes.value || []).map((s) => ({ value: String(s.id), label: `Scene ${s.scene_order}` })),
  ]);
  const captionPresetOptions = computed(() =>
    (captionPresets.value || []).map((p) => ({ value: p.id, label: p.name }))
  );
  function onApplyCaptionPreset(id) {
    applyCaptionPreset((captionPresets.value || []).find((p) => p.id === Number(id)) || {});
  }
  const motionSaveState = ref("idle");
  const motionSaveError = ref("");
  const visualStyleDraft = ref(null);
  let visualStyleSaveTimer = null;
  const visualStyleSaveState = ref("idle");
  // Free-text descriptor for the "custom" style. Lives on scene.custom_visual_style.
  // Saved with a similar debounce pattern as captions / visual_style.
  const customVisualStyleDraft = ref("");
  let customVisualStyleSaveTimer = null;
  const customVisualStyleSaveState = ref("idle");
  // All panels collapsed by default — users open only what they need.
  // Cuts visual clog; the badges/tags on each header already surface state at a glance.
  const panelState = ref({
    script: true,
    visual: true,
    motion: true,
    voice: true,
    sounds: true,
    captions: true,
    music: true,
    brand: true,
    project: true,
  });
  const sceneScriptDraft = ref("");
  let scriptSaveTimer = null;
  let voiceSaveTimer = null;
  let captionSaveTimer = null;
  let motionSaveTimer = null;
  let beforeUnloadHandler = null;

  const activeScene = computed(
    () => scenes.value.find((scene) => scene.id === activeSceneId.value) ?? null
  );
  const activeSceneVisualUrl = computed(
    () => activeScene.value?.visual_asset?.storage_url ?? null
  );
  const activeSceneVisualAsset = computed(() => activeScene.value?.visual_asset ?? null);
  const activeSceneVisualType = computed(
    () => String(activeScene.value?.visual_type || "")
  );
  function sceneHasResolvedAIImage(scene) {
    if (!scene || String(scene.visual_type || "") !== "ai_image") return false;
    return Boolean(scene.visual_asset || scene.visual_asset_id);
  }

  const activeSceneAIImagePending = computed(() => {
    const scene = activeScene.value;
    if (!scene) return false;
    // Treat the scene as pending ONLY when the backend has explicitly flagged
    // it as in-progress (image_generation_settings_json.in_progress = true).
    //
    // Previous logic returned `!settings.needs_visual` as a fallback, which
    // evaluated to `true` for any scene with visual_type=ai_image and no
    // settings object — meaning a scene whose generation job silently failed
    // to write back would show "AI Generating..." forever, with no recovery.
    // Seen on prod 2026-06-02 (project 20, scene 187): orphaned asset, NULL
    // settings, infinite spinner.
    const settings = scene.image_generation_settings;
    if (!settings) return false;
    return Boolean(settings.in_progress);
  });

  // True while an i2v animation job is running for the active scene.
  const activeSceneAnimationPending = computed(() => {
    const settings = activeScene.value?.image_generation_settings ?? {};
    return Boolean(settings.animation_in_progress);
  });
  // The provider is backed up: the clip is paid for and still being made; the server collects it.
  const activeSceneStillRendering = computed(() => Boolean(activeScene.value?.image_generation_settings?.animation_still_rendering));
  const activeSceneAnimationError = computed(() => {
    return activeScene.value?.image_generation_settings?.animation_last_error || "";
  });
  const canAnimateActiveScene = computed(() => {
    // Allow animate when scene has any visual asset. When it's already a video
    // (a previous animation), the button labels itself "Re-animate" and re-runs
    // i2v against the latest still — or against the same source if we tracked it.
    return Boolean(activeScene.value?.visual_asset_id);
  });
  const activeSceneAlreadyAnimated = computed(() => {
    const asset = activeScene.value?.visual_asset;
    if (!asset) return false;
    return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
  });

  // When switching to a scene that has in_progress=true, auto-start the poll
  // so the UI unsticks itself even if Reverb dropped the event
  watch(activeSceneAIImagePending, (pending) => {
    if (pending && !aiImagePending.value) {
      aiImagePending.value = true;
      if (activeScene.value?.id) pollSceneUntilVisual(activeScene.value.id);
    }
  });
  const activeSceneVisualGenerationError = computed(() => {
    const scene = activeScene.value;
    const settings = scene?.image_generation_settings;
    if (sceneHasResolvedAIImage(scene) && !settings?.in_progress) return "";
    if (!settings?.needs_visual) return "";
    return (
      settings.last_error ||
      "Image generation failed. Please revise the prompt and try again."
    );
  });
  const activeSceneVisualIsVideo = computed(() => {
    const asset = activeSceneVisualAsset.value;
    if (!asset) return false;
    return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
  });
  const previewVideoShortfall = computed(() => activeSceneVisualIsVideo.value && previewVideoDuration.value > 0
    ? Math.max(0, sceneDuration(activeScene.value) - previewVideoDuration.value) : 0);
  // True only when the scene visual is a user library asset (not AI broll, not stock/matched)
  const activeSceneVisualIsFromLibrary = computed(() => {
    const asset = activeSceneVisualAsset.value;
    if (!asset) return false;
    // AI images own their own tab, with their own preview and controls.
    if (activeScene.value?.visual_type === "ai_image") return false;
    // Everything else assigned to the scene shows here. Stock pulls are tagged
    // "matched_visual" and used to be excluded, which meant picking a previously
    // used stock image out of the library rendered neither its preview nor the
    // Animate button — the asset is in the workspace library either way, and
    // how it first arrived there says nothing about whether it can be animated.
    return true;
  });
  const activeSceneAudioUrl = computed(
    () => activeScene.value?.audio_asset?.storage_url ?? null
  );
  const activeSceneSoundAsset = computed(() => activeScene.value?.sound_asset ?? null);
  const activeSceneSoundUrl = computed(() => activeSceneSoundAsset.value?.storage_url ?? null);
  const selectedProjectChannel = computed(() =>
    channels.value.find((channel) => String(channel.id) === String(projectChannelId.value)) || null
  );
  const activeMusicTrack = computed(() =>
    musicTracks.value.find((t) => t.id === selectedMusicTrackId.value) ?? null
  );
  const activeMusicTrackUrl = computed(() => activeMusicTrack.value?.storage_url ?? null);
  const auditionMusicTrack = computed(() =>
    musicTracks.value.find((t) => t.id === auditionMusicTrackId.value) ?? null
  );
  const previewMusicVolume = computed(() => {
    const rawVolume =
      musicDuckDuringVoice.value && activeSceneAudioUrl.value && isPreviewPlaying.value && isAudioPlaying.value
        ? musicDuckVolume.value
        : musicVolume.value;

    return Math.max(0, Math.min(1, Number(rawVolume || 0) / 100));
  });
  const filteredMusicTracks = computed(() => {
    if (musicMoodFilter.value === "all") return musicTracks.value;
    return musicTracks.value.filter((t) =>
      (t.tags ?? []).some((tag) => tag.toLowerCase() === musicMoodFilter.value)
    );
  });
  const myImageAssetsFiltered = computed(() => {
    const q = myImageSearch.value.toLowerCase();
    if (!q) return myImageAssets.value;
    return myImageAssets.value.filter(a => String(a.title ?? '').toLowerCase().includes(q));
  });

  const customAudioAssetsFiltered = computed(() => {
    const q = customAudioSearch.value.toLowerCase();
    if (!q) return customAudioAssets.value;
    return customAudioAssets.value.filter(a => String(a.title ?? '').toLowerCase().includes(q));
  });

  const musicTrackGroups = computed(() => {
    const groups = {};
    for (const track of filteredMusicTracks.value) {
      const mood = track.tags?.find((tag) => tag !== "music") ?? "other";
      if (!groups[mood]) groups[mood] = [];
      groups[mood].push(track);
    }

    return Object.entries(groups).map(([mood, tracks]) => ({ mood, tracks }));
  });
  const activeSceneVisualLoaded = computed(() => {
    const sceneId = activeSceneId.value;
    if (!sceneId || !activeSceneVisualUrl.value) return false;
    return Boolean(mediaCache.value.visual[sceneId]?.loaded);
  });
  const isVisualLoading = computed(() => {
    if (!activeSceneVisualUrl.value) return false;
    return !activeSceneVisualLoaded.value && !visualLoadFailed.value;
  });

  // Per-scene asset readiness — a scene is playable only once ALL of its assets
  // (visual, voice, music) can play. A failed asset counts as "ready" so we
  // surface its error instead of blocking forever. Stops music from playing
  // over a visual/voice that hasn't loaded yet.
  const activeSceneVisualReady = computed(() => {
    if (!activeSceneVisualUrl.value) return true;
    return activeSceneVisualLoaded.value || visualLoadFailed.value;
  });
  const activeSceneVoiceReady = computed(() => {
    const id = activeSceneId.value;
    if (!id || !activeSceneAudioUrl.value) return true;
    const cached = mediaCache.value.audio[id];
    return Boolean(cached?.loaded || cached?.failed);
  });
  const activeSceneMusicReady = computed(() => {
    if (!activeMusicTrackUrl.value) return true;
    return musicReady.value;
  });
  const activeSceneAssetsReady = computed(
    () => activeSceneVisualReady.value && activeSceneVoiceReady.value && activeSceneMusicReady.value
  );
  // Show the loading overlay (scene mode) while any asset is still loading.
  const isSceneLoading = computed(
    () => previewMode.value === "scene"
      && !activeSceneAssetsReady.value
      && Boolean(activeSceneVisualUrl.value || activeSceneAudioUrl.value || activeMusicTrackUrl.value)
  );
  // Music readiness: false the moment a track is bound (needs loading), true
  // when there's none. The live music <audio> flips it true on canplay.
  watch(activeMusicTrackUrl, (url) => { musicReady.value = !url; }, { immediate: true });
  // If the user pressed play while assets were still loading, start the moment
  // everything is ready.
  watch(activeSceneAssetsReady, (ready) => {
    if (ready && pendingScenePlay.value && previewMode.value === "scene") {
      pendingScenePlay.value = false;
      startPreviewPlay();
    }
  });
  const showTextCardPreview = computed(
    () => !currentVisualUrl.value && activeSceneVisualType.value === "text_card"
  );
  const showWaveformPreview = computed(
    () => activeSceneVisualType.value === "waveform"
  );
  const waveformLive = ref([0.28, 0.52, 0.34, 0.76, 0.42, 0.88, 0.48, 0.66, 0.31, 0.58, 0.40, 0.72, 0.35, 0.65]);
  let waveformRafId = null;

  // Web Audio API state — read the live mix from narration and music elements
  let waveformAudioCtx = null;
  let waveformAnalyser = null;
  let waveformConnectedCount = 0;
  let waveformUserActivated = false;
  const waveformConnectedEls = new WeakMap();

  // Simulation fallback state (speech-like energy distribution)
  let waveformSimBands = new Array(14).fill(0.05);
  let waveformSimLastMs = 0;

  function waveformEnsureContext() {
    if (waveformAudioCtx && waveformAnalyser) return true;

    try {
      waveformAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
      waveformAnalyser = waveformAudioCtx.createAnalyser();
      waveformAnalyser.fftSize = 128;
      waveformAnalyser.smoothingTimeConstant = 0.82;
      waveformAnalyser.connect(waveformAudioCtx.destination);
      return true;
    } catch {
      waveformAudioCtx = null;
      waveformAnalyser = null;
      return false;
    }
  }

  function mediaCrossOriginMode(url) {
    if (!url) return null;

    try {
      const resolved = new URL(url, window.location.origin);
      return resolved.origin === window.location.origin || resolved.origin === API_ORIGIN
        ? MEDIA_ELEMENT_CROSS_ORIGIN
        : null;
    } catch {
      return null;
    }
  }

  function unlockWaveformAudio() {
    waveformUserActivated = true;
    if (!waveformEnsureContext()) return false;

    if (waveformAudioCtx?.state === "suspended") {
      waveformAudioCtx.resume().catch(() => {});
    }

    waveformTryConnect();
    return true;
  }

  function waveformTryConnectElement(el) {
    if (!el || waveformConnectedEls.has(el)) return Boolean(el && waveformConnectedEls.has(el));
    if (!waveformUserActivated || !waveformAudioCtx || !waveformAnalyser) return false;
    if (!mediaCrossOriginMode(el.currentSrc || el.src)) return false;

    try {
      const src = waveformAudioCtx.createMediaElementSource(el);
      src.connect(waveformAnalyser);
      waveformConnectedEls.set(el, src);
      waveformConnectedCount += 1;
      return true;
    } catch {
      // SecurityError (CORS) or already connected elsewhere — fall through to simulation
      return false;
    }
  }

  function waveformTryConnect() {
    return [audioRef.value, musicAudioRef.value].some((el) => waveformTryConnectElement(el));
  }

  function waveformHasActiveSource() {
    const narrationPlaying = Boolean(audioRef.value && !audioRef.value.paused && activeSceneAudioUrl.value);
    const musicPlaying = Boolean(musicAudioRef.value && !musicAudioRef.value.paused && activeMusicTrackUrl.value);
    return narrationPlaying || musicPlaying;
  }

  function tickWaveform(ts) {
    const playing = isPreviewPlaying.value;
    const audioActive = playing && waveformHasActiveSource();

    if (audioActive && waveformConnectedCount > 0 && waveformAnalyser) {
      // Real frequency data from the active audio mix
      waveformTryConnect();
      if (waveformAudioCtx?.state === "suspended") waveformAudioCtx.resume().catch(() => {});
      const data = new Uint8Array(waveformAnalyser.frequencyBinCount);
      waveformAnalyser.getByteFrequencyData(data);
      const count = waveformLive.value.length;
      const usableBins = Math.max(count, Math.floor(data.length * 0.8));
      const binsPerBar = Math.max(1, Math.floor(usableBins / count));

      // Average small frequency ranges so voice and music both produce stable motion.
      waveformLive.value = Array.from({ length: count }, (_, i) => {
        const start = i * binsPerBar;
        const end = i === count - 1 ? usableBins : Math.min(usableBins, start + binsPerBar);
        let total = 0;

        for (let bin = start; bin < end; bin += 1) {
          total += data[bin] ?? 0;
        }

        const avg = total / Math.max(1, end - start);
        return Math.max(0.04, Math.min(1, avg / 180));
      });
    } else if (playing) {
      // Fallback: speech-like pseudo-reactive simulation
      // Refresh target band heights every ~80 ms with a realistic speech energy envelope
      if (!waveformSimLastMs || ts - waveformSimLastMs > 80) {
        waveformSimLastMs = ts;
        const energy = 0.3 + Math.random() * 0.7;
        const n = waveformSimBands.length;
        waveformSimBands = waveformSimBands.map((_, i) => {
          const pos = i / n;
          // Energy peaks in low-mid range (speech formants), tapers at extremes
          const envelope = Math.max(0, 1 - Math.pow(pos * 2.5 - 0.5, 2) * 2.2);
          return Math.max(0.06, Math.min(1, energy * envelope * (0.3 + Math.random() * 0.7)));
        });
      }
      // Lerp smoothly toward target heights
      waveformLive.value = waveformLive.value.map((cur, i) => cur + (waveformSimBands[i] - cur) * 0.28);
    } else {
      // Paused or stopped: decay to near-zero idle dots
      waveformLive.value = waveformLive.value.map(v => Math.max(0.04, v * 0.75));
    }

    waveformRafId = requestAnimationFrame(tickWaveform);
  }

  function startWaveformAnimation() {
    if (!waveformRafId) {
      waveformRafId = requestAnimationFrame(tickWaveform);
    }
  }

  function stopWaveformAnimation() {
    if (waveformRafId) { cancelAnimationFrame(waveformRafId); waveformRafId = null; }
  }
  const audiogramBgStyle = computed(() => {
    const bg = AUDIOGRAM_BACKGROUNDS.find(b => b.key === audiogramBg.value);
    return { background: bg ? bg.css : AUDIOGRAM_BACKGROUNDS[0].css };
  });
  const activeVoiceName = computed(() => {
    const voiceId = activeScene.value?.voice_settings?.voice_id;
    const match = voiceProfiles.value.find(
      (profile) => profile.provider_voice_key === voiceId
    );
    if (match?.name) return match.name;
    return voiceId ? voiceId.charAt(0).toUpperCase() + voiceId.slice(1) : "Default";
  });
  const activeVoiceSpeed = computed(
    () => activeScene.value?.voice_settings?.speed ?? 1.0
  );
  const activeVoiceOutdated = computed(
    () => Boolean(activeScene.value?.voice_settings?.is_outdated)
  );
  // Hook options sorted by score descending; unscored hooks go last.
  const sortedHookOptions = computed(() =>
    [...hookOptions.value].sort((a, b) => {
      const sa = a.hook_score ?? -1;
      const sb = b.hook_score ?? -1;
      return sb - sa;
    })
  );

  // C9 — on-demand hook generation: re-roll + re-score the project's hook options
  // from its script (LLM text, no credit charge). Poll for the scored results.
  const hooksGenerating = ref(false);
  const hasScriptForHooks = computed(() => !!String(project.value?.script_text || "").trim());
  async function regenerateHooks() {
    if (!project.value || hooksGenerating.value) return;
    hooksGenerating.value = true;
    try {
      await api.post(`/projects/${project.value.id}/hooks/generate`);
      pushToast({
        id: `hooks-${Date.now()}`,
        title: "Generating hooks",
        message: "Fresh hook options are being written and scored…",
        created_at: new Date().toISOString(),
      });
      // GenerateHooksJob → ScoreHooksJob run async; poll the payload until scores land.
      const startCount = hookOptions.value.length;
      for (let i = 0; i < 8; i++) {
        await new Promise((r) => setTimeout(r, 2500));
        try {
          await refreshProjectPayload();
        } catch {
          /* transient — keep polling */
        }
        const scored = hookOptions.value.some((h) => h.hook_score != null);
        if (hookOptions.value.length !== startCount && scored) break;
      }
    } catch (e) {
      pushToast({
        id: `hooks-err-${Date.now()}`,
        title: "Hook generation failed",
        message: e.response?.data?.error?.message || "Could not generate hooks.",
        created_at: new Date().toISOString(),
      });
    } finally {
      hooksGenerating.value = false;
    }
  }
  // True when the active scene's visual is a still image (not video, text card, or waveform).
  // Ken Burns motion controls only appear in this state.
  const activeSceneIsStillImage = computed(() => {
    if (!activeScene.value) return false;
    const vtype = String(activeScene.value.visual_type || "");
    if (vtype === "text_card" || vtype === "waveform") return false;
    if (!activeScene.value.visual_asset) return false;
    return !activeSceneVisualIsVideo.value;
  });
  const activeMotionClass = computed(() => {
    if (!activeSceneIsStillImage.value) return "";
    const effect = motionEffectDraft.value;
    if (effect === "static") return "";
    return "kb-" + effect.replace(/_/g, "-");
  });
  const activeSceneIndex = computed(() =>
    scenes.value.findIndex((scene) => scene.id === activeSceneId.value)
  );
  // Preview box matches the project's real aspect ratio (was locked to 9:16).
  // Fit within a 480×480 bounding box so the layout stays stable across ratios.
  // The preview box is an inline style, so it beats every stylesheet — a media
  // query cannot shrink it. On a phone the player is a sticky header with the
  // scene list scrolling beneath, so its size has to come from the viewport
  // rather than a fixed 480px box that leaves no room for anything else.
  // Which editor sheet is open on a phone: 'scenes' | 'config' | null.
  // mobile-editor-v3.html makes the editor a fixed-height app — bar, canvas,
  // dock — rather than a scrolling page, so the scene list and the config panel
  // both live in sheets reached from the dock instead of stacking below the
  // player where neither could be found.
  const mobileSheet = ref(null);
  const mobilePanelOpen = computed(() => mobileSheet.value === 'config');
  function openPanel(tab) {
    if (mobileSheet.value === 'config' && cruiseTab.value === tab) {
      mobileSheet.value = null;
      return;
    }
    cruiseTab.value = tab;
    mobileSheet.value = 'config';
  }
  function toggleSheet(name) {
    mobileSheet.value = mobileSheet.value === name ? null : name;
  }

  const viewport = ref({
    w: typeof window !== "undefined" ? window.innerWidth : 1440,
    h: typeof window !== "undefined" ? window.innerHeight : 900,
  });
  function onViewportResize() {
    viewport.value = { w: window.innerWidth, h: window.innerHeight };
    syncAppHeight();
  }

  // iOS Safari's 100dvh does not reliably equal what you can actually see, so
  // the shell is sized from the visual viewport instead. It's a min-height, not a
  // height, and the page still scrolls: when the measurement is wrong the content
  // is reachable rather than stranded past the bottom of a locked page. The dock
  // is fixed on top of all that, so it's on screen either way.
  function syncAppHeight() {
    if (typeof window === "undefined") return;
    const h = window.visualViewport?.height ?? window.innerHeight;
    document.documentElement.style.setProperty("--wyv-app-h", `${Math.round(h)}px`);
  }


  const isPhone = computed(() => viewport.value.w <= 860);

  const previewContainerStyle = computed(() => {
    const ratio = project.value?.aspect_ratio || "9:16";
    const [w, h] = { "9:16": [9, 16], "16:9": [16, 9], "1:1": [1, 1] }[ratio] || [9, 16];
    // On a phone the frame grows to fill the canvas — `.canvas{flex:1}` with
    // `.frame{flex:1;aspect-ratio}` in the mockup — and an inline pixel size
    // would override any stylesheet trying to do that. So return nothing and
    // let CSS own it.
    if (viewport.value.w <= 860) return {};

    const box = 480;
    const scale = box / Math.max(w, h);
    return { width: `${Math.round(w * scale)}px`, height: `${Math.round(h * scale)}px` };
  });
  const projectTitle = computed(
    () => project.value?.title || `Project #${projectId.value}`
  );

  // The phone bar carries one status word where the desktop has a whole pill of
  // export detail. Export state wins when there is one — it's what you came back
  // to check — otherwise the project's own readiness.
  const mobileStatus = computed(() => {
    const job = latestExportJob.value;
    if (job) {
      if (job.status === 'completed') return { text: 'Ready', tone: 'ok' };
      if (job.status === 'failed') return { text: 'Failed', tone: 'bad' };
      return { text: exportStatusCopy(job).replace('Export ', ''), tone: 'busy' };
    }
    if (!scenes.value.length) return null;
    return { text: 'Draft', tone: 'idle' };
  });
  const editingTitle = ref(false);
  const titleDraft = ref('');
  const titleSaving = ref(false);

  async function startEditTitle() {
    titleDraft.value = project.value?.title || '';
    editingTitle.value = true;
    await nextTick();
    document.getElementById('editor-title-input')?.select();
  }

  async function commitTitle() {
    editingTitle.value = false;
    const title = titleDraft.value.trim();
    if (!title || title === project.value?.title) return;
    titleSaving.value = true;
    try {
      await api.patch(`/projects/${projectId.value}`, { title });
      if (project.value) project.value.title = title;
    } catch { /* revert silently */ } finally {
      titleSaving.value = false;
    }
  }

  const unreadCount = computed(() =>
    notifications.value.filter((item) => !item.is_read).length
  );
  const latestExportJob = computed(() => exportJobs.value[0] ?? null);
  // Exporting several aspect ratios makes one file each (C10). The newest finished file per ratio, newest first; the
  // ratio chips pick which one Open, Download, Schedule, approval and sharing act on (2026-10-10). Before, everything
  // used the newest export only and the other ratio had no way in.
  const exportsByRatio = computed(() => {
    const seen = new Set();
    return exportJobs.value.filter((job) => job.status === "completed" && job.output_asset?.storage_url
      && !seen.has(job.aspect_ratio) && seen.add(job.aspect_ratio));
  });
  const selectedExportRatio = ref(null);
  const activeExportJob = computed(() =>
    exportsByRatio.value.find((job) => job.aspect_ratio === selectedExportRatio.value)
    ?? (latestExportJob.value?.status === "completed" ? latestExportJob.value : exportsByRatio.value[0] ?? latestExportJob.value));

  // ── Post-export feedback (once per user, ever) ──────────────────────────
  // Fires when an export completes in this session — the peak moment, when the
  // result is fresh on screen. Two steps: stars + aspect chips (which flip
  // between "what stood out" and "what fell short" based on the rating), then
  // an optional comment. Dismissing counts as answered — one ask per user.
  const exportFbOpen = ref(false);
  const exportFbRating = ref(0);
  const exportFbOptions = ref([]);
  const exportFbComment = ref('');
  const exportFbSending = ref(false);
  const exportFbDone = ref(false);
  let exportFbShownThisSession = false;

  const EXPORT_FB_POSITIVE = ['Video quality', 'Voices', 'Script', 'Speed', 'Ease of use', 'Value for credits'];
  const EXPORT_FB_NEGATIVE = ['Video quality', 'Voices sounded off', 'Script missed the mark', 'Too slow', 'Editor was confusing', 'Cost too many credits'];
  const exportFbChips = computed(() => exportFbRating.value >= 4 ? EXPORT_FB_POSITIVE : EXPORT_FB_NEGATIVE);

  watch(() => latestExportJob.value?.status, (status, prev) => {
    if (status !== 'completed' || prev === 'completed' || prev === undefined) return;
    if (exportFbShownThisSession) return;
    if (mePayload.value?.preferences?.export_feedback_done) return;
    exportFbShownThisSession = true;
    // Let the "Export complete" moment land first.
    setTimeout(() => { exportFbOpen.value = true; }, 1800);
  });

  function exportFbToggle(chip) {
    const i = exportFbOptions.value.indexOf(chip);
    if (i >= 0) exportFbOptions.value.splice(i, 1); else exportFbOptions.value.push(chip);
  }

  async function exportFbSubmit(dismissed = false) {
    if (exportFbSending.value) return;
    exportFbSending.value = true;
    try {
      await api.post('/feedback/export', dismissed ? { dismissed: true } : {
        rating: exportFbRating.value || null,
        options: exportFbOptions.value,
        comment: exportFbComment.value.trim() || null,
        project_id: Number(projectId.value) || null,
        export_job_id: latestExportJob.value?.id ?? null,
      });
    } catch { /* feedback must never error at the user */ }
    finally {
      exportFbSending.value = false;
      if (mePayload.value?.preferences) mePayload.value.preferences.export_feedback_done = true;
      if (dismissed) { exportFbOpen.value = false; }
      else { exportFbDone.value = true; setTimeout(() => { exportFbOpen.value = false; }, 2000); }
    }
  }
  // Download is the funnel's blind spot: every paying customer exports, then
  // the product loses sight of the video. This is the only event that can tell
  // export-then-post-manually apart from export-then-nothing. Client-side by
  // necessity — the file is fetched straight from storage, no API hop to hook.
  function trackVideoDownloaded(via) {
    window.__posthog?.capture('video_downloaded', {
      via,
      project_id: Number(projectId.value),
      export_job_id: activeExportJob.value?.id ?? null,
    })
  }
  // Checks on the finished file (sound, blank frames, every word spoken, length), 2026-10-28: notes, never a block.
  const exportChecks = computed(() => Array.isArray(activeExportJob.value?.checks) ? activeExportJob.value.checks : [])
  const exportCheckNotes = computed(() => exportChecks.value.filter((c) => c.status === 'note'))

  const latestExportDownloadUrl = computed(
    () => activeExportJob.value?.output_asset?.storage_url ?? null
  );
  function hasPendingExportChanges() {
    return [scriptSaveState, voiceSaveState, captionSaveState, motionSaveState,
      musicSaveState, audiogramSaveState, visualStyleSaveState, customVisualStyleSaveState]
      .some(state => ['pending', 'saving', 'error'].includes(state.value))
      || Boolean(musicSaveTimer || sceneVoiceVolumeSaveTimer || sceneSoundVolumeSaveTimer || audiogramSaveTimer)
      || sceneDurationSaving.value || Boolean(sceneDurationError.value)
      || volumeSavesPending.value > 0 || Boolean(volumeSaveError.value || musicSaveError.value)
      || Boolean(activeScene.value && sceneScriptDraft.value !== (activeScene.value.script_text || ''));
  }

  async function performExportAction(action, job) {
    deliveryExport.value = job;
    mobileSheet.value = null;
    if (action === 'schedule') { scheduleModalOpen.value = true; return; }
    if (action === 'approval') { approvalModalOpen.value = true; return; }
    if (action === 'share') { await toggleShareLink(); return; }
    const url = action === 'download' ? (job.download_url || job.output_asset?.storage_url) : job.output_asset?.storage_url;
    if (!url) return;
    trackVideoDownloaded(action);
    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener';
    if (action === 'download') link.download = job.file_name || 'video.mp4';
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  // Whether the exported video still matches the project. The guard below
  // already asks this before delivering, but only reactively — the toolbar
  // showed "Export ready" and "Update video" side by side with no way to tell
  // which applied, so pressing Update was the natural move even when nothing
  // had changed. That is how one project got rendered twice a second apart.
  const exportStaleOnServer = ref(false);

  async function refreshExportFreshness() {
    const job = latestExportJob.value;
    if (!job || job.status !== 'completed') { exportStaleOnServer.value = false; return; }
    try {
      const { data } = await api.get(`/projects/${projectId.value}/exports/${job.id}/freshness`);
      exportStaleOnServer.value = !!data?.data?.is_stale;
    } catch {
      // Unknown is not stale — never nag on a failed check.
      exportStaleOnServer.value = false;
    }
  }

  // Unsaved edits count too, so the toolbar reacts as they type rather than
  // waiting for a save to land.
  const exportToolbar = computed(() => exportToolbarState(latestExportJob.value, exportOutOfDate.value));

  const exportOutOfDate = computed(() =>
    !!latestExportJob.value
    && latestExportJob.value.status === 'completed'
    && (exportStaleOnServer.value || hasPendingExportChanges())
  );

  watch(() => latestExportJob.value?.id, () => { refreshExportFreshness() }, { immediate: true });
  watch(() => hasPendingExportChanges(), (pending, was) => {
    if (was && !pending) refreshExportFreshness();
  });

  const { warning: exportWarning, checking: exportActionChecking, request: requestExportAction,
    cancel: cancelExportAction, continuePrevious: continueExportAction, updateFirst: updateExportAction } = useExportActionGuard({
    projectId: () => projectId.value,
    getExport: () => activeExportJob.value,
    hasPendingChanges: hasPendingExportChanges,
    perform: performExportAction,
    update: queueExport,
  });

  // Resume-failed: scan scenes for image / animation failures so we can
  // surface a banner that re-runs everything in one click. Mirrors the
  // backend's needs_visual + animation_last_error classification in
  // ProjectController::resumeFailed.
  const failedScenes = computed(() => {
    const failed = [];
    for (const s of scenes.value) {
      const cfg = s.image_generation_settings ?? {};
      const imageBroken = !!cfg.needs_visual || (cfg.last_error && !s.visual_asset_id);
      const animBroken  = !!cfg.animation_last_error && !cfg.animation_video_asset_id;
      if (imageBroken) failed.push({ id: s.id, order: s.scene_order, kind: 'image' });
      else if (animBroken) failed.push({ id: s.id, order: s.scene_order, kind: 'animate' });
    }
    return failed;
  });
  const failedSceneCount = computed(() => failedScenes.value.length);
  const resumePending = ref(false);
  const resumeError = ref('');
  async function resumeFailedScenes() {
    if (!project.value?.id || resumePending.value || failedSceneCount.value === 0) return;
    resumePending.value = true;
    resumeError.value = '';
    try {
      const res = await api.post(`/projects/${project.value.id}/resume-failed`);
      pushToast({
        id: `resume-${Date.now()}`,
        title: `Resuming ${res.data?.data?.resumed ?? failedSceneCount.value} scene${failedSceneCount.value === 1 ? '' : 's'}`,
        message: 'Re-dispatched the failed jobs — they\'ll finish in the background.',
      });
      // Pop active scene poller so the editor picks up the new state as
      // each scene completes.
      for (const f of failedScenes.value) pollSceneUntilVisual(f.id);
    } catch (e) {
      const code = e?.response?.status;
      if (code === 402) {
        resumeError.value = e.response.data?.error?.message ?? 'Not enough credits to resume.';
      } else {
        resumeError.value = e.response?.data?.error?.message ?? 'Resume failed.';
      }
    } finally {
      resumePending.value = false;
    }
  }

  const wholeVideoTake = computed(() => ['one_shot', 'restyle'].includes(project.value?.visual_brief?.ugc_format));
  const exportBlockerMessage = computed(() => editorReadiness(scenes.value, wholeVideoTake.value));

  // Per-section error indicators for the active scene
  const activeSceneVisualError = computed(() => {
    const scene = activeScene.value;
    if (!scene) return null;
    const VISUAL_OPTIONAL = ['text_card', 'waveform'];
    if (!scene.visual_asset_id && !VISUAL_OPTIONAL.includes(String(scene.visual_type || '')))
      return 'No visual assigned — pick a stock clip, image, or generate with AI.'
    return null;
  });

  const activeSceneVoiceError = computed(() => {
    const scene = activeScene.value;
    if (!scene) return null;
    if (!scene.voice_settings?.audio_asset_id)
      return 'No voice generated — wait for TTS to finish or click Regenerate.'
    return null;
  });

  const activeSceneScriptError = computed(() => {
    const scene = activeScene.value;
    if (!scene) return null;
    if (!String(scene.script_text || '').trim())
      return 'Scene has no script — add copy before this scene can be exported.'
    return null;
  });

  const captionPreviewClass = computed(() => {
    if (!captionEnabledDraft.value || !captionsCanRender.value) return "caption-hidden";
    if (captionStyleDraft.value === "editorial") return "caption-style-editorial";
    if (captionStyleDraft.value === "hacker") return "caption-style-hacker";
    return "caption-style-impact";
  });
  const captionsCanRender = computed(() => {
    if (
      activeSceneAIImagePending.value ||
      activeSceneVisualGenerationError.value ||
      visualLoadFailed.value
    ) return false;
    if (activeSceneVisualUrl.value) return activeSceneVisualLoaded.value;
    return showTextCardPreview.value || showWaveformPreview.value;
  });
  const captionPositionStyle = computed(() => {
    if (captionPositionDraft.value === "center")
      return { top: "50%", transform: "translateY(-50%)", bottom: "auto" };
    if (captionPositionDraft.value === "top_third")
      return { top: "5%", bottom: "auto" };
    return {};
  });
  const flattenedCaptionFonts = computed(() =>
    CAPTION_FONT_GROUPS.flatMap((group) =>
      group.fonts.map((font) => ({ font, group: group.label }))
    )
  );
  const selectedCaptionFont = computed(
    () =>
      flattenedCaptionFonts.value.find((item) => item.font === captionFontDraft.value) || {
        font: captionFontDraft.value || DEFAULT_CAPTION_FONT,
        group: "Custom",
      }
  );
  const captionFontStyle = computed(() => {
    // The export makes a caption basePx/480 of the video's height (playResY/480). The preview does the same against the
    // frame's real height in container units (cqh on .preview-container), not an assumed 480px: on a smaller screen the
    // frame shrinks while fixed pixels did not, so captions previewed bigger than they exported (2026-10-10).
    const basePx = captionBasePx(captionSizeDraft.value);
    return {
      fontFamily: fontFamilyValue(captionFontDraft.value || DEFAULT_CAPTION_FONT),
      fontSize: `${((basePx / 480) * 100).toFixed(3)}cqh`,
    };
  });
  // The font picker shows the face only: its label sits outside the preview frame.
  const captionFamilyStyle = computed(() => ({ fontFamily: fontFamilyValue(captionFontDraft.value || DEFAULT_CAPTION_FONT) }));
  const captionSizeIsCustom = computed(() => /^\d+$/.test(String(captionSizeDraft.value ?? "")));
  const activeCaptionSettings = computed(
    () => activeScene.value?.caption_settings ?? activeScene.value?.caption_settings_json ?? {}
  );

  const sceneTypeOptions = [
    "Narration",
    "Hook",
    "Transition",
    "Text Card",
    "Quote",
  ];
  const addSceneStockTypeOptions = ["Stock Clip", "BG Loop", "Text Only", "Audiogram"];
  const visualSourceTypeMap = {
    "Stock Video": "stock_clip",
    "Stock Image": "image_montage",
    "Stock Clip": "stock_clip",
    "BG Loop": "background_loop",
    "AI Image": "ai_image",
    "Text Only": "text_card",
    Audiogram: "waveform",
  };
  const visualTypeLabelMap = {
    image_montage: "Stock Clip",
    stock_clip: "Stock Clip",
    background_loop: "BG Loop",
    ai_image: "AI Image",
    text_card: "Text Only",
    waveform: "Audiogram",
  };
  const REWRITE_EDIT_OPTIONS = [
    { label: "Shorten", mode: "shorten" },
    { label: "Expand", mode: "expand" },
    { label: "Simplify", mode: "simplify" },
    { label: "Stronger hook", mode: "stronger_hook" },
  ];
  const REWRITE_TONE_OPTIONS = [
    { label: "Punchier", mode: "more_punchy" },
    { label: "Dramatic", mode: "more_dramatic" },
    { label: "Scarier", mode: "scarier" },
    { label: "Documentary", mode: "more_documentary" },
    { label: "Educational", mode: "more_educational" },
  ];

  watch(
    activeScene,
    (scene, prevScene) => {
      const sceneChanged = scene?.id !== prevScene?.id;

      if (sceneChanged) {
        // Scene switch — cancel pending saves and reset all draft state
        if (scriptSaveTimer) {
          window.clearTimeout(scriptSaveTimer);
          scriptSaveTimer = null;
        }
        if (voiceSaveTimer) {
          window.clearTimeout(voiceSaveTimer);
          voiceSaveTimer = null;
        }
        if (captionSaveTimer) {
          window.clearTimeout(captionSaveTimer);
          captionSaveTimer = null;
        }
        sceneScriptDraft.value = scene?.script_text || "";
        voiceProfileKey.value = scene?.voice_settings?.voice_id || "Kore";
        voiceDirectionDraft.value = scene?.voice_settings?.voice_prompt || "";
        voiceSpeedDraft.value = String(scene?.voice_settings?.speed ?? 1.0);
        voiceStabilityDraft.value = String(scene?.voice_settings?.stability ?? "medium");
        visualQueryDraft.value = scene?.visual_prompt || "";
        const rawType = String(scene?.visual_type || "");
        const assetTags = Array.isArray(scene?.visual_asset?.tags) ? scene.visual_asset.tags : [];
        const isLibraryAsset = scene?.visual_asset && !assetTags.includes("matched_visual") && rawType !== "ai_image";
        if (rawType === "ai_image") selectedSwapVisualSource.value = "AI Image";
        else if (rawType === "waveform") selectedSwapVisualSource.value = "Audiogram";
        else if (isLibraryAsset) selectedSwapVisualSource.value = "My Assets";
        else if (rawType === "image_montage") selectedSwapVisualSource.value = "Stock Image";
        else if (rawType === "background_loop") selectedSwapVisualSource.value = "Stock Video";
        else selectedSwapVisualSource.value = "Stock Video";
        // Restore audiogram settings
        const imgSettings = scene?.image_generation_settings ?? scene?.image_generation_settings_json ?? {};
        audiogramStyle.value = imgSettings.audiogram_style ?? "bars";
        audiogramColor.value = imgSettings.audiogram_color ?? "#ff6b35";
        audiogramBg.value = imgSettings.audiogram_bg ?? "dark";
        // Restore per-scene voice + sound volume
        const voiceSettingsForVol = scene?.voice_settings ?? scene?.voice_settings_json ?? {};
        sceneVoiceVolume.value = Math.max(0, Math.min(200, parseInt(voiceSettingsForVol.volume ?? 100, 10) || 100));
        const soundSettings = scene?.sound_settings_json ?? scene?.sound_settings ?? {};
        sceneSoundVolume.value = Math.max(0, Math.min(200, parseInt(soundSettings.volume ?? 100, 10) || 100));
        const captionSettings = normalizeCaptionSettings(
          scene?.caption_settings || scene?.caption_settings_json
        );
        captionEnabledDraft.value = captionSettings.enabled !== false;
        captionStyleDraft.value = String(captionSettings.style_key);
        captionHighlightDraft.value = String(captionSettings.highlight_mode);
        captionPositionDraft.value = String(captionSettings.position);
        captionFontDraft.value = String(captionSettings.font);
        captionColorDraft.value = captionSettings.color || "#ffffff";
        captionSizeDraft.value = captionSettings.size || "medium";
        captionHighlightColorDraft.value = captionSettings.highlight_color || "#ff6b35";
        captionAnimationDraft.value = String(captionSettings.animation || "plain");
        captionHighlightStyleDraft.value = String(captionSettings.highlight_style || "color");
        captionPanelColorDraft.value = captionSettings.panel_color || "";
        captionBackdropDraft.value =
          captionSettings.backdrop === undefined ? null : captionSettings.backdrop;
        fontDropdownOpen.value = false;
        const motionSettings = scene?.motion_settings || scene?.motion_settings_json || {};
        motionEffectDraft.value = String(motionSettings.effect || "zoom_in");
        motionIntensityDraft.value = String(motionSettings.intensity || "moderate");
        visualStyleDraft.value = scene?.visual_style ?? scene?.image_generation_settings?.style ?? project.value?.ai_broll_style ?? null;
        // Reuse the model the scene was last generated with, so a retry /
        // regenerate stays in sync with the original plan instead of snapping
        // back to the default. (prompt stays blank → backend reuses visual_prompt.)
        aiImageModelKey.value = scene?.image_generation_settings?.model_key ?? 'gpt-image-1';
        visualStyleSaveState.value = "idle";
        customVisualStyleDraft.value = scene?.custom_visual_style ?? project.value?.custom_visual_style ?? "";
        customVisualStyleSaveState.value = "idle";
        aiImagePending.value = false;
        aiImageError.value = "";
        visualSwapPending.value = false;
        visualSwapError.value = "";
        voiceSaveState.value = "idle";
        voiceSaveError.value = "";
        scriptSaveState.value = "idle";
        scriptSaveError.value = "";
        captionSaveState.value = "idle";
        captionSaveError.value = "";
        rewriteToolsVisible.value = false; // collapse rewrite on scene switch
        rewritePreviewVisible.value = false;
        rewritePreviewCopy.value = "";
        rewriteCustomInstruction.value = "";
        rewriteMode.value = "";
        rewriteError.value = "";
        sceneDurationDraft.value = String(scene?.duration_seconds ?? "");
        sceneDurationSaving.value = false;
        // Reset and reload both visual and audio
        if (audioRef.value) {
          audioRef.value.pause();
          audioRef.value.load();
          isAudioPlaying.value = false;
        }
        if (soundAudioRef.value) {
          soundAudioRef.value.pause();
          soundAudioRef.value.load();
        }
        isAudioLoading.value = false;
        visualLoadFailed.value = false;
        syncActiveSceneMedia(scene);
        if (isPreviewPlaying.value) {
          nextTick(() => playActiveSceneAudio(pendingPreviewAudioOffset));
        }
        return;
      }

      // Same scene updated — selectively reload only what changed
      const prevVisualUrl = prevScene?.visual_asset?.storage_url ?? null;
      const nextVisualUrl = scene?.visual_asset?.storage_url ?? null;
      const visualChanged =
        scene?.visual_asset_id !== prevScene?.visual_asset_id ||
        nextVisualUrl !== prevVisualUrl;

      const prevAudioUrl = prevScene?.audio_asset?.storage_url ?? null;
      const nextAudioUrl = scene?.audio_asset?.storage_url ?? null;
      const audioChanged = nextAudioUrl !== prevAudioUrl;
      const prevSoundUrl = prevScene?.sound_asset?.storage_url ?? null;
      const nextSoundUrl = scene?.sound_asset?.storage_url ?? null;
      const soundChanged = nextSoundUrl !== prevSoundUrl;

      if (visualChanged) {
        visualLoadFailed.value = false;
        syncActiveSceneMedia(scene);
        if (sceneHasResolvedAIImage(scene)) {
          aiImagePending.value = false;
          aiImageError.value = "";
        }
      }

      if (audioChanged) {
        if (audioRef.value) {
          audioRef.value.pause();
          audioRef.value.load();
          isAudioPlaying.value = false;
        }
        isAudioLoading.value = false;
        if (scene?.audio_asset?.storage_url) {
          preloadSceneAudio(scene);
        }
        if (isPreviewPlaying.value) {
          nextTick(() => playActiveSceneAudio(pendingPreviewAudioOffset));
        }
      }

      if (soundChanged) {
        if (soundAudioRef.value) {
          soundAudioRef.value.pause();
          soundAudioRef.value.load();
        }
        if (isPreviewPlaying.value) {
          nextTick(() => playActiveSceneSound(pendingPreviewAudioOffset));
        }
      }
    },
    { immediate: true }
  );

  watch(activeMusicTrackUrl, () => {
    if (!musicAudioRef.value) return;
    musicAudioRef.value.pause();
    musicAudioRef.value.load();
    if (isPreviewPlaying.value) {
      nextTick(() => playPreviewMusic());
    }
  });

  watch(previewMusicVolume, () => {
    syncPreviewMusicVolume();
  });

  watch([voiceProfileKey, voiceSpeedDraft, voiceStabilityDraft, voiceDirectionDraft], () => {
    const scene = activeScene.value;

    if (!scene) return;

    const savedVoice = scene.voice_settings || {};
    const nextSettings = {
      voice_id: voiceProfileKey.value,
      speed: Number(voiceSpeedDraft.value || 1),
      stability: voiceStabilityDraft.value,
      // Gemini delivery direction; ignored by OpenAI voices. Empty string clears it.
      voice_prompt: activeVoiceIsGemini.value ? voiceDirectionDraft.value.trim() : "",
    };

    if (
      String(savedVoice.voice_id || "Kore") === nextSettings.voice_id &&
      Number(savedVoice.speed ?? 1) === nextSettings.speed &&
      String(savedVoice.stability || "medium") === nextSettings.stability &&
      String(savedVoice.voice_prompt || "") === nextSettings.voice_prompt
    ) {
      if (voiceSaveTimer) {
        window.clearTimeout(voiceSaveTimer);
        voiceSaveTimer = null;
      }
      if (voiceSaveState.value !== "saved") {
        voiceSaveState.value = "idle";
      }
      voiceSaveError.value = "";
      return;
    }

    if (voiceSaveTimer) {
      window.clearTimeout(voiceSaveTimer);
    }

    voiceSaveState.value = "pending";
    voiceSaveError.value = "";
    voiceSaveTimer = window.setTimeout(() => {
      persistVoiceSettings(scene.id, nextSettings);
    }, 500);
  });

  watch(sceneScriptDraft, (draft) => {
    const scene = activeScene.value;

    if (!scene) return;

    const savedScript = scene.script_text || "";

    if (draft === savedScript) {
      if (scriptSaveTimer) {
        window.clearTimeout(scriptSaveTimer);
        scriptSaveTimer = null;
      }
      if (scriptSaveState.value !== "saved") {
        scriptSaveState.value = "idle";
      }
      scriptSaveError.value = "";
      return;
    }

    if (scriptSaveTimer) {
      window.clearTimeout(scriptSaveTimer);
    }

    scriptSaveState.value = "pending";
    scriptSaveError.value = "";

    scriptSaveTimer = window.setTimeout(() => {
      persistSceneScript(scene.id, draft);
    }, 700);
  });

  watch([captionEnabledDraft, captionStyleDraft, captionHighlightDraft, captionPositionDraft, captionFontDraft, captionColorDraft, captionSizeDraft, captionHighlightColorDraft, captionAnimationDraft, captionHighlightStyleDraft, captionPanelColorDraft, captionBackdropDraft], () => {
    const scene = activeScene.value;

    if (!scene) return;

    const savedCaptions = activeCaptionSettings.value || {};
    const nextSettings = {
      ...savedCaptions,
      enabled: captionEnabledDraft.value,
      style_key: captionStyleDraft.value,
      highlight_mode: captionHighlightDraft.value,
      position: captionPositionDraft.value,
      font: captionFontDraft.value,
      highlight_color: captionHighlightColorDraft.value,
      color: captionColorDraft.value,
      size: captionSizeDraft.value,
      preset_id: savedCaptions.preset_id || null,
      animation: captionAnimationDraft.value,
      highlight_style: captionHighlightStyleDraft.value,
      panel_color: captionPanelColorDraft.value || null,
      backdrop: captionBackdropDraft.value,
    };

    if (
      (savedCaptions.enabled !== false) === nextSettings.enabled &&
      String(savedCaptions.style_key || "impact") === nextSettings.style_key &&
      String(savedCaptions.highlight_mode || "keywords") === nextSettings.highlight_mode &&
      String(savedCaptions.position || "bottom_third") === nextSettings.position &&
      String(savedCaptions.font || DEFAULT_CAPTION_FONT) === nextSettings.font &&
      String(savedCaptions.color || "#ffffff") === nextSettings.color &&
      String(savedCaptions.size || "medium") === nextSettings.size &&
      String(savedCaptions.highlight_color || "#ff6b35") === nextSettings.highlight_color &&
      String(savedCaptions.animation || "plain") === nextSettings.animation &&
      String(savedCaptions.highlight_style || "color") === nextSettings.highlight_style &&
      (savedCaptions.panel_color || null) === nextSettings.panel_color &&
      (savedCaptions.backdrop ?? null) === (nextSettings.backdrop ?? null)
    ) {
      if (captionSaveTimer) {
        window.clearTimeout(captionSaveTimer);
        captionSaveTimer = null;
      }
      if (captionSaveState.value !== "saved") {
        captionSaveState.value = "idle";
      }
      captionSaveError.value = "";
      return;
    }

    if (captionSaveTimer) {
      window.clearTimeout(captionSaveTimer);
    }

    patchSceneCaptionSettings(scene.id, nextSettings);
    captionSaveState.value = "pending";
    captionSaveError.value = "";
    captionSaveTimer = window.setTimeout(() => {
      persistCaptionSettings(scene.id, nextSettings);
    }, 500);
  });

  watch([motionEffectDraft, motionIntensityDraft], () => {
    const scene = activeScene.value;
    if (!scene) return;

    const savedMotion = scene.motion_settings || scene.motion_settings_json || {};
    const nextSettings = {
      effect: motionEffectDraft.value,
      intensity: motionIntensityDraft.value,
    };

    if (
      String(savedMotion.effect || "zoom_in") === nextSettings.effect &&
      String(savedMotion.intensity || "moderate") === nextSettings.intensity
    ) {
      if (motionSaveTimer) {
        window.clearTimeout(motionSaveTimer);
        motionSaveTimer = null;
      }
      if (motionSaveState.value !== "saved") {
        motionSaveState.value = "idle";
      }
      motionSaveError.value = "";
      return;
    }

    if (motionSaveTimer) {
      window.clearTimeout(motionSaveTimer);
    }

    motionSaveState.value = "pending";
    motionSaveError.value = "";
    motionSaveTimer = window.setTimeout(() => {
      persistMotionSettings(scene.id, nextSettings);
    }, 500);
  });

  watch(visualStyleDraft, (nextStyle) => {
    const scene = activeScene.value;
    if (!scene) return;
    if ((nextStyle ?? null) === (scene.visual_style ?? null)) return;
    // Loading a scene fills the draft with the style it inherits; that is not a change to save (it also became an undo
    // step that cleared redo).
    if (scene.visual_style == null && (nextStyle ?? null) === (scene.image_generation_settings?.style ?? project.value?.ai_broll_style ?? null)) return;

    visualStyleSaveState.value = "pending";
    clearTimeout(visualStyleSaveTimer);
    visualStyleSaveTimer = setTimeout(async () => {
      try {
        const response = await api.patch(`/scenes/${scene.id}`, { visual_style: nextStyle });
        const updated = response.data?.data?.scene;
        if (updated) {
          scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
        }
        visualStyleSaveState.value = "saved";
      } catch {
        visualStyleSaveState.value = "idle";
      }
    }, 500);
  });

  watch(customVisualStyleDraft, (nextCustom) => {
    const scene = activeScene.value;
    if (!scene) return;
    const next = (nextCustom ?? "").trim();
    const prev = (scene.custom_visual_style ?? "").trim();
    if (next === prev) return;

    customVisualStyleSaveState.value = "pending";
    clearTimeout(customVisualStyleSaveTimer);
    customVisualStyleSaveTimer = setTimeout(async () => {
      try {
        const response = await api.patch(`/scenes/${scene.id}`, { custom_visual_style: next || null });
        const updated = response.data?.data?.scene;
        if (updated) {
          scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
        }
        customVisualStyleSaveState.value = "saved";
      } catch {
        customVisualStyleSaveState.value = "idle";
      }
    }, 600);
  });

  watch(
    [exportJobs, queuedExportJobId],
    ([jobs, pendingJobId]) => {
      if (!pendingJobId) return;

      const matchingJob = jobs.find((job) => job.id === pendingJobId);

      if (!matchingJob) return;

      if (
        matchingJob.status === "completed" &&
        matchingJob.output_asset?.storage_url
      ) {
        pushToast({
          id: `export-complete-${matchingJob.id}`,
          title: "Export ready",
          message: matchingJob.file_name || "Your rendered MP4 is ready.",
          created_at: new Date().toISOString(),
        });
        window.open(
          matchingJob.output_asset.storage_url,
          "_blank",
          "noopener,noreferrer"
        );
        queuedExportJobId.value = null;
        exportState.value = "idle";
        return;
      }

      if (matchingJob.status === "failed") {
        pushToast({
          id: `export-failed-${matchingJob.id}`,
          title: "Export failed",
          message: matchingJob.failure_reason || "Export failed.",
          created_at: new Date().toISOString(),
        });
        queuedExportJobId.value = null;
        exportState.value = "error";
      }
    },
    { deep: true }
  );

  // Poll export status while any job is pending — covers WebSocket gaps and
  // server-side retried jobs that weren't initiated from this UI session.
  const hasActiveExport = computed(() =>
    exportJobs.value.some((j) => ['queued', 'processing'].includes(j.status))
  )

  // True for the WHOLE export, not just the brief save POST — drives the
  // Export button's disabled + "Exporting…" state for both manual and
  // assistant-triggered exports.
  const exportInProgress = computed(() =>
    exportPending.value || queuedExportJobId.value !== null || hasActiveExport.value
  )

  watch(
    () => queuedExportJobId.value || hasActiveExport.value,
    (active) => {
      if (exportPollTimer) { clearInterval(exportPollTimer); exportPollTimer = null; }
      if (active) {
        exportPollTimer = setInterval(() => loadExportJobs(), 5000);
      }
    },
    { immediate: true }
  );

  function humanizeSceneType(sceneType) {
    return String(sceneType || "narration")
      .replace(/_/g, " ")
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function sceneTypeLabel(scene) {
    return humanizeSceneType(scene?.scene_type);
  }

  function sceneVisualLabel(scene) {
    if (scene?.visual_type === "text_card") return "text card";
    if (scene?.visual_type === "waveform") return "audiogram";
    if (scene?.visual_type === "ai_image") return "ai image";
    if (scene?.visual_type === "background_loop") return "bg loop";
    if (scene?.visual_type === "stock_clip") return "stock clip";
    if (scene?.visual_asset?.asset_type === "video") return "stock clip";
    if (scene?.visual_asset?.asset_type === "image") return "ai image";
    return "bg loop";
  }

  function selectedVisualType() {
    if (selectedSwapVisualSource.value === "Stock Video") {
      return stockVideoSubType.value || "stock_clip";
    }
    return visualSourceTypeMap[selectedSwapVisualSource.value] || "stock_clip";
  }

  function sceneVoiceOutdated(scene) {
    return Boolean(scene?.voice_settings?.is_outdated);
  }

  function normalizeScenePayload(scene) {
    if (!scene) return null;

    const captionSettings = normalizeCaptionSettings(
      scene.caption_settings ?? scene.caption_settings_json
    );

    return {
      ...scene,
      voice_settings: scene.voice_settings ?? scene.voice_settings_json ?? null,
      caption_settings: captionSettings,
      caption_settings_json: captionSettings,
      image_generation_settings: normalizeImageGenerationSettings(scene),
      locked_fields: scene.locked_fields ?? scene.locked_fields_json ?? null,
    };
  }

  function normalizeImageGenerationSettings(scene) {
    const settings = scene?.image_generation_settings ?? scene?.image_generation_settings_json ?? null;
    if (!settings || typeof settings !== "object") return settings;

    if (
      String(scene?.visual_type || "") === "ai_image" &&
      scene?.visual_asset_id &&
      Number(settings.asset_id || 0) === Number(scene.visual_asset_id) &&
      !settings.in_progress
    ) {
      return {
        ...settings,
        needs_visual: false,
        last_error: null,
      };
    }

    return settings;
  }

  function normalizeCaptionSettings(settings, fallback = {}) {
    const source = settings && typeof settings === "object" ? settings : {};
    const fallbackSource = fallback && typeof fallback === "object" ? fallback : {};

    return {
      ...DEFAULT_CAPTION_SETTINGS,
      ...fallbackSource,
      ...source,
      enabled: source.enabled ?? fallbackSource.enabled ?? DEFAULT_CAPTION_SETTINGS.enabled,
      style_key: source.style_key || fallbackSource.style_key || DEFAULT_CAPTION_SETTINGS.style_key,
      highlight_mode:
        source.highlight_mode ||
        fallbackSource.highlight_mode ||
        DEFAULT_CAPTION_SETTINGS.highlight_mode,
      position: source.position || fallbackSource.position || DEFAULT_CAPTION_SETTINGS.position,
      font: source.font || fallbackSource.font || DEFAULT_CAPTION_SETTINGS.font,
      highlight_color:
        source.highlight_color ||
        fallbackSource.highlight_color ||
        DEFAULT_CAPTION_SETTINGS.highlight_color,
      color: source.color || fallbackSource.color || DEFAULT_CAPTION_SETTINGS.color,
      size: source.size || fallbackSource.size || DEFAULT_CAPTION_SETTINGS.size,
      preset_id: source.preset_id ?? fallbackSource.preset_id ?? DEFAULT_CAPTION_SETTINGS.preset_id,
    };
  }

  function sortScenesByOrder(nextScenes) {
    return [...nextScenes].sort(
      (left, right) => Number(left.scene_order || 0) - Number(right.scene_order || 0)
    );
  }

  function replaceSceneInCollection(updatedScene) {
    // A scene came back (often a picture or animation finishing): undo and redo may be available again.
    scheduleHistoryRefresh();
    if (!updatedScene?.id) return;

    const idx = scenes.value.findIndex((s) => s.id === updatedScene.id);
    if (idx === -1) {
      // New scene we don't have yet — append, then sort.
      scenes.value.push(updatedScene);
      scenes.value = sortScenesByOrder(scenes.value);
      return;
    }

    const existing = scenes.value[idx];
    const prevVisualUrl = existing.visual_asset?.storage_url ?? null;
    const captionSettings = normalizeCaptionSettings(
      updatedScene.caption_settings ?? updatedScene.caption_settings_json,
      existing.caption_settings ?? existing.caption_settings_json
    );

    // Mutate the existing scene IN PLACE. Vue 3's fine-grained reactivity
    // only re-renders the bindings whose specific keys changed, instead
    // of remounting every component that touches this scene. Before this,
    // every caption toggle / preview poll / generation event replaced the
    // whole scenes array with new object references and the editor
    // flickered on every API response.
    //
    // Same-asset responses keep the asset object we already have: every PATCH
    // re-signs storage URLs, and adopting the new URL for an UNCHANGED asset
    // forced the <img>/<video>/<audio> to reload — a "Loading scene…" flash on
    // every caption save. A different asset id (regeneration, swap) still
    // takes the fresh payload.
    const keepAssets = {};
    for (const key of ["visual_asset", "audio_asset"]) {
      if (
        updatedScene[key]?.id &&
        existing[key]?.id === updatedScene[key].id &&
        existing[key]?.storage_url
      ) {
        keepAssets[key] = existing[key];
      }
    }
    Object.assign(existing, updatedScene, {
      caption_settings: captionSettings,
      caption_settings_json: captionSettings,
      ...keepAssets,
    });

    // Keep the live preview in sync when the ACTIVE scene's visual changed.
    // The <img>/<video> binds to the currentVisualUrl ref, which is only
    // refreshed by preloadSceneVisual(). Every swap funnels through here, but
    // only some call sites remembered to re-preload — so Cruise applies (and
    // any future path) left the preview showing the old visual until a page
    // refresh re-ran the scene-select flow. Unchanged URLs (caption toggles,
    // poll heartbeats) no-op inside preloadSceneVisual, so there's no flicker;
    // the old visual stays put until the new one decodes.
    const nextVisualUrl = existing.visual_asset?.storage_url ?? null;
    if (existing.id === activeSceneId.value && nextVisualUrl !== prevVisualUrl) {
      if (nextVisualUrl) {
        preloadSceneVisual(existing);
      } else {
        currentVisualUrl.value = null;
      }
    }

    // Re-sort only when the array is actually out of order (scene_order
    // changed). The common case — same scene updated with same order —
    // skips the sort entirely so the array reference stays stable too.
    let outOfOrder = false;
    for (let i = 0; i < scenes.value.length - 1; i++) {
      if ((scenes.value[i].scene_order ?? 0) > (scenes.value[i + 1].scene_order ?? 0)) {
        outOfOrder = true;
        break;
      }
    }
    if (outOfOrder) scenes.value = sortScenesByOrder(scenes.value);
  }

  function patchSceneCaptionSettings(sceneId, captionSettings) {
    const scene = scenes.value.find((item) => item.id === sceneId);

    if (!scene) return;

    const normalizedSettings = normalizeCaptionSettings(captionSettings);

    scene.caption_settings = normalizedSettings;
    scene.caption_settings_json = normalizedSettings;
  }

  function buildSceneLabel(sceneType) {
    const label = humanizeSceneType(sceneType);
    return label === "Narration" ? `Scene ${scenes.value.length + 1}` : label;
  }

  function resetAddSceneDrafts() {
    selectedSceneType.value = "Narration";
    selectedAddSceneVisualSource.value = "Stock Clip";
    addSceneVisualMode.value = "";
    addSceneStockSubType.value = "stock_clip";
    addSceneVisualStyle.value = null;
    addSceneCustomVisualStyle.value = "";
    addSceneVisualQuery.value = "";
    addScenePickedAsset.value = null;
    addSceneCharacterId.value = null;
    addSceneCharPopoverOpen.value = false;
    newSceneScript.value = "";
  }

  // Called when the Assets tab is selected; opens the existing MediaPickerModal
  // in 'visual' mode but redirects the selection into the add-scene buffer
  // instead of assigning to activeScene.
  function pickAssetForNewScene() {
    addSceneAssetPickerActive.value = true;
    mediaPickerMode.value = "visual";
    mediaPickerVisible.value = true;
  }

  async function generateNewSceneDraft() {
    if (!project.value || addSceneGeneratePending.value) return;

    addSceneGeneratePending.value = true;
    addSceneGenerateError.value = "";

    let insertAfterSceneId = null;
    if (addScenePanelPosition.value.startsWith("after-")) {
      insertAfterSceneId = Number(addScenePanelPosition.value.replace("after-", "")) || null;
    }

    try {
      const response = await api.post("/scenes/generate-draft", {
        project_id: project.value.id,
        insert_after_scene_id: insertAfterSceneId,
        scene_type: String(selectedSceneType.value || "Narration").toLowerCase().replace(/\s+/g, "_"),
        current_text: newSceneScript.value.trim(),
      });

      newSceneScript.value = response.data?.data?.draft?.candidate || newSceneScript.value;
    } catch (requestError) {
      addSceneGenerateError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "AI draft failed.";
    } finally {
      addSceneGeneratePending.value = false;
    }
  }

  function scriptSaveCopy() {
    if (scriptSaveState.value === "pending") return "Unsaved changes";
    if (scriptSaveState.value === "saving") return "Saving...";
    if (scriptSaveState.value === "saved") return "Saved";
    if (scriptSaveState.value === "error") return scriptSaveError.value || "Save failed";
    return "";
  }

  function voiceSaveCopy() {
    if (voiceSaveState.value === "pending") return "Unsaved voice changes";
    if (voiceSaveState.value === "saving") return "Saving voice...";
    if (voiceSaveState.value === "saved") return "Voice saved";
    if (voiceSaveState.value === "error") return voiceSaveError.value || "Voice save failed";
    return "";
  }

  function captionSaveCopy() {
    if (captionSaveState.value === "pending") return "Unsaved captions";
    if (captionSaveState.value === "saving") return "Saving captions...";
    if (captionSaveState.value === "saved") return "Captions saved";
    if (captionSaveState.value === "error") return captionSaveError.value || "Caption save failed";
    return "";
  }

  function exportStatusCopy(job) {
    if (!job) return "";
    if (job.status === "completed") return "Export ready";
    if (job.status === "failed") return "Export failed";
    if (job.status === "processing") return `Exporting ${job.progress_percent || 0}%`;
    if (job.status === "queued") return "Export queued";
    return `Export ${job.status}`;
  }

  function formatSceneDuration(value) {
    const amount = Number(value || 0);
    if (!amount) return "—";
    return `${amount.toFixed(1)}s`;
  }

  function previewWords(text, highlightMode, progress) {
    const mode = highlightMode || captionHighlightDraft.value || "keywords";
    const pct = Math.min(1, Math.max(0, (progress ?? playProgress.value) / 100));

    if (mode === "none") return [];

    const timedWords = captionTimingWords(activeScene.value);
    if (timedWords.length > 0 && (mode === "word_by_word" || mode === "line_by_line")) {
      return previewTimedWords(timedWords, mode);
    }

    const words = String(text || "")
      .trim()
      .split(/\s+/)
      .filter(Boolean);
    if (words.length === 0) return [];

    if (mode === "word_by_word") {
      const idx = Math.min(Math.floor(pct * words.length), words.length - 1);
      return [{ text: words[idx], highlighted: true }];
    }

    if (mode === "line_by_line") {
      const wordsPerLine = 4;
      const lines = [];
      for (let i = 0; i < words.length; i += wordsPerLine) {
        lines.push(words.slice(i, i + wordsPerLine));
      }
      const wordIdx = Math.min(Math.floor(pct * words.length), words.length - 1);
      const lineIdx = Math.min(Math.floor(wordIdx / wordsPerLine), lines.length - 1);
      const lineWords = lines[lineIdx];
      const hStart = Math.min(1, lineWords.length - 1);
      const hEnd = Math.min(lineWords.length, hStart + 2);
      return lineWords.map((word, i) => ({
        text: i === lineWords.length - 1 ? word : word + " ",
        highlighted: i >= hStart && i < hEnd,
      }));
    }

    // keywords: full text with 2nd–3rd words highlighted
    const highlightStart = Math.min(1, words.length - 1);
    const highlightEnd = Math.min(words.length, highlightStart + 2);
    return words.map((word, index) => ({
      text: `${word}${index === words.length - 1 ? "" : " "}`,
      highlighted: index >= highlightStart && index < highlightEnd,
    }));
  }

  function captionTimingWords(scene) {
    const words = scene?.audio_asset?.metadata_json?.caption_timing?.words;
    if (!Array.isArray(words)) return [];

    return words
      .map((word) => ({
        text: String(word?.text || word?.word || "").trim(),
        start: Number(word?.start),
        end: Number(word?.end),
      }))
      .filter((word) => word.text && Number.isFinite(word.start) && Number.isFinite(word.end))
      .sort((a, b) => a.start - b.start);
  }

  function previewTimedWords(timedWords, mode) {
    const currentSeconds = currentCaptionSeconds();
    const wordIndex = timedWords.findIndex(
      (word) => currentSeconds >= word.start && currentSeconds < word.end
    );
    const activeIndex =
      wordIndex >= 0
        ? wordIndex
        : Math.max(0, Math.min(timedWords.length - 1, timedWords.findLastIndex((word) => word.start <= currentSeconds)));

    if (mode === "word_by_word") {
      return [{ text: timedWords[activeIndex]?.text || "", highlighted: true }];
    }

    const wordsPerLine = 4;
    const lineStart = Math.floor(activeIndex / wordsPerLine) * wordsPerLine;
    return timedWords.slice(lineStart, lineStart + wordsPerLine).map((word, index, lineWords) => ({
      text: `${word.text}${index === lineWords.length - 1 ? "" : " "}`,
      highlighted: lineStart + index === activeIndex,
    }));
  }

  function currentCaptionSeconds() {
    const audio = audioRef.value;
    if (audio && Number.isFinite(audio.currentTime)) {
      return Math.max(0, audio.currentTime);
    }

    return currentSceneAudioOffset();
  }

  function fontFamilyValue(font) {
    return `"${font}", sans-serif`;
  }

  function selectCaptionFont(font) {
    captionFontDraft.value = font;
    fontDropdownOpen.value = false;
  }

  function formatPreviewTime(value) {
    const whole = Math.max(0, Math.round(Number(value || 0)));
    const mins = Math.floor(whole / 60);
    const secs = whole % 60;
    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
  }

  const totalVideoDuration = computed(() =>
    scenes.value.reduce((sum, s) => sum + sceneDuration(s), 0)
  );

  function sceneDuration(scene) {
    const audioDuration = Number(scene?.audio_asset?.duration_seconds || 0);
    if (Number.isFinite(audioDuration) && audioDuration > 0) {
      return Math.max(0.1, audioDuration);
    }

    return Math.max(0.1, Number(scene?.duration_seconds || 12));
  }

  function sceneAtFullElapsed(elapsedSeconds) {
    if (!scenes.value.length) return { scene: null, index: -1, start: 0, duration: 0 };

    const clamped = Math.max(0, Math.min(elapsedSeconds, totalVideoDuration.value || 0));
    let start = 0;

    for (let index = 0; index < scenes.value.length; index += 1) {
      const scene = scenes.value[index];
      const duration = sceneDuration(scene);
      if (clamped < start + duration || index === scenes.value.length - 1) {
        return { scene, index, start, duration };
      }
      start += duration;
    }

    return { scene: scenes.value[0], index: 0, start: 0, duration: sceneDuration(scenes.value[0]) };
  }

  // Cumulative scene start percentages for scrubber boundary markers (full video mode)
  const sceneBoundaryPcts = computed(() => {
    if (scenes.value.length < 2) return [];
    const total = totalVideoDuration.value || 1;
    const pcts = [];
    let cum = 0;
    for (let i = 0; i < scenes.value.length - 1; i++) {
      cum += sceneDuration(scenes.value[i]);
      pcts.push((cum / total) * 100);
    }
    return pcts;
  });

  const previewContextDuration = computed(() =>
    previewMode.value === "full"
      ? totalVideoDuration.value
      : sceneDuration(activeScene.value)
  );

  const previewElapsedSecs = computed(() =>
    (playProgress.value / 100) * previewContextDuration.value
  );

  const previewTimer = computed(() => ({
    elapsed: formatPreviewTime(previewElapsedSecs.value),
    total: formatPreviewTime(previewContextDuration.value),
  }));

  function formatNotifTime(value) {
    if (!value) return "now";
    const ts = new Date(value).getTime();
    const delta = Math.floor((Date.now() - ts) / 60000);
    if (delta < 1) return "now";
    if (delta < 60) return `${delta} min ago`;
    const hrs = Math.floor(delta / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
  }

  function updateVisualCache(sceneId, value) {
    mediaCache.value = {
      ...mediaCache.value,
      visual: {
        ...mediaCache.value.visual,
        [sceneId]: {
          ...(mediaCache.value.visual[sceneId] || {}),
          ...value,
        },
      },
    };
  }

  function updateAudioCache(sceneId, value) {
    mediaCache.value = {
      ...mediaCache.value,
      audio: {
        ...mediaCache.value.audio,
        [sceneId]: {
          ...(mediaCache.value.audio[sceneId] || {}),
          ...value,
        },
      },
    };
  }

  function syncActiveSceneMedia(scene) {
    const sceneId = scene?.id;
    if (!sceneId) {
      currentVisualUrl.value = null;
      return;
    }

    const visualUrl = scene.visual_asset?.storage_url ?? null;
    const cachedVisual = mediaCache.value.visual[sceneId];

    if (!visualUrl) {
      currentVisualUrl.value = null;
    } else if (cachedVisual?.loaded) {
      currentVisualUrl.value = visualUrl;
    } else {
      currentVisualUrl.value = null;
    }

    if (visualUrl) {
      preloadSceneVisual(scene);
    }

    if (scene.audio_asset?.storage_url) {
      preloadSceneAudio(scene);
    }
  }

  function preloadSceneVisual(scene) {
    const sceneId = scene?.id;
    const asset = scene?.visual_asset;
    const url = asset?.storage_url;

    if (!sceneId || !url) return;

    const cached = mediaCache.value.visual[sceneId];
    if (cached?.loaded && cached.url === url) {
      if (activeSceneId.value === sceneId) {
        currentVisualUrl.value = url;
      }
      return;
    }

    const preloadKey = `visual:${sceneId}:${url}`;
    if (mediaPreloaders.has(preloadKey)) return;

    updateVisualCache(sceneId, { url, loaded: false, failed: false });

    const isVideo =
      asset?.asset_type === "video" ||
      String(asset?.mime_type || "").startsWith("video/");

    if (isVideo) {
      const video = document.createElement("video");
      video.preload = "metadata";
      video.muted = true;
      video.playsInline = true;
      const clear = () => mediaPreloaders.delete(preloadKey);

      video.onloadeddata = () => {
        updateVisualCache(sceneId, { url, loaded: true, failed: false });
        if (activeSceneId.value === sceneId) {
          currentVisualUrl.value = url;
        }
        clear();
      };
      video.onerror = () => {
        updateVisualCache(sceneId, { url, loaded: false, failed: true });
        if (activeSceneId.value === sceneId) {
          visualLoadFailed.value = true;
          currentVisualUrl.value = null;
        }
        clear();
      };

      mediaPreloaders.set(preloadKey, video);
      video.src = url;
      video.load();
      return;
    }

    const image = new Image();
    image.onload = () => {
      updateVisualCache(sceneId, { url, loaded: true, failed: false });
      if (activeSceneId.value === sceneId) {
        currentVisualUrl.value = url;
      }
      mediaPreloaders.delete(preloadKey);
    };
    image.onerror = () => {
      updateVisualCache(sceneId, { url, loaded: false, failed: true });
      if (activeSceneId.value === sceneId) {
        visualLoadFailed.value = true;
        currentVisualUrl.value = null;
      }
      mediaPreloaders.delete(preloadKey);
    };

    mediaPreloaders.set(preloadKey, image);
    image.src = url;
  }

  function preloadSceneAudio(scene) {
    const sceneId = scene?.id;
    const url = scene?.audio_asset?.storage_url;

    if (!sceneId || !url) return;

    const cached = mediaCache.value.audio[sceneId];
    if (cached?.loaded && cached.url === url) return;

    const preloadKey = `audio:${sceneId}:${url}`;
    if (mediaPreloaders.has(preloadKey)) return;

    updateAudioCache(sceneId, { url, loaded: false, failed: false });

    const audio = new Audio();
    audio.crossOrigin = MEDIA_ELEMENT_CROSS_ORIGIN;
    audio.preload = "metadata";
    const clear = () => mediaPreloaders.delete(preloadKey);

    audio.onloadeddata = () => {
      updateAudioCache(sceneId, { url, loaded: true, failed: false });
      if (activeSceneId.value === sceneId) {
        isAudioLoading.value = false;
      }
      clear();
    };
    audio.oncanplaythrough = () => {
      updateAudioCache(sceneId, { url, loaded: true, failed: false });
      if (activeSceneId.value === sceneId) {
        isAudioLoading.value = false;
      }
      clear();
    };
    audio.onerror = () => {
      updateAudioCache(sceneId, { url, loaded: false, failed: true });
      if (activeSceneId.value === sceneId) {
        isAudioLoading.value = false;
      }
      clear();
    };

    mediaPreloaders.set(preloadKey, audio);
    audio.src = url;
    audio.load();
  }

  async function loadImageModelCatalog() {
    try {
      const res = await api.get('/image-models');
      const models = res?.data?.data?.models;
      if (Array.isArray(models) && models.length) availableImageModelsEditor.value = models;
    } catch { /* keep fallback list */ }
  }

  const modelPickerOpen = ref(false);
  const activeImageModelLabel = computed(() => activeImageModelMeta.value?.label ?? 'GPT Image 1');

  // --- Failed-generation recovery -----------------------------------------
  // A failed project used to open into the normal editor with zero scenes — a
  // dead surface that recorded rageclicks from two paying users. The failure
  // reason has been stored on the project all along (generation_status_json);
  // it was simply never shown.
  const generationFailed = computed(() =>
    project.value?.status === 'failed' && scenes.value.length === 0);
  const generationFailureMessage = computed(() =>
    project.value?.generation_status_json?.last_message
    || 'Generation failed before any scenes were created.');
  const retrySourceDraft = ref('');
  const retryPending = ref(false);
  const retryError = ref('');
  // Sources a user can meaningfully edit inline before retrying.
  const retrySourceEditable = computed(() =>
    ['url', 'prompt', 'script', 'product_description'].includes(project.value?.source_type));

  watch(generationFailed, (failed) => {
    if (failed) retrySourceDraft.value = project.value?.source_content_raw || '';
  }, { immediate: true });

  async function retryGeneration(opts = {}) {
    if (retryPending.value) return;
    retryPending.value = true;
    retryError.value = '';
    try {
      const body = {};
      if (opts.readScanned) body.pdf_read_scanned = true;
      if (retrySourceEditable.value && retrySourceDraft.value.trim()
          && retrySourceDraft.value.trim() !== (project.value?.source_content_raw || '').trim()) {
        body.source_content_raw = retrySourceDraft.value.trim();
      }
      await api.post(`/projects/${projectId.value}/retry-generation`, body);
      // The pipeline re-runs from the script; the progress page owns that view.
      router.push({ name: 'generation-progress', params: { projectId: projectId.value } });
    } catch (err) {
      retryError.value = err.response?.data?.error?.message ?? 'Retry failed.';
    } finally {
      retryPending.value = false;
    }
  }

  async function loadProject() {
    loading.value = true;
    error.value = "";

    try {
      const response = await api.get(`/projects/${projectId.value}`);
      applyProjectPayload(response.data?.data, { preserveActiveScene: false });
      await loadExportJobs();
      loadImageModelCatalog();   // fire-and-forget — falls back to static list
      loadImageStyleCatalog();   // fire-and-forget — falls back to icon list
      loadCruiseConversation();  // fire-and-forget — hydrates Assistant chat history
      subscribeProjectChannel();
    } catch (requestError) {
      error.value =
        requestError.response?.data?.error?.message ?? "Project load failed.";
    } finally {
      loading.value = false;
    }
  }

  function applyProjectPayload(data, { preserveActiveScene = true } = {}) {
    const previousActiveSceneId = activeSceneId.value;

    project.value = data?.project ?? null;
    projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
    projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
    selectedMusicTrackId.value = project.value?.music_asset_id ?? null;
    const ms = project.value?.music_settings_json ?? {};
    musicVolume.value = ms.volume ?? 30;
    musicDuckVolume.value = ms.duck_volume ?? 8;
    musicFadeInMs.value = ms.fade_in_ms ?? 500;
    musicLoop.value = ms.loop ?? true;
    musicDuckDuringVoice.value = ms.duck_during_voice ?? true;
    // Merge each scene in place when we already have it — same-id scenes
    // keep their object identity so Vue only re-evaluates the keys that
    // actually changed (caption settings, asset ids). Without this, a TTS
    // completion event triggered refreshProjectPayload() which rebuilt
    // every scene from scratch and the editor flickered each time.
    const freshScenes = (data?.scenes ?? []).map((scene) => normalizeScenePayload(scene));
    if (!scenes.value?.length) {
      scenes.value = freshScenes;
    } else {
      const byId = new Map(scenes.value.map((s) => [s.id, s]));
      const merged = freshScenes.map((fresh) => {
        const existing = byId.get(fresh.id);
        if (!existing) return fresh;
        Object.assign(existing, fresh);
        return existing;
      });
      // Same length + same id order? Skip array reassignment to keep the
      // ref stable. Otherwise (scene added / removed / reordered) replace.
      const sameOrder = merged.length === scenes.value.length
        && merged.every((s, i) => s.id === scenes.value[i].id);
      if (!sameOrder) scenes.value = merged;
    }
    hookOptions.value = data?.hook_options ?? [];

    if (preserveActiveScene && scenes.value.some((scene) => scene.id === previousActiveSceneId)) {
      activeSceneId.value = previousActiveSceneId;
    } else {
      activeSceneId.value = scenes.value[0]?.id ?? null;
    }

    scenes.value.forEach((scene) => {
      preloadSceneVisual(scene);
      preloadSceneAudio(scene);
    });
  }

  async function refreshProjectPayload() {
    const response = await api.get(`/projects/${projectId.value}`);
    applyProjectPayload(response.data?.data, { preserveActiveScene: true });
    // A generation finishing reloads the project: undo and redo may be available again.
    scheduleHistoryRefresh();
  }

  // ── Undo / redo (2026-10-10) ───────────────────────────────────────────────
  // One history per project, shared with the in-editor assistant and ChatGPT/Claude (EditHistory on the server).
  // Every edit is a step; undo and redo are free (no credits move, generated files are kept).
  const editHistory = ref({ can_undo: false, can_redo: false, undo_label: null, redo_label: null, busy: false });
  const historyStepping = ref(false);
  let historyTimer = null;
  async function loadEditHistory() {
    if (!projectId.value) return;
    try { editHistory.value = (await api.get(`/projects/${projectId.value}/history`)).data?.data ?? editHistory.value; } catch { /* the buttons just stay as they were */ }
  }
  function scheduleHistoryRefresh() {
    if (historyTimer) window.clearTimeout(historyTimer);
    historyTimer = window.setTimeout(loadEditHistory, 400);
  }
  // Re-select the open scene so every draft (script, voice, captions…) reloads from what was put back.
  async function resyncSceneDrafts() {
    const keep = activeSceneId.value;
    activeSceneId.value = null;
    await nextTick();
    activeSceneId.value = scenes.value.some((s) => s.id === keep) ? keep : scenes.value[0]?.id ?? null;
  }
  // Only a save still on its way blocks undo; a save that failed does not.
  function savesInFlight() {
    return [scriptSaveState, voiceSaveState, captionSaveState, motionSaveState, musicSaveState, audiogramSaveState, visualStyleSaveState, customVisualStyleSaveState]
      .some((state) => ["pending", "saving"].includes(state.value))
      || Boolean(scriptSaveTimer || voiceSaveTimer || captionSaveTimer || musicSaveTimer || audiogramSaveTimer || sceneVoiceVolumeSaveTimer || sceneSoundVolumeSaveTimer)
      || volumeSavesPending.value > 0 || sceneDurationSaving.value
      || Boolean(activeScene.value && sceneScriptDraft.value !== (activeScene.value.script_text || ""));
  }
  async function stepHistory(step) {
    const can = step === "undo" ? editHistory.value.can_undo : editHistory.value.can_redo;
    if (!can || historyStepping.value) return;
    // A save still on its way would land on top of the undo: let it finish first.
    if (savesInFlight()) {
      pushToast({ id: `history-wait-${Date.now()}`, title: "Saving your changes", message: `Try ${step} again in a moment.` });
      return;
    }
    historyStepping.value = true;
    let label;
    try {
      const expect = step === "undo" ? editHistory.value.undo_id : editHistory.value.redo_id;
      const { data } = await api.post(`/projects/${projectId.value}/${step}`, expect ? { edit_id: expect } : {});
      label = data?.data?.undone ?? data?.data?.redone;
      editHistory.value = data?.data ?? editHistory.value;
    } catch (e) {
      pushToast({ id: `history-fail-${Date.now()}`, title: `Could not ${step}`, message: e?.response?.data?.error?.message ?? "Try again in a moment." });
      loadEditHistory();
      historyStepping.value = false;
      return;
    }
    // It worked; showing it is separate, so a failed reload never reads as a failed undo (and invites a second one).
    pushToast({ id: `history-${Date.now()}`, title: step === "undo" ? "Undone" : "Redone", message: label || "" });
    try {
      await refreshProjectPayload();
      await resyncSceneDrafts();
      refreshExportFreshness();
    } catch {
      pushToast({ id: `history-reload-${Date.now()}`, title: "Reload to see the change", message: "It was saved, but the editor could not refresh." });
    } finally {
      historyStepping.value = false;
    }
  }
  // Any edit refreshes what undo and redo would do (the in-editor assistant's too).
  const historyInterceptor = api.interceptors.response.use((response) => {
    const method = String(response.config?.method || "get").toLowerCase();
    const url = String(response.config?.url || "");
    if (method !== "get" && !/\/(undo|redo|history)$/.test(url) && (/^\/scenes/.test(url) || url.startsWith(`/projects/${projectId.value}`) || /^\/cruise\/(apply|undo)/.test(url))) scheduleHistoryRefresh();
    return response;
  });
  // ⌘Z / Ctrl+Z undo, ⇧⌘Z / Ctrl+Y redo — except while typing, where the text box keeps its own undo.
  function historyKeys(event) {
    if (!(event.metaKey || event.ctrlKey) || event.altKey) return;
    const t = event.target;
    if (t && (t.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(t.tagName))) return;
    if (document.querySelector('[role="dialog"]:not(.wcn), [aria-modal="true"]')) return;   // a dialog is open (not the cookie notice)
    const key = event.key.toLowerCase();
    if (key === "z" && !event.shiftKey) { event.preventDefault(); stepHistory("undo"); }
    else if ((key === "z" && event.shiftKey) || key === "y") { event.preventDefault(); stepHistory("redo"); }
  }
  onMounted(() => { document.addEventListener("keydown", historyKeys); loadEditHistory(); });
  onBeforeUnmount(() => { document.removeEventListener("keydown", historyKeys); api.interceptors.response.eject(historyInterceptor); if (historyTimer) window.clearTimeout(historyTimer); });

  async function loadMe() {
    try {
      const response = await api.get("/me");
      mePayload.value = response.data?.data?.user ?? null;
      // Credits live at data.credits — sibling to user, not nested inside.
      cruiseCreditsPayload.value = response.data?.data?.credits ?? null;
      // Hydrate workspace-level Cruise prefs so the toggle reflects truth.
      cruiseAutoApply.value = response.data?.data?.cruise?.auto_apply ?? true;
      cruisePrefs.value = {
        image_model:    response.data?.data?.cruise?.image_model    ?? null,
        animation_tier: response.data?.data?.cruise?.animation_tier ?? null,
        visual_source:  response.data?.data?.cruise?.visual_source  ?? 'auto',
      };
      await Promise.all([loadVoiceProfiles(), loadCaptionPresets(), loadChannels(), loadBrandKits(), loadMusicTracks()]);
      await loadNotifications();
      subscribeWorkspaceNotifications();
    } catch {
      mePayload.value = null;
      voiceProfiles.value = [];
      channels.value = [];
      brandKits.value = [];
    }
  }

  // Toggle the workspace's Cruise auto-apply pref. Optimistic UI — flip
  // the local ref immediately, send PATCH in the background. Revert on
  // failure.
  async function setCruiseAutoApply(next) {
    const previous = cruiseAutoApply.value
    cruiseAutoApply.value = next
    try {
      await api.patch('/cruise/settings', { auto_apply: next })
    } catch {
      cruiseAutoApply.value = previous
    }
  }

  // Patch a single pref. Optimistic — flip locally, revert on network
  // failure. Keys map 1:1 to backend (image_model, animation_tier,
  // visual_source). Passing null clears the bias.
  async function setCruisePref(key, value) {
    const previous = cruisePrefs.value[key]
    cruisePrefs.value = { ...cruisePrefs.value, [key]: value }
    try {
      await api.patch('/cruise/settings', { [key]: value })
    } catch {
      cruisePrefs.value = { ...cruisePrefs.value, [key]: previous }
    }
  }

  // Auto-apply runs the action right after resolve when:
  //   - workspace pref is on
  //   - the proposed action's confirmation_class is 'auto'
  //   - user can afford it
  // Skips action cards that need confirmation (prompt / always_prompt) or
  // would push the user below zero balance.
  async function maybeAutoApply(msg) {
    if (!cruiseAutoApply.value) return
    const cards = msg?.actions || []
    if (!cards.length) return
    // Auto-apply each card flagged 'auto' if balance covers it. Stops at
    // the first 'prompt'/'always_prompt' card so the user still confirms
    // anything risky / expensive.
    let runningTotal = 0
    for (let i = 0; i < cards.length; i++) {
      const c = cards[i]
      if (c.confirmation_class !== 'auto') break
      runningTotal += (c.estimated_cost ?? 0)
      if (cruiseUserBalance.value < runningTotal) break
      await cruiseApplyAction(msg, i)
    }
  }

  // Quick prompt was clicked — fill the input and submit immediately.
  async function cruiseUseQuickPrompt(text) {
    if (cruiseResolving.value) return
    cruiseInputText.value = text
    await cruiseSubmitIntent()
  }

  async function loadVoiceProfiles() {
    try {
      const response = await api.get("/voice-profiles");
      voiceProfiles.value = response.data?.data?.voice_profiles ?? [];
    } catch {
      voiceProfiles.value = [];
    }
  }

  async function loadCaptionPresets() {
    try {
      const response = await api.get("/caption-presets");
      captionPresets.value = response.data?.data?.caption_presets ?? [];
    } catch {
      captionPresets.value = [];
    }
  }

  async function loadChannels() {
    try {
      const response = await api.get("/channels");
      channels.value = response.data?.data?.channels ?? [];
    } catch {
      channels.value = [];
    }
  }

  async function loadBrandKits() {
    try {
      const response = await api.get("/brand-kits");
      brandKits.value = response.data?.data?.brand_kits ?? [];
    } catch {
      brandKits.value = [];
    }
  }

  async function loadMusicTracks() {
    try {
      const response = await api.get("/assets", { params: { asset_type: "music", per_page: 50 } });
      musicTracks.value = response.data?.data?.assets ?? [];
    } catch {
      musicTracks.value = [];
    }
  }

  async function loadMyImageAssets() {
    if (myImageLoading.value) return;
    myImageLoading.value = true;
    try {
      const response = await api.get("/assets", { params: { asset_type: "image", per_page: 60 } });
      myImageAssets.value = response.data?.data?.assets ?? [];
    } catch {
      myImageAssets.value = [];
    } finally {
      myImageLoading.value = false;
    }
  }

  // ── Custom voice: upload + record ───────────────────────────
  function handleVoiceFileUpload(event) {
    const file = event?.target?.files?.[0];
    if (!file) return;
    event.target.value = '';
    enterPreview(file, file.name.replace(/\.[^.]+$/, ''));
  }

  function enterPreview(blob, name) {
    // Release any prior preview URL
    if (voicePreviewUrl.value) {
      try { URL.revokeObjectURL(voicePreviewUrl.value); } catch {}
    }
    voicePreviewBlob.value = blob;
    voicePreviewUrl.value = URL.createObjectURL(blob);
    voicePreviewName.value = name || 'Custom voice';
    voiceUploadStatus.value = 'previewing';
    voiceUploadError.value = '';
  }

  async function confirmUploadFromPreview() {
    if (!voicePreviewBlob.value) return;
    const blob = voicePreviewBlob.value;
    const name = voicePreviewName.value;
    // Release the preview URL before kicking off the upload
    if (voicePreviewUrl.value) {
      try { URL.revokeObjectURL(voicePreviewUrl.value); } catch {}
      voicePreviewUrl.value = null;
    }
    voicePreviewBlob.value = null;
    await uploadVoiceBlob(blob, name);
  }

  async function uploadVoiceBlob(blob, title) {
    voiceUploadStatus.value = 'uploading';
    voiceUploadError.value = '';
    voiceUploadAsset.value = null;
    try {
      const fd = new FormData();
      fd.append('asset_type', 'audio');
      fd.append('title', title || 'Custom voice');
      fd.append('asset_file', blob, (title || 'voice') + (blob.type.includes('webm') ? '.webm' : (blob.type.includes('wav') ? '.wav' : '.mp3')));
      const res = await api.post('/assets', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      const asset = res.data?.data?.asset;
      if (!asset) throw new Error('Upload returned no asset');
      voiceUploadAsset.value = asset;
      voiceUploadStatus.value = asset.transcription_status === 'completed' ? 'ready' : 'transcribing';
      if (voiceUploadStatus.value === 'transcribing') startVoiceTranscribePoll(asset.id);
    } catch (e) {
      voiceUploadError.value = e?.response?.data?.error?.message || e?.message || 'Upload failed.';
      voiceUploadStatus.value = 'error';
    }
  }

  function startVoiceTranscribePoll(assetId) {
    clearInterval(voiceTranscribePoller);
    let attempts = 0;
    voiceTranscribePoller = setInterval(async () => {
      attempts++;
      if (attempts > 60) { clearInterval(voiceTranscribePoller); voiceUploadStatus.value = 'error'; voiceUploadError.value = 'Transcription timed out.'; return; }
      try {
        const res = await api.get(`/assets/${assetId}`);
        const asset = res.data?.data?.asset ?? res.data?.data;
        if (!asset) return;
        voiceUploadAsset.value = asset;
        if (asset.transcription_status === 'completed') {
          clearInterval(voiceTranscribePoller);
          voiceUploadStatus.value = 'ready';
        } else if (asset.transcription_status === 'failed') {
          clearInterval(voiceTranscribePoller);
          voiceUploadStatus.value = 'error';
          voiceUploadError.value = 'Transcription failed. You can still use the audio without word-level captions.';
        }
      } catch { /* keep polling */ }
    }, 2000);
  }

  async function applyVoiceUpload({ updateScript = true } = {}) {
    if (!voiceUploadAsset.value || !activeScene.value) return;
    const asset = voiceUploadAsset.value;
    const currentVoiceSettings = activeScene.value.voice_settings || activeScene.value.voice_settings_json || {};
    const payload = {
      voice_settings_json: {
        ...currentVoiceSettings,
        audio_asset_id: asset.id,
        is_outdated: false,
        custom_audio: true,
      },
    };
    if (updateScript && asset.transcript_text) {
      payload.script_text = asset.transcript_text;
    }
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, payload);
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) replaceSceneInCollection(updatedScene);
      // Reset upload UI
      voiceUploadStatus.value = 'idle';
      voiceUploadAsset.value = null;
      voiceUploadError.value = '';
    } catch (err) {
      voiceUploadError.value = err?.response?.data?.error?.message || 'Could not apply voice.';
    }
  }

  function toggleVoicePreviewPlay() {
    const el = voicePreviewRef.value;
    if (!el) return;
    if (voicePreviewPlaying.value) { el.pause(); } else { el.play().catch(() => {}); }
  }

  function onVoicePreviewTime() {
    const el = voicePreviewRef.value;
    if (!el) return;
    voicePreviewCurrent.value = el.currentTime || 0;
    if (Number.isFinite(el.duration)) voicePreviewDuration.value = el.duration;
  }

  function onVoicePreviewSeek(e) {
    const el = voicePreviewRef.value;
    if (!el || !voicePreviewDuration.value) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    el.currentTime = pct * voicePreviewDuration.value;
  }

  function fmtVoicePreviewTime(s) {
    if (!Number.isFinite(s)) return '0:00';
    const sec = Math.floor(s);
    return `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
  }

  function cancelVoiceUpload() {
    clearInterval(voiceTranscribePoller);
    if (voicePreviewUrl.value) {
      try { URL.revokeObjectURL(voicePreviewUrl.value); } catch {}
    }
    voicePreviewBlob.value = null;
    voicePreviewUrl.value = null;
    voicePreviewName.value = '';
    voiceUploadStatus.value = 'idle';
    voiceUploadAsset.value = null;
    voiceUploadError.value = '';
  }

  async function startVoiceRecording() {
    voiceUploadError.value = '';
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
      const rec = new MediaRecorder(stream, { mimeType });
      voiceRecordedChunks.value = [];
      rec.ondataavailable = (e) => { if (e.data?.size) voiceRecordedChunks.value.push(e.data); };
      rec.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        voiceRecording.value = false;
        clearInterval(voiceRecordTimer);
        const blob = new Blob(voiceRecordedChunks.value, { type: mimeType });
        voiceRecordedChunks.value = [];
        if (blob.size > 0) {
          enterPreview(blob, `Voice recording ${new Date().toLocaleTimeString()}`);
        }
      };
      voiceRecorder.value = rec;
      voiceRecording.value = true;
      voiceRecordSeconds.value = 0;
      voiceRecordTimer = setInterval(() => { voiceRecordSeconds.value += 1; }, 1000);
      rec.start();
    } catch (err) {
      voiceUploadError.value = err?.name === 'NotAllowedError'
        ? 'Microphone permission denied. Allow microphone access and try again.'
        : 'Could not start recording.';
    }
  }

  function stopVoiceRecording() {
    if (voiceRecorder.value && voiceRecording.value) {
      voiceRecorder.value.stop();
    }
  }

  async function loadCustomAudioAssets() {
    if (customAudioLoading.value) return;
    customAudioLoading.value = true;
    try {
      const response = await api.get("/assets", { params: { asset_type: "audio", per_page: 60 } });
      customAudioAssets.value = response.data?.data?.assets ?? [];
    } catch {
      customAudioAssets.value = [];
    } finally {
      customAudioLoading.value = false;
    }
  }

  async function assignAssetImage(asset) {
    if (!activeScene.value || visualSwapPending.value) return;
    visualSwapPending.value = true;
    visualSwapError.value = "";
    visualLoadFailed.value = false;
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, {
        visual_asset_id: asset.id,
        visual_type: "image_montage",
        visual_prompt: asset.title || "",
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) {
        replaceSceneInCollection(updatedScene);
        preloadSceneVisual(updatedScene);
        pushToast({ id: `visual-done-${updatedScene.id}-${Date.now()}`, title: 'Visual updated', message: `Scene ${updatedScene.scene_order ?? ''} visual swapped.` });
      }
    } catch (err) {
      visualSwapError.value = err?.response?.data?.error?.message || "Failed to assign image.";
    } finally {
      visualSwapPending.value = false;
    }
  }

  async function assignCustomAudio(asset) {
    if (!activeScene.value) return;
    const currentVoiceSettings = activeScene.value.voice_settings || {};
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, {
        voice_settings_json: {
          ...currentVoiceSettings,
          audio_asset_id: asset.id,
          is_outdated: false,
          custom_audio: true,
        },
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) replaceSceneInCollection(updatedScene);
    } catch (err) {
      console.error("Failed to assign custom audio", err);
    }
  }

  function openMediaPicker(mode) {
    mediaPickerMode.value = mode;
    mediaPickerVisible.value = true;
  }

  function syncSceneVoiceVolume() {
    if (audioRef.value) {
      audioRef.value.volume = Math.max(0, Math.min(1, sceneVoiceVolume.value / 100));
    }
  }

  function scheduleSceneVoiceVolumeSave() {
    syncSceneVoiceVolume();
    const scene = activeScene.value;
    if (!scene) return;
    const sceneId = scene.id;
    const volume = sceneVoiceVolume.value;
    const settings = { ...(scene.voice_settings_json || scene.voice_settings || {}), volume };
    clearTimeout(sceneVoiceVolumeSaveTimer);
    volumeSaveError.value = '';
    sceneVoiceVolumeSaveTimer = setTimeout(async () => {
      sceneVoiceVolumeSaveTimer = null;
      volumeSavesPending.value++;
      try {
        const response = await api.patch(`/scenes/${sceneId}`, { voice_settings_json: settings });
        const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
        if (updated) replaceSceneInCollection(updated);
      } catch { volumeSaveError.value = 'Audio volume could not be saved. Adjust it again before updating the video.'; }
      finally { volumeSavesPending.value--; }
    }, 500);
  }

  function scheduleSceneSoundVolumeSave() {
    syncSceneSoundVolume();
    const scene = activeScene.value;
    if (!scene) return;
    const sceneId = scene.id;
    const volume = sceneSoundVolume.value;
    const settings = { ...(scene.sound_settings_json || {}), volume };
    clearTimeout(sceneSoundVolumeSaveTimer);
    volumeSaveError.value = '';
    sceneSoundVolumeSaveTimer = setTimeout(async () => {
      sceneSoundVolumeSaveTimer = null;
      volumeSavesPending.value++;
      try {
        const response = await api.patch(`/scenes/${sceneId}`, { sound_settings_json: settings });
        const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
        if (updated) replaceSceneInCollection(updated);
      } catch { volumeSaveError.value = 'Audio volume could not be saved. Adjust it again before updating the video.'; }
      finally { volumeSavesPending.value--; }
    }, 500);
  }

  async function saveAudiogramSettings({ apply = false } = {}) {
    if (!activeScene.value) return;
    clearTimeout(audiogramSaveTimer);
    audiogramSaveTimer = null;
    audiogramSaveState.value = "saving";
    const existing = activeScene.value.image_generation_settings ?? activeScene.value.image_generation_settings_json ?? {};
    const payload = {
      image_generation_settings_json: {
        ...existing,
        audiogram_style: audiogramStyle.value,
        audiogram_color: audiogramColor.value,
        audiogram_bg: audiogramBg.value,
      },
    };
    if (apply) {
      payload.visual_type = "waveform";
      payload.visual_asset_id = null;
    }
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, payload);
      const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updated) replaceSceneInCollection(updated);
      audiogramSaveState.value = "saved";
      setTimeout(() => { audiogramSaveState.value = "idle"; }, 1400);
    } catch {
      audiogramSaveState.value = "idle";
    }
  }

  function selectAudiogramStyle(key) {
    audiogramStyle.value = key;
    saveAudiogramSettings();
  }
  function selectAudiogramColor(color) {
    audiogramColor.value = color;
    saveAudiogramSettings();
  }
  function queueAudiogramColorSave() {
    clearTimeout(audiogramSaveTimer);
    audiogramSaveTimer = setTimeout(() => saveAudiogramSettings(), 600);
  }
  function selectAudiogramBg(key) {
    audiogramBg.value = key;
    saveAudiogramSettings();
  }

  function handleMediaPickerSelect({ mode, item }) {
    if (mode === "visual") {
      if (item._type === "asset") {
        // If the picker was opened from the add-scene panel, route the
        // selection into the add-scene buffer instead of assigning to the
        // currently-active scene.
        if (addSceneAssetPickerActive.value) {
          addScenePickedAsset.value = item;
          addSceneAssetPickerActive.value = false;
          return;
        }
        const isVid = item.asset_type === "video" || String(item.mime_type ?? "").startsWith("video/");
        assignAssetVisual(item, isVid ? "background_loop" : "image_montage");
      }
    } else if (mode === "music") {
      if (item._type === "no-music") {
        selectMusicTrack(null);
      } else if (item._type === "track" || item._type === "asset") {
        selectMusicTrack(item.id);
      }
    } else if (mode === "sound") {
      if (item._type === "asset") assignSceneSound(item);
    }
  }

  async function assignSceneSound(asset) {
    if (!activeScene.value) return;
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, {
        sound_asset_id: asset ? asset.id : null,
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) replaceSceneInCollection(updatedScene);
    } catch (err) {
      console.error("Failed to assign sound", err);
    }
  }

  async function assignAssetVisual(asset, visualType) {
    if (!activeScene.value || visualSwapPending.value) return;
    visualSwapPending.value = true;
    visualSwapError.value = "";
    visualLoadFailed.value = false;
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, {
        visual_asset_id: asset.id,
        visual_type: visualType,
        visual_prompt: asset.title || "",
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updatedScene) {
        replaceSceneInCollection(updatedScene);
        preloadSceneVisual(updatedScene);
        pushToast({ id: `visual-done-${updatedScene.id}-${Date.now()}`, title: 'Visual updated', message: `Scene ${updatedScene.scene_order ?? ''} visual swapped.` });
      }
    } catch (err) {
      visualSwapError.value = err?.response?.data?.error?.message || "Failed to assign visual.";
    } finally {
      visualSwapPending.value = false;
    }
  }

  async function persistMusicSettings() {
    musicSaveTimer = null;
    if (!project.value) return;
    musicSaveState.value = "saving";
    musicSaveError.value = "";
    try {
      const response = await api.patch(`/projects/${project.value.id}`, {
        music_asset_id: selectedMusicTrackId.value ?? null,
        music_settings_json: {
          volume: musicVolume.value,
          duck_volume: musicDuckVolume.value,
          fade_in_ms: musicFadeInMs.value,
          loop: musicLoop.value,
          duck_during_voice: musicDuckDuringVoice.value,
        },
      });
      project.value = response.data?.data?.project ?? project.value;
      musicSaveState.value = "saved";
    } catch (err) {
      musicSaveError.value = err?.response?.data?.error?.message ?? "Failed to save music settings.";
      musicSaveState.value = "idle";
    }
  }

  function scheduleMusicSave() {
    musicSaveState.value = "idle";
    clearTimeout(musicSaveTimer);
    musicSaveTimer = setTimeout(persistMusicSettings, 500);
  }

  async function saveProjectDefaults() {
    if (!project.value || projectDefaultsSaveState.value === "saving") return;

    projectDefaultsSaveState.value = "saving";
    projectDefaultsSaveError.value = "";

    try {
      const response = await api.patch(`/projects/${project.value.id}`, {
        channel_id: projectChannelId.value ? Number(projectChannelId.value) : null,
        brand_kit_id: projectBrandKitId.value ? Number(projectBrandKitId.value) : null,
      });

      project.value = response.data?.data?.project ?? project.value;
      projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
      projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
      projectDefaultsSaveState.value = "saved";
    } catch (requestError) {
      projectDefaultsSaveError.value =
        requestError.response?.data?.error?.message ?? "Could not save project defaults.";
      projectDefaultsSaveState.value = "error";
    }
  }

  watch(projectChannelId, (nextChannelId, previousChannelId) => {
    const channel = channels.value.find((item) => String(item.id) === String(nextChannelId));
    const previousChannel = channels.value.find((item) => String(item.id) === String(previousChannelId));

    if (!channel) return;

    if (!projectBrandKitId.value || String(projectBrandKitId.value) === String(previousChannel?.brand_kit_id || "")) {
      projectBrandKitId.value = channel.brand_kit_id ? String(channel.brand_kit_id) : "";
    }
  });

  async function loadNotifications() {
    try {
      const response = await api.get("/notifications");
      notifications.value = response.data?.data?.notifications ?? [];
    } catch {
      notifications.value = [];
    }
  }

  async function loadExportJobs() {
    if (!projectId.value) return;

    try {
      const response = await api.get(`/projects/${projectId.value}/exports`);
      exportJobs.value = response.data?.data?.export_jobs ?? [];
    } catch {
      exportJobs.value = [];
    }
  }

  async function markNotificationRead(notificationId) {
    try {
      await api.post(`/notifications/${notificationId}/read`);
      notifications.value = notifications.value.map((item) =>
        item.id === notificationId ? { ...item, is_read: true } : item
      );
    } catch {
      // no-op
    }
  }

  async function markAllRead() {
    const unread = notifications.value.filter((item) => !item.is_read);
    await Promise.all(unread.map((item) => markNotificationRead(item.id)));
  }

  function pushToast(notification) {
    notificationToasts.value = [notification, ...notificationToasts.value].slice(0, 3);
    window.setTimeout(() => {
      notificationToasts.value = notificationToasts.value.filter((toast) => toast.id !== notification.id);
    }, 5000);
  }

  function onPostScheduled({ mode, posts } = {}) {
    if (mode === 'now') {
      // Keep modal open — it shows live polling. Push a "publishing" toast so
      // the user has feedback even if they close the modal early.
      pushToast({ id: 'publish-now-' + Date.now(), title: 'Publishing…', message: 'Your post is being sent to the platform.' })
      pollPublishStatus(posts ?? [])
    } else {
      scheduleModalOpen.value = false
      const label = mode === 'draft' ? 'Saved as draft' : 'Post scheduled'
      const detail = mode === 'draft' ? 'Your post was saved as a draft.' : 'Your post has been scheduled successfully.'
      pushToast({ id: 'scheduled-' + Date.now(), title: label, message: detail })
    }
  }

  function pollPublishStatus(posts) {
    if (!posts.length) return
    const ids = posts.map(p => p.id).filter(Boolean)
    if (!ids.length) return
    let attempts = 0
    const timer = setInterval(async () => {
      attempts++
      if (attempts > 20) { clearInterval(timer); return }
      try {
        const res = await api.get('/scheduled-posts', { params: { per_page: 50 } })
        const all = res.data?.data?.posts ?? []
        const watched = all.filter(p => ids.includes(p.id))
        const allDone = watched.every(p => !['pending', 'processing', 'scheduled'].includes(p.status) || p.status === 'scheduled')
        const anyFailed = watched.some(p => p.status === 'failed')
        const anyPublished = watched.some(p => p.status === 'published')
        if (anyPublished || anyFailed) {
          clearInterval(timer)
          scheduleModalOpen.value = false
          if (anyFailed && !anyPublished) {
            pushToast({ id: 'publish-fail-' + Date.now(), title: 'Publish failed', message: watched.find(p => p.status === 'failed')?.failure_reason || 'The post could not be published.' })
          } else {
            pushToast({ id: 'publish-ok-' + Date.now(), title: 'Published!', message: 'Your post was published successfully.' })
          }
        }
      } catch { /* silent */ }
    }, 3000)
  }

  function subscribeWorkspaceNotifications() {
    const echo = getEcho();
    const workspaceId = mePayload.value?.workspace_id;

    if (!echo || !workspaceId) return;

    if (workspaceChannelName) {
      echo.leave(workspaceChannelName);
    }

    workspaceChannelName = `workspace.${workspaceId}`;

    echo.private(workspaceChannelName).listen(".notification.created", (payload) => {
      const normalized = {
        id: payload.id,
        type: payload.type,
        title: payload.title,
        message: payload.message,
        payload: payload.payload,
        is_read: payload.is_read,
        created_at: payload.created_at,
      };

      notifications.value = [normalized, ...notifications.value].slice(0, 50);
      pushToast(normalized);
    });
  }

  function unsubscribeWorkspaceNotifications() {
    const echo = getEcho();

    if (echo && workspaceChannelName) {
      echo.leave(workspaceChannelName);
    }
  }

  async function logout() {
    await authStore.logout();
    router.push({ name: "login" });
  }

  function selectScene(sceneId) {
    // Picking a scene on a phone is the whole reason the sheet is open — drop
    // it so the tap lands back on the frame, including when the tapped scene
    // was already the active one.
    if (isPhone.value && mobileSheet.value === 'scenes') mobileSheet.value = null;
    if (sceneId === activeSceneId.value) return;

    stopPreviewPlay();
    playProgress.value = 0;
    flushActiveSceneDrafts().then((flushed) => {
      if (flushed === false) return;
      activeSceneId.value = sceneId;
    });
  }

  function skipToScene(direction) {
    stopPreviewPlay();
    const idx = activeSceneIndex.value;
    const next = scenes.value[idx + direction];
    if (next) {
      flushActiveSceneDrafts().then((flushed) => {
        if (flushed === false) return;
        activeSceneId.value = next.id;
      });
    }
  }

  async function setPreviewMode(mode) {
    if (previewMode.value === mode) return;

    stopPreviewPlay();

    if (mode === "full" && scenes.value[0]?.id && activeSceneId.value !== scenes.value[0].id) {
      const flushed = await flushActiveSceneDrafts();
      if (flushed === false) return;
      activeSceneId.value = scenes.value[0].id;
    }

    previewMode.value = mode;
    playProgress.value = 0;
  }

  // iOS (and Android Chrome with autoplay off) refuse <audio>.play() unless
  // that element has been played once inside a user gesture. Our real play()
  // runs after `await flushActiveSceneDrafts()` and inside nextTick, by which
  // point the gesture is spent — so on a phone the preview ran silently while
  // the timer and captions advanced normally. The click handler starts each
  // element itself; permission is granted per element and survives later src
  // changes, so this only matters on the first tap.
  //
  // Nothing here mutes: a muted prime looks identical until the play() promise
  // resolves, and it doesn't resolve while the file is still loading — which is
  // exactly the case this exists to fix.
  const primedMedia = new WeakSet();
  function primeMediaForGesture() {
    for (const el of [audioRef.value, soundAudioRef.value, musicAudioRef.value]) {
      // An element with no source can't be primed — the next tap retries it.
      if (!el || primedMedia.has(el) || (!el.src && !el.currentSrc)) continue;
      primedMedia.add(el);

      let started;
      try {
        started = el.play();
      } catch {
        primedMedia.delete(el);
        continue;
      }
      Promise.resolve(started)
        .then(() => {
          // startPreviewPlay() seeks and plays these a tick later; if it hasn't
          // yet — the scene's assets are still loading — park them at the start.
          if (!isPreviewPlaying.value) {
            el.pause();
            try { el.currentTime = 0; } catch {}
          }
        })
        .catch(() => { primedMedia.delete(el); });
    }
  }

  function togglePreviewPlay() {
    unlockWaveformAudio();
    // pendingScenePlay (spinner showing while assets load) counts as active, so
    // a click cancels the queued play instead of re-queuing it.
    if (isPreviewPlaying.value || pendingScenePlay.value) {
      stopPreviewPlay();
      return;
    }
    primeMediaForGesture();
    startPreviewPlay();
  }

  async function startPreviewPlay() {
    if (!scenes.value.length) return;

    const flushed = await flushActiveSceneDrafts();
    if (flushed === false) return;

    if (previewMode.value === "full") {
      syncFullPreviewScene();
    }

    // Per-scene: don't start until EVERY asset (visual, voice, music) can play,
    // so music can't run ahead of an unloaded visual/voice. The loading overlay
    // shows meanwhile; the activeSceneAssetsReady watcher resumes us once ready.
    if (previewMode.value === "scene" && !activeSceneAssetsReady.value) {
      pendingScenePlay.value = true;
      return;
    }
    pendingScenePlay.value = false;

    stopMusicAudition();
    isPreviewPlaying.value = true;
    nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
    nextTick(() => playPreviewMusic());
    const TICK = 50;
    previewPlayTimer = window.setInterval(() => {
      const dur = previewContextDuration.value || 1;

      if (previewMode.value === "scene") {
        // Drive progress from actual audio position so captions stay in sync
        const audio = audioRef.value;
        if (
          audio &&
          !audio.paused &&
          Number.isFinite(audio.duration) &&
          audio.duration > 0
        ) {
          const newProgress = (audio.currentTime / audio.duration) * 100;
          if (audio.ended || newProgress >= 100) {
            playProgress.value = 0;
            playActiveSceneAudio(0);
          } else {
            playProgress.value = newProgress;
          }
        } else {
          // Audio not ready yet — advance timer as fallback
          playProgress.value += (100 / dur) * (TICK / 1000);
          if (playProgress.value >= 100) {
            playProgress.value = 0;
            playActiveSceneAudio(0);
          }
        }
      } else {
        const audioDrivenProgress = fullPreviewAudioProgress();
        playProgress.value =
          audioDrivenProgress ??
          playProgress.value + (100 / dur) * (TICK / 1000);

        if (playProgress.value >= 100) {
          playProgress.value = 100;
          stopPreviewPlay();
        } else {
          syncFullPreviewScene();
        }
      }
    }, TICK);
  }

  function stopPreviewPlay() {
    isPreviewPlaying.value = false;
    pendingScenePlay.value = false;
    if (previewPlayTimer) {
      window.clearInterval(previewPlayTimer);
      previewPlayTimer = null;
    }
    if (audioRef.value) {
      audioRef.value.pause();
    }
    if (soundAudioRef.value) {
      soundAudioRef.value.pause();
    }
    stopPreviewMusic();
  }

  async function timelineSeek(pct) {
    if (previewMode.value !== "full") await setPreviewMode("full");
    playProgress.value = Math.max(0, Math.min(100, pct));
    syncFullPreviewScene();
    if (isPreviewPlaying.value) {
      nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
      nextTick(() => playPreviewMusic());
    } else if (audioRef.value) {
      audioRef.value.currentTime = currentSceneAudioOffset();
    }
  }

  async function timelineReorder(newSceneIds) {
    const previousScenes = [...scenes.value];
    const reordered = newSceneIds.map((id, orderIndex) => {
      const sc = scenes.value.find(s => s.id === id);
      return { ...sc, scene_order: orderIndex + 1 };
    }).filter(Boolean);
    scenes.value = reordered;

    try {
      const response = await api.patch("/scenes/reorder", {
        project_id: project.value.id,
        scene_ids: newSceneIds,
      });
      scenes.value = sortScenesByOrder(
        (response.data?.data?.scenes ?? []).map(s => normalizeScenePayload(s)).filter(Boolean)
      );
    } catch {
      scenes.value = previousScenes;
    }
  }

  function scrubberSeek(event) {
    const track = event.currentTarget.querySelector(".scrubber-track");
    if (!track) return;
    const rect = track.getBoundingClientRect();
    playProgress.value = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
    if (previewMode.value === "full") {
      syncFullPreviewScene();
    }
    if (isPreviewPlaying.value) {
      nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
      nextTick(() => playPreviewMusic());
    } else if (audioRef.value) {
      audioRef.value.currentTime = currentSceneAudioOffset();
    }
  }

  function currentSceneAudioOffset() {
    if (previewMode.value === "full") {
      const elapsed = previewElapsedSecs.value;
      const match = sceneAtFullElapsed(elapsed);
      return Math.max(0, elapsed - match.start);
    }

    return previewElapsedSecs.value;
  }

  // Use the scene clock for both video layers. At EOF keep the last frame;
  // do not replay the action while narration continues.
  function syncSceneVideoPreview() {
    const offset = currentSceneAudioOffset();
    for (const video of [previewVideoRef.value, previewVideoBackgroundRef.value]) {
      if (!video || !Number.isFinite(video.duration) || video.duration <= 0) continue;
      const target = Math.min(offset, Math.max(0, video.duration - 0.04));
      if (Math.abs(video.currentTime - target) > 0.3 || !isPreviewPlaying.value) {
        video.currentTime = target;
      }
      if (isPreviewPlaying.value && offset < video.duration - 0.04) video.play().catch(() => {});
      else video.pause();
    }
  }
  function sceneVideoMetadataLoaded() {
    previewVideoDuration.value = previewVideoRef.value?.duration || 0;
    syncSceneVideoPreview();
  }
  watch([previewElapsedSecs, isPreviewPlaying, activeSceneId], () => nextTick(syncSceneVideoPreview));
  watch(currentVisualUrl, () => { previewVideoDuration.value = 0; });

  function fullPreviewAudioProgress() {
    if (previewMode.value !== "full") return null;

    const audio = audioRef.value;
    if (
      !audio ||
      audio.paused ||
      !Number.isFinite(audio.duration) ||
      audio.duration <= 0 ||
      !activeScene.value
    ) {
      return null;
    }

    const currentSceneIndex = scenes.value.findIndex(
      (scene) => scene.id === activeScene.value.id
    );
    if (currentSceneIndex < 0) return null;

    let sceneStart = 0;
    for (let i = 0; i < currentSceneIndex; i += 1) {
      sceneStart += sceneDuration(scenes.value[i]);
    }

    // The REAL file's duration, not the stored one — historical TTS assets
    // carry word-count estimates that undershoot by seconds, and clamping to
    // them advanced scenes mid-sentence.
    const activeDuration = (Number.isFinite(audio.duration) && audio.duration > 0)
      ? audio.duration
      : sceneDuration(activeScene.value);
    const sceneElapsed = Math.min(audio.currentTime, activeDuration);
    const total = totalVideoDuration.value || 1;

    return Math.max(0, Math.min(100, ((sceneStart + sceneElapsed) / total) * 100));
  }

  function syncFullPreviewScene() {
    if (previewMode.value !== "full") return;

    const elapsed = previewElapsedSecs.value;
    const match = sceneAtFullElapsed(elapsed);
    if (!match.scene) return;

    // A scene OWNS the stage until its narration finishes. The boundary math
    // runs on stored durations, which for older audio are estimates — when the
    // estimate ran short, the preview cut scenes off mid-sentence. If the
    // current scene's voice is still playing, hold; the tick advances us the
    // moment it actually ends.
    const audio = audioRef.value;
    if (
      match.scene.id !== activeSceneId.value &&
      audio && !audio.paused && !audio.ended &&
      activeSceneAudioUrl.value
    ) {
      return;
    }

    pendingPreviewAudioOffset = Math.max(0, elapsed - match.start);
    if (activeSceneId.value !== match.scene.id) {
      activeSceneId.value = match.scene.id;
      scrollSceneIntoView(match.scene.id);
    }
  }

  function scrollSceneIntoView(sceneId) {
    nextTick(() => {
      const el = document.getElementById(`scene-item-${sceneId}`);
      if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }

  function playActiveSceneAudio(offsetSeconds = 0) {
    if (!isPreviewPlaying.value) return;

    playActiveSceneSound(offsetSeconds);

    if (!audioRef.value || !activeSceneAudioUrl.value) {
      isAudioPlaying.value = false;
      isAudioLoading.value = false;
      syncPreviewMusicVolume();
      return;
    }

    isAudioLoading.value = true;
    preloadSceneAudio(activeScene.value);

    const duration = Number.isFinite(audioRef.value.duration) ? audioRef.value.duration : null;
    const boundedOffset = duration
      ? Math.max(0, Math.min(offsetSeconds, Math.max(0, duration - 0.05)))
      : Math.max(0, offsetSeconds);

    try {
      audioRef.value.currentTime = boundedOffset;
    } catch {
      // Some browsers reject currentTime before metadata loads; playback can still begin.
    }

    audioRef.value.play().catch(() => {
      isAudioPlaying.value = false;
      isAudioLoading.value = false;
      syncPreviewMusicVolume();
    });
  }

  function playActiveSceneSound(offsetSeconds = 0) {
    if (!soundAudioRef.value || !activeSceneSoundUrl.value || !isPreviewPlaying.value) return;

    const duration = Number.isFinite(soundAudioRef.value.duration) ? soundAudioRef.value.duration : null;
    const boundedOffset = duration
      ? Math.max(0, Math.min(offsetSeconds, Math.max(0, duration - 0.05)))
      : Math.max(0, offsetSeconds);

    soundAudioRef.value.volume = Math.max(0, Math.min(1, sceneSoundVolume.value / 100));

    try {
      soundAudioRef.value.pause();
      soundAudioRef.value.currentTime = boundedOffset;
    } catch {
      // Some browsers reject currentTime before metadata loads; playback can still begin.
    }

    soundAudioRef.value.play().catch(() => {});
  }

  function previewMusicOffset() {
    if (previewMode.value === "full") {
      return previewElapsedSecs.value;
    }

    return 0;
  }

  function syncPreviewMusicVolume() {
    if (!musicAudioRef.value) return;
    musicAudioRef.value.volume = previewMusicVolume.value;
  }

  function playPreviewMusic() {
    if (!musicAudioRef.value || !activeMusicTrackUrl.value || !isPreviewPlaying.value) return;

    syncPreviewMusicVolume();

    const offset = previewMusicOffset();
    const duration = Number.isFinite(musicAudioRef.value.duration) ? musicAudioRef.value.duration : null;
    if (!musicLoop.value && duration && offset >= duration) {
      musicAudioRef.value.pause();
      return;
    }

    const nextTime = duration && duration > 0
      ? (musicLoop.value ? offset % duration : offset)
      : offset;

    try {
      musicAudioRef.value.currentTime = Math.max(0, nextTime);
    } catch {
      // Metadata may not be available yet; the browser will start from the beginning.
    }

    musicAudioRef.value.play().catch(() => {});
  }

  function stopPreviewMusic() {
    if (musicAudioRef.value) {
      musicAudioRef.value.pause();
    }
  }

  function handleSceneAudioPlay() {
    isAudioPlaying.value = true;
    syncPreviewMusicVolume();
  }

  function handleSceneAudioPause() {
    isAudioPlaying.value = false;
    if (soundAudioRef.value) {
      try { soundAudioRef.value.pause(); soundAudioRef.value.currentTime = 0; } catch {}
    }
    syncPreviewMusicVolume();
  }

  function moodGradient(mood) {
    const map = {
      dark: "linear-gradient(135deg,#1a0a2e,#0d0d2b)",
      upbeat: "linear-gradient(135deg,#0d2e1a,#1a2e0d)",
      calm: "linear-gradient(135deg,#0d1a2e,#1a1a0d)",
      epic: "linear-gradient(135deg,#2e0d0d,#1a1a2e)",
      corporate: "linear-gradient(135deg,#0d2e2e,#1a2e1a)",
    };
    return map[mood?.toLowerCase()] ?? "linear-gradient(135deg,#1a1a2e,#2e1a1a)";
  }

  function moodEmoji(mood) {
    const map = { dark: "🌑", upbeat: "🎵", calm: "🌊", epic: "⚡", corporate: "💼" };
    return map[mood?.toLowerCase()] ?? "🎵";
  }

  function trackMoodLabel(track) {
    const mood = (track.tags ?? []).find((t) => t !== "music") ?? "music";
    return mood.charAt(0).toUpperCase() + mood.slice(1) + " · Royalty-free";
  }

  function selectMusicTrack(trackId) {
    selectedMusicTrackId.value = trackId;
    scheduleMusicSave();

    if (isPreviewPlaying.value) {
      nextTick(() => playPreviewMusic());
    }
  }

  function toggleMusicAudition(track) {
    if (!track?.storage_url || !musicAuditionRef.value) return;

    unlockWaveformAudio();
    stopPreviewPlay();

    if (auditionMusicTrackId.value === track.id && !musicAuditionRef.value.paused) {
      musicAuditionRef.value.pause();
      auditionMusicTrackId.value = null;
      return;
    }

    auditionMusicTrackId.value = track.id;
    nextTick(() => {
      if (!musicAuditionRef.value) return;
      musicAuditionRef.value.volume = Math.max(0, Math.min(1, Number(musicVolume.value || 0) / 100));
      musicAuditionRef.value.currentTime = 0;
      musicAuditionRef.value.play().catch(() => {
        auditionMusicTrackId.value = null;
      });
    });
  }

  function stopMusicAudition() {
    if (musicAuditionRef.value) {
      musicAuditionRef.value.pause();
    }
    auditionMusicTrackId.value = null;
  }

  function moodLabel(mood) {
    const value = String(mood || "other");
    return value.charAt(0).toUpperCase() + value.slice(1);
  }

  function formatTrackDuration(secs) {
    if (!secs) return "—";
    const m = Math.floor(Number(secs) / 60);
    const s = Math.round(Number(secs) % 60);
    return `${m}:${String(s).padStart(2, "0")}`;
  }

  function togglePanel(name) {
    panelState.value[name] = !panelState.value[name];
  }

  function toggleAddScene(position) {
    const opening = addScenePanelPosition.value !== position;
    addScenePanelPosition.value = opening ? position : "";

    // The add panel opens at the top of the scene list. In the phone sheet the
    // list is usually scrolled somewhere down it, so opening the panel put it
    // out of sight and "+ Add" looked like it did nothing.
    if (!opening) return;
    nextTick(() => {
      const list = document.querySelector('.scene-list');
      const panel = list?.querySelector('.add-scene-panel.open');
      if (!list || !panel) return;
      if (position === 'top') {
        list.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    });
  }

  function closeAddScene() {
    addScenePanelPosition.value = "";
    resetAddSceneDrafts();
    addSceneGenerateError.value = "";
  }

  function selectAddSceneVisualMode(mode) {
    addSceneVisualMode.value = mode;
    // No auto-open of the library picker — the user explicitly clicks
    // "Pick from your library" inside the tab when they're ready.
  }

  function applyCaptionPreset(preset) {
    if (preset.preset_type) captionStyleDraft.value = preset.preset_type;
    if (preset.font) captionFontDraft.value = preset.font;
    if (preset.font_size_rule) captionSizeDraft.value = preset.font_size_rule;
    if (preset.highlight_mode) captionHighlightDraft.value = preset.highlight_mode;
    if (preset.highlight_color) captionHighlightColorDraft.value = preset.highlight_color;
    if (preset.caption_color) captionColorDraft.value = preset.caption_color;
    if (preset.caption_position) captionPositionDraft.value = preset.caption_position;
    if (preset.animation_type) captionAnimationDraft.value = preset.animation_type;
    // watcher autosaves
  }

  async function saveCaptionPreset() {
    const name = captionPresetSaveName.value.trim();
    if (!name) return;
    captionPresetSaving.value = true;
    try {
      const response = await api.post("/caption-presets", {
        name,
        preset_type: captionStyleDraft.value || null,
        font: captionFontDraft.value || null,
        font_size_rule: captionSizeDraft.value || null,
        highlight_mode: captionHighlightDraft.value || null,
        highlight_color: captionHighlightColorDraft.value || null,
        caption_color: captionColorDraft.value || null,
        caption_position: captionPositionDraft.value || null,
        animation_type: captionAnimationDraft.value || null,
      });
      const created = response.data?.data?.caption_preset;
      if (created) captionPresets.value = [...captionPresets.value, created].sort((a, b) => a.name.localeCompare(b.name));
      captionPresetSaveName.value = "";
      captionPresetSaveOpen.value = false;
    } catch (_) {
      // non-critical
    } finally {
      captionPresetSaving.value = false;
    }
  }

  async function deleteCaptionPreset(presetId) {
    captionPresetDeleting.value = presetId;
    try {
      await api.delete(`/caption-presets/${presetId}`);
      captionPresets.value = captionPresets.value.filter((p) => p.id !== presetId);
    } catch (_) {
      // non-critical
    } finally {
      captionPresetDeleting.value = null;
    }
  }

  async function saveVoiceProfile() {
    const name = voiceProfileSaveName.value.trim();
    if (!name || !voiceProfileKey.value) return;
    voiceProfileSaving.value = true;
    try {
      // The provider is a fact about the voice, not a constant. Hardcoding
      // "openai" here once re-registered a customer's chatterbox clone under the
      // wrong engine, and the profile he named "Default" spoke in a stock voice
      // from then on.
      const key = voiceProfileKey.value;
      const provider = key.startsWith("clone-")
        ? "replicate:chatterbox"
        : ["alloy", "echo", "fable", "onyx", "nova", "shimmer"].includes(key)
        ? "openai"
        : "google";
      const response = await api.post("/voice-profiles", {
        name,
        provider_voice_key: key,
        provider,
        language: activeScene.value?.voice_settings?.language || "en",
      });
      const created = response.data?.data?.voice_profile;
      if (created) voiceProfiles.value = [...voiceProfiles.value, created].sort((a, b) => a.name.localeCompare(b.name));
      voiceProfileSaveName.value = "";
      voiceProfileSaveOpen.value = false;
    } catch (_) {
      // non-critical
    } finally {
      voiceProfileSaving.value = false;
    }
  }

  async function saveSceneDuration() {
    if (!activeScene.value) return;
    const val = parseFloat(sceneDurationDraft.value);
    if (isNaN(val) || val < 1 || val > 600) return;
    if (activeScene.value?.voice_settings?.audio_asset_id) return;
    sceneDurationError.value = "";
    sceneDurationSaving.value = true;
    try {
      const response = await api.patch(`/scenes/${activeScene.value.id}`, { duration_seconds: val });
      const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updated) replaceSceneInCollection(updated);
    } catch (error) {
      sceneDurationError.value = error.response?.data?.error?.message || "Duration could not be saved. Try again.";
    } finally {
      sceneDurationSaving.value = false;
    }
  }

  function toggleRewriteTools() {
    rewriteToolsVisible.value = !rewriteToolsVisible.value;
    if (!rewriteToolsVisible.value) {
      rewritePreviewVisible.value = false;
      rewritePreviewCopy.value = "";
      rewriteMode.value = "";
      rewriteError.value = "";
    }
  }

  async function submitRewrite(mode) {
    if (!activeScene.value || !mode) return;

    rewritePending.value = true;
    rewriteError.value = "";
    rewriteMode.value = mode;

    try {
      const response = await api.post(`/scenes/${activeScene.value.id}/rewrite`, {
        mode,
        apply: false,
      });

      rewritePreviewCopy.value =
        response.data?.data?.rewrite?.candidate || "";
      rewritePreviewVisible.value = Boolean(rewritePreviewCopy.value);
    } catch (requestError) {
      rewriteError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Rewrite failed.";
      rewritePreviewVisible.value = false;
    } finally {
      rewritePending.value = false;
    }
  }

  function submitRewriteCustom() {
    const source =
      sceneScriptDraft.value.trim() || activeScene.value?.script_text || "";
    if (!source) return;

    const suffix = rewriteCustomInstruction.value.trim();
    rewriteMode.value = "custom";
    rewriteError.value = "";
    rewritePreviewCopy.value = suffix ? `${source} ${suffix}.` : source;
    rewritePreviewVisible.value = true;
  }

  function hideRewritePreview() {
    rewritePreviewVisible.value = false;
    rewriteError.value = "";
  }

  async function acceptRewrite() {
    if (!activeScene.value) return;

    if (rewriteMode.value && rewriteMode.value !== "custom") {
      rewriteApplyPending.value = true;
      rewriteError.value = "";

      try {
        const response = await api.post(`/scenes/${activeScene.value.id}/rewrite`, {
          mode: rewriteMode.value,
          apply: true,
        });

        const updatedScene = response.data?.data?.scene ?? null;
        if (updatedScene) {
          const normalizedScene = normalizeScenePayload(updatedScene);
          scenes.value = scenes.value.map((scene) =>
            scene.id === normalizedScene.id ? { ...scene, ...normalizedScene } : scene
          );
          activeSceneId.value = normalizedScene.id;
          sceneScriptDraft.value = normalizedScene.script_text || "";
        }

        rewritePreviewVisible.value = false;
        return;
      } catch (requestError) {
        rewriteError.value =
          requestError.response?.data?.error?.message ||
          requestError.response?.data?.message ||
          "Failed to apply rewrite.";
        return;
      } finally {
        rewriteApplyPending.value = false;
      }
    }

    sceneScriptDraft.value = rewritePreviewCopy.value;
    activeScene.value.voice_settings = {
      ...(activeScene.value.voice_settings || {}),
      is_outdated: true,
    };
    rewritePreviewVisible.value = false;
  }

  async function regenerateVoiceForScene(sceneId) {
    if (voiceRegeneratePending.value) return
    // If not already active, select it first so the panel shows the right scene
    if (activeSceneId.value !== sceneId) selectScene(sceneId)
    await nextTick()
    regenerateVoice()
  }

  async function regenerateVoice() {
    if (!activeScene.value || voiceRegeneratePending.value) return;

    const flushed = await flushActiveSceneDrafts();
    if (flushed === false) return;

    voiceRegeneratePending.value = true;
    voiceRegenerateError.value = "";
    isAudioPlaying.value = false;
    isAudioLoading.value = true;

    try {
      const response = await api.post(`/scenes/${activeScene.value.id}/regenerate-voice`);
      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (!updatedScene) {
        throw new Error("Voice regeneration returned no scene payload.");
      }

      updatedScene.voice_settings = {
        ...(updatedScene.voice_settings || {}),
        is_outdated: false,
      };
      updatedScene.voice_settings_json = updatedScene.voice_settings;

      replaceSceneInCollection(updatedScene);
      activeSceneId.value = updatedScene.id;
      sceneScriptDraft.value = updatedScene.script_text || "";
      voiceProfileKey.value = updatedScene.voice_settings?.voice_id || "Kore";
      voiceDirectionDraft.value = updatedScene.voice_settings?.voice_prompt || "";
      voiceSpeedDraft.value = String(updatedScene.voice_settings?.speed ?? 1.0);
      voiceStabilityDraft.value = String(updatedScene.voice_settings?.stability ?? "medium");
      voiceSaveState.value = "saved";
      scriptSaveState.value = "idle";
      preloadSceneAudio(updatedScene);
      pushToast({ id: `voice-done-${updatedScene.id}-${Date.now()}`, title: 'Voice ready', message: `Scene ${updatedScene.scene_order ?? ''} voice regenerated.` });
    } catch (requestError) {
      voiceRegenerateError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        requestError.message ||
        "Voice regeneration failed.";
      isAudioLoading.value = false;
    } finally {
      voiceRegeneratePending.value = false;
    }
  }

  async function persistSceneScript(sceneId, scriptText) {
    scriptSaveTimer = null;
    scriptSaveState.value = "saving";
    scriptSaveError.value = "";

    const currentScene = scenes.value.find((scene) => scene.id === sceneId);
    const voiceSettings = {
      ...((currentScene?.voice_settings || currentScene?.voice_settings_json || {}) ?? {}),
      is_outdated: true,
    };

    try {
      const response = await api.patch(`/scenes/${sceneId}`, {
        script_text: scriptText,
        status: "edited",
        voice_settings_json: voiceSettings,
      });

      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (!updatedScene) {
        throw new Error("Scene save returned no payload.");
      }

      replaceSceneInCollection(updatedScene);

      if (activeSceneId.value === updatedScene.id && sceneScriptDraft.value === scriptText) {
        sceneScriptDraft.value = updatedScene.script_text || "";
      }

      scriptSaveState.value = "saved";
      window.setTimeout(() => {
        if (scriptSaveState.value === "saved") {
          scriptSaveState.value = "idle";
        }
      }, 1200);
    } catch (requestError) {
      scriptSaveState.value = "error";
      scriptSaveError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        requestError.message ||
        "Save failed.";
    }
  }

  // Apply the active scene's voice to every scene in the project.
  //
  // Carries the WHOLE voice setting, not just the voice id: provider, speed,
  // stability and the Gemini delivery direction. Copying the voice alone would
  // leave other scenes reading in the old direction, which sounds like the
  // change half-failed.
  //
  // Each scene is marked is_outdated so GenerateTTSJob re-synthesises it —
  // without that the old audio asset is kept and nothing audibly changes.
  const applyVoiceAllState = ref("idle");   // idle | saving | saved | error
  const applyVoiceAllError = ref("");

  // --- Re-record every scene's voiceover -----------------------------------
  // Distinct from "apply this voice to all scenes", which only copies settings.
  // This actually re-runs TTS and costs credits, so it's priced first. The three
  // engines bill differently (OpenAI 1cr, cloned 2cr, Gemini 3cr) and a project
  // can mix them, so the total comes from the server per scene.
  const bulkVoiceOpen       = ref(false);
  const bulkVoicePreview    = ref(null);
  const bulkVoiceLoading    = ref(false);
  const bulkVoiceSubmitting = ref(false);
  const bulkVoiceError      = ref("");

  async function openBulkVoice() {
    bulkVoiceOpen.value = true;
    bulkVoiceError.value = "";
    bulkVoiceLoading.value = true;
    bulkVoicePreview.value = null;
    try {
      const response = await api.post(`/projects/${projectId.value}/rerecord-all`, {});
      bulkVoicePreview.value = response.data?.data ?? null;
    } catch (err) {
      bulkVoiceError.value = err.response?.data?.error?.message ?? "Could not price this.";
    } finally {
      bulkVoiceLoading.value = false;
    }
  }

  async function submitBulkVoice() {
    const preview = bulkVoicePreview.value;
    if (!preview || !preview.eligible_count || !preview.affordable) return;
    bulkVoiceSubmitting.value = true;
    bulkVoiceError.value = "";
    try {
      const response = await api.post(`/projects/${projectId.value}/rerecord-all`, { confirm: true });
      const started = response.data?.data?.started_count ?? 0;
      bulkVoiceOpen.value = false;
      bulkVoicePreview.value = null;
      pushToast({
        id: `bulk-voice-${Date.now()}`,
        title: `Re-recording ${started} scene${started === 1 ? '' : 's'}`,
        message: 'Each scene re-records its own script.',
      });
    } catch (err) {
      bulkVoiceError.value =
        err.response?.data?.error?.message ?? "Could not start re-recording.";
    } finally {
      bulkVoiceSubmitting.value = false;
    }
  }

  async function applyVoiceToAllScenes() {
    const source = activeScene.value;
    if (!source || applyVoiceAllState.value === "saving") return;

    const selectedProfile = (voiceProfiles.value || []).find(
      (p) => p.provider_voice_key === voiceProfileKey.value
    );
    const provider =
      selectedProfile?.provider ||
      (OPENAI_VOICE_KEYS.includes(String(voiceProfileKey.value).toLowerCase()) ? "openai" : "google");

    const shared = {
      voice_id: voiceProfileKey.value,
      provider,
      speed: Number(voiceSpeedDraft.value || 1),
      stability: voiceStabilityDraft.value,
      voice_prompt: activeVoiceIsGemini.value ? voiceDirectionDraft.value.trim() : "",
    };

    const targets = scenes.value.filter((scene) => scene.id !== source.id);
    if (targets.length === 0) return;

    applyVoiceAllState.value = "saving";
    applyVoiceAllError.value = "";

    let failed = 0;

    for (const scene of targets) {
      try {
        const response = await api.patch(`/scenes/${scene.id}`, {
          voice_settings_json: {
            ...(scene.voice_settings || {}),
            ...shared,
            is_outdated: true,
          },
          status: "edited",
        });
        const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
        if (updated) {
          scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
        }
      } catch {
        failed++;
      }
    }

    if (failed > 0) {
      applyVoiceAllState.value = "error";
      // Say how many, not just "failed" — a partial apply leaves the project in a
      // mixed state and the user needs to know it wasn't all-or-nothing.
      applyVoiceAllError.value = `${failed} of ${targets.length} scenes could not be updated. Try again.`;
      return;
    }

    applyVoiceAllState.value = "saved";
    window.setTimeout(() => {
      if (applyVoiceAllState.value === "saved") applyVoiceAllState.value = "idle";
    }, 1600);
  }

  async function persistVoiceSettings(sceneId, nextSettings) {
    voiceSaveTimer = null;
    voiceSaveState.value = "saving";
    voiceSaveError.value = "";

    const currentScene = scenes.value.find((scene) => scene.id === sceneId);
    const currentVoice = currentScene?.voice_settings || {};
    // Provider comes from the selected profile (authoritative): 'google' for
    // Gemini, 'openai', or 'replicate:chatterbox' for a cloned voice. Falling
    // back to voice-id inference only when the profile isn't loaded.
    const selectedProfile = (voiceProfiles.value || []).find(
      (p) => p.provider_voice_key === nextSettings.voice_id
    );
    const provider =
      selectedProfile?.provider ||
      (OPENAI_VOICE_KEYS.includes(String(nextSettings.voice_id).toLowerCase()) ? "openai" : "google");
    const mergedSettings = {
      ...currentVoice,
      voice_id: nextSettings.voice_id,
      provider,
      speed: nextSettings.speed,
      stability: nextSettings.stability,
      // Gemini delivery direction (empty for OpenAI/clone voices); backend folds
      // it into the prompt at synth time.
      voice_prompt: nextSettings.voice_prompt ?? "",
      is_outdated: true,
    };

    try {
      const response = await api.patch(`/scenes/${sceneId}`, {
        voice_settings_json: mergedSettings,
        status: "edited",
      });

      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (!updatedScene) {
        throw new Error("Voice save returned no payload.");
      }

      scenes.value = scenes.value.map((scene) =>
        scene.id === updatedScene.id ? { ...scene, ...updatedScene } : scene
      );

      voiceSaveState.value = "saved";
      window.setTimeout(() => {
        if (voiceSaveState.value === "saved") {
          voiceSaveState.value = "idle";
        }
      }, 1200);
    } catch (requestError) {
      voiceSaveState.value = "error";
      voiceSaveError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        requestError.message ||
        "Voice save failed.";
    }
  }

  async function saveUgcHeadline(event) {
    const sceneId = Number(event.target.dataset.sceneId);
    const scene = scenes.value.find(s => s.id === sceneId);
    if (!scene) return;
    if (captionSaveTimer) window.clearTimeout(captionSaveTimer);
    await persistCaptionSettings(sceneId, {
      ...normalizeCaptionSettings(scene.caption_settings_json),
      ugc_headline: { text: event.target.value },
    });
  }

  async function persistCaptionSettings(sceneId, nextSettings) {
    captionSaveTimer = null;
    captionSaveState.value = "saving";
    captionSaveError.value = "";

    try {
      const response = await api.patch(`/scenes/${sceneId}`, {
        caption_settings_json: nextSettings,
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene);

      if (!updatedScene) {
        throw new Error("Caption update returned no scene payload.");
      }

      replaceSceneInCollection(updatedScene);
      captionSaveState.value = "saved";
      window.setTimeout(() => {
        if (captionSaveState.value === "saved") {
          captionSaveState.value = "idle";
        }
      }, 1200);
    } catch (requestError) {
      captionSaveState.value = "error";
      captionSaveError.value =
        requestError.response?.data?.error?.message ?? "Caption save failed";
    }
  }

  // Apply the current caption settings to EVERY scene at once. Patches all scenes
  // in parallel, then merges the returned scenes back into the collection in one
  // pass (avoids per-call races on scenes.value).
  const applyAllState = ref("idle"); // idle | applying | done | error
  async function applyCaptionsToAllScenes() {
    const targets = (scenes.value || []).filter((s) => s && s.id);
    if (!targets.length || applyAllState.value === "applying") return;

    const settings = {
      enabled: captionEnabledDraft.value,
      style_key: captionStyleDraft.value,
      highlight_mode: captionHighlightDraft.value,
      position: captionPositionDraft.value,
      font: captionFontDraft.value,
      highlight_color: captionHighlightColorDraft.value,
      color: captionColorDraft.value,
      size: captionSizeDraft.value,
      preset_id: activeCaptionSettings.value?.preset_id || null,
      animation: captionAnimationDraft.value,
      highlight_style: captionHighlightStyleDraft.value,
      panel_color: captionPanelColorDraft.value || null,
      backdrop: captionBackdropDraft.value,
    };

    applyAllState.value = "applying";
    try {
      const updated = await Promise.all(
        targets.map(async (s) => {
          const resp = await api.patch(`/scenes/${s.id}`, { caption_settings_json: settings });
          return normalizeScenePayload(resp.data?.data?.scene);
        })
      );
      const byId = new Map(updated.filter(Boolean).map((s) => [s.id, s]));
      scenes.value = scenes.value.map((s) => byId.get(s.id) || s);
      applyAllState.value = "done";
      window.setTimeout(() => { if (applyAllState.value === "done") applyAllState.value = "idle"; }, 1800);
    } catch (e) {
      applyAllState.value = "error";
      window.setTimeout(() => { if (applyAllState.value === "error") applyAllState.value = "idle"; }, 2500);
    }
  }

  async function persistMotionSettings(sceneId, nextSettings) {
    motionSaveTimer = null;
    motionSaveState.value = "saving";
    motionSaveError.value = "";

    try {
      const response = await api.patch(`/scenes/${sceneId}`, {
        motion_settings_json: nextSettings,
      });
      const updatedScene = normalizeScenePayload(response.data?.data?.scene);
      if (updatedScene) replaceSceneInCollection(updatedScene);
      motionSaveState.value = "saved";
      window.setTimeout(() => {
        if (motionSaveState.value === "saved") motionSaveState.value = "idle";
      }, 1200);
    } catch (requestError) {
      motionSaveState.value = "error";
      motionSaveError.value =
        requestError.response?.data?.error?.message ?? "Motion save failed";
    }
  }

  // ── Characters ──────────────────────────────────────────────────────────────
  async function loadCharacters() {
    try {
      const response = await api.get("/characters");
      characters.value = response.data?.data?.characters ?? [];
    } catch {
      characters.value = [];
    }
  }

  const activeCharacter = computed(() => {
    const id = activeScene.value?.character_id;
    if (!id) return null;
    return characters.value.find((c) => c.id === id) ?? null;
  });

  // The character currently bound to the in-progress add-scene draft.
  // Backs the rich chip + popover above the Style grid in both add-scene
  // popovers. Null until the user picks one.
  const addSceneCharacter = computed(() => {
    const id = addSceneCharacterId.value;
    if (!id) return null;
    return characters.value.find((c) => c.id === Number(id)) ?? null;
  });

  function selectAddSceneCharacter(id) {
    addSceneCharacterId.value = id;
    addSceneCharPopoverOpen.value = false;
  }

  async function selectCharacter(characterId) {
    const scene = activeScene.value;
    if (!scene) return;
    characterPopoverOpen.value = false;
    try {
      const response = await api.patch(`/scenes/${scene.id}`, { character_id: characterId });
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
    } catch (e) {
      // Silent revert — scene unchanged.
    }
  }

  function pickCharacterFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      createCharacterError.value = "Please pick an image file (PNG or JPG).";
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      createCharacterError.value = "Image must be 10MB or less.";
      return;
    }
    createCharacterError.value = "";
    createCharacterFile.value = file;
    if (createCharacterPreviewUrl.value) URL.revokeObjectURL(createCharacterPreviewUrl.value);
    createCharacterPreviewUrl.value = URL.createObjectURL(file);
  }

  function clearCharacterFile() {
    if (createCharacterPreviewUrl.value) URL.revokeObjectURL(createCharacterPreviewUrl.value);
    createCharacterFile.value = null;
    createCharacterPreviewUrl.value = "";
  }

  function closeCharacterModal() {
    createCharacterOpen.value = false;
    createCharacterName.value = "";
    createCharacterDescription.value = "";
    clearCharacterFile();
    createCharacterError.value = "";
  }

  async function createCharacter() {
    const name = createCharacterName.value.trim();
    if (!name) {
      createCharacterError.value = "Name is required.";
      return;
    }
    createCharacterSaving.value = true;
    createCharacterError.value = "";
    try {
      // If user attached an image, upload it as a workspace asset first.
      let referenceAssetId = null;
      if (createCharacterFile.value) {
        const fd = new FormData();
        fd.append("title", name);
        fd.append("asset_type", "image");
        fd.append("asset_file", createCharacterFile.value);
        const upload = await api.post("/assets", fd, {
          headers: { "Content-Type": "multipart/form-data" },
        });
        referenceAssetId = upload.data?.data?.asset?.id ?? null;
      }

      const response = await api.post("/characters", {
        name,
        description: createCharacterDescription.value.trim() || null,
        reference_asset_id: referenceAssetId,
      });
      const created = response.data?.data?.character;
      if (created) {
        characters.value = [created, ...characters.value];
        await selectCharacter(created.id);
      }
      closeCharacterModal();
    } catch (e) {
      createCharacterError.value =
        e.response?.data?.error?.message ?? "Could not create character.";
    } finally {
      createCharacterSaving.value = false;
    }
  }

  function hookScoreClass(score) {
    if (score == null) return "score-none";
    if (score >= 80) return "score-green";
    if (score >= 60) return "score-yellow";
    return "score-red";
  }

  async function useHook(option) {
    // Apply hook text to the first hook-type scene (or the first scene if none).
    const hookScene =
      scenes.value.find((s) => s.scene_type === "hook") ?? scenes.value[0] ?? null;
    if (!hookScene) return;

    try {
      const response = await api.patch(`/scenes/${hookScene.id}`, {
        script_text: option.hook_text,
      });
      const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (updated) {
        replaceSceneInCollection(updated);
        if (activeSceneId.value === hookScene.id) {
          sceneScriptDraft.value = option.hook_text;
        }
      }
    } catch {
      // silent — hook text can be copied manually if PATCH fails
    }
  }

  async function swapVisual() {
    if (!activeScene.value || visualSwapPending.value) return;

    const flushed = await flushActiveSceneDrafts();
    if (flushed === false) return;

    visualSwapPending.value = true;
    visualSwapError.value = "";
    visualLoadFailed.value = false;
    currentVisualUrl.value = null;

    try {
      const response = await api.post(`/scenes/${activeScene.value.id}/swap-visual`, {
        query: visualQueryDraft.value || activeScene.value.visual_prompt || "",
        visual_type: selectedVisualType(),
      });

      const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (!updatedScene) {
        throw new Error("Visual swap returned no payload.");
      }

      replaceSceneInCollection(updatedScene);

      visualQueryDraft.value = updatedScene.visual_prompt || "";
      preloadSceneVisual(updatedScene);
    } catch (requestError) {
      visualSwapError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        requestError.message ||
        "Visual swap failed.";
      visualLoadFailed.value = true;
    } finally {
      visualSwapPending.value = false;
    }
  }

  // --- Restyle every scene's image ---------------------------------------
  // Applies the picked style + model to the whole project. Each scene rebuilds
  // from its OWN prompt server-side, so scene 4 still depicts scene 4 — only the
  // look changes. There is deliberately no bulk prompt override.
  const bulkStyleOpen    = ref(false);
  const bulkStylePreview = ref(null);
  const bulkStyleLoading = ref(false);
  const bulkStyleSubmitting = ref(false);
  const bulkStyleError   = ref("");

  const bulkStyleKey = computed(() =>
    visualStyleDraft.value
    ?? activeScene.value?.visual_style
    ?? activeScene.value?.image_generation_settings?.style
    ?? "cinematic");

  async function openBulkStyle() {
    bulkStyleOpen.value = true;
    bulkStyleError.value = "";
    bulkStyleLoading.value = true;
    bulkStylePreview.value = null;
    try {
      const response = await api.post(`/projects/${projectId.value}/restyle-all`, {
        style: bulkStyleKey.value,
        custom_visual_style: customVisualStyleDraft.value || undefined,
        model_key: aiImageModelKey.value,
      });
      bulkStylePreview.value = response.data?.data ?? null;
    } catch (err) {
      bulkStyleError.value = err.response?.data?.error?.message ?? "Could not price this.";
    } finally {
      bulkStyleLoading.value = false;
    }
  }

  async function submitBulkStyle() {
    const preview = bulkStylePreview.value;
    if (!preview || !preview.eligible_count || !preview.affordable) return;
    bulkStyleSubmitting.value = true;
    bulkStyleError.value = "";
    try {
      const response = await api.post(`/projects/${projectId.value}/restyle-all`, {
        style: bulkStyleKey.value,
        custom_visual_style: customVisualStyleDraft.value || undefined,
        model_key: aiImageModelKey.value,
        confirm: true,
      });
      const started = response.data?.data?.started_count ?? 0;
      bulkStyleOpen.value = false;
      bulkStylePreview.value = null;
      pushToast({
        id: `bulk-style-${Date.now()}`,
        title: `Restyling ${started} scene${started === 1 ? '' : 's'}`,
        message: 'Each scene keeps its own subject — only the look changes.',
      });
      for (const row of preview.scenes) pollSceneUntilVisual(row.scene_id);
    } catch (err) {
      bulkStyleError.value =
        err.response?.data?.error?.message ?? "Could not start restyling.";
    } finally {
      bulkStyleSubmitting.value = false;
    }
  }

  // Edit the image the scene already has, rather than rolling a new one. The
  // reroll path discards the picture; this corrects it and costs less (10cr vs
  // 16cr), so the fix for a small flaw stops being a fresh gamble.
  const editInstruction = ref("");
  const editPending = ref(false);
  const editError = ref("");
  const EDIT_SUGGESTIONS = ['Wider shot', 'Remove text', 'Brighter', 'Different background'];

  async function editSceneImage() {
    const instruction = editInstruction.value.trim();
    if (!activeScene.value || editPending.value || instruction.length < 3) return;

    editPending.value = true;
    editError.value = "";

    try {
      await api.post(`/scenes/${activeScene.value.id}/edit-image`, { instruction });
      editInstruction.value = "";
      pollSceneUntilVisual(activeScene.value.id);
    } catch (err) {
      // 409 means something is already running on this scene — not an error to show.
      if (err.response?.status === 409) return;
      editError.value = err.response?.data?.error?.message ?? "That edit could not be applied.";
    } finally {
      editPending.value = false;
    }
  }

  async function generateAIImage() {
    if (!activeScene.value || aiImagePending.value || activeSceneAIImagePending.value) return;

    aiImagePending.value = true;
    aiImageError.value = "";

    try {
      await api.post(`/scenes/${activeScene.value.id}/generate-image`, {
        style: visualStyleDraft.value ?? activeScene.value?.visual_style ?? activeScene.value?.image_generation_settings?.style ?? "cinematic",
        prompt_override: aiImagePromptOverride.value || undefined,
        model_key: aiImageModelKey.value,
      });
      // Reverb fires the result — polling is a safety net if the socket drops
      pollSceneUntilVisual(activeScene.value.id)
    } catch (err) {
      // 409 means the backend already has a generation in progress — treat as pending, not an error
      if (err.response?.status === 409) {
        aiImageError.value = "";
        // Stay pending; Reverb will fire when it completes
        return;
      }
      aiImageError.value =
        err.response?.data?.error?.message ||
        err.message ||
        "Image generation failed.";
      aiImagePending.value = false;
    }
    // aiImagePending stays true until Reverb fires completed/failed
  }

  // ── i2v Animation (rung 4) ──────────────────────────────────────────────────
  function openAnimateModal() {
    if (!canAnimateActiveScene.value || activeSceneAnimationPending.value) return;
    // Pre-fill with the scene's last animation settings if any — saves the
    // re-animate flow a tier/duration click.
    const lastSettings = activeScene.value?.image_generation_settings ?? {};
    animateTier.value = ['quick','balanced','premium','seedance_lite','seedance_pro','veo_fast','seedance_25','spokesperson'].includes(lastSettings.animation_tier)
      ? lastSettings.animation_tier
      : 'quick';
    animateDuration.value = lastSettings.animation_duration === 10 ? 10 : 5;
    // Pre-fill priority: prior animation prompt → parser-suggested motion
    // (stashed by one-shot prompt flow on scene.image_generation_settings.
    // suggested_motion_prompt) → empty.
    animateMotionPrompt.value =
      lastSettings.animation_motion_prompt
      || lastSettings.suggested_motion_prompt
      || "";
    animateError.value = "";
    // Always reopen on the single-scene path — bulk is an explicit opt-in each time.
    bulkAnimateMode.value = false;
    bulkAnimatePreview.value = null;
    bulkAnimateSelection.value = null;
    bulkAnimateSourceId.value = null;   // re-seeded when bulk is switched on
    animateModalOpen.value = true;
    animateModelOpen.value = false;
    // Never carry a tick across openings — each scene attests for itself.
    spokespersonConsent.value = false;
  }

  function closeAnimateModal() {
    if (animateSubmitting.value) return;
    animateModalOpen.value = false;
    animateModelOpen.value = false;
  }

  const canRevertAnimation = computed(() => {
    const settings = activeScene.value?.image_generation_settings ?? {};
    const originalId = settings.animation_original_image_asset_id;
    if (!originalId) return false;
    // Available whenever the current visual is NOT already the original still —
    // i.e. any animation version is selected. Don't gate on the video-type
    // check (it was hiding the button after switching versions); compare ids.
    return Number(activeScene.value?.visual_asset_id) !== Number(originalId);
  });

  const activeSceneAnimationHistory = computed(() => {
    const items = activeScene.value?.image_generation_settings?.animation_history;
    return Array.isArray(items) ? items : [];
  });

  // The preserved original still — shown as the first card in the version strip.
  const activeSceneAnimationOriginal = computed(() => {
    return activeScene.value?.image_generation_settings?.animation_original_image ?? null;
  });

  // Switch the scene's visual back to the original still (a card click). Same
  // endpoint as the ↺ button, but no canRevert guard — clicking the still card
  // explicitly means "show the still".
  async function useOriginalStill() {
    const scene = activeScene.value;
    const original = activeSceneAnimationOriginal.value;
    if (!scene || !original || scene.visual_asset_id === original.asset_id) return;
    try {
      const response = await api.post(`/scenes/${scene.id}/animate/revert`);
      const updated = response.data?.data?.scene;
      if (updated) replaceSceneInCollection(normalizeScenePayload(updated));
    } catch (e) {
      pushToast({
        id: `still-fail-${scene.id}-${Date.now()}`,
        title: 'Could not switch',
        message: e?.response?.data?.error?.message ?? 'Try again.',
      });
    }
  }

  async function useHistoryAnimation(assetId) {
    const scene = activeScene.value;
    if (!scene) return;
    try {
      const response = await api.post(`/scenes/${scene.id}/animate/use-history`, { asset_id: assetId });
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
    } catch (err) {
      pushToast({
        id: `history-fail-${scene.id}-${Date.now()}`,
        title: 'Could not swap',
        message: err.response?.data?.error?.message ?? 'That animation is no longer available.',
      });
    }
  }

  async function cancelAnimation() {
    const scene = activeScene.value;
    if (!scene || !activeSceneAnimationPending.value) return;
    try {
      const response = await api.post(`/scenes/${scene.id}/animate/cancel`);
      const refunded = response.data?.data?.refunded_credits ?? 0;
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
      pushToast({
        id: `anim-cancel-${scene.id}-${Date.now()}`,
        title: 'Animation cancelled',
        message: refunded > 0 ? `${refunded} credits refunded.` : 'Refund not applicable.',
      });
    } catch (err) {
      pushToast({
        id: `anim-cancel-fail-${scene.id}-${Date.now()}`,
        title: 'Could not cancel',
        message: err.response?.data?.error?.message ?? 'Cancel request failed.',
      });
    }
  }

  async function revertAnimation() {
    const scene = activeScene.value;
    if (!scene || !canRevertAnimation.value) return;
    try {
      const response = await api.post(`/scenes/${scene.id}/animate/revert`);
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
      pushToast({ id: `revert-${scene.id}-${Date.now()}`, title: 'Reverted', message: 'Original image restored.' });
    } catch (err) {
      pushToast({
        id: `revert-fail-${scene.id}-${Date.now()}`,
        title: 'Could not revert',
        message: err.response?.data?.error?.message ?? 'The original image is no longer available.',
      });
    }
  }

  // --- Animate every scene at once ---------------------------------------
  // The preview is always fetched from the server rather than multiplied in the
  // browser. Spokesperson bills per second of each scene's voiceover, so the
  // honest total can be nearly double a count x tier-price guess — and this is
  // the one screen where being wrong about the number means someone confirms a
  // charge they didn't agree to.
  const bulkAnimateMode    = ref(false);
  const bulkAnimatePreview = ref(null);
  const bulkAnimateLoading = ref(false);
  // null = "all eligible". Once the user unticks anything it becomes an explicit
  // id list, which is also what gets sent so the server prices exactly the set
  // shown on screen.
  const bulkAnimateSelection = ref(null);
  // A picked still applied to every chosen scene. Set it and no scene is turned
  // away for lacking an image of its own — that picked image becomes the source.
  const bulkAnimateSourceId = ref(null);

  // Every scene the batch could act on, eligible or not, in scene order — the
  // eligible ones plus the ones held back for a reason. Rendering the excluded
  // scenes alongside the included ones is what makes a count of 6 out of 13
  // legible instead of looking like a miscount.
  const bulkAnimateRows = computed(() => {
    const p = bulkAnimatePreview.value;
    if (!p) return [];
    const rows = [
      ...p.scenes.map((r) => ({ ...r, blocked: false })),
      ...p.skipped.map((r) => ({ ...r, blocked: !r.selectable })),
    ];
    const sel = bulkAnimateSelection.value;
    return rows
      .map((r) => ({
        ...r,
        // Derived LOCALLY so a tick moves the instant it's clicked. Reading it
        // back from the server meant every toggle waited on a round trip, and
        // the row list swapped out underneath — which is what flickered.
        included: r.blocked ? false : (sel === null ? true : sel.includes(r.scene_id)),
      }))
      .sort((a, b) => a.order - b.order);
  });

  // Only the very first fetch blanks the panel. Re-pricing keeps the list on
  // screen and just marks the total as stale.
  const bulkAnimateFirstLoad = computed(
    () => bulkAnimateLoading.value && !bulkAnimatePreview.value);

  const bulkAnimateSelectableCount = computed(
    () => bulkAnimateRows.value.filter((r) => !r.blocked).length);

  // Scenes that will lose their own image to the batch's image.
  const bulkAnimateReplacedCount = computed(
    () => bulkAnimatePreview.value?.replaced_count ?? 0);

  // The active scene's own still, offered as the obvious source to reuse.
  const bulkAnimateOwnStill = computed(() => {
    const a = activeSceneVisualAsset.value;
    if (a && a.asset_type === 'image') return a;
    const origId = activeScene.value?.image_generation_settings?.animation_original_image_asset_id;
    return origId ? { id: origId, title: 'this scene\'s original image' } : null;
  });

  async function loadBulkAnimatePreview() {
    if (!projectId.value) return;
    bulkAnimateLoading.value = true;
    animateError.value = "";
    try {
      const body = {
        tier: animateTier.value,
        duration_seconds: animateDuration.value,
        motion_prompt: animateMotionPrompt.value.trim() || null,
        quality: animateTier.value === 'spokesperson' ? null : animateQuality.value,
        // Only the spokesperson tier has an engine choice; the i2v tiers are a
        // different pipeline entirely.
        lipsync_engine: animateTier.value === 'spokesperson' ? (lipsyncEngine.value || undefined) : undefined,
      };
      if (bulkAnimateSelection.value !== null) body.scene_ids = bulkAnimateSelection.value;
      if (bulkAnimateSourceId.value) body.source_asset_id = bulkAnimateSourceId.value;
      const response = await api.post(`/projects/${projectId.value}/animate-all`, body);
      bulkAnimatePreview.value = response.data?.data ?? null;
    } catch (err) {
      bulkAnimatePreview.value = null;
      animateError.value = err.response?.data?.error?.message ?? "Could not price this.";
    } finally {
      bulkAnimateLoading.value = false;
    }
  }

  // Re-prices on every toggle. The total on screen is always the server's number
  // for the exact set that is ticked — never a local subtraction.
  function toggleBulkScene(row) {
    if (row.blocked) return;
    const current = bulkAnimateSelection.value
      ?? bulkAnimateRows.value.filter((r) => !r.blocked).map((r) => r.scene_id);
    bulkAnimateSelection.value = current.includes(row.scene_id)
      ? current.filter((id) => id !== row.scene_id)
      : [...current, row.scene_id];
    loadBulkAnimatePreview();
  }

  function bulkAnimateSelectAll() {
    bulkAnimateSelection.value = null;
    loadBulkAnimatePreview();
  }

  function toggleBulkAnimate() {
    bulkAnimateMode.value = !bulkAnimateMode.value;
    bulkAnimateSelection.value = null;
    // Default the fallback to the image you're configuring the action on. Bulk
    // should behave like running this same action on each scene — a scene with
    // its own still uses that; one with none (stock clip, upload) borrows this
    // rather than being turned away and needing a separate decision.
    bulkAnimateSourceId.value = bulkAnimateOwnStill.value?.id ?? null;
    if (bulkAnimateMode.value) loadBulkAnimatePreview();
    else { bulkAnimatePreview.value = null; animateError.value = ""; }
  }

  // Any option that moves the price re-prices. A stale total here is the same
  // failure as a wrong one.
  watch([animateTier, animateDuration, animateQuality], () => {
    if (bulkAnimateMode.value) loadBulkAnimatePreview();
  });

  async function submitBulkAnimate() {
    const preview = bulkAnimatePreview.value;
    if (!preview || !preview.eligible_count || !preview.affordable) return;
    animateSubmitting.value = true;
    animateError.value = "";
    try {
      const body = {
        tier: animateTier.value,
        duration_seconds: animateDuration.value,
        motion_prompt: animateMotionPrompt.value.trim() || null,
        quality: animateTier.value === 'spokesperson' ? null : animateQuality.value,
        // Only the spokesperson tier has an engine choice; the i2v tiers are a
        // different pipeline entirely.
        lipsync_engine: animateTier.value === 'spokesperson' ? (lipsyncEngine.value || undefined) : undefined,
        confirm: true,
        ...(animateTier.value === 'spokesperson' ? { consent: spokespersonConsent.value } : {}),
      };
      if (bulkAnimateSelection.value !== null) body.scene_ids = bulkAnimateSelection.value;
      if (bulkAnimateSourceId.value) body.source_asset_id = bulkAnimateSourceId.value;
      const response = await api.post(`/projects/${projectId.value}/animate-all`, body);
      const started = response.data?.data?.started_count ?? 0;
      animateModalOpen.value = false;
      bulkAnimateMode.value = false;
      bulkAnimatePreview.value = null;
      bulkAnimateSelection.value = null;
      bulkAnimateSourceId.value = null;
      pushToast({
        id: `bulk-anim-${Date.now()}`,
        title: `Animating ${started} scene${started === 1 ? '' : 's'}`,
        message: 'They render in the background — you can keep editing.',
      });
      // Each scene lands independently; poll them all so the grid fills in.
      for (const row of preview.scenes) pollSceneUntilVisual(row.scene_id);
    } catch (err) {
      animateError.value =
        err.response?.data?.error?.message ?? "Could not start animating.";
    } finally {
      animateSubmitting.value = false;
    }
  }

  // `sourceAssetId`: start from another image in the workspace (the new editor's AI video tab) instead of the scene's still.
  async function submitAnimate({ sourceAssetId = null } = {}) {
    const scene = activeScene.value;
    if (!scene) return;
    animateSubmitting.value = true;
    animateError.value = "";
    try {
      const response = await api.post(`/scenes/${scene.id}/animate`, {
        ...(sourceAssetId ? { source_asset_id: sourceAssetId } : {}),
        tier: animateTier.value,
        duration_seconds: animateDuration.value,
        motion_prompt: animateMotionPrompt.value.trim() || null,
        quality: animateTier.value === 'spokesperson' ? null : animateQuality.value,
        // Only the spokesperson tier has an engine choice; the i2v tiers are a
        // different pipeline entirely.
        lipsync_engine: animateTier.value === 'spokesperson' ? (lipsyncEngine.value || undefined) : undefined,
        ...(animateTier.value === 'spokesperson' ? { consent: spokespersonConsent.value || activeSceneConsented.value } : {}),
      });
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
      animateModalOpen.value = false;
      pollSceneUntilVisual(scene.id);
    } catch (err) {
      animateError.value =
        err.response?.data?.error?.message ?? "Animation could not start.";
    } finally {
      animateSubmitting.value = false;
    }
  }

  // Re-render a spokesperson clip's lip-sync after the voice changed — straight
  // from the Visual tab, no need to open the animate modal.
  const lipSyncRegenerating = ref(false);
  async function regenerateLipSync() {
    const scene = activeScene.value;
    if (!scene) return;
    lipSyncRegenerating.value = true;
    animateError.value = "";
    try {
      const response = await api.post(`/scenes/${scene.id}/animate`, {
        tier: "spokesperson",
        lipsync_engine: lipsyncEngine.value || undefined,
      });
      const updated = response.data?.data?.scene;
      if (updated) scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      pollSceneUntilVisual(scene.id);
    } catch (err) {
      animateError.value = err.response?.data?.error?.message ?? "Could not regenerate lip-sync.";
    } finally {
      lipSyncRegenerating.value = false;
    }
  }

  // Max attempts × ~5s/attempt sets the polling ceiling. Replicate i2v on
  // premium tier (Kling 2.1) routinely takes 3–5 min; image gen with character
  // (gpt-image-2) takes 30–90s. 80 attempts (~6–7 min) covers the slowest path
  // without leaving the spinner up forever if Reverb dropped the completion
  // event mid-run.
  const POLL_MAX_ATTEMPTS = 80;

  async function pollSceneUntilVisual(sceneId, attempt = 0) {
    if (attempt >= POLL_MAX_ATTEMPTS) {
      // Last-ditch: one final refresh so the cached scene state reflects
      // reality even if we just gave up. Without this, animation_in_progress
      // stays true locally and the UI shows "Animating…" indefinitely while
      // the backend has actually completed.
      try {
        const response = await api.get(`/scenes/${sceneId}/preview`);
        const refreshed = normalizeScenePayload(response.data?.data?.scene ?? null);
        if (refreshed) replaceSceneInCollection(refreshed);
      } catch {
        // Silent — if even this fails, the user can hard-refresh.
      }
      aiImagePending.value = false;
      return;
    }

    window.setTimeout(async () => {
      try {
        const response = await api.get(`/scenes/${sceneId}/preview`);
        const refreshed = normalizeScenePayload(response.data?.data?.scene ?? null);

        if (refreshed) {
          replaceSceneInCollection(refreshed);
          const settings = refreshed.image_generation_settings ?? {};

          // Image generation OR animation job still running — keep polling.
          if (settings.in_progress || settings.animation_in_progress) {
            pollSceneUntilVisual(sceneId, attempt + 1);
            return;
          }

          // Animation failure surfaces via animation_last_error — bail with a toast.
          if (settings.animation_last_error) {
            aiImagePending.value = false;
            pushToast({ id: `anim-fail-${refreshed.id}-${Date.now()}`, title: 'Animation failed', message: settings.animation_last_error.slice(0, 240) });
            return;
          }

          // Animation success: new visual_asset will be a video.
          if (settings.animation_video_asset_id && refreshed.visual_asset?.asset_type === 'video') {
            aiImagePending.value = false;
            pushToast({ id: `anim-done-${refreshed.id}-${Date.now()}`, title: 'Animation ready', message: `Scene ${refreshed.scene_order ?? ''} is now a video clip.` });
            return;
          }

          // Image gen failed
          if (settings.needs_visual) {
            aiImagePending.value = false;
            aiImageError.value =
              settings.last_error ||
              "Image generation failed. Please revise the prompt and try again.";
            return;
          }

          // Image gen succeeded — visual_asset is now the newly generated image
          if (refreshed.visual_asset) {
            aiImagePending.value = false;
            aiImageError.value = "";
            pushToast({ id: `ai-image-done-${refreshed.id}-${Date.now()}`, title: 'Image ready', message: `Scene ${refreshed.scene_order ?? ''} AI image generated.` });
            return;
          }
        }
      } catch {
        // Realtime updates may still arrive; keep polling briefly as a fallback.
      }

      pollSceneUntilVisual(sceneId, attempt + 1);
    }, attempt < 3 ? 2500 : 5000);
  }

  async function queueExport() {
    if (!project.value || exportPending.value || exportInProgress.value) return null;
    if (exportBlockerMessage.value) {
      pushToast({ id: `export-blocked-${Date.now()}`, title: 'Video needs attention', message: exportBlockerMessage.value });
      return null;
    }

    const flushed = await flushActiveSceneDrafts();
    if (flushed === false) return;

    exportPending.value = true;
    exportState.value = "saving";

    try {
      exportMenuOpen.value = false;
      const ratios = exportAspectRatios.value.length
        ? [...new Set(exportAspectRatios.value)]
        : [project.value.aspect_ratio || "9:16"];

      const response = await api.post(`/projects/${project.value.id}/export`, {
        aspect_ratios: ratios,
        language: project.value.primary_language || "en",
        watermark_enabled: false,
      });

      const data = response.data?.data ?? {};
      const jobs = data.export_jobs ?? (data.export_job ? [data.export_job] : []);
      const first = jobs[0] ?? null;
      queuedExportJobId.value = first?.id ?? null;
      if (jobs.length) {
        const ids = new Set(jobs.map((j) => j.id));
        exportJobs.value = [...jobs, ...exportJobs.value.filter((job) => !ids.has(job.id))];
      }
      const skipped = data.skipped_aspect_ratios ?? [];
      exportState.value = "saved";
      pushToast({
        id: `export-${first?.id || Date.now()}`,
        title: jobs.length > 1 ? `${jobs.length} exports queued` : "Export queued",
        message: skipped.length
          ? `Queued ${jobs.length} · skipped ${skipped.join(", ")} (export limit reached)`
          : jobs.length > 1
            ? ratios.join(", ")
            : first?.file_name || "Your export job has been queued.",
        created_at: new Date().toISOString(),
      });
      window.setTimeout(() => {
        if (exportState.value === "saved") {
          exportState.value = "idle";
        }
      }, 2500);
      return first;
    } catch (requestError) {
      exportState.value = "error";
      const message =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Export failed.";
      pushToast({
        id: `export-error-${Date.now()}`,
        title: "Export failed",
        message,
        created_at: new Date().toISOString(),
      });
    } finally {
      exportPending.value = false;
    }
  }

  let projectChannelName = null;

  function subscribeProjectChannel() {
    const echo = getEcho();
    if (!echo || !projectId.value) return;

    projectChannelName = `project.${projectId.value}`;

    echo.private(projectChannelName).listen(".generation.progress", (payload) => {
      // Drive the Cruise chat's running action card from the same event
      // stream so the user sees the checklist tick off stage by stage.
      // We no longer suppress the editor refresh — replaceSceneInCollection
      // mutates in place now so per-stage updates are cheap and the Config
      // tab stays consistent with the Assistant's checklist.
      try { cruiseHandleProgressEvent(payload); } catch (_) {}

      if (payload.stage === "tts" && ["completed", "failed"].includes(String(payload.status || ""))) {
        if (payload.scene_id) {
          api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
            const refreshed = res.data?.data?.scene ?? null;
            if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
          }).catch(() => {});
        }
        refreshProjectPayload().catch(() => {});
        return;
      }

      // Animation events run on the same project channel — refresh the scene so the
      // editor swaps in the new video asset (or shows the persisted error).
      if (payload.stage === "animation") {
        if (payload.scene_id && ["processing", "completed", "failed"].includes(String(payload.status || ""))) {
          api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
            const refreshed = res.data?.data?.scene ?? null;
            if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
          }).catch(() => {});
          if (payload.status === "completed") {
            pushToast({
              id: `anim-done-${payload.scene_id}-${Date.now()}`,
              title: 'Animation ready',
              message: `Scene clip is ready to play.`,
            });
          }
        }
        return;
      }

      if (payload.stage !== "ai_image") return;

      if (payload.status === "processing" && payload.scene_id) {
        api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
          const refreshed = res.data?.data?.scene ?? null;
          if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
        }).catch(() => {});
      } else if (payload.status === "completed" && payload.scene_id) {
        // Refresh the scene so the new visual_asset appears in the editor
        api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
          const refreshed = res.data?.data?.scene ?? null;
          if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
        }).catch(() => {});
        aiImagePending.value = false;
        aiImageError.value = "";
      } else if (payload.status === "failed") {
        aiImagePending.value = false;
        aiImageError.value =
          payload.message || "Image generation failed. Please revise the prompt and try again.";
        if (payload.scene_id) {
          api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
            const refreshed = res.data?.data?.scene ?? null;
            if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
          }).catch(() => {});
        }
      }
    });

    echo.private(projectChannelName).listen(".export.progress", (payload) => {
      const jobId = Number(payload.export_job_id);
      const idx = exportJobs.value.findIndex((j) => j.id === jobId);
      const nextJob = {
        ...(idx >= 0 ? exportJobs.value[idx] : {}),
        id: jobId,
        status: payload.status,
        progress_percent: payload.progress_percent,
        failure_reason: payload.failure_reason ?? payload.message ?? null,
        file_name: payload.file_name ?? (idx >= 0 ? exportJobs.value[idx]?.file_name : null),
      };

      if (idx >= 0) {
        exportJobs.value = exportJobs.value.map((job) =>
          job.id === jobId
            ? nextJob
            : job
        );
      } else {
        exportJobs.value = [nextJob, ...exportJobs.value];
      }

      if (["completed", "failed"].includes(String(payload.status || ""))) {
        loadExportJobs();
      }
    });
  }

  function unsubscribeProjectChannel() {
    const echo = getEcho();
    if (echo && projectChannelName) {
      echo.leave(projectChannelName);
      projectChannelName = null;
    }
  }

  async function createScene(insertAfterSceneId = null) {
    if (!project.value) return;

    const scriptText = newSceneScript.value.trim();
    const sceneType = String(selectedSceneType.value || "Narration")
      .toLowerCase()
      .replace(/\s+/g, "_");
    const addSceneModeTypeMap = {
      stock_video: addSceneStockSubType.value || "stock_clip",
      stock_image: "image_montage",
      ai_image: "ai_image",
      audiogram: "waveform",
      assets: addScenePickedAsset.value
        ? (addScenePickedAsset.value.asset_type === "video" ? "background_loop" : "image_montage")
        : "stock_clip",
    };
    // When the user clicks Add Scene without picking a source tab (the new
    // default after we stopped auto-defaulting to Stock Video), send the
    // sentinel 'placeholder' so the backend stores a scene with no visual
    // intent. The editor surfaces "No visual assigned — pick one" on that
    // scene and the user fills it in afterward instead of silently getting
    // a random stock clip they didn't ask for.
    const visualType = addSceneVisualMode.value
      ? (addSceneModeTypeMap[addSceneVisualMode.value] ?? "stock_clip")
      : "placeholder";
    // Only send the query (which the backend uses to seed a stock search) when
    // the user typed it OR explicitly chose a source. An untouched popover
    // sends no query → no auto-fetch.
    const visualQuery = addSceneVisualMode.value
      ? (addSceneVisualQuery.value.trim() || scriptText || buildSceneLabel(sceneType))
      : null;

    try {
      const response = await api.post("/scenes", {
        project_id: project.value.id,
        insert_after_scene_id: insertAfterSceneId,
        scene_type: sceneType,
        label: buildSceneLabel(sceneType),
        script_text: scriptText,
        duration_seconds: scriptText
          ? Math.max(
              3,
              Math.min(12, Math.ceil(scriptText.split(/\s+/).filter(Boolean).length / 3))
            )
          : 3,
        visual_type: visualType,
        visual_prompt: visualQuery || null,
        visual_style: addSceneVisualStyle.value,
        // Pass the typed custom descriptor only when 'custom' was actually
        // picked; otherwise leave undefined so the backend falls through to
        // its inherit-from-project default.
        ...(addSceneVisualStyle.value === 'custom' && addSceneCustomVisualStyle.value.trim()
          ? { custom_visual_style: addSceneCustomVisualStyle.value.trim() }
          : {}),
        // Pre-attach the asset picked via MediaPickerModal when Assets tab is used.
        ...(addSceneVisualMode.value === 'assets' && addScenePickedAsset.value
          ? { visual_asset_id: addScenePickedAsset.value.id }
          : {}),
        // Per-scene character override; null = backend inherits project default.
        ...(addSceneCharacterId.value
          ? { character_id: Number(addSceneCharacterId.value) }
          : {}),
      });

      const createdScene = normalizeScenePayload(response.data?.data?.scene ?? null);
      if (!createdScene) {
        throw new Error("Scene create returned no payload.");
      }

      let nextScene = createdScene;

      if (visualType === "ai_image") {
        aiImagePending.value = true;
        aiImageError.value = "";
        await api.post(`/scenes/${createdScene.id}/generate-image`, {
          style: addSceneVisualStyle.value || "cinematic",
          prompt_override: visualQuery || undefined,
        });
        nextScene = {
          ...createdScene,
          visual_type: "ai_image",
          visual_prompt: visualQuery,
          visual_style: addSceneVisualStyle.value,
        };
      } else if (!["text_card", "waveform"].includes(visualType)) {
        const visualResponse = await api.post(`/scenes/${createdScene.id}/swap-visual`, {
          query: visualQuery,
          visual_type: visualType,
        });
        nextScene = normalizeScenePayload(visualResponse.data?.data?.scene ?? null) || createdScene;
      }

      scenes.value = sortScenesByOrder([
        ...scenes.value.filter((scene) => scene.id !== nextScene.id),
        nextScene,
      ]);
      preloadSceneVisual(nextScene);
      closeAddScene();
      activeSceneId.value = nextScene.id;
      if (visualType === "ai_image") {
        pollSceneUntilVisual(nextScene.id);
      }
    } catch (requestError) {
      pushToast({
        id: `scene-create-error-${Date.now()}`,
        title: "Could not add scene",
        message:
          requestError.response?.data?.error?.message ||
          requestError.response?.data?.message ||
          requestError.message ||
          "Scene create failed.",
        created_at: new Date().toISOString(),
      });
    }
  }

  async function duplicateScene(sceneId) {
    try {
      const response = await api.post(`/scenes/${sceneId}/duplicate`);
      const duplicatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (!duplicatedScene) {
        throw new Error("Scene duplicate returned no payload.");
      }

      scenes.value = sortScenesByOrder([...scenes.value, duplicatedScene]);
      activeSceneId.value = duplicatedScene.id;
    } catch (requestError) {
      pushToast({
        id: `scene-duplicate-error-${sceneId}`,
        title: "Could not duplicate scene",
        message:
          requestError.response?.data?.error?.message ||
          requestError.response?.data?.message ||
          "Scene duplicate failed.",
        created_at: new Date().toISOString(),
      });
    }
  }

  function promptDeleteScene(sceneId) {
    if (scenes.value.length <= 1) {
      pushToast({
        id: `scene-delete-blocked-${sceneId}`,
        title: "Cannot delete scene",
        message: "Projects must keep at least one scene.",
        created_at: new Date().toISOString(),
      });
      return;
    }

    deleteSceneTarget.value = scenes.value.find((scene) => scene.id === sceneId) ?? null;
  }

  function closeDeleteSceneModal() {
    if (deleteScenePending.value) return;
    deleteSceneTarget.value = null;
  }

  async function confirmDeleteScene() {
    const sceneId = deleteSceneTarget.value?.id;
    if (!sceneId || deleteScenePending.value) return;

    deleteScenePending.value = true;

    try {
      await api.delete(`/scenes/${sceneId}`);
      const remaining = scenes.value
        .filter((scene) => scene.id !== sceneId)
        .map((scene, index) => ({ ...scene, scene_order: index + 1 }));
      scenes.value = remaining;

      if (activeSceneId.value === sceneId) {
        activeSceneId.value = remaining[0]?.id ?? null;
      }
      deleteSceneTarget.value = null;
    } catch (requestError) {
      pushToast({
        id: `scene-delete-error-${sceneId}`,
        title: "Could not delete scene",
        message:
          requestError.response?.data?.error?.message ||
          requestError.response?.data?.message ||
          "Scene delete failed.",
        created_at: new Date().toISOString(),
      });
    } finally {
      deleteScenePending.value = false;
    }
  }

  async function moveScene(sceneId, direction) {
    if (sceneReorderPendingId.value !== null) return;

    const index = scenes.value.findIndex((scene) => scene.id === sceneId);
    if (index < 0) return;

    const targetIndex = direction === "up" ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= scenes.value.length) return;

    const previousScenes = [...scenes.value];
    const reordered = [...scenes.value];
    const [movedScene] = reordered.splice(index, 1);
    reordered.splice(targetIndex, 0, movedScene);
    scenes.value = reordered.map((scene, orderIndex) => ({
      ...scene,
      scene_order: orderIndex + 1,
    }));
    sceneReorderPendingId.value = sceneId;

    try {
      const response = await api.patch("/scenes/reorder", {
        project_id: project.value.id,
        scene_ids: reordered.map((scene) => scene.id),
      });

      scenes.value = sortScenesByOrder(
        (response.data?.data?.scenes ?? [])
          .map((scene) => normalizeScenePayload(scene))
          .filter(Boolean)
      );
    } catch (requestError) {
      scenes.value = previousScenes;
      pushToast({
        id: `scene-reorder-error-${sceneId}`,
        title: "Could not reorder scenes",
        message:
          requestError.response?.data?.error?.message ||
          requestError.response?.data?.message ||
          "Scene reorder failed.",
        created_at: new Date().toISOString(),
      });
    } finally {
      sceneReorderPendingId.value = null;
    }
  }

  async function flushActiveSceneDrafts() {
    const scene = activeScene.value;
    if (!scene || wholeVideoTake.value) return true;

    try {
      if (scriptSaveTimer || scriptSaveState.value === "pending" || sceneScriptDraft.value !== (scene.script_text || "")) {
        if (scriptSaveTimer) {
          window.clearTimeout(scriptSaveTimer);
          scriptSaveTimer = null;
        }

        if (sceneScriptDraft.value !== (scene.script_text || "")) {
          await persistSceneScript(scene.id, sceneScriptDraft.value);
        }
      }

      const savedVoice = scene.voice_settings || {};
      const nextVoice = {
        voice_id: voiceProfileKey.value,
        speed: Number(voiceSpeedDraft.value || 1),
        stability: voiceStabilityDraft.value,
        voice_prompt: activeVoiceIsGemini.value ? voiceDirectionDraft.value.trim() : "",
      };

      if (
        voiceSaveTimer ||
        String(savedVoice.voice_id || "Kore") !== nextVoice.voice_id ||
        Number(savedVoice.speed ?? 1) !== nextVoice.speed ||
        String(savedVoice.stability || "medium") !== nextVoice.stability ||
        String(savedVoice.voice_prompt || "") !== nextVoice.voice_prompt
      ) {
        if (voiceSaveTimer) {
          window.clearTimeout(voiceSaveTimer);
          voiceSaveTimer = null;
        }

        await persistVoiceSettings(scene.id, nextVoice);
      }

      const savedCaptions = activeCaptionSettings.value || {};
      const nextCaptions = {
        enabled: captionEnabledDraft.value,
        style_key: captionStyleDraft.value,
        highlight_mode: captionHighlightDraft.value,
        position: captionPositionDraft.value,
        font: captionFontDraft.value,
        highlight_color: captionHighlightColorDraft.value,
        color: captionColorDraft.value,
        size: captionSizeDraft.value,
        preset_id: savedCaptions.preset_id || null,
        animation: captionAnimationDraft.value,
        highlight_style: captionHighlightStyleDraft.value,
        panel_color: captionPanelColorDraft.value || null,
        backdrop: captionBackdropDraft.value,
      };

      if (
        captionSaveTimer ||
        (savedCaptions.enabled !== false) !== nextCaptions.enabled ||
        String(savedCaptions.style_key || "impact") !== nextCaptions.style_key ||
        String(savedCaptions.highlight_mode || "keywords") !== nextCaptions.highlight_mode ||
        String(savedCaptions.position || "bottom_third") !== nextCaptions.position ||
        String(savedCaptions.font || DEFAULT_CAPTION_FONT) !== nextCaptions.font ||
        String(savedCaptions.animation || "plain") !== nextCaptions.animation
      ) {
        if (captionSaveTimer) {
          window.clearTimeout(captionSaveTimer);
          captionSaveTimer = null;
        }

        await persistCaptionSettings(scene.id, nextCaptions);
      }
    } catch {
      return false;
    }

    const deadline = Date.now() + 15000;
    while (hasPendingExportChanges() && Date.now() < deadline) {
      if ([scriptSaveState, voiceSaveState, captionSaveState, motionSaveState, audiogramSaveState].some(state => state.value === 'error') || musicSaveError.value || volumeSaveError.value) return false;
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (hasPendingExportChanges()) return false;
    return (
      scriptSaveState.value !== "error" &&
      voiceSaveState.value !== "error" &&
      captionSaveState.value !== "error"
    );
  }

  function toggleAudioPlayback() {
    if (!audioRef.value || !activeSceneAudioUrl.value) return;
    unlockWaveformAudio();
    isAudioLoading.value = true;
    preloadSceneAudio(activeScene.value);
    if (isAudioPlaying.value) {
      audioRef.value.pause();
      if (soundAudioRef.value) { soundAudioRef.value.pause(); }
      isAudioLoading.value = false;
    } else {
      audioRef.value.currentTime = 0;
      audioRef.value.play().catch(() => {
        isAudioPlaying.value = false;
        isAudioLoading.value = false;
      });
      if (soundAudioRef.value && activeSceneSoundUrl.value) {
        soundAudioRef.value.volume = Math.max(0, Math.min(1, sceneSoundVolume.value / 100));
        soundAudioRef.value.currentTime = 0;
        soundAudioRef.value.play().catch(() => {});
      }
    }
  }

  function syncSceneSoundVolume() {
    if (soundAudioRef.value) {
      soundAudioRef.value.volume = Math.max(0, Math.min(1, sceneSoundVolume.value / 100));
    }
  }

  onMounted(() => {
    window.addEventListener('resize', onViewportResize);
    window.visualViewport?.addEventListener('resize', onViewportResize);
    syncAppHeight();
    loadLipsyncEngines();
    loadCreditCosts();
    beforeUnloadHandler = (event) => {
      if (hasPendingExportChanges()) {
        event.preventDefault();
        event.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", beforeUnloadHandler);
    window.addEventListener("keydown", onCruiseKeydown);
    loadMe();
    loadProject();
    loadCharacters();
  });

  watch(showWaveformPreview, (active) => {
    if (active) startWaveformAnimation();
    else stopWaveformAnimation();
  }, { immediate: true });

  // Reconnect Web Audio when the narration audio element is replaced (scene change)
  watch(audioRef, (el) => {
    if (el && showWaveformPreview.value && waveformUserActivated) waveformTryConnectElement(el);
  });

  watch(musicAudioRef, (el) => {
    if (el && showWaveformPreview.value && waveformUserActivated) waveformTryConnectElement(el);
  });

  onBeforeUnmount(() => {
    window.removeEventListener("keydown", onCruiseKeydown);
    if (exportPollTimer) { clearInterval(exportPollTimer); exportPollTimer = null; }
    stopWaveformAnimation();
    if (waveformAudioCtx) {
      try { waveformAudioCtx.close(); } catch {}
      waveformAudioCtx = null;
      waveformAnalyser = null;
      waveformConnectedCount = 0;
      waveformUserActivated = false;
    }
    if (scriptSaveTimer) {
      window.clearTimeout(scriptSaveTimer);
    }
    if (voiceSaveTimer) {
      window.clearTimeout(voiceSaveTimer);
    }
    if (captionSaveTimer) {
      window.clearTimeout(captionSaveTimer);
    }
    for (const timer of [musicSaveTimer, sceneVoiceVolumeSaveTimer, sceneSoundVolumeSaveTimer,
      audiogramSaveTimer, motionSaveTimer, visualStyleSaveTimer, customVisualStyleSaveTimer]) {
      if (timer) window.clearTimeout(timer);
    }
    mediaPreloaders.forEach((media) => {
      media.onload = null;
      media.onerror = null;
      media.onloadeddata = null;
      media.oncanplaythrough = null;
    });
    mediaPreloaders.clear();
    unsubscribeWorkspaceNotifications();
    unsubscribeProjectChannel();
    if (beforeUnloadHandler) {
      window.removeEventListener("beforeunload", beforeUnloadHandler);
    }
  });

  return {
    route,
    workspaceStore,
    router,
    authStore,
    sidebarStore,
    projectId,
    loading,
    error,
    project,
    scenes,
    cruiseTab,
    cruiseAssistantPending,
    onCruiseKeydown,
    cruiseMessages,
    cruiseInputText,
    cruiseResolving,
    cruiseApplying,
    cruiseScopeSceneId,
    cruiseChatScrollRef,
    cruisePulseSection,
    cruiseToast,
    cruiseAutoApply,
    cruisePrefs,
    cruisePrefsOpen,
    cruiseBrief,
    cruiseBriefLocked,
    cruiseBriefOpen,
    cruiseBriefBusy,
    cruiseResetConfirmOpen,
    cruiseResetting,
    CRUISE_QUICK_PROMPTS,
    cruiseCreditsPayload,
    cruiseUserBalance,
    cruiseScopeLabel,
    cruiseScopeValue,
    cruiseEffectiveSceneId,
    cruiseActionIcon,
    cruiseActionTitle,
    cruiseActionsSummary,
    cruiseSubmitIntent,
    cruiseSkipAction,
    cruiseSkipAll,
    cruiseApplyAction,
    cruiseApplyAll,
    cruiseGetCard,
    cruiseProposedCount,
    cruiseTotalCost,
    cruiseCanApplyAll,
    cruiseRecomputeMessageStatus,
    CRUISE_PROGRESS_PHRASES,
    cruiseStartProgressLoop,
    cruiseStopProgressLoop,
    CRUISE_POLL_INTERVAL_MS,
    CRUISE_POLL_TIMEOUT_MS,
    cruiseStartPollingFallback,
    cruiseStopPolling,
    cruiseInitActionState,
    cruiseToolHasAsyncWork,
    cruiseToolTouchesProject,
    cruiseExpectedStages,
    cruiseStagePendingText,
    cruiseInitialProgressText,
    cruiseFindCardForProgress,
    cruiseHandleProgressEvent,
    cruiseStageLabel,
    cruiseSuccessText,
    cruiseShowToast,
    cruiseScrollChatToBottom,
    loadCruiseConversation,
    cruiseToggleBrief,
    cruiseTogglePrefs,
    cruiseSaveBrief,
    cruiseRefreshBrief,
    cruiseResetConversation,
    cruiseConfirmReset,
    cruiseCanUndo,
    cruiseUndoAction,
    cruiseFriendlyError,
    cruiseRetryAction,
    cruiseResumeInflightAfterHydrate,
    cruisePulseClass,
    hookOptions,
    mePayload,
    creditCosts,
    loadCreditCosts,
    aiMusicCost,
    isAdmin,
    activeSceneId,
    notificationDrawerOpen,
    notifications,
    notificationToasts,
    exportJobs,
    scheduleModalOpen,
    deliveryExport,
    approvalModalOpen,
    approvalForm,
    approvalSubmitting,
    approvalError,
    approvalCreated,
    submitApproval,
    closeApprovalModal,
    copyApprovalUrl,
    audioRef,
    musicAudioRef,
    soundAudioRef,
    musicAuditionRef,
    isAudioPlaying,
    isAudioLoading,
    musicReady,
    pendingScenePlay,
    auditionMusicTrackId,
    currentVisualUrl,
    previewVideoRef,
    previewVideoBackgroundRef,
    previewVideoDuration,
    visualLoadFailed,
    mediaCache,
    mediaPreloaders,
    MEDIA_ELEMENT_CROSS_ORIGIN,
    API_ORIGIN,
    addScenePanelPosition,
    selectedSceneType,
    selectedAddSceneVisualSource,
    addSceneVisualMode,
    addSceneStockSubType,
    addSceneVisualStyle,
    addSceneCustomVisualStyle,
    addSceneVisualQuery,
    addScenePickedAsset,
    addSceneCharacterId,
    addSceneCharPopoverOpen,
    selectedSwapVisualSource,
    newSceneScript,
    rewriteToolsVisible,
    rewritePreviewVisible,
    rewritePreviewCopy,
    rewriteCustomInstruction,
    rewriteMode,
    rewritePending,
    rewriteApplyPending,
    rewriteError,
    sceneDurationDraft,
    sceneDurationSaving,
    sceneDurationError,
    captionPresets,
    captionPresetSaveOpen,
    captionPresetSaveName,
    captionPresetSaving,
    captionPresetDeleting,
    voiceProfileSaveOpen,
    voiceProfileSaveName,
    voiceProfileSaving,
    voiceRegeneratePending,
    voiceRegenerateError,
    addSceneGeneratePending,
    addSceneGenerateError,
    sceneReorderPendingId,
    deleteScenePending,
    deleteSceneTarget,
    voiceProfiles,
    channels,
    brandKits,
    projectChannelId,
    projectBrandKitId,
    projectDefaultsSaveState,
    projectDefaultsSaveError,
    musicTracks,
    selectedMusicTrackId,
    myImageAssets,
    myImageSearch,
    myImageLoading,
    customAudioAssets,
    customAudioSearch,
    customAudioLoading,
    voiceTab,
    voiceUploadStatus,
    voicePreviewBlob,
    voicePreviewUrl,
    voicePreviewName,
    voicePreviewRef,
    voicePreviewPlaying,
    voicePreviewCurrent,
    voicePreviewDuration,
    voiceUploadAsset,
    voiceUploadError,
    voiceRecording,
    voiceRecorder,
    voiceRecordedChunks,
    voiceRecordSeconds,
    mediaPickerVisible,
    mediaPickerMode,
    addSceneAssetPickerActive,
    musicPanelTab,
    aiMusicMood,
    aiMusicPending,
    aiMusicError,
    AI_MUSIC_MOODS,
    regenerateAIMusic,
    pollProjectMusicUntilNew,
    musicVolume,
    musicDuckVolume,
    musicFadeInMs,
    musicLoop,
    musicDuckDuringVoice,
    musicSaveState,
    musicSaveError,
    sceneVoiceVolume,
    sceneSoundVolume,
    volumeSavesPending,
    volumeSaveError,
    audiogramStyle,
    audiogramColor,
    audiogramBg,
    audiogramSaveState,
    AUDIOGRAM_STYLES,
    AUDIOGRAM_COLORS,
    AUDIOGRAM_BACKGROUNDS,
    voiceProfileKey,
    voiceSpeedDraft,
    voiceStabilityDraft,
    voiceDirectionDraft,
    voiceSaveState,
    voiceSaveError,
    visualQueryDraft,
    visualSwapPending,
    visualSwapError,
    aiImagePromptOverride,
    aiImagePending,
    aiImageModelKey,
    availableImageModelsEditor,
    activeImageModelMeta,
    activeSceneHasCharacter,
    aiImageError,
    animateModalOpen,
    animateModelOpen,
    animateTier,
    animateDuration,
    animateMotionPrompt,
    animateSubmitting,
    animateError,
    ANIMATE_TIER_COSTS_5S,
    ANIMATE_QUALITY_OPTIONS,
    ANIMATE_QUALITY_DEFAULT,
    animateQuality,
    lipsyncEngines,
    lipsyncEngine,
    lipsyncMinSeconds,
    loadLipsyncEngines,
    spokespersonCost,
    ANIMATE_TIER_DURATIONS,
    activeSceneHasVoice,
    spokespersonOutdated,
    spokespersonConsent,
    activeSceneConsented,
    needsSpokespersonConsent,
    animateDurations,
    animateShortDuration,
    animateQualityOptions,
    animateCost,
    ANIMATE_TIER_META,
    ANIMATE_TIER_ORDER,
    ANIMATE_PROMPT_SUGGESTIONS,
    characters,
    characterPopoverOpen,
    createCharacterOpen,
    createCharacterName,
    createCharacterDescription,
    createCharacterFile,
    createCharacterPreviewUrl,
    createCharacterSaving,
    createCharacterError,
    AI_IMAGE_STYLES,
    richImageStyles,
    imageStylesByKey,
    stylePickerRows,
    loadImageStyleCatalog,
    stylePickerOpen,
    topAddStylePickerOpen,
    perSceneAddStyleOpenId,
    exportPending,
    exportMenuOpen,
    exportAspectRatios,
    ASPECT_RATIO_OPTIONS,
    openExportMenu,
    toggleExportRatio,
    shareTogglePending,
    shareMenuOpen,
    shareWrap,
    shareAction,
    closeShareMenu,
    shareCopiedToast,
    toggleShareLink,
    exportState,
    timelineOpen,
    previewMode,
    stockVideoSubType,
    playProgress,
    isPreviewPlaying,
    musicMoodFilter,
    queuedExportJobId,
    scriptSaveState,
    scriptSaveError,
    captionEnabledDraft,
    captionStyleDraft,
    captionHighlightDraft,
    captionPositionDraft,
    DEFAULT_CAPTION_FONT,
    DEFAULT_CAPTION_SETTINGS,
    captionFontDraft,
    captionColorDraft,
    captionSizeDraft,
    captionHighlightColorDraft,
    captionAnimationDraft,
    captionHighlightStyleDraft,
    captionPanelColorDraft,
    captionBackdropDraft,
    captionEffectModalOpen,
    CAPTION_COLOR_SWATCHES,
    CAPTION_PANEL_SWATCHES,
    captionPanelRow,
    activeCaptionAnimation,
    captionBackdropChecked,
    selectCaptionAnimation,
    captionPreviewSettings,
    captionPreviewTimedWords,
    captionClock,
    captionClockTick,
    leaveSaveDialog,
    stayInEditorButton,
    chooseLeave,
    CAPTION_SIZE_MAP,
    captionBasePx,
    fontDropdownOpen,
    captionSaveState,
    captionSaveError,
    CAPTION_FONT_GROUPS,
    motionEffectDraft,
    motionIntensityDraft,
    MOTION_EFFECT_OPTIONS,
    MOTION_INTENSITY_OPTIONS,
    VOICE_SPEED_OPTIONS,
    VOICE_STABILITY_OPTIONS,
    voiceProfileOptions,
    OPENAI_VOICE_KEYS,
    activeVoiceIsGemini,
    VOICE_PERSONAS,
    showVoiceModal,
    previewLoadingKey,
    previewPlayingKey,
    clonedVoices,
    geminiVoices,
    otherVoices,
    openVoiceModal,
    closeVoiceModal,
    stopPreview,
    previewVoice,
    selectVoice,
    applyPersona,
    goToVoices,
    showCloneModal,
    openCloneInEditor,
    onVoiceCloned,
    ADD_SCENE_STOCK_OPTIONS,
    STOCK_VIDEO_SUBTYPE_OPTIONS,
    CAPTION_HIGHLIGHT_OPTIONS,
    CAPTION_HIGHLIGHT_STYLE_OPTIONS,
    CAPTION_POSITION_OPTIONS,
    CRUISE_IMAGE_MODELS,
    CRUISE_ANIMATION_TIERS,
    crSuffix,
    CRUISE_IMAGE_MODEL_OPTIONS,
    tierSlow,
    CRUISE_ANIMATION_TIER_OPTIONS,
    CRUISE_VISUAL_SOURCE_OPTIONS,
    APPROVAL_EXPIRES_OPTIONS,
    channelOptions,
    brandKitOptions,
    cruiseScopeOptions,
    captionPresetOptions,
    onApplyCaptionPreset,
    motionSaveState,
    motionSaveError,
    visualStyleDraft,
    visualStyleSaveState,
    customVisualStyleDraft,
    customVisualStyleSaveState,
    panelState,
    sceneScriptDraft,
    activeScene,
    activeSceneVisualUrl,
    activeSceneVisualAsset,
    activeSceneVisualType,
    sceneHasResolvedAIImage,
    activeSceneAIImagePending,
    activeSceneAnimationPending,
    activeSceneStillRendering,
    activeSceneAnimationError,
    canAnimateActiveScene,
    activeSceneAlreadyAnimated,
    activeSceneVisualGenerationError,
    activeSceneVisualIsVideo,
    previewVideoShortfall,
    activeSceneVisualIsFromLibrary,
    activeSceneAudioUrl,
    activeSceneSoundAsset,
    activeSceneSoundUrl,
    selectedProjectChannel,
    activeMusicTrack,
    activeMusicTrackUrl,
    auditionMusicTrack,
    previewMusicVolume,
    filteredMusicTracks,
    myImageAssetsFiltered,
    customAudioAssetsFiltered,
    musicTrackGroups,
    activeSceneVisualLoaded,
    isVisualLoading,
    activeSceneVisualReady,
    activeSceneVoiceReady,
    activeSceneMusicReady,
    activeSceneAssetsReady,
    isSceneLoading,
    showTextCardPreview,
    showWaveformPreview,
    waveformLive,
    waveformConnectedEls,
    waveformEnsureContext,
    mediaCrossOriginMode,
    unlockWaveformAudio,
    waveformTryConnectElement,
    waveformTryConnect,
    waveformHasActiveSource,
    tickWaveform,
    startWaveformAnimation,
    stopWaveformAnimation,
    audiogramBgStyle,
    activeVoiceName,
    activeVoiceSpeed,
    activeVoiceOutdated,
    sortedHookOptions,
    hooksGenerating,
    hasScriptForHooks,
    regenerateHooks,
    activeSceneIsStillImage,
    activeMotionClass,
    activeSceneIndex,
    mobileSheet,
    mobilePanelOpen,
    openPanel,
    toggleSheet,
    viewport,
    onViewportResize,
    syncAppHeight,
    isPhone,
    previewContainerStyle,
    projectTitle,
    mobileStatus,
    editingTitle,
    titleDraft,
    titleSaving,
    startEditTitle,
    commitTitle,
    unreadCount,
    latestExportJob,
    exportsByRatio,
    selectedExportRatio,
    activeExportJob,
    exportFbOpen,
    exportFbRating,
    exportFbOptions,
    exportFbComment,
    exportFbSending,
    exportFbDone,
    EXPORT_FB_POSITIVE,
    EXPORT_FB_NEGATIVE,
    exportFbChips,
    exportFbToggle,
    exportFbSubmit,
    trackVideoDownloaded,
    exportChecks,
    exportCheckNotes,
    latestExportDownloadUrl,
    hasPendingExportChanges,
    performExportAction,
    exportStaleOnServer,
    refreshExportFreshness,
    exportToolbar,
    exportOutOfDate,
    exportWarning,
    exportActionChecking,
    requestExportAction,
    cancelExportAction,
    continueExportAction,
    updateExportAction,
    failedScenes,
    failedSceneCount,
    resumePending,
    resumeError,
    resumeFailedScenes,
    wholeVideoTake,
    exportBlockerMessage,
    activeSceneVisualError,
    activeSceneVoiceError,
    activeSceneScriptError,
    captionPreviewClass,
    captionsCanRender,
    captionPositionStyle,
    flattenedCaptionFonts,
    selectedCaptionFont,
    captionFontStyle,
    captionFamilyStyle,
    captionSizeIsCustom,
    activeCaptionSettings,
    sceneTypeOptions,
    addSceneStockTypeOptions,
    visualSourceTypeMap,
    visualTypeLabelMap,
    REWRITE_EDIT_OPTIONS,
    REWRITE_TONE_OPTIONS,
    hasActiveExport,
    exportInProgress,
    humanizeSceneType,
    sceneTypeLabel,
    sceneVisualLabel,
    selectedVisualType,
    sceneVoiceOutdated,
    normalizeScenePayload,
    normalizeImageGenerationSettings,
    normalizeCaptionSettings,
    sortScenesByOrder,
    replaceSceneInCollection,
    patchSceneCaptionSettings,
    buildSceneLabel,
    resetAddSceneDrafts,
    pickAssetForNewScene,
    generateNewSceneDraft,
    scriptSaveCopy,
    voiceSaveCopy,
    captionSaveCopy,
    exportStatusCopy,
    formatSceneDuration,
    previewWords,
    captionTimingWords,
    previewTimedWords,
    currentCaptionSeconds,
    fontFamilyValue,
    selectCaptionFont,
    formatPreviewTime,
    totalVideoDuration,
    sceneDuration,
    sceneAtFullElapsed,
    sceneBoundaryPcts,
    previewContextDuration,
    previewElapsedSecs,
    previewTimer,
    formatNotifTime,
    updateVisualCache,
    updateAudioCache,
    syncActiveSceneMedia,
    preloadSceneVisual,
    preloadSceneAudio,
    loadImageModelCatalog,
    modelPickerOpen,
    activeImageModelLabel,
    generationFailed,
    generationFailureMessage,
    retrySourceDraft,
    retryPending,
    retryError,
    retrySourceEditable,
    retryGeneration,
    loadProject,
    applyProjectPayload,
    refreshProjectPayload,
    editHistory,
    historyStepping,
    loadEditHistory,
    scheduleHistoryRefresh,
    resyncSceneDrafts,
    savesInFlight,
    stepHistory,
    historyInterceptor,
    historyKeys,
    loadMe,
    setCruiseAutoApply,
    setCruisePref,
    maybeAutoApply,
    cruiseUseQuickPrompt,
    loadVoiceProfiles,
    loadCaptionPresets,
    loadChannels,
    loadBrandKits,
    loadMusicTracks,
    loadMyImageAssets,
    handleVoiceFileUpload,
    enterPreview,
    confirmUploadFromPreview,
    uploadVoiceBlob,
    startVoiceTranscribePoll,
    applyVoiceUpload,
    toggleVoicePreviewPlay,
    onVoicePreviewTime,
    onVoicePreviewSeek,
    fmtVoicePreviewTime,
    cancelVoiceUpload,
    startVoiceRecording,
    stopVoiceRecording,
    loadCustomAudioAssets,
    assignAssetImage,
    assignCustomAudio,
    openMediaPicker,
    syncSceneVoiceVolume,
    scheduleSceneVoiceVolumeSave,
    scheduleSceneSoundVolumeSave,
    saveAudiogramSettings,
    selectAudiogramStyle,
    selectAudiogramColor,
    queueAudiogramColorSave,
    selectAudiogramBg,
    handleMediaPickerSelect,
    assignSceneSound,
    assignAssetVisual,
    persistMusicSettings,
    scheduleMusicSave,
    saveProjectDefaults,
    loadNotifications,
    loadExportJobs,
    markNotificationRead,
    markAllRead,
    pushToast,
    onPostScheduled,
    pollPublishStatus,
    subscribeWorkspaceNotifications,
    unsubscribeWorkspaceNotifications,
    logout,
    selectScene,
    skipToScene,
    setPreviewMode,
    primedMedia,
    primeMediaForGesture,
    togglePreviewPlay,
    startPreviewPlay,
    stopPreviewPlay,
    timelineSeek,
    timelineReorder,
    scrubberSeek,
    currentSceneAudioOffset,
    syncSceneVideoPreview,
    sceneVideoMetadataLoaded,
    fullPreviewAudioProgress,
    syncFullPreviewScene,
    scrollSceneIntoView,
    playActiveSceneAudio,
    playActiveSceneSound,
    previewMusicOffset,
    syncPreviewMusicVolume,
    playPreviewMusic,
    stopPreviewMusic,
    handleSceneAudioPlay,
    handleSceneAudioPause,
    moodGradient,
    moodEmoji,
    trackMoodLabel,
    selectMusicTrack,
    toggleMusicAudition,
    stopMusicAudition,
    moodLabel,
    formatTrackDuration,
    togglePanel,
    toggleAddScene,
    closeAddScene,
    selectAddSceneVisualMode,
    applyCaptionPreset,
    saveCaptionPreset,
    deleteCaptionPreset,
    saveVoiceProfile,
    saveSceneDuration,
    toggleRewriteTools,
    submitRewrite,
    submitRewriteCustom,
    hideRewritePreview,
    acceptRewrite,
    regenerateVoiceForScene,
    regenerateVoice,
    persistSceneScript,
    applyVoiceAllState,
    applyVoiceAllError,
    bulkVoiceOpen,
    bulkVoicePreview,
    bulkVoiceLoading,
    bulkVoiceSubmitting,
    bulkVoiceError,
    openBulkVoice,
    submitBulkVoice,
    applyVoiceToAllScenes,
    persistVoiceSettings,
    saveUgcHeadline,
    persistCaptionSettings,
    applyAllState,
    applyCaptionsToAllScenes,
    persistMotionSettings,
    loadCharacters,
    activeCharacter,
    addSceneCharacter,
    selectAddSceneCharacter,
    selectCharacter,
    pickCharacterFile,
    clearCharacterFile,
    closeCharacterModal,
    createCharacter,
    hookScoreClass,
    useHook,
    swapVisual,
    bulkStyleOpen,
    bulkStylePreview,
    bulkStyleLoading,
    bulkStyleSubmitting,
    bulkStyleError,
    bulkStyleKey,
    openBulkStyle,
    submitBulkStyle,
    editInstruction,
    editPending,
    editError,
    EDIT_SUGGESTIONS,
    editSceneImage,
    generateAIImage,
    openAnimateModal,
    closeAnimateModal,
    canRevertAnimation,
    activeSceneAnimationHistory,
    activeSceneAnimationOriginal,
    useOriginalStill,
    useHistoryAnimation,
    cancelAnimation,
    revertAnimation,
    bulkAnimateMode,
    bulkAnimatePreview,
    bulkAnimateLoading,
    bulkAnimateSelection,
    bulkAnimateSourceId,
    bulkAnimateRows,
    bulkAnimateFirstLoad,
    bulkAnimateSelectableCount,
    bulkAnimateReplacedCount,
    bulkAnimateOwnStill,
    loadBulkAnimatePreview,
    toggleBulkScene,
    bulkAnimateSelectAll,
    toggleBulkAnimate,
    submitBulkAnimate,
    submitAnimate,
    lipSyncRegenerating,
    regenerateLipSync,
    POLL_MAX_ATTEMPTS,
    pollSceneUntilVisual,
    queueExport,
    subscribeProjectChannel,
    unsubscribeProjectChannel,
    createScene,
    duplicateScene,
    promptDeleteScene,
    closeDeleteSceneModal,
    confirmDeleteScene,
    moveScene,
    flushActiveSceneDrafts,
    toggleAudioPlayback,
    syncSceneSoundVolume,
    voiceDescription,
    CAPTION_ANIMATIONS,
  };
}
