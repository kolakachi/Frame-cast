import { watch } from 'vue'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { configureApiClient, setApiAccessToken } from './services/api'
import { disconnectEcho, initEcho } from './services/echo'
import { useAuthStore } from './stores/auth'
import './style.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

const authStore = useAuthStore()
authStore.hydrate()

configureApiClient(authStore)

watch(
  () => authStore.accessToken,
  (token) => {
    setApiAccessToken(token)

    if (!token) {
      disconnectEcho()
      return
    }

    initEcho(token)
  },
  { immediate: true },
)

watch(
  () => authStore.isAuthenticated,
  (isAuthenticated) => {
    if (isAuthenticated) {
      return
    }

    if (router.currentRoute.value.meta.requiresAuth) {
      router.push({ name: 'login' })
    }
  },
)

app.use(router)
app.mount('#app')
