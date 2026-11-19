<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/composables/useAuth'
import FormField from '@/components/FormField.vue'
import {
  type Tournament,
  type TournamentDetail,
  tournamentStatus,
  tierNames,
} from '@/lib/tournament'
const auth = useAuth(),
  route = useRoute(),
  router = useRouter()
const admin = computed(
  () => route.name === 'admin-tournaments' || route.name === 'admin-tournament',
)
const moderationPassword = ref('')
const commissionLoaded = ref(false)
const detail = ref<TournamentDetail | null>(null)
const list = ref<Tournament[]>([]),
  page = ref(1),
  lastPage = ref(1),
  total = ref(0)
const status = ref(''),
  error = ref(''),
  message = ref(''),
  busy = ref(false),
  showCreate = ref(false)
const form = ref({
  name: '',
  entry_fee: 100,
  commission_percent: 10,
  capacity: 4,
  min_rank: 1,
  max_rank: 10,
  round_minutes: 10,
  game_minutes: 8,
})
const projected = computed(() => {
  const gross = form.value.capacity * form.value.entry_fee
  const commission = Math.floor((gross * form.value.commission_percent) / 100)
  const pool = gross - commission
  const second = Math.floor(pool * 0.3)
  const third = Math.floor(pool * 0.2)
  return { commission, pool, prizes: [pool - second - third, second, third] }
})
const now = ref(Date.now())
let poll: ReturnType<typeof setInterval> | undefined,
  ticker: ReturnType<typeof setInterval> | undefined
let anchor = 0,
  localAnchor = 0,
  disposed = false,
  loading = false
