import { createRouter, createWebHistory } from 'vue-router'
import OnboardingView from '../views/OnboardingView.vue'
import DashboardView from '../views/DashboardView.vue'
import EditorView from '../views/EditorView.vue'
import GenerationProgressView from '../views/GenerationProgressView.vue'
import AssetLibraryView from '../views/AssetLibraryView.vue'
import LoginView from '../views/LoginView.vue'
import MagicLinkView from '../views/MagicLinkView.vue'
import RegisterView from '../views/RegisterView.vue'
import SettingsView from '../views/SettingsView.vue'
import VariantsView from '../views/VariantsView.vue'
import AdminView from '../views/AdminView.vue'
import WorkspaceView from '../views/WorkspaceView.vue'
import ChannelsView from '../views/ChannelsView.vue'
import ChannelDetailView from '../views/ChannelDetailView.vue'
import SeriesView from '../views/SeriesView.vue'
import SeriesDetailView from '../views/SeriesDetailView.vue'
import SeriesCreateView from '../views/SeriesCreateView.vue'
import VideosView from '../views/VideosView.vue'
import JobsView from '../views/JobsView.vue'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/onboarding', name: 'onboarding', component: OnboardingView, meta: { requiresAuth: true, skipOnboardingGuard: true } },
  { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true } },
  { path: '/register', name: 'register', component: RegisterView, meta: { guestOnly: true } },
  { path: '/auth/magic', name: 'magic-link', component: MagicLinkView },
  { path: '/dashboard', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true } },
  { path: '/assets', name: 'asset-library', component: AssetLibraryView, meta: { requiresAuth: true } },
  { path: '/workspace', name: 'workspace', component: WorkspaceView, meta: { requiresAuth: true } },
  { path: '/settings', name: 'settings', component: SettingsView, meta: { requiresAuth: true } },
  { path: '/admin', name: 'admin', component: AdminView, meta: { requiresAuth: true, adminOnly: true } },
  { path: '/series', name: 'series', component: SeriesView, meta: { requiresAuth: true } },
  { path: '/series/new', name: 'series-create', component: SeriesCreateView, meta: { requiresAuth: true } },
  { path: '/series/:seriesId', name: 'series-detail', component: SeriesDetailView, meta: { requiresAuth: true } },
  { path: '/channels', name: 'channels', component: ChannelsView, meta: { requiresAuth: true } },
  { path: '/channels/:channelId', name: 'channel-detail', component: ChannelDetailView, meta: { requiresAuth: true } },
  { path: '/videos', name: 'videos', component: VideosView, meta: { requiresAuth: true } },
  { path: '/jobs', name: 'jobs', component: JobsView, meta: { requiresAuth: true } },
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

  if (to.meta.adminOnly && !['super_admin', 'platform_admin'].includes(authStore.user?.role)) {
    return { name: 'dashboard' }
  }

  // Redirect unonboarded users to the wizard (except the wizard itself and auth routes)
  if (
    authStore.isAuthenticated &&
    !authStore.isOnboarded &&
    !to.meta.skipOnboardingGuard &&
    !to.meta.guestOnly
  ) {
    return { name: 'onboarding' }
  }

  return true
})

export default router
