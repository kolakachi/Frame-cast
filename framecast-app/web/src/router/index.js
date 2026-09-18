import { createRouter, createWebHistory } from 'vue-router'
import OnboardingView from '../views/OnboardingView.vue'
import DashboardView from '../views/DashboardView.vue'
import EditorView from '../views/EditorView.vue'
import GenerationProgressView from '../views/GenerationProgressView.vue'
import AssetLibraryView from '../views/AssetLibraryView.vue'
import LoginView from '../views/LoginView.vue'
import MagicLinkView from '../views/MagicLinkView.vue'
import RegisterView from '../views/RegisterView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ResetPasswordView from '../views/ResetPasswordView.vue'
import SettingsView from '../views/SettingsView.vue'
import PlansView from '../views/PlansView.vue'
import VariantsView from '../views/VariantsView.vue'
import AdminView from '../views/AdminView.vue'
import WorkspaceView from '../views/WorkspaceView.vue'
import ChannelsView from '../views/ChannelsView.vue'
import ChannelDetailView from '../views/ChannelDetailView.vue'
import SeriesView from '../views/SeriesView.vue'
import UgcAdsView from '../views/UgcAdsView.vue'
import SeriesDetailView from '../views/SeriesDetailView.vue'
import SeriesCreateView from '../views/SeriesCreateView.vue'
import VideosView from '../views/VideosView.vue'
import JobsView from '../views/JobsView.vue'
import CalendarView from '../views/CalendarView.vue'
import CharactersView from '../views/CharactersView.vue'
import VoicesView from '../views/VoicesView.vue'
import ApprovalReviewView from '../views/ApprovalReviewView.vue'
import SampleView from '../views/SampleView.vue'
import NotFoundView from '../views/NotFoundView.vue'
import AppSumoActivateView from '../views/AppSumoActivateView.vue'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/client-work', name: 'client-work', component: () => import('../views/ClientWorkView.vue'), meta: { requiresAuth: true } },
  { path: '/delivery/:token', name: 'client-delivery', component: () => import('../views/ClientDeliveryView.vue'), meta: { public: true } },
  { path: '/', redirect: '/dashboard' },
  { path: '/onboarding', name: 'onboarding', component: OnboardingView, meta: { requiresAuth: true, skipOnboardingGuard: true } },
  { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true } },
  { path: '/register', name: 'register', component: RegisterView, meta: { guestOnly: true } },
  { path: '/auth/magic', name: 'magic-link', component: MagicLinkView },
  // AppSumo LTD activation — public (buyer may be logged out or in); not
  // guestOnly so an existing user can attach a license without being bounced.
  { path: '/appsumo/activate', name: 'appsumo-activate', component: AppSumoActivateView, meta: { public: true } },
  // Affiliates have no user account, so this route stands outside the app
  // shell and carries its own session.
  { path: '/affiliates', name: 'affiliate-portal', component: () => import('../views/AffiliatePortalView.vue'), meta: { public: true } },
  { path: '/auth/forgot', name: 'forgot-password', component: ForgotPasswordView, meta: { guestOnly: true } },
  { path: '/auth/reset', name: 'reset-password', component: ResetPasswordView, meta: { guestOnly: true } },
  { path: '/approve/:token', name: 'approval-review', component: ApprovalReviewView, meta: { public: true } },
  // Public share page for cold-DM motion — no auth needed
  { path: '/sample/:token', name: 'sample', component: SampleView, meta: { public: true } },
  { path: '/dashboard', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true } },
  { path: '/assets', name: 'asset-library', component: AssetLibraryView, meta: { requiresAuth: true } },
  { path: '/workspace', name: 'workspace', component: WorkspaceView, meta: { requiresAuth: true } },
  { path: '/settings', name: 'settings', component: SettingsView, meta: { requiresAuth: true } },
  { path: '/plans', name: 'plans', component: PlansView, meta: { requiresAuth: true } },
  // Landing point for "finish checkout" email links. Public so a signed-out
  // click keeps the plan instead of losing it to the login redirect.
  { path: '/continue', name: 'continue-checkout', component: () => import('../views/ContinueCheckoutView.vue'), meta: { public: true } },
  { path: '/admin', name: 'admin', component: AdminView, meta: { requiresAuth: true, adminOnly: true } },
  { path: '/series', name: 'series', component: SeriesView, meta: { requiresAuth: true } },
  { path: '/ugc-ads', name: 'ugc-ads', component: UgcAdsView, meta: { requiresAuth: true, internalOnly: true } },
  // The run and review screens are route-addressable so "you can leave this
  // page — we'll keep working" is actually true.
  { path: '/ugc-ads/run/:runId', name: 'ugc-run', component: () => import('../views/UgcRunView.vue'), meta: { requiresAuth: true, internalOnly: true } },
  { path: '/ugc-ads/review/:projectId', name: 'ugc-review', component: () => import('../views/UgcReviewView.vue'), meta: { requiresAuth: true, internalOnly: true } },
  // The same pipeline, entered by someone who has the footage and needs the
  // arrangement. They would never look inside something called UGC Ads, and a
  // second implementation would be two to maintain — so it is one view with a
  // different way in and a different name on it.
  { path: '/from-my-footage', name: 'from-my-footage', component: () => import('../views/FootageFlowView.vue'), meta: { requiresAuth: true, internalOnly: true } },
  { path: '/from-my-footage/:sessionId', name: 'footage-flow', component: () => import('../views/FootageFlowView.vue'), meta: { requiresAuth: true, internalOnly: true } },
  { path: '/from-my-footage/:sessionId/compare', name: 'footage-compare', component: () => import('../views/FootageFlowView.vue'), meta: { requiresAuth: true, internalOnly: true } },
  { path: '/series/new', name: 'series-create', component: SeriesCreateView, meta: { requiresAuth: true } },
  { path: '/series/:seriesId', name: 'series-detail', component: SeriesDetailView, meta: { requiresAuth: true } },
  // Agency client management. Its own area rather than a settings tab: a
  // client can have a hundred members, which no accordion can hold.
  { path: '/clients', name: 'clients', component: () => import('../views/ClientsView.vue'), meta: { requiresAuth: true } },
  { path: '/clients/:id', name: 'client-detail', component: () => import('../views/ClientDetailView.vue'), meta: { requiresAuth: true } },
  { path: '/channels', name: 'channels', component: ChannelsView, meta: { requiresAuth: true } },
  { path: '/channels/:channelId', name: 'channel-detail', component: ChannelDetailView, meta: { requiresAuth: true } },
  { path: '/videos', name: 'videos', component: VideosView, meta: { requiresAuth: true } },
  { path: '/calendar', name: 'calendar', component: CalendarView, meta: { requiresAuth: true } },
  { path: '/characters', name: 'characters', component: CharactersView, meta: { requiresAuth: true } },
  { path: '/voices', name: 'voices', component: VoicesView, meta: { requiresAuth: true } },
  { path: '/jobs', name: 'jobs', component: JobsView, meta: { requiresAuth: true } },
  { path: '/projects/:projectId/generation', name: 'generation-progress', component: GenerationProgressView, meta: { requiresAuth: true } },
  { path: '/projects/:projectId/editor', name: 'project-editor', component: EditorView, meta: { requiresAuth: true } },
  { path: '/projects/:projectId/variants', name: 'project-variants', component: VariantsView, meta: { requiresAuth: true } },
  // Catch-all 404. public:true so a mistyped URL shows the 404 instead of
  // bouncing through the auth / onboarding guards.
  { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView, meta: { public: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async function (to) {
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

  // Unreleased features. The API answers 404 for anyone outside the team, so
  // the route is turned away here rather than rendering a page whose every
  // request fails.
  if (to.meta.internalOnly) {
    // The cached user is written at sign-in and only refreshed with the access
    // token, so a session older than the flag simply has no `is_internal` — and
    // treating missing as false locks out the very people the page is for.
    // Ask the API once when the answer is unknown; a definite false is trusted.
    if (authStore.user && authStore.user.is_internal === undefined) {
      await authStore.refreshUser()
    }
    if (!authStore.user?.is_internal) {
      return { name: 'dashboard' }
    }
  }

  if (["client", "client_editor", "client_admin"].includes(authStore.user?.role) && to.name === "dashboard") return { name: "client-work" };

  // Redirect unonboarded users to the wizard (except the wizard itself,
  // auth routes, and public-share/approval pages that anyone — incl.
  // unonboarded users hitting a share link — should see).
  if (
    authStore.isAuthenticated &&
    !authStore.isOnboarded &&
    !["client", "client_editor", "client_admin"].includes(authStore.user?.role) &&
    !to.meta.skipOnboardingGuard &&
    !to.meta.guestOnly &&
    !to.meta.public
  ) {
    return { name: 'onboarding' }
  }

  return true
})

export default router
