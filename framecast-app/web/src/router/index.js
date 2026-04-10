import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import EditorView from '../views/EditorView.vue'
import GenerationProgressView from '../views/GenerationProgressView.vue'
import AssetLibraryView from '../views/AssetLibraryView.vue'
import LoginView from '../views/LoginView.vue'
import MagicLinkView from '../views/MagicLinkView.vue'
import RegisterView from '../views/RegisterView.vue'
import SettingsView from '../views/SettingsView.vue'
import VariantsView from '../views/VariantsView.vue'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true } },
  { path: '/register', name: 'register', component: RegisterView, meta: { guestOnly: true } },
  { path: '/auth/magic', name: 'magic-link', component: MagicLinkView },
  { path: '/dashboard', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true } },
  { path: '/assets', name: 'asset-library', component: AssetLibraryView, meta: { requiresAuth: true } },
  { path: '/settings', name: 'settings', component: SettingsView, meta: { requiresAuth: true } },
  { path: '/projects/:projectId/generation', name: 'generation-progress', component: GenerationProgressView, meta: { requiresAuth: true } },
  { path: '/projects/:projectId/editor', name: 'project-editor', component: EditorView, meta: { requiresAuth: true } },
  { path: '/projects/:projectId/variants', name: 'project-variants', component: VariantsView, meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(function (to) {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && authStore.isAuthenticated) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
