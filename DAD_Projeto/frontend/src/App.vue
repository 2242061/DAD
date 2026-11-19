<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { useAuth } from '@/composables/useAuth'

const auth = useAuth()
const route = useRoute()
const router = useRouter()
const leaving = ref(false)
const error = ref('')
const initials = computed(() =>
  (auth.user.value?.name ?? 'Visitante')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase(),
)
function focusMain() {
  document.getElementById('main-content')?.focus()
}
function sessionExpired() {
  auth.clearSession()
  if (route.meta.authenticated)
    void router.replace({
      name: 'login',
      query: { notice: 'session-expired', redirect: route.fullPath },
    })
}
onMounted(() => window.addEventListener('auth:expired', sessionExpired))
onBeforeUnmount(() => window.removeEventListener('auth:expired', sessionExpired))
async function logout() {
  leaving.value = true
  error.value = ''
  try {
    await auth.logout()
    await router.push('/entrar')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Não foi possível terminar a sessão.'
  } finally {
    leaving.value = false
  }
}
</script>

<template>
  <a class="skip-link" href="#main-content" @click.prevent="focusMain">Saltar para o conteúdo</a>
  <div class="app-layout">
    <aside class="sidebar">
      <RouterLink to="/explorar" class="brand" aria-label="Dama — início"
        ><span class="brand-symbol"><i /><i /><i /><i /></span
        ><span>dama<span class="brand-dot">.</span></span></RouterLink
      >
      <p class="sidebar-caption">O TEU CLUBE DE DAMAS</p>
      <nav aria-label="Navegação principal">
        <p class="nav-label">PLATAFORMA</p>
        <RouterLink to="/explorar" class="nav-item"><AppIcon name="grid" />Visão geral</RouterLink>
        <RouterLink
          :to="auth.user.value ? '/conta' : '/entrar'"
          class="nav-item"
          :class="{ 'router-link-active': route.name === 'register' }"
          ><AppIcon name="user" />{{
            auth.user.value ? 'A minha conta' : 'Entrar / Registar'
          }}</RouterLink
        >
        <RouterLink v-if="auth.isAdmin.value" to="/admin" class="nav-item"
          ><AppIcon name="shield" />Administração</RouterLink
        >
        <RouterLink to="/jogar" class="nav-item"
          ><AppIcon name="chess" />Jogar<span class="tiny-label">Novo</span></RouterLink
        >
        <RouterLink to="/assistir" class="nav-item"><AppIcon name="chess" />Assistir</RouterLink>
        <RouterLink to="/ranking" class="nav-item"><AppIcon name="trophy" />Ranking</RouterLink>
        <RouterLink to="/torneios" class="nav-item"><AppIcon name="trophy" />Torneios</RouterLink>
        <RouterLink to="/carteira" class="nav-item"><AppIcon name="wallet" />Carteira</RouterLink>
      </nav>
      <div class="sidebar-bottom">
        <div class="development-note">
          <span class="status-dot" /><span
            >O clube está a crescer<small>Projeto DAD · 2026/27</small></span
          >
        </div>
        <div v-if="auth.user.value" class="sidebar-profile">
          <span class="avatar">{{ initials }}</span>
          <div>
            <strong>{{ auth.user.value.name }}</strong
            ><small>{{ auth.isAdmin.value ? 'Administrador' : 'Jogador' }}</small>
          </div>
        </div>
        <p v-else class="sidebar-footnote">Um clássico português.<br />Uma nova forma de jogar.</p>
      </div>
    </aside>
    <div class="workspace">
      <header class="topbar">
        <div class="breadcrumb">
          O clube <span>/</span> <strong>{{ route.meta.title }}</strong>
        </div>
        <div class="topbar-actions">
          <span class="edition">DAMAS PORTUGUESAS</span
          ><button v-if="auth.user.value" class="button-text" :disabled="leaving" @click="logout">
            <AppIcon name="logout" :size="17" />{{ leaving ? 'A sair…' : 'Sair' }}</button
          ><RouterLink
            v-else
            :to="route.name === 'register' ? '/entrar' : '/registar'"
            class="button-small"
            >{{ route.name === 'register' ? 'Entrar' : 'Criar conta'
            }}<AppIcon name="arrow" :size="16"
          /></RouterLink>
        </div>
      </header>
      <main id="main-content" tabindex="-1">
        <div v-if="error" class="alert error" role="alert">{{ error }}</div>
        <div v-if="auth.connectionError.value" class="alert error" role="alert">
          {{ auth.connectionError.value }}
          <button class="inline-link" @click="auth.refreshUser">Tentar novamente</button>
        </div>
        <RouterView :key="route.path" />
      </main>
      <footer class="site-footer">
        <span>Feito para quem pensa a próxima jogada.</span
        ><span>Projeto académico · DAD 2026/27</span>
      </footer>
    </div>
  </div>
</template>
