<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { io, type Socket } from 'socket.io-client'
import { useAuth } from '@/composables/useAuth'

type Move = { path: number[]; captures: number[]; kings: number }
type Turn = 'white' | 'black'
type Game = {
  players?: Record<Turn, string>
  mode?: 'casual' | 'ranked' | 'tournament'
  discoverable?: boolean
  wallet_reward?: number
  entry_fee?: number
  tournament_id?: number | null
  rank_change?: { delta: number; before: number; after: number; tier: { name: string } } | null
  id: string
  clock?: {
    server_now: number
    started_at: number | null
    deadline_at: number | null
    finished_at: number | null
    turn_seconds: number
    timeout_rule: string
    game_deadline_at?: number | null
  }
  code?: string
  phase?: 'waiting' | 'active' | 'finished'
  my_color?: Turn
  revision: number
  board: (string | null)[]
  turn: Turn
  status: 'active' | 'finished'
  winner: Turn | null
  reason: string | null
  draw_offer?: Turn
  legal_moves: Move[]
  history: {
    color: Turn
    type?: string
    notation: string
    path: number[]
    captures: number[]
    promoted: boolean
  }[]
  quiet: Record<Turn, number>
  forced: { color: Turn; moves: number } | null
}
const route = useRoute()
const router = useRouter()
const training = route.name === 'training'
const practiceApi = training ? '/api/practice?mode=bot' : '/api/practice'
const watching = route.name === 'spectator'
const online = route.name === 'online' || watching
const gameApi = watching ? '/api/spectate' : '/api/games'
const auth = useAuth()
const selectedMode = ref<'casual' | 'ranked'>(route.query.mode === 'ranked' ? 'ranked' : 'casual')
const loginTarget = computed(() => ({
  path: '/entrar',
  query: { redirect: roomCode ? `/jogar/online/${roomCode}` : '/jogar/online?mode=ranked' },
}))
const roomCode = String(route.params.code ?? '').toUpperCase()
const joinCode = ref(roomCode)
type OpenRoom = { code: string; mode: 'ranked' | 'casual'; host: string; entry_fee: number }
type RoomPage = { ranked_entry_fee: number; data: OpenRoom[]; page: number; has_more: boolean }
const discoverable = ref(true)
const rooms = ref<RoomPage>()
const roomsLoading = ref(false)
const roomsError = ref('')
let roomsTimer: ReturnType<typeof setInterval> | undefined
const connected = ref(false)
const copied = ref(false)
let socket: Socket | undefined
let polling: ReturnType<typeof setInterval> | undefined
let disposed = false
let ticker: ReturnType<typeof setInterval> | undefined
const clockNow = ref(0)
let serverAnchor = 0,
  localAnchor = 0,
  nextClockSync = 0
function timeLabel(seconds: number) {
  const n = Math.max(0, Math.floor(seconds))
  return `${Math.floor(n / 60)
    .toString()
    .padStart(2, '0')}:${(n % 60).toString().padStart(2, '0')}`
}
function playerTime(color: Turn) {
  if (!game.value?.clock || game.value.phase === 'waiting') return '—:—'
  if (game.value.status === 'finished') return '—:—'
  return timeLabel(
    game.value.turn === color
      ? Math.ceil(((game.value.clock.deadline_at ?? clockNow.value) - clockNow.value) / 1000)
      : game.value.clock.turn_seconds,
  )
}
const elapsedTime = computed(() => {
  const c = game.value?.clock
  return c?.started_at
    ? timeLabel(((c.finished_at ?? clockNow.value) - c.started_at) / 1000)
    : '00:00'
})
let requestVersion = 0
const game = ref<Game | null>(null)
const canPlay = computed(
  () =>
    !watching &&
    !!game.value &&
    game.value.status === 'active' &&
    (!online || (game.value.phase === 'active' && game.value.my_color === game.value.turn)),
)
const inviteUrl = computed(
  () => `${window.location.origin}/#/jogar/online/${game.value?.code ?? ''}`,
)

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const path = ref<number[]>([])
const confirm = ref<'restart' | 'resign' | null>(null)
const flipped = ref(false)
const colorName = (color: Turn) => (color === 'white' ? 'Brancas' : 'Pretas')
const topColor = computed<Turn>(() => (flipped.value ? 'white' : 'black'))
const bottomColor = computed<Turn>(() => (flipped.value ? 'black' : 'white'))
function playerLabel(color: Turn) {
  if (watching) return game.value?.players?.[color] ?? ''
  if (training) return color === 'white' ? 'Tu' : 'Bot de treino'
  if (!online) return color === 'white' ? 'Jogador 1 · começa a partida' : 'Jogador 2'
  if (game.value?.my_color === color) return 'Tu'
  return game.value?.phase === 'waiting' ? 'À espera de jogador' : 'Adversário'
}
function pieceCount(color: Turn) {
  return game.value
    ? game.value.board.filter((piece) => piece?.toLowerCase() === (color === 'white' ? 'w' : 'b'))
        .length
    : 12
}

