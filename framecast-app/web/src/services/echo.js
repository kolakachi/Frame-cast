import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

let echoInstance = null

export function initEcho(token) {
  if (echoInstance || !token) {
    return echoInstance
  }

  window.Pusher = Pusher

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${import.meta.env.VITE_API_URL ?? 'http://localhost:8000'}/api/v1/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    },
  })

  return echoInstance
}

export function disconnectEcho() {
  if (!echoInstance) {
    return
  }

  echoInstance.disconnect()
  echoInstance = null
}

export function getEcho() {
  return echoInstance
}
