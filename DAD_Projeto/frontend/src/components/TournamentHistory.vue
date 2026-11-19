<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { type Tournament, tournamentStatus } from '@/lib/tournament'
type History = {
  stats: {
    created: number
    participated: number
    registered: number
    podiums: number
    points: number
  }
  data: (Tournament & { created_by_me: boolean; participated: boolean; prize: number })[]
  meta: { current_page: number; last_page: number; total: number }
}
const history = ref<History | null>(null),
  error = ref(''),
  busy = ref(false)
async function load(page = 1) {
  busy.value = true
  error.value = ''
  try {
    history.value = await api<History>(`/api/account/tournaments?page=${page}`)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Não foi possível carregar os torneios.'
  } finally {
    busy.value = false
  }
}
onMounted(() => void load())
</script>
<template>
  <section class="tournament-history">
    <div class="card-heading">
      <div>
        <h2>O teu percurso nos torneios</h2>
        <p>Participações, torneios criados e pódios conquistados.</p>
      </div>
      <RouterLink to="/torneios" class="button-secondary">Explorar torneios ↗</RouterLink>
    </div>
    <p v-if="error" class="alert error" role="alert">
      {{ error }} <button class="inline-link" @click="load()">Tentar novamente</button>
    </p>
    <template v-if="history"
      ><div class="player-stats-grid tournament-profile-stats">
        <div
          v-for="(label, key) in {
            participated: 'Participações',
            created: 'Criados',
            registered: 'Inscrições abertas',
            podiums: 'Pódios',
            points: 'Pontos de rank',
          }"
          :key="key"
          class="surface"
        >
          <span>{{ label }}</span
          ><strong>{{ history.stats[key] }}</strong>
        </div>
      </div>
      <p class="player-stats-note">
        A participação conta a partir do início. Inscrições retiradas não contam; os torneios
        cancelados continuam no registo de quem os criou.
      </p>
      <div class="surface tournament-history-list">
        <p v-if="!history.data.length">Ainda não criaste nem participaste em torneios.</p>
        <RouterLink v-for="t in history.data" :key="t.id" :to="`/torneios/${t.id}`"
          ><div>
            <strong>{{ t.name }}</strong>
            <p>
              {{ tournamentStatus[t.status] }} ·
              {{ t.created_by_me ? 'Criado por ti' : 'Participante' }} ·
              {{ new Date(t.created_at).toLocaleDateString('pt-PT') }}
            </p>
          </div>
          <b>{{ t.prize ? `+${t.prize} pts` : 'Ver torneio ↗' }}</b></RouterLink
        >
      </div>
      <div v-if="history.meta.last_page > 1" class="player-history-pages">
        <button
          class="button-small"
          :disabled="busy || history.meta.current_page === 1"
          @click="load(history.meta.current_page - 1)"
        >
          Anterior</button
        ><span>{{ history.meta.current_page }} / {{ history.meta.last_page }}</span
        ><button
          class="button-small"
          :disabled="busy || history.meta.current_page === history.meta.last_page"
          @click="load(history.meta.current_page + 1)"
        >
          Seguinte
        </button>
      </div>
    </template>
  </section>
</template>
