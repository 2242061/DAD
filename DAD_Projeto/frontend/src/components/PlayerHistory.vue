<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import RankSummary from '@/components/RankSummary.vue'
import type { Ranking } from '@/lib/rank'
defineProps<{ detailed: boolean }>()
type RecordGame = {
  id: string
  code: string
  color: string
  opponent: string
  mode: 'casual' | 'ranked' | 'tournament'
  rank_delta: number | null
  result: string
  reason: string | null
  started_at: string | null
  duration_seconds: number | null
  moves: number
  history: { notation: string; color: string; type?: string }[]
}
type Response = {
  ranking: Ranking
  data: RecordGame[]
  stats: Record<string, number>
  meta: { current_page: number; last_page: number; total: number }
}
const response = ref<Response | null>(null)
const loading = ref(false),
  error = ref(''),
  selected = ref<string | null>(null)
const labels: Record<string, string> = {
  games: 'Jogos',
  wins: 'Vitórias',
  draws: 'Empates',
  losses: 'Derrotas',
  active: 'Em curso',
  finished: 'Terminados',
}
const results: Record<string, string> = {
  win: 'Vitória',
  draw: 'Empate',
  loss: 'Derrota',
  active: 'Em curso',
}
const reasons: Record<string, string> = {
  capture: 'Captura de todas as peças',
  blocked: 'Adversário sem jogadas',
  resignation: 'Desistência',
  timeout: 'Tempo esgotado',
  tournament_time: 'Limite de tempo do torneio',
  threefold: 'Repetição tripla',
  twenty_moves: 'Regra dos 20 lances',
  forced_twelve: 'Limite da forçada',
}
function duration(seconds: number | null) {
  if (seconds === null) return '—'
  return `${Math.floor(seconds / 60)} min ${seconds % 60} s`
}
function date(value: string | null) {
  return value
    ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' }).format(
        new Date(value),
      )
    : '—'
}
async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    response.value = await api<Response>(`/api/account/games?page=${page}`)
    selected.value = null
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Não foi possível carregar os jogos.'
  } finally {
    loading.value = false
  }
}
onMounted(() => void load())
</script>
<template>
  <section
    class="player-results"
    aria-label="Estatísticas e histórico do jogador"
    :aria-busy="loading"
  >
    <p v-if="error" class="alert error" role="alert">
      {{ error }} <button class="inline-link" @click="load()">Tentar novamente</button>
    </p>
    <p v-if="!response && loading" role="status">A carregar os teus jogos…</p>
    <RankSummary v-if="response?.ranking" :ranking="response.ranking" />
    <div v-if="response" class="player-stats">
      <div
        v-for="key in ['games', 'wins', 'draws', 'losses', 'active', 'finished']"
        :key="key"
        class="surface"
        :class="key"
      >
        <span>{{ labels[key] }}</span
        ><strong>{{ response.stats[key] }}</strong>
      </div>
    </div>
    <p class="player-stats-note">
      Totais das partidas casuais, competitivas e de torneios desta conta. O rank usa resultados
      competitivos e prémios de pódio. Partidas locais e jogos como visitante não entram nestes
      totais.
    </p>
    <section v-if="detailed && response" class="surface player-game-list">
      <div class="card-heading">
        <div>
          <h2>O teu percurso no tabuleiro</h2>
          <p>
            {{ response.meta.total }} {{ response.meta.total === 1 ? 'partida' : 'partidas' }} ·
            resultados guardados na tua conta
          </p>
        </div>
        <button class="button-small" :disabled="loading" @click="load(response.meta.current_page)">
          Atualizar
        </button>
      </div>
      <p v-if="!response.data.length" class="practice-empty">
        Ainda não tens partidas registadas. Entra numa sala online com esta conta para começares o
        teu histórico.
      </p>
      <article v-for="game in response.data" :key="game.id" class="player-game-row">
        <div class="player-game-summary">
          <span class="player-result-badge" :class="game.result">{{ results[game.result] }}</span>
          <div>
            <strong
              >contra {{ game.opponent }}
              <span class="history-mode">{{
                game.mode === 'tournament'
                  ? 'Torneio'
                  : game.mode === 'ranked'
                    ? 'Competitivo'
                    : 'Casual'
              }}</span></strong
            ><small
              >{{ date(game.started_at) }} · {{ game.color === 'white' ? 'Brancas' : 'Pretas' }} ·
              Sala {{ game.code }}</small
            >
          </div>
          <div class="player-game-duration">
            <strong>{{ duration(game.duration_seconds) }}</strong
            ><small
              >{{ game.moves }} {{ game.moves === 1 ? 'lance' : 'lances' }}
              <span v-if="game.rank_delta !== null">
                · {{ game.rank_delta > 0 ? '+' : '' }}{{ game.rank_delta }} pts</span
              ></small
            >
          </div>
          <button
            class="button-text"
            :aria-expanded="selected === game.id"
            @click="selected = selected === game.id ? null : game.id"
          >
            {{ selected === game.id ? 'Fechar' : 'Ver lances' }}
          </button>
        </div>
        <div v-if="selected === game.id" class="player-game-detail">
          <p>{{ game.reason ? (reasons[game.reason] ?? game.reason) : 'Partida em curso' }}</p>
          <ol v-if="game.history.length">
            <li v-for="(move, index) in game.history" :key="index">
              {{ move.color === 'white' ? 'Brancas' : 'Pretas' }} · {{ move.notation }}
            </li>
          </ol>
          <p v-else>Ainda não foram registados lances.</p>
        </div>
      </article>
      <div v-if="response.meta.last_page > 1" class="player-history-pages">
        <button
          class="button-small"
          :disabled="loading || response.meta.current_page <= 1"
          @click="load(response.meta.current_page - 1)"
        >
          Anterior</button
        ><span>Página {{ response.meta.current_page }} de {{ response.meta.last_page }}</span
        ><button
          class="button-small"
          :disabled="loading || response.meta.current_page >= response.meta.last_page"
          @click="load(response.meta.current_page + 1)"
        >
          Seguinte
        </button>
      </div>
    </section>
  </section>
</template>
