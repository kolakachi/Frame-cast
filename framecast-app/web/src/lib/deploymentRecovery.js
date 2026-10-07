// A failed import can mean a deployment, an offline browser or a CDN failure.
// Never infer that it is safe to reload, or replay the action that failed.
export function isChunkLoadError(error) {
  return /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Loading chunk [\w-]+ failed|Unable to preload CSS/i.test(String(error?.message || error || ''))
}

const guards = new Set()
export function registerReloadGuard(guard) {
  guards.add(guard)
  return () => guards.delete(guard)
}

export function reloadBlockReason() {
  try {
    for (const guard of guards) {
      const reason = guard()
      if (reason) return reason
    }
  } catch {
    return 'Your draft could not be checked. Copy unsaved text before refreshing the page manually.'
  }
  return ''
}

export function createDeploymentRecovery({ notify, reload, beforeReload = reloadBlockReason, online = () => true }) {
  let visible = false
  let reloading = false
  return {
    report(error, preload = false) {
      if (!preload && !isChunkLoadError(error)) return false
      if (!visible) { visible = true; notify('') }
      return true
    },
    refresh() {
      if (!visible || reloading) return false
      const reason = online() ? beforeReload() : 'You appear to be offline. Reconnect, then try again.'
      if (reason) { notify(reason); return false }
      reloading = true
      // Reload the current URL, not the failed navigation. This leaves a saved
      // composer draft attached to its original conversation. No POST is retried.
      reload()
      return true
    },
  }
}