const candidates = computed(
  () =>
    game.value?.legal_moves.filter((move) => path.value.every((s, i) => move.path[i] === s)) ?? [],
)
const destinations = computed(() =>
  path.value.length
    ? [
        ...new Set(
          candidates.value
            .map((m) => m.path[path.value.length])
            .filter((s): s is number => s !== undefined),
        ),
      ]
    : [],
)
const origins = computed(() => [...new Set(game.value?.legal_moves.map((m) => m.path[0]) ?? [])])
const capturesRequired = computed(() => (game.value?.legal_moves[0]?.captures.length ?? 0) > 0)
const latest = computed(() => game.value?.history.at(-1)?.path ?? [])
const preview = computed(() => {
  const board = [...(game.value?.board ?? Array(32).fill(null))]
  if (path.value.length > 1) {
    const from = path.value[0]!
    const piece = board[from - 1]
    board[from - 1] = null
    board[path.value.at(-1)! - 1] = piece ?? null
  }
  return board
})
const cells = computed(() =>
  Array.from({ length: 64 }, (_, index) => {
    const row = Math.floor(index / 8),
      col = index % 8
    const square = (row + col) % 2 === 0 ? (7 - row) * 4 + Math.floor((7 - col) / 2) + 1 : null
    return { index, square }
  }),
)
const displayCells = computed(() => (flipped.value ? [...cells.value].reverse() : cells.value))
const result = computed(() => {
  if (!game.value || game.value.status !== 'finished') return ''
  const reasons: Record<string, string> = {
    tournament_time: 'O tempo máximo desta partida do torneio terminou.',
    capture: 'Todas as peças adversárias foram capturadas.',
    blocked: 'O adversário ficou sem jogadas legais.',
    agreement: 'Os dois jogadores aceitaram o empate.',
    abandonment: 'A partida terminou por abandono após uma ausência prolongada.',
    timeout: 'O jogador que tinha a vez ficou sem tempo.',
    dead_position: 'Nenhuma das cores consegue forçar uma vitória neste final.',
    resignation: 'A partida terminou por desistência.',
    cancelled: 'A sala foi encerrada antes de começar.',
    threefold: 'A mesma posição repetiu-se três vezes, com a mesma cor a jogar.',
    twenty_moves: 'Cada cor completou 20 lances sem captura nem movimento de peão neste final.',
    forced_twelve: 'Terminou o limite de 12 lances da forçada.',
  }
  return reasons[game.value.reason ?? ''] ?? ''
})
const heading = computed(() =>
  !game.value
    ? 'A primeira jogada é tua.'
    : game.value.reason === 'cancelled'
      ? 'Sala encerrada'
      : game.value.status === 'finished'
        ? game.value.winner
          ? (online && game.value.my_color) || training
            ? game.value.winner === (training ? 'white' : game.value.my_color)
              ? 'Ganhaste!'
              : 'Perdeste esta partida'
            : `${colorName(game.value.winner)} venceram!`
          : 'Partida empatada'
        : online && game.value.phase === 'waiting'
          ? 'À espera de companhia'
          : `${colorName(game.value.turn)} a jogar`,
)
const instruction = computed(() => {
  if (!game.value)
    return online
      ? 'Cria uma sala ou entra com o código de um convite.'
      : training
        ? 'Joga com as brancas. O bot responde com as pretas.'
        : 'Começa uma partida e partilha o tabuleiro com outra pessoa.'
  if (watching)
    return game.value.status === 'finished'
      ? result.value
      : 'Os lances aparecem à medida que são jogados.'
  if (online && game.value.phase === 'waiting')
    return game.value.discoverable
      ? 'A partida começa quando alguém entrar pela lista ou pelo convite.'
      : 'Partilha o convite. A partida começa quando a outra pessoa entrar.'
  if (online && game.value.status === 'active' && !canPlay.value)
    return 'É a vez do teu adversário. O tabuleiro será atualizado automaticamente.'
  if (game.value.status === 'finished') return result.value
  if (busy.value) return 'A validar a jogada…'
  if (path.value.length > 1) return 'Continua a captura: escolhe o próximo destino assinalado.'
  if (path.value.length) return 'Escolhe um dos destinos assinalados.'
  return capturesRequired.value
    ? 'Captura obrigatória. Seleciona uma das peças assinaladas.'
    : 'Seleciona uma peça para veres os destinos legais.'
})
function squareLabel(square: number) {
  const piece = preview.value[square - 1]
  const description = piece
    ? `${piece === piece.toUpperCase() ? 'dama' : 'peão'} ${piece.toLowerCase() === 'w' ? 'branco' : 'preto'}`
    : 'vazia'
  return `Casa ${square}, ${description}${destinations.value.includes(square) ? ', destino legal' : ''}`
}
function accept(next: Game | null) {
  if (disposed) return
  if (next && game.value && next.id === game.value.id && next.revision < game.value.revision) return
  const changed = next?.id !== game.value?.id || next?.revision !== game.value?.revision
  if (online && !game.value && next?.my_color === 'black') flipped.value = true
  game.value = next
  if (next?.clock) {
    serverAnchor = next.clock.server_now
    localAnchor = performance.now()
    clockNow.value = serverAnchor
  }
  if (changed) {
    path.value = []
    confirm.value = null
  }
}
async function load(silent = false) {
  if (online && !roomCode) {
    loading.value = false
    return
  }
  const version = ++requestVersion
  try {
    const response = await api<{ data: Game | null }>(
      online ? `${gameApi}/${roomCode}` : practiceApi,
    )
    if (version !== requestVersion || disposed) return
    accept(response.data)
    if (!silent) error.value = ''
    if (online && game.value && !socket) connectRealtime()
  } catch (cause) {
    if (
      online &&
      !watching &&
      !game.value &&
      cause instanceof ApiError &&
      [401, 403].includes(cause.status)
    ) {
      if (cause.status === 401) selectedMode.value = 'ranked'
      try {
        const invitation = await api<{ data: { mode: 'casual' | 'ranked' | 'tournament' } }>(
          `/api/games/${roomCode}/invitation`,
        )
        selectedMode.value = invitation.data.mode === 'casual' ? 'casual' : 'ranked'
      } catch {
        /* The normal room error remains available when joining. */
      }
      return
    }
    if (!silent)
      error.value = cause instanceof Error ? cause.message : 'Não foi possível carregar a partida.'
  } finally {
    loading.value = false
  }
}
function connectRealtime() {
  socket = io({
    auth: async (done) => {
      try {
        const data = await api<{ ticket: string }>(`${gameApi}/${roomCode}/ticket`)
        done({ ticket: data.ticket })
      } catch {
        done({ ticket: 'invalid' })
      }
    },
    reconnectionDelay: 1000,
    reconnectionDelayMax: 5000,
  })
  socket.on('game:ready', () => {
    connected.value = true
    void load(true)
  })
  socket.on('game:changed', (event: { game: string; revision: number }) => {
    if (event.game === game.value?.id && event.revision > game.value.revision) void load(true)
  })
  socket.on('disconnect', () => {
    connected.value = false
  })
  socket.on('connect_error', () => {
    connected.value = false
  })
  polling = setInterval(() => {
    if (!busy.value && (!document.hidden || !watching)) {
      void load(true)
      if (socket && !socket.connected && !socket.active) socket.connect()
    }
  }, 5000)
}
function invitationCode(value: string): string {
  const text = value.trim()
  if (/^[a-z0-9]{10}$/i.test(text)) return text.toUpperCase()
  try {
    const url = new URL(text)
    if (!['http:', 'https:'].includes(url.protocol)) return ''
    return (
      url.hash.match(/^#\/jogar\/online\/([a-z0-9]{10})\/?(?:\?.*)?$/i)?.[1]?.toUpperCase() ?? ''
    )
  } catch {
    return ''
  }
}
async function loadRooms(page = rooms.value?.page ?? 1) {
  if (roomsLoading.value) return
  roomsLoading.value = true
  try {
    rooms.value = await api<RoomPage>(`/api/games/open?page=${page}`)
    roomsError.value = ''
  } catch {
    roomsError.value = 'Não foi possível atualizar as salas abertas.'
  } finally {
    roomsLoading.value = false
  }
}

async function roomAction(action: 'create' | 'join', room?: OpenRoom) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await api('/sanctum/csrf-cookie')
    const code = room?.code ?? invitationCode(joinCode.value)
    if (action === 'join' && !/^[A-Z0-9]{10}$/.test(code))
      throw new Error(
        'Cola o link completo do convite ou introduz o código da sala com 10 caracteres.',
      )
    let entryFee: number | undefined
    if (action === 'join') {
      const invitation =
        room ??
        (await api<{ data: { mode: string; entry_fee: number } }>(`/api/games/${code}/invitation`))
          .data
      if (invitation.mode === 'ranked') {
        selectedMode.value = 'ranked'
        if (!auth.user.value) throw new Error('Entra na tua conta para jogar no competitivo.')
        entryFee = invitation.entry_fee
        if (
          !window.confirm(
            `Confirmas a entrada por ${entryFee} Damas? O vencedor recebe ${2 * entryFee} Damas. Em caso de empate, a entrada é devolvida.`,
          )
        )
          return
      }
    }
    const response = await api<{ data: Game }>(
      action === 'create' ? '/api/games' : `/api/games/${code}/join`,
      {
        method: 'POST',
        body: JSON.stringify(
          action === 'create'
            ? { mode: selectedMode.value, discoverable: discoverable.value }
            : { entry_fee: entryFee },
        ),
      },
    )
    if (roomCode === response.data.code) {
      accept(response.data)
      if (!socket) connectRealtime()
    } else await router.push(`/jogar/online/${response.data.code}`)
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 401) {
      selectedMode.value = 'ranked'
      error.value = 'Entra na tua conta para jogar no competitivo.'
    } else {
      error.value = cause instanceof Error ? cause.message : 'Não foi possível entrar na sala.'
      if (cause instanceof ApiError && cause.status === 409) void loadRooms()
    }
  } finally {
    busy.value = false
  }
}
async function copyInvite() {
  try {
    await navigator.clipboard.writeText(inviteUrl.value)
    copied.value = true
  } catch {
    error.value = 'Copia o endereço apresentado na caixa do convite.'
  }
}
async function run(
  action:
    'restart' | 'move' | 'resign' | 'offer_draw' | 'accept_draw' | 'decline_draw' | 'claim_draw',
  chosenPath?: number[],
) {
  if (watching || busy.value) return
  if (online && action === 'restart') {
    await router.push('/jogar/online')
    return
  }
  busy.value = true
  error.value = ''
  requestVersion++
  try {
    await api('/sanctum/csrf-cookie')
    const response = await api<{ data: Game }>(online ? `${gameApi}/${roomCode}` : practiceApi, {
      method: action === 'restart' ? 'POST' : 'PATCH',
      body: JSON.stringify(
        action === 'restart'
          ? {}
          : {
              id: game.value?.id,
              revision: game.value?.revision,
              action,
              ...(chosenPath ? { path: chosenPath } : {}),
            },
      ),
    })
    accept(response.data)
    path.value = []
    confirm.value = null
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 409) await load()
    error.value =
      cause instanceof ApiError
        ? (cause.errors.path?.[0] ?? cause.errors.action?.[0] ?? cause.message)
        : 'Não foi possível concluir a ação.'
    if (action === 'move') path.value = []
  } finally {
    busy.value = false
  }
}
function select(square: number) {
  if (busy.value || !canPlay.value) return
  if (destinations.value.includes(square)) {
    path.value = [...path.value, square]
    const finished = candidates.value.find((m) => m.path.length === path.value.length)
    if (finished) void run('move', [...path.value])
  } else if (origins.value.includes(square)) {
    path.value = [square]
  }
}
function refreshOnFocus() {
  if (!busy.value && !confirm.value) void load()
}
onMounted(() => {
  void load().then(() => {
    if (disposed || !online || watching || game.value) return
    void loadRooms()
    roomsTimer = setInterval(() => {
      if (!document.hidden && !game.value) void loadRooms()
    }, 8000)
  })
  ticker = setInterval(() => {
    clockNow.value = serverAnchor + performance.now() - localAnchor
    const clock = game.value?.clock
    if (
      game.value?.status === 'active' &&
      clock?.deadline_at &&
      clockNow.value >= clock.deadline_at &&
      !busy.value &&
      performance.now() >= nextClockSync
    ) {
      nextClockSync = performance.now() + 2000
      void load(true)
    }
  }, 250)
  window.addEventListener('focus', refreshOnFocus)
})
onBeforeUnmount(() => {
  disposed = true
  if (ticker) clearInterval(ticker)
  if (roomsTimer) clearInterval(roomsTimer)
  socket?.disconnect()
  if (polling) clearInterval(polling)
  window.removeEventListener('focus', refreshOnFocus)
})
</script>

