import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import AuthView from '@/views/AuthView.vue'
import ExploreView from '@/views/ExploreView.vue'
import AccountView from '@/views/AccountView.vue'
import AdminView from '@/views/AdminView.vue'
import AdminSettingsView from '@/views/AdminSettingsView.vue'
import RecoveryView from '@/views/RecoveryView.vue'
import VerifyEmailView from '@/views/VerifyEmailView.vue'
import AuditView from '@/views/AuditView.vue'
import PlayView from '@/views/PlayView.vue'
import SpectatorsView from '@/views/SpectatorsView.vue'
import RankingView from '@/views/RankingView.vue'
import TournamentsView from '@/views/TournamentsView.vue'
import WalletView from '@/views/WalletView.vue'
import WalletAuditView from '@/views/WalletAuditView.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', redirect: '/entrar' },
    {
      path: '/recuperar-conta',
      name: 'forgot-password',
      component: RecoveryView,
      meta: { title: 'Recuperar conta' },
    },
    {
      path: '/repor-palavra-passe',
      name: 'reset-password',
      component: RecoveryView,
      meta: { title: 'Nova palavra-passe' },
    },
    {
      path: '/verificar-email',
      name: 'verify-email',
      component: VerifyEmailView,
      meta: { title: 'Confirmar email', authenticated: true },
    },
    {
      path: '/admin/historico',
      name: 'audit',
      component: AuditView,
      meta: { title: 'Histórico de ações', authenticated: true, admin: true },
    },
    { path: '/entrar', name: 'login', component: AuthView, meta: { title: 'Entrar', guest: true } },
    {
      path: '/registar',
      name: 'register',
      component: AuthView,
      meta: { title: 'Criar conta', guest: true },
    },
    {
      path: '/jogar/online/:code?',
      name: 'online',
      component: PlayView,
      meta: { title: 'Jogar online' },
    },
    {
      path: '/assistir',
      name: 'spectators',
      component: SpectatorsView,
      meta: { title: 'Partidas em direto' },
    },
    {
      path: '/assistir/:code',
      name: 'spectator',
      component: PlayView,
      meta: { title: 'Assistir à partida' },
    },
    {
      path: '/jogar/treino',
      name: 'training',
      component: PlayView,
      meta: { title: 'Treino contra o bot' },
    },
    { path: '/jogar', name: 'play', component: PlayView, meta: { title: 'Jogar' } },
    { path: '/explorar', name: 'explore', component: ExploreView, meta: { title: 'Visão geral' } },
    {
      path: '/conta',
      name: 'account',
      component: AccountView,
      meta: { title: 'A minha conta', authenticated: true },
    },
    {
      path: '/admin',
      name: 'admin',
      component: AdminView,
      meta: { title: 'Administração', authenticated: true, admin: true },
    },
    {
      path: '/torneios',
      name: 'tournaments',
      component: TournamentsView,
      meta: { title: 'Torneios', authenticated: true },
    },
    {
      path: '/torneios/:id',
      name: 'tournament',
      component: TournamentsView,
      meta: { title: 'Torneio', authenticated: true },
    },
    {
      path: '/admin/torneios',
      name: 'admin-tournaments',
      component: TournamentsView,
      meta: { title: 'Gestão de torneios', authenticated: true, admin: true },
    },
    {
      path: '/admin/torneios/:id',
      name: 'admin-tournament',
      component: TournamentsView,
      meta: { title: 'Gerir torneio', authenticated: true, admin: true },
    },
    {
      path: '/admin/configuracao',
      name: 'admin-settings',
      component: AdminSettingsView,
      meta: { title: 'Configuração', authenticated: true, admin: true },
    },
    { path: '/ranking', name: 'ranking', component: RankingView, meta: { title: 'Ranking' } },
    {
      path: '/carteira',
      name: 'wallet',
      component: WalletView,
      meta: { title: 'Carteira', authenticated: true },
    },
    {
      path: '/admin/carteira',
      name: 'wallet-audit',
      component: WalletAuditView,
      meta: { title: 'Auditoria financeira', authenticated: true, admin: true },
    },
    { path: '/:pathMatch(.*)*', redirect: '/explorar' },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  await auth.initialize()
  if (to.meta.authenticated) await auth.refreshUser()
  if (to.meta.authenticated && !auth.user.value)
    return { name: 'login', query: { redirect: to.fullPath } }
  if (to.meta.admin && !auth.isAdmin.value) return { name: 'account' }
  if (to.meta.guest && auth.user.value) return { name: 'account' }
})
router.afterEach((to) => {
  document.title = `${String(to.meta.title ?? 'Damas portuguesas')} · Dama`
})

export default router
