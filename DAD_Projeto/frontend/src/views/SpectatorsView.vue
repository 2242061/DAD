<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'

type PublicGame = {
  code: string
  mode: 'ranked' | 'tournament'
  players: { white: string; black: string }
  tournament_round: number | null
}
type GamePage = { data: PublicGame[]; page: number; has_more: boolean }

const games = ref<GamePage>()
const loading = ref(false)
const error = ref('')
let timer: ReturnType<typeof setInterval>

async function load(page = games.value?.page ?? 1) {
  if (loading.value) return
  loading.value = true
  try {
    games.value = await api<GamePage>(`/api/spectate?page=${page}`)
    error.value = ''
  } catch {
    error.value = 'Não foi possível atualizar as partidas. Tenta novamente.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
  timer = setInterval(() => {
    if (!document.hidden) void load()
  }, 10000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <section class="page-content tournament-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">BANCADA</p>
        <h1>Partidas em direto<span class="green-dot">.</span></h1>
        <p>Assiste a partidas competitivas e de torneio sem criar conta.</p>
      </div>
      <button class="button-secondary" :disabled="loading" @click="load()">
        Atualizar partidas
      </button>
    </div>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <p v-else-if="loading && !games" role="status">A procurar partidas…</p>
    <template v-if="games">
      <p v-if="!games.data.length && !error" class="surface practice-empty">
        Não há partidas públicas nesta página.
      </p>
      <div class="tournament-grid">
        <RouterLink
          v-for="game in games.data"
          :key="game.code"
          :to="`/assistir/${game.code}`"
          class="surface tournament-card"
        >
          <span class="rank-tier-label">{{
            game.mode === 'ranked' ? 'Competitivo' : `Torneio · Ronda ${game.tournament_round}`
          }}</span>
          <h2>{{ game.players.white }} × {{ game.players.black }}</h2>
          <p>Brancas × Pretas</p>
          <span class="inline-link">Assistir ↗</span>
        </RouterLink>
      </div>
      <div v-if="games.page > 1 || games.has_more" class="player-history-pages">
        <button
          class="button-small"
          :disabled="loading || games.page <= 1"
          @click="load(games.page - 1)"
        >
          Anterior
        </button>
        <span>Página {{ games.page }}</span>
        <button
          class="button-small"
          :disabled="loading || !games.has_more"
          @click="load(games.page + 1)"
        >
          Seguinte
        </button>
      </div>
    </template>
  </section>
</template>
