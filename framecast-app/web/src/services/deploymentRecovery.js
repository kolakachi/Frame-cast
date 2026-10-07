import { reactive } from 'vue'
import { createDeploymentRecovery } from '../lib/deploymentRecovery.js'

export const deploymentNotice = reactive({ visible: false, reason: '' })
export const deploymentRecovery = createDeploymentRecovery({
  notify(reason) { deploymentNotice.visible = true; deploymentNotice.reason = reason },
  reload: () => window.location.reload(),
  online: () => navigator.onLine !== false,
})

export function installDeploymentRecovery(router) {
  window.addEventListener('vite:preloadError', event => {
    deploymentRecovery.report(event.payload, true)
    // Keep rejection observable to the router/caller; suppressing it can make
    // a missing route component look like a successful empty navigation.
  })
  router.onError(error => deploymentRecovery.report(error))
}