const rounds = computed(() =>
  detail.value
    ? detail.value.capacity % 2
      ? detail.value.capacity
      : detail.value.capacity - 1
    : 0,
)
const remaining = computed(() => {
  if (!detail.value?.round_deadline_at) return ''
  const n = Math.max(0, Math.ceil((Date.parse(detail.value.round_deadline_at) - now.value) / 1000))
  return `${Math.floor(n / 60)
    .toString()
    .padStart(2, '0')}:${(n % 60).toString().padStart(2, '0')}`
})
async function load() {
  if (loading) return
  loading = true
  try {
    if (route.params.id) {
      const response = await api<{ data: TournamentDetail }>(`/api/tournaments/${route.params.id}`)
      if (disposed) return
      detail.value = response.data
      anchor = response.data.server_now
      localAnchor = performance.now()
      now.value = anchor
    } else {
      const response = await api<{
        data: Tournament[]
        default_commission_percent: number
        meta: { last_page: number; total: number }
      }>(
        `${admin.value ? '/api/admin/tournaments' : '/api/tournaments'}?page=${page.value}${status.value ? `&status=${status.value}` : ''}`,
      )
      if (disposed) return
      list.value = response.data
      if (!commissionLoaded.value) {
        if (!showCreate.value) form.value.commission_percent = response.default_commission_percent
        commissionLoaded.value = true
      }
      lastPage.value = response.meta.last_page
      total.value = response.meta.total
    }
  } catch (cause) {
    if (!disposed)
      error.value =
        cause instanceof Error ? cause.message : 'Não foi possível carregar os torneios.'
  } finally {
    loading = false
  }
}
async function create() {
  busy.value = true
  error.value = ''
  try {
    const response = await api<{ data: Tournament }>('/api/tournaments', {
      method: 'POST',
      body: JSON.stringify(form.value),
    })
    await router.push(`/torneios/${response.data.id}`)
  } catch (cause) {
    fail(cause)
  } finally {
    busy.value = false
  }
}
function fail(cause: unknown) {
  error.value =
    cause instanceof ApiError && Object.keys(cause.errors).length
      ? Object.values(cause.errors).flat().join(' ')
      : cause instanceof Error
        ? cause.message
        : 'Não foi possível concluir.'
}
async function action(name: string) {
  if (!detail.value || busy.value) return
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const moderation = admin.value && (name === 'start' || name === 'cancel')
    const response = await api<{ data: TournamentDetail }>(
      `/api/${moderation ? 'admin/' : ''}tournaments/${detail.value.id}/${name}`,
      {
        method: 'POST',
        ...(moderation
          ? { body: JSON.stringify({ current_password: moderationPassword.value }) }
          : {}),
      },
    )
    detail.value = response.data
    moderationPassword.value = ''
    message.value =
      {
        join: 'Inscrição confirmada.',
        leave: 'Inscrição retirada.',
        start: 'Torneio iniciado. Boa sorte!',
        cancel: 'Torneio cancelado.',
      }[name] ?? ''
  } catch (cause) {
    fail(cause)
  } finally {
    busy.value = false
  }
}
async function retry() {
  error.value = ''
  await load()
}
async function filter() {
  page.value = 1
  await load()
}
async function changePage(delta: number) {
  page.value += delta
  await load()
}
onMounted(() => {
  void load()
  poll = setInterval(() => void load(), 5000)
  ticker = setInterval(
    () => (now.value = anchor ? anchor + performance.now() - localAnchor : Date.now()),
    500,
  )
})
onBeforeUnmount(() => {
  disposed = true
  clearInterval(poll)
  clearInterval(ticker)
})
</script>
<template>
  <section class="page-content tournament-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">O CLUBE ENTRA EM COMPETIÇÃO</p>
        <h1>
          {{ detail ? detail.name : admin ? 'Gestão de torneios' : 'Torneios'
          }}<span class="green-dot">.</span>
        </h1>
        <p>
          {{
            admin
              ? 'Consulta todos os torneios, desde as inscrições ao pódio final.'
              : 'Uma liga, várias rondas e três lugares no pódio.'
          }}
        </p>
      </div>
      <RouterLink
        v-if="detail"
        :to="admin ? '/admin/torneios' : '/torneios'"
        class="button-secondary"
        >Todos os torneios</RouterLink
      >
      <button v-else-if="!admin" class="button-primary" @click="showCreate = !showCreate">
        {{ showCreate ? 'Fechar formulário' : 'Criar torneio +' }}
      </button>
    </div>
    <div v-if="error" role="alert" class="alert error">
      {{ error }}
      <button class="inline-link" @click="retry">Tentar novamente</button>
    </div>
    <p v-if="message" role="status" class="alert success">{{ message }}</p>
    <template v-if="!route.params.id">
      <form v-if="showCreate" class="surface tournament-form" @submit.prevent="create">
        <h2>Preparar o torneio</h2>
        <p>
          4 a 16 participantes. O criador pode inscrever-se, mas não ocupa uma vaga automaticamente.
        </p>
        <label class="tournament-field tournament-wide"
          >Nome<input
            v-model="form.name"
            required
            minlength="3"
            maxlength="80"
            placeholder="Ex.: Liga de novembro"
        /></label>
        <label class="tournament-field"
          >Participantes<input
            v-model.number="form.capacity"
            type="number"
            min="4"
            max="16"
            required
        /></label>
        <label class="tournament-field"
          >Duração da ronda (min)<input
            v-model.number="form.round_minutes"
            type="number"
            min="1"
            max="120"
            required
        /></label>
        <label class="tournament-field"
          >Duração máxima do jogo (min)<input
            v-model.number="form.game_minutes"
            type="number"
            min="1"
            :max="form.round_minutes"
            required
        /></label>
        <label class="tournament-field"
          >Escalão mínimo<select v-model.number="form.min_rank">
            <option v-for="(name, i) in tierNames" :key="name" :value="i + 1">{{ name }}</option>
          </select></label
        >
        <label class="tournament-field"
          >Escalão máximo<select v-model.number="form.max_rank">
            <option v-for="(name, i) in tierNames" :key="name" :value="i + 1">{{ name }}</option>
          </select></label
        >
        <label class="tournament-field"
          >Inscrição (Damas)<input
            v-model.number="form.entry_fee"
            type="number"
            min="1"
            max="10000"
            required
        /></label>
        <label class="tournament-field"
          >Comissão da plataforma (%)<input
            v-model.number="form.commission_percent"
            type="number"
            min="0"
            max="30"
            required
        /></label>
        <p class="tournament-wide">
          Com todas as vagas preenchidas, a plataforma recebe {{ projected.commission }} DM e o
          pódio divide {{ projected.pool }} DM: {{ projected.prizes.join(' / ') }} DM.
        </p>
        <p class="tournament-wide">
          O tempo do jogo inclui todos os lances e não pode ultrapassar a ronda. Ao atingir o
          limite, um jogo ainda em curso termina empatado. Cada vez mantém 60 segundos.
        </p>
        <button class="button-primary" :disabled="busy">
          {{ busy ? 'A criar…' : 'Publicar torneio' }}
        </button>
      </form>
      <div class="tournament-toolbar">
        <label
          >Estado
          <select v-model="status" @change="filter">
            <option value="">Todos</option>
            <option v-for="(label, key) in tournamentStatus" :key="key" :value="key">
              {{ label }}
            </option>
          </select></label
        ><span>{{ total }} torneios</span>
      </div>
      <div class="tournament-grid">
        <RouterLink
          v-for="t in list"
          :key="t.id"
          :to="`${admin ? '/admin' : ''}/torneios/${t.id}`"
          class="surface tournament-card"
          ><span class="rank-tier-label">{{ tournamentStatus[t.status] }}</span>
          <h2>{{ t.name }}</h2>
          <p>Criado por {{ t.creator }}</p>
          <p>
            {{
              t.entry_fee
                ? `Inscrição: ${t.entry_fee} DM · Prémios: ${t.finances.pool} DM`
                : 'Inscrição gratuita'
            }}
          </p>
          <div class="tournament-card-stats">
            <strong>{{ t.registered }}/{{ t.capacity }}<small>participantes</small></strong
            ><strong>{{ t.round_minutes }} min<small>por ronda</small></strong
            ><strong>{{ t.game_minutes }} min<small>por jogo</small></strong>
          </div>
          <p>{{ tierNames[t.min_rank - 1] }} → {{ tierNames[t.max_rank - 1] }}</p>
          <span class="inline-link">Ver torneio ↗</span></RouterLink
        >
      </div>
      <p v-if="!list.length" class="surface practice-empty">Ainda não há torneios neste estado.</p>
      <div v-if="lastPage > 1" class="player-history-pages">
        <button class="button-small" :disabled="page === 1" @click="changePage(-1)">Anterior</button
        ><span>{{ page }} / {{ lastPage }}</span
        ><button class="button-small" :disabled="page === lastPage" @click="changePage(1)">
          Seguinte
        </button>
      </div>
    </template>
    <template v-else-if="detail">
      <div v-if="detail.status === 'finished'" class="game-result-banner" role="status">
        <span class="eyebrow">TORNEIO TERMINADO</span>
        <h2>
          {{
            detail.my_award
              ? `Conquistaste o pódio! +${detail.my_award} pontos de rank`
              : 'O pódio está decidido.'
          }}
        </h2>
        <p>Consulta a classificação final. Só os três primeiros recebem pontos de rank.</p>
      </div>
      <section class="surface tournament-overview">
        <div>
          <span class="rank-tier-label">{{ tournamentStatus[detail.status] }}</span>
          <h2>
            {{
              detail.status === 'active'
                ? `Ronda ${detail.current_round} de ${rounds}`
                : `${detail.registered} de ${detail.capacity} inscritos`
            }}
          </h2>
          <p v-if="detail.status === 'active'">
            Próxima ronda ou fim do torneio em <strong>{{ remaining }}</strong>
          </p>
          <p>
            {{ detail.round_minutes }} min por ronda · {{ detail.game_minutes }} min por jogo ·
            {{ tierNames[detail.min_rank - 1] }} a {{ tierNames[detail.max_rank - 1] }}
          </p>
          <p v-if="detail.entry_fee">
            Inscrição {{ detail.entry_fee }} DM · Comissão {{ detail.commission_percent }}% ({{
              detail.finances.commission
            }}
            DM)
          </p>
          <p v-if="detail.entry_fee">
            Prémios do pódio: {{ detail.finances.prizes.join(' / ') }} DM · Total
            {{ detail.finances.pool }} DM
          </p>
          <p>
            Criado por {{ detail.creator }} ·
            {{ new Date(detail.created_at).toLocaleDateString('pt-PT') }}
          </p>
        </div>
        <div class="tournament-actions" v-if="detail.status === 'registration'">
          <button
            v-if="!admin && !detail.joined"
            class="button-primary"
            :disabled="busy || detail.registered >= detail.capacity"
            @click="action('join')"
          >
            Inscrever-me{{ detail.entry_fee ? ` · ${detail.entry_fee} DM` : '' }}</button
          ><button
            v-else-if="!admin"
            class="button-secondary"
            :disabled="busy"
            @click="action('leave')"
          >
            Retirar inscrição</button
          ><template v-if="admin || detail.creator_id === auth.user.value?.id"
            ><FormField
              v-if="admin"
              v-model="moderationPassword"
              label="Palavra-passe de administrador"
              type="password"
              autocomplete="current-password"
            />
            <button
              class="button-primary"
              :disabled="
                busy || detail.registered !== detail.capacity || (admin && !moderationPassword)
              "
              @click="action('start')"
            >
              Iniciar torneio
            </button>
            <details>
              <summary>Cancelar torneio</summary>
              <p>As inscrições são reembolsadas e o registo é conservado.</p>
              <button
                class="button-small"
                :disabled="busy || (admin && !moderationPassword)"
                @click="action('cancel')"
              >
                Confirmar cancelamento
              </button>
            </details></template
          >
        </div>
      </section>
      <section v-if="detail.status === 'finished'" class="tournament-podium">
        <article v-for="p in detail.standings.slice(0, 3)" :key="p.user_id" class="surface">
          <span>{{ p.position }}.º LUGAR</span>
          <h2>{{ p.name }}</h2>
          <strong>+{{ p.prize }} pts de rank</strong>
          <p v-if="p.coin_prize">+{{ p.coin_prize }} Damas</p>
          <p>{{ p.score }} pontos no torneio</p>
        </article>
      </section>
      <section class="surface tournament-standings">
        <h2>
          {{
            detail.status === 'registration'
              ? 'Participantes inscritos'
              : 'Classificação do torneio'
          }}
        </h2>
        <div class="tournament-table-wrap">
          <table>
            <thead>
              <tr>
                <th>Lugar</th>
                <th>Jogador</th>
                <th>Pts</th>
                <th>V</th>
                <th>E</th>
                <th>D</th>
                <th>Jogos</th>
                <th>Prémio</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in detail.standings" :key="p.user_id">
                <td>{{ detail.status === 'registration' ? '—' : p.position }}</td>
                <th>{{ p.name }} <small v-if="p.user_id === auth.user.value?.id">(tu)</small></th>
                <td>{{ p.score }}</td>
                <td>{{ p.wins }}</td>
                <td>{{ p.draws }}</td>
                <td>{{ p.losses }}</td>
                <td>{{ p.played }}</td>
                <td>{{ p.prize ? `+${p.prize}` : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="!detail.standings.length">As primeiras inscrições vão aparecer aqui.</p>
      </section>
      <section v-if="detail.games.length" class="tournament-matches">
        <h2>Jogos por ronda</h2>
        <article v-for="g in detail.games" :key="g.code" class="surface tournament-match">
          <span>Ronda {{ g.round }}</span>
          <div>
            <strong>{{ g.white }} <small>vs</small> {{ g.black }}</strong>
            <p>
              {{
                g.phase === 'active'
                  ? 'Em jogo'
                  : g.winner
                    ? `${g.winner === 'white' ? g.white : g.black} venceu`
                    : 'Empate'
              }}
            </p>
          </div>
          <RouterLink
            v-if="g.mine"
            :to="`/jogar/online/${g.code}`"
            :class="g.phase === 'active' ? 'button-primary' : 'button-small'"
            >{{ g.phase === 'active' ? 'Jogar agora ↗' : 'Ver partida' }}</RouterLink
          >
          <RouterLink v-else :to="`/assistir/${g.code}`" class="button-small"
            >Assistir ↗</RouterLink
          >
        </article>
      </section>
    </template>
    <section class="surface tournament-rules">
      <h2>Como funciona</h2>
      <p>
        Todos contra todos, uma partida por par. Com um número ímpar, cada pessoa tem uma ronda de
        descanso. O criador inicia quando todas as vagas estiverem preenchidas. As rondas avançam
        pelo horário, mesmo que os jogos terminem antes.
      </p>
      <p>
        Vitória: 3 pontos no torneio. Empate: 1. Derrota: 0. Desempates por mais vitórias, mais
        pontos de rank à inscrição e, por fim, inscrição mais antiga. O limite de jogo determina
        empate se ainda não houver resultado.
      </p>
      <p>
        <strong>Pódio: 1.º +150 · 2.º +100 · 3.º +60 pontos de rank.</strong> As partidas do torneio
        não atribuem os +30/−15 habituais. O preço da inscrição e a comissão são definidos ao criar
        o torneio. O pódio divide o fundo após a comissão em 50%, 30% e 20%. Retirar a inscrição ou
        cancelar antes do início devolve o valor pago.
      </p>
    </section>
  </section>
</template>
