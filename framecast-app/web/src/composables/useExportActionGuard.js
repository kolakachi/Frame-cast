import { ref, onBeforeUnmount } from 'vue'
import api from '../services/api'

export function useExportActionGuard({ projectId, getExport, hasPendingChanges, perform, update }) {
  const warning = ref(null)
  const checking = ref(false)
  let version = 0
  function cancel() { version++; warning.value = null }
  async function request(action) {
    if (checking.value) return
    const job = getExport()
    if (!job) return
    checking.value = true
    const ticket = ++version
    try {
      const { data } = await api.get(`/projects/${projectId()}/exports/${job.id}/freshness`)
      if (ticket !== version) return
      if (data?.data?.is_stale || hasPendingChanges()) {
        warning.value = { action, job, busy: false, message: 'Your exported video does not include your latest edits. Continue with the previous video, or update it first.' }
      } else await perform(action, job)
    } catch (error) {
      if (ticket !== version) return
      warning.value = { action, job, busy: false,
        uncertain: true, unavailable: [404, 422].includes(error.response?.status),
        message: 'We could not verify that this export includes your latest changes. You can update the video before continuing.' }
    } finally { checking.value = false }
  }
  async function continuePrevious() {
    const pending = warning.value
    if (!pending || pending.busy || pending.unavailable) return
    pending.busy = true
    const ticket = ++version
    try {
      // It may have been archived by another export while the dialog was open.
      await api.get(`/projects/${projectId()}/exports/${pending.job.id}/freshness`)
      if (ticket !== version) return
      cancel()
      await perform(pending.action, pending.job)
    } catch (error) {
      if (ticket !== version) return
      pending.busy = false
      pending.unavailable = [404, 422].includes(error.response?.status)
      pending.message = 'The previous video could not be opened. Please update the export or try again.'
    }
  }
  async function updateFirst() {
    const pending = warning.value
    if (!pending || pending.busy) return
    pending.busy = true
    const ticket = ++version
    try {
      const job = await update()
      if (!job) throw new Error('Save your changes and resolve any export errors, then try again.')
      for (let attempt = 0; attempt < 600; attempt++) {
        if (ticket !== version) return
        const { data } = await api.get(`/projects/${projectId()}/exports`)
        const current = data?.data?.export_jobs?.find(item => Number(item.id) === Number(job.id))
        if (current?.status === 'failed') throw new Error(current.failure_reason || 'Updating failed. Your requested action has not been performed.')
        if (current?.status === 'completed') {
          const { data: fresh } = await api.get(`/projects/${projectId()}/exports/${current.id}/freshness`)
          if (ticket !== version) return
          if (fresh?.data?.is_stale || hasPendingChanges()) throw new Error('More edits were made while updating. Update again to include them, or cancel and use the previous video.')
          cancel()
          await perform(pending.action, current)
          return
        }
        await new Promise(resolve => setTimeout(resolve, 3000))
      }
      throw new Error('The update is still running. Close this dialog and check its progress in the editor.')
    } catch (error) {
      if (ticket === version && warning.value) {
        warning.value.busy = false
        warning.value.message = error.message || 'Could not update the video. Please try again.'
      }
    }
  }
  onBeforeUnmount(cancel)
  return { warning, checking, request, cancel, continuePrevious, updateFirst }
}
