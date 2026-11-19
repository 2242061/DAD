<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import type { CurrentTier, Tier } from '@/lib/rank'
type Board = {
  data: { id: number; name: string; points: number; tier: CurrentTier; position: number }[]
  tiers: Tier[]
  rules: { win: number; loss: number; draw: number }
  meta: { current_page: number; last_page: number; total: number }
}
const board = ref<Board | null>(null),
  loading = ref(false),
  error = ref('')
async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    board.value = await api<Board>(`/api/ranking?page=${page}`)
  } catch (cause) {
    error.value =
      cause instanceof Error ? cause.message : 'Não foi possível carregar a classificação.'
  } finally {
    loading.value = false
  }
}
onMounted(() => void load())
</script>
<template>
  <section class="page-content ranking-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">CADA VITÓRIA CONTA</p>
        <h1>A tua próxima conquista<span class="green-dot">.</span></h1>
        <p>De Novato a Imortal. Dez escalões para conquistar no competitivo.</p>
      </div>
      <RouterLink to="/jogar/online?mode=ranked" class="button-primary"
        >Jogar competitivo ↗</RouterLink
      >
    </div>
    <div class="rank-rules">
      <span><strong>+30</strong> vitória</span><span><strong>−15</strong> derrota</span
      ><span><strong>0</strong> empate</span>
      <p>
        Começas com 0 pontos. Podes subir e descer de escalão. As partidas competitivas e os prémios
        de pódio dos torneios alteram estes pontos.
      </p>
    </div>
    <div v-if="error" class="alert error" role="alert">
      {{ error }} <button class="inline-link" @click="load()">Tentar novamente</button>
    </div>
    <p v-if="loading && !board" role="status">A carregar a classificação…</p>
    <template v-if="board"
      ><section class="surface rank-leaderboard">
        <div class="card-heading">
          <div>
            <h2>Classificação competitiva</h2>
            <p>{{ board.meta.total }} jogadores com resultados competitivos ou pódios</p>
          </div>
          <button class="button-small" :disabled="loading" @click="load(board.meta.current_page)">
            Atualizar
          </button>
        </div>
        <p v-if="!board.data.length" class="practice-empty">
          A classificação está à espera das primeiras partidas competitivas. O teu primeiro
          resultado pode começar esta história.
        </p>
        <ol v-else>
          <li v-for="player in board.data" :key="player.id">
            <span class="rank-position">{{ player.position.toString().padStart(2, '0') }}</span
            ><strong>{{ player.name }}</strong
            ><span class="rank-tier-label">{{ player.tier.name }}</span
            ><b>{{ player.points }} <small>pts</small></b>
          </li>
        </ol>
        <div v-if="board.meta.last_page > 1" class="player-history-pages">
          <button
            class="button-small"
            :disabled="loading || board.meta.current_page === 1"
            @click="load(board.meta.current_page - 1)"
          >
            Anterior</button
          ><span>Página {{ board.meta.current_page }} de {{ board.meta.last_page }}</span
          ><button
            class="button-small"
            :disabled="loading || board.meta.current_page === board.meta.last_page"
            @click="load(board.meta.current_page + 1)"
          >
            Seguinte
          </button>
        </div>
      </section>
      <section class="rank-tier-section">
        <p class="eyebrow">O CAMINHO ATÉ AO TOPO</p>
        <h2>Os 10 escalões</h2>
        <div class="rank-tier-grid">
          <article v-for="tier in board.tiers" :key="tier.level">
            <span class="rank-tier-number">{{ tier.level.toString().padStart(2, '0') }}</span>
            <h3>{{ tier.name }}</h3>
            <p>
              {{ tier.minimum
              }}{{ tier.level === 10 ? '+' : `–${(board.tiers[tier.level]?.minimum ?? 1) - 1}` }}
              pontos
            </p>
          </article>
        </div>
        <p class="player-stats-note">
          A relação vitórias/derrotas é informativa. O escalão depende dos pontos; os pódios dos
          torneios valem +150, +100 e +60. As partidas casuais não entram nesta classificação.
        </p>
      </section></template
    >
  </section>
</template>