<template>
  <section class="page-content practice-page">
    <div class="practice-heading">
      <div>
        <p class="eyebrow">{{ watching ? 'BANCADA' : 'PENSA. ESCOLHE. JOGA.' }}</p>
        <h1>
          {{
            watching
              ? 'Assistir à partida'
              : training
                ? 'Treino contra o bot'
                : 'O teu próximo lance'
          }}<span>.</span>
        </h1>
        <p>
          {{
            watching
              ? 'Acompanha o tabuleiro, os relógios e os lances.'
              : training
                ? 'Pratica damas portuguesas contra o computador.'
                : 'Damas portuguesas, um tabuleiro e duas pessoas.'
          }}
        </p>
      </div>
      <span class="practice-mode"
        ><span class="status-dot" />{{
          watching
            ? 'Espectador'
            : online
              ? game?.mode === 'tournament'
                ? 'Partida de torneio'
                : (game?.mode ?? selectedMode) === 'ranked'
                  ? 'Competitivo · vale pontos'
                  : 'Casual · sem pontos'
              : training
                ? 'Treino · sem pontos'
                : 'Partida local · 2 jogadores'
        }}</span
      >
    </div>
    <RouterLink v-if="watching" to="/assistir" class="inline-link">← Partidas em direto</RouterLink>
    <nav v-else class="play-tabs" aria-label="Modo de jogo">
      <RouterLink to="/jogar">No mesmo dispositivo</RouterLink>
      <RouterLink to="/jogar/treino">Treinar com o bot</RouterLink
      ><RouterLink to="/jogar/online" :class="{ active: online }">Jogar online</RouterLink>
    </nav>
    <section v-if="online && !watching && !game && !loading" class="online-lobby">
      <div>
        <p class="eyebrow">CRIAR PARTIDA</p>
        <h2>Duas pessoas. Cada uma no seu tabuleiro.</h2>
        <div class="game-mode-picker" role="group" aria-label="Tipo de partida">
          <button
            type="button"
            :aria-pressed="selectedMode === 'casual'"
            :class="{ active: selectedMode === 'casual' }"
            @click="selectedMode = 'casual'"
          >
            Casual<small>Com ou sem conta · sem pontos</small></button
          ><button
            type="button"
            :aria-pressed="selectedMode === 'ranked'"
            :class="{ active: selectedMode === 'ranked' }"
            @click="selectedMode = 'ranked'"
          >
            Competitivo<small>Conta obrigatória · vale pontos</small>
          </button>
        </div>
        <p>
          {{
            selectedMode === 'ranked'
              ? `Vitória +30, derrota −15 e empate 0 pontos. ${rooms ? `Entrada de ${rooms.ranked_entry_fee} Damas por jogador, cobrada quando a partida começa. O vencedor recebe ${2 * rooms.ranked_entry_fee} Damas. No empate, cada jogador recupera a entrada.` : 'A consultar a taxa de entrada…'}`
              : 'Joga sem conta ou inicia sessão para guardar a partida no histórico.'
          }}
        </p>
        <p>
          <label class="room-visibility"
            ><input v-model="discoverable" type="checkbox" /> Mostrar a sala em Encontrar
            partida</label
          >
        </p>
        <button
          v-if="selectedMode === 'casual' || auth.user.value"
          class="button-primary"
          :disabled="busy || (selectedMode === 'ranked' && !rooms)"
          @click="roomAction('create')"
        >
          {{ selectedMode === 'ranked' ? 'Criar sala competitiva' : 'Criar sala casual' }}
          <span>↗</span></button
        ><RouterLink v-else :to="loginTarget" class="button-primary"
          >Entrar para jogar competitivo ↗</RouterLink
        >
      </div>
      <form @submit.prevent="roomAction('join')">
        <label for="room-code">Já tens um convite?</label>
        <p>
          Cola o link completo do convite ou apenas o código da sala. Depois, clica em Entrar na
          sala.
        </p>
        <input
          id="room-code"
          v-model="joinCode"
          maxlength="2048"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          placeholder="Link do convite ou código da sala"
          :disabled="busy"
        /><button
          v-if="selectedMode !== 'ranked' || auth.user.value"
          class="button-small"
          :disabled="busy || !joinCode.trim()"
        >
          Entrar na sala</button
        ><RouterLink v-else :to="loginTarget" class="button-small"
          >Entrar na conta para aceitar o convite</RouterLink
        >
      </form>
    </section>
    <section
      v-if="online && !watching && !game && !loading"
      class="surface tournament-standings open-rooms"
    >
      <div class="card-heading">
        <div>
          <h2>Encontrar partida</h2>
          <p>Entra diretamente numa sala à espera de adversário.</p>
        </div>
        <button class="button-secondary" :disabled="roomsLoading" @click="loadRooms()">
          Atualizar salas
        </button>
      </div>
      <p v-if="roomsError" role="alert">{{ roomsError }}</p>
      <p v-else-if="roomsLoading && !rooms" role="status">A procurar salas…</p>
      <p v-else-if="!rooms?.data.length">
        Não há salas abertas nesta página. Podes criar uma acima.
      </p>
      <article v-for="room in rooms?.data" :key="room.code" class="surface tournament-match">
        <div>
          <strong>{{ room.host }}</strong>
          <p>
            {{
              room.mode === 'ranked' ? `Competitivo · ${room.entry_fee} Damas de entrada` : 'Casual'
            }}
          </p>
        </div>
        <RouterLink
          v-if="room.mode === 'ranked' && !auth.user.value"
          :to="{ path: '/entrar', query: { redirect: `/jogar/online/${room.code}` } }"
          class="button-small"
          >Iniciar sessão</RouterLink
        >
        <button v-else class="button-small" :disabled="busy" @click="roomAction('join', room)">
          Juntar-me
        </button>
      </article>
      <div v-if="rooms && (rooms.page > 1 || rooms.has_more)" class="player-history-pages">
        <button
          class="button-small"
          :disabled="roomsLoading || rooms.page <= 1"
          @click="loadRooms(rooms.page - 1)"
        >
          Anterior
        </button>
        <span>Página {{ rooms.page }}</span>
        <button
          class="button-small"
          :disabled="roomsLoading || !rooms.has_more"
          @click="loadRooms(rooms.page + 1)"
        >
          Seguinte
        </button>
      </div>
    </section>
    <div v-if="online && !watching && game" class="online-room-bar">
      <div>
        <strong>Sala {{ game.code }}</strong
        ><span
          >Jogas com as {{ colorName(game.my_color!).toLowerCase() }} ·
          {{
            connected ? 'Em tempo real' : 'A recuperar ligação · atualização periódica ativa'
          }}</span
        >
      </div>
      <button class="button-small" @click="copyInvite">
        {{ copied ? 'Convite copiado ✓' : 'Copiar convite' }}</button
      ><input readonly aria-label="Endereço do convite" :value="inviteUrl" />
      <p v-if="game.mode === 'ranked' && game.entry_fee" class="online-invite-help">
        Entrada por jogador {{ game.entry_fee }} Damas · prémio {{ 2 * game.entry_fee }} Damas ·
        reembolso no empate
      </p>
      <p class="online-invite-help">
        Abre este link noutro navegador ou numa janela privada e clica em Entrar na sala. Também
        podes colá-lo no campo de entrada. Duas abas normais partilham o mesmo jogador.
      </p>
    </div>
    <p v-if="watching && game" role="status">
      {{
        game.status === 'finished'
          ? 'Terminada'
          : connected
            ? 'Em direto'
            : 'A atualizar periodicamente'
      }}
      ·
      {{ game.mode === 'tournament' ? 'Torneio' : 'Competitivo' }}
    </p>
    <RouterLink
      v-if="
        !watching &&
        game &&
        game.phase !== 'waiting' &&
        (game.mode === 'ranked' || game.mode === 'tournament')
      "
      :to="`/assistir/${game.code}`"
      class="inline-link"
      >Abrir vista de espectador ↗</RouterLink
    >
    <div v-if="error" class="alert error" role="alert">
      {{ error }}
      <button class="inline-link" :disabled="busy" @click="load(false)">Atualizar tabuleiro</button>
    </div>
    <p v-if="game?.phase === 'waiting' && game.discoverable">
      A tua sala aparece em Encontrar partida.
    </p>
    <div v-if="game?.clock" class="game-time-bar">
      <span
        >Tempo de jogo <strong>{{ elapsedTime }}</strong></span
      ><span>{{ game.clock.turn_seconds }} s por jogada · quem fica sem tempo perde</span
      ><span v-if="game.clock.game_deadline_at"
        >Tempo até ao limite do jogo
        <strong>{{
          timeLabel(
            Math.ceil((game.clock.game_deadline_at - (game.clock.finished_at ?? clockNow)) / 1000),
          )
        }}</strong></span
      >
    </div>
    <section
      v-if="game?.status === 'finished'"
      class="game-result-banner"
      :class="{
        'result-loss': !watching && online && game.winner && game.winner !== game.my_color,
      }"
      role="status"
      aria-live="polite"
    >
      <p class="eyebrow">
        {{
          game.reason === 'cancelled'
            ? 'SALA ENCERRADA'
            : !game.winner
              ? 'EMPATE'
              : online && game.winner === game.my_color
                ? 'VITÓRIA'
                : online && !watching
                  ? 'DERROTA'
                  : 'PARTIDA TERMINADA'
        }}
      </p>
      <h2>{{ heading }}</h2>
      <p>{{ result }}</p>
      <p v-if="game.wallet_reward">Carteira: +{{ game.wallet_reward }} Damas</p>
      <p v-if="game.rank_change">
        <strong
          >{{ game.rank_change.delta > 0 ? '+' : '' }}{{ game.rank_change.delta }} pontos de
          rank</strong
        >
        · Total {{ game.rank_change.after }} · {{ game.rank_change.tier.name }}
      </p>
      <template v-if="!watching && game.tournament_id"
        ><p>
          Este jogo conta para a classificação do torneio. Os pontos de rank são atribuídos só ao
          pódio final.
        </p>
        <RouterLink :to="`/torneios/${game.tournament_id}`" class="button-primary"
          >Voltar ao torneio ↗</RouterLink
        ></template
      >
    </section>
    <RouterLink
      v-else-if="!watching && game?.tournament_id"
      :to="`/torneios/${game.tournament_id}`"
      class="inline-link tournament-back"
      >← Ver ronda e classificação do torneio</RouterLink
    >
    <p v-if="loading" class="practice-loading" role="status">A preparar o tabuleiro…</p>
    <div v-else-if="!online || game" class="practice-layout">
      <section
        class="practice-table"
        :aria-label="
          online ? 'Partida online de damas portuguesas' : 'Partida local de damas portuguesas'
        "
      >
        <div
          class="practice-player"
          :class="{ 'is-turn': game?.turn === topColor && game.status === 'active' }"
        >
          <span class="practice-avatar" :class="topColor">●</span>
          <div>
            <strong>{{ colorName(topColor) }}</strong
            ><small>{{ playerLabel(topColor) }}</small>
          </div>
          <span class="practice-material">{{ pieceCount(topColor) }} peças</span
          ><span
            class="player-clock"
            :class="{ ticking: game?.turn === topColor && game.status === 'active' }"
            :aria-label="`Tempo das ${colorName(topColor).toLowerCase()}`"
            >{{ playerTime(topColor) }}</span
          >
        </div>
        <div class="practice-board-frame">
          <div
            class="practice-board"
            :aria-busy="busy"
            aria-label="Tabuleiro de 8 por 8, brancas começam nas casas 1 a 12"
          >
            <template v-for="cell in displayCells" :key="cell.index">
              <button
                v-if="cell.square"
                type="button"
                class="practice-square dark"
                :class="{
                  selected: path.at(-1) === cell.square,
                  destination: destinations.includes(cell.square),
                  playable: canPlay && !path.length && origins.includes(cell.square),
                  previous: latest.includes(cell.square),
                }"
                :aria-label="squareLabel(cell.square)"
                :aria-pressed="path.at(-1) === cell.square"
                :disabled="!canPlay || busy"
                @click="select(cell.square)"
              >
                <span class="practice-square-number" aria-hidden="true">{{ cell.square }}</span>
                <span
                  v-if="preview[cell.square - 1]"
                  class="practice-piece"
                  :class="preview[cell.square - 1]?.toLowerCase() === 'w' ? 'white' : 'black'"
                  aria-hidden="true"
                >
                  <svg
                    v-if="preview[cell.square - 1] === preview[cell.square - 1]?.toUpperCase()"
                    viewBox="0 0 32 32"
                  >
                    <path d="M6 23 3 10l8 6 5-10 5 10 8-6-3 13H6Zm1 4h18" />
                  </svg>
                  <span v-else class="practice-piece-ring" />
                </span>
                <span
                  v-else-if="destinations.includes(cell.square)"
                  class="practice-destination"
                  aria-hidden="true"
                />
              </button>
              <div v-else class="practice-square light" aria-hidden="true" />
            </template>
          </div>
        </div>
        <div
          class="practice-player"
          :class="{ 'is-turn': game?.turn === bottomColor && game.status === 'active' }"
        >
          <span class="practice-avatar" :class="bottomColor">●</span>
          <div>
            <strong>{{ colorName(bottomColor) }}</strong
            ><small>{{ playerLabel(bottomColor) }}</small>
          </div>
          <span class="practice-material">{{ pieceCount(bottomColor) }} peças</span
          ><span
            class="player-clock"
            :class="{ ticking: game?.turn === bottomColor && game.status === 'active' }"
            :aria-label="`Tempo das ${colorName(bottomColor).toLowerCase()}`"
            >{{ playerTime(bottomColor) }}</span
          >
        </div>
        <div class="practice-board-tools">
          <button class="button-text" @click="flipped = !flipped">↻ Rodar tabuleiro</button
          ><span>Casas numeradas de 1 a 32</span>
        </div>
      </section>
      <aside class="practice-panel">
        <section class="practice-status" aria-live="polite" aria-atomic="true">
          <p class="eyebrow">
            {{
              game?.status === 'finished'
                ? 'RESULTADO'
                : online && !canPlay
                  ? 'A PARTIDA'
                  : 'A TUA VEZ'
            }}
          </p>
          <h2>{{ heading }}</h2>
          <p>{{ instruction }}</p>
          <p v-if="game?.rank_change" class="rank-match-result">
            {{ game.rank_change.delta > 0 ? '+' : '' }}{{ game.rank_change.delta }} pontos ·
            {{ game.rank_change.after }} pts · {{ game.rank_change.tier.name }}
          </p>
          <button
            v-if="!game"
            class="button-primary practice-start"
            :disabled="busy"
            @click="run('restart')"
          >
            {{ busy ? 'A começar…' : training ? 'Começar treino' : 'Começar partida local' }}
            <span>↗</span>
          </button>
          <button v-if="path.length && !busy" class="button-text" @click="path = []">
            Limpar seleção
          </button>
          <p v-if="game?.forced" class="practice-counter">
            Forçada · {{ game.forced.moves }}/12 lances das
            {{ colorName(game.forced.color).toLowerCase() }}
          </p>
          <div v-if="game && !watching" class="practice-actions">
            <button
              class="button-small"
              :disabled="busy"
              @click="game?.status === 'finished' ? run('restart') : (confirm = 'restart')"
            >
              {{ online ? 'Outra sala' : 'Nova partida' }}
            </button>
            <button
              v-if="game.status === 'active'"
              class="button-text"
              :disabled="busy"
              @click="confirm = 'resign'"
            >
              {{ online && game.phase === 'waiting' ? 'Encerrar sala' : 'Desistir' }}
            </button>
          </div>
          <button v-if="canPlay" class="button-text" :disabled="busy" @click="run('claim_draw')">
            Reclamar empate técnico
          </button>
          <div v-if="online && !watching && game?.phase === 'active'" class="practice-actions">
            <button
              v-if="!game.draw_offer && game.history.some((entry) => entry.type !== 'timeout')"
              class="button-text"
              :disabled="busy"
              @click="run('offer_draw')"
            >
              Propor empate
            </button>
            <p v-else-if="game.draw_offer === game.my_color && game.draw_offer">
              Proposta enviada.
            </p>
            <template v-else-if="game.draw_offer">
              <p>O adversário propôs um empate.</p>
              <button class="button-small" :disabled="busy" @click="run('accept_draw')">
                Aceitar empate
              </button>
              <button class="button-text" :disabled="busy" @click="run('decline_draw')">
                Recusar
              </button>
            </template>
          </div>
          <div
            v-if="confirm"
            class="practice-confirm"
            role="group"
            :aria-label="confirm === 'restart' ? 'Confirmar nova partida' : 'Confirmar desistência'"
          >
            <p>
              {{
                confirm === 'restart'
                  ? online
                    ? 'Sair desta vista? A partida atual continua disponível pelo convite.'
                    : 'Terminar esta partida e começar outra?'
                  : `Confirmar a desistência das ${colorName(online ? game!.my_color! : game!.turn).toLowerCase()}?`
              }}
            </p>
            <button class="button-small" :disabled="busy" @click="run(confirm!)">Confirmar</button>
            <button class="button-text" :disabled="busy" @click="confirm = null">Cancelar</button>
          </div>
        </section>
        <section class="practice-history">
          <div class="practice-section-title">
            <h2>Lances da partida</h2>
            <span>{{ game?.history.length ?? 0 }}</span>
          </div>
          <p v-if="!game?.history.length" class="practice-empty">
            Cada decisão deixa uma marca.<br />Os lances vão aparecer aqui.
          </p>
          <ol v-else aria-label="Histórico de lances">
            <li
              v-for="(move, index) in game.history"
              :key="index"
              :class="{ 'timeout-event': move.type === 'timeout' }"
            >
              <span>{{ index + 1 }}.</span
              ><span :class="move.color">{{ colorName(move.color) }}</span
              ><strong>{{ move.notation }}{{ move.promoted ? ' ♛' : '' }}</strong>
            </li>
          </ol>
          <p class="practice-notation">
            – movimento <span>× captura</span> <span>♛ promoção</span>
          </p>
        </section>
        <div v-if="!watching" class="practice-note">
          <strong>{{
            online
              ? 'Cada pessoa joga a sua cor.'
              : training
                ? 'Tu contra o computador.'
                : 'Uma mesa, duas pessoas.'
          }}</strong>
          <p>
            {{
              online
                ? game?.mode === 'ranked' || game?.mode === 'tournament'
                  ? 'O teu lugar pertence à tua conta. Se a ligação cair, entra na mesma conta e abre este convite para retomar. Para o adversário jogar, usa outra conta num navegador ou sessão diferente.'
                  : 'Se a ligação cair, recuperamos o estado ao voltar a ligar. Mantém este navegador e o convite: o teu lugar pertence à sessão. Duas abas da mesma sessão representam a mesma pessoa.'
                : training
                  ? 'O bot responde automaticamente. A partida fica nesta sessão e não altera o ranking nem a carteira.'
                  : 'Alternem as jogadas neste dispositivo. A partida fica guardada nesta sessão. Escolhe Jogar online para usares dois navegadores.'
            }}
          </p>
        </div>
      </aside>
    </div>
    <section class="practice-rules">
      <div>
        <span>01</span>
        <h3>Avança na diagonal</h3>
        <p>
          Os peões movem-se e capturam para a frente. Ao chegar à última linha, tornam-se damas.
        </p>
      </div>
      <div>
        <span>02</span>
        <h3>Captura é obrigatória</h3>
        <p>Escolhe a maior quantidade de peças. Em caso de empate, a sequência com mais damas.</p>
      </div>
      <div>
        <span>03</span>
        <h3>Uma dama, mais caminhos</h3>
        <p>
          As damas percorrem diagonais em ambos os sentidos. Os pontos mostram os destinos legais.
        </p>
      </div>
      <div>
        <span>04</span>
        <h3>Como termina a partida</h3>
        <p>
          Vences por captura, bloqueio ou desistência do adversário. No competitivo e em torneios,
          ficar sem tempo para jogar também dá a vitória à outra cor.
        </p>
      </div>
      <div>
        <span>05</span>
        <h3>Quando há empate</h3>
        <p>
          Três repetições da mesma posição, os limites de 20 lances e da forçada de 12 lances podem
          dar empate. Online também podes propor empate ao adversário ou pedir a verificação de um
          final de dama contra dama.
        </p>
      </div>
    </section>
  </section>
</template>
