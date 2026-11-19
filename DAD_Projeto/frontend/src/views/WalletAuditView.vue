<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import {
  type WalletOperation,
  type WalletRules,
  paymentMethods,
  walletStatus,
  walletKinds,
  coins,
  euros,
  walletDate,
} from '@/lib/wallet'
type AuditOperation = WalletOperation & {
  name: string
  user_id: number
  gateway_status: number | null
  resolution_reason: string | null
  resolved_by: number | null
}
type Audit = {
  data: AuditOperation[]
  rules: WalletRules
  stats: {
    balance: number
    reserved: number
    fees: number
    tournament_revenue: number
    payments_euros: number
    withdrawals_euros: number
    review: number
  }
  meta: { current_page: number; last_page: number; total: number }
}
const response = ref<Audit | null>(null),
  loading = ref(false),
  busy = ref(false),
  error = ref(''),
  message = ref(''),
  status = ref(''),
  kind = ref(''),
  search = ref('')
const selected = ref<AuditOperation | null>(null),
  outcome = ref('failed'),
  reason = ref(''),
  password = ref(''),
  confirmed = ref(false)
async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({ page: String(page) })
    if (status.value) params.set('status', status.value)
    if (kind.value) params.set('kind', kind.value)
    if (search.value.trim()) params.set('q', search.value.trim())
    response.value = await api<Audit>(`/api/admin/wallet/transactions?${params}`)
  } catch (cause) {
    fail(cause)
  } finally {
    loading.value = false
  }
}
function fail(cause: unknown) {
  error.value =
    cause instanceof ApiError && Object.keys(cause.errors).length
      ? Object.values(cause.errors).flat().join(' ')
      : cause instanceof Error
        ? cause.message
        : 'Não foi possível carregar a auditoria.'
}
function open(op: AuditOperation) {
  selected.value = op
  outcome.value = 'failed'
  reason.value = ''
  password.value = ''
  confirmed.value = false
  message.value = ''
  error.value = ''
}
function cancel() {
  selected.value = null
  reason.value = ''
  password.value = ''
  confirmed.value = false
}
async function resolve() {
  if (!selected.value || busy.value || !confirmed.value) return
  busy.value = true
  error.value = ''
  try {
    await api(`/api/admin/wallet/transactions/${selected.value.id}/resolve`, {
      method: 'POST',
      body: JSON.stringify({
        status: outcome.value,
        reason: reason.value,
        current_password: password.value,
      }),
    })
    message.value = 'Resultado registado na carteira e no histórico de auditoria.'
    cancel()
    await load(response.value?.meta.current_page)
  } catch (cause) {
    fail(cause)
  } finally {
    password.value = ''
    busy.value = false
  }
}
const userId = Number(useRoute().query.user)
type Balance = { name: string; balance: number; reserved: number }
const account = ref<Balance>()
const newBalance = ref(0)
const adjustmentReason = ref('')
const adjustmentPassword = ref('')
const balancePath = `/api/admin/wallet/users/${userId}`

async function loadBalance() {
  try {
    account.value = await api<Balance>(balancePath)
    newBalance.value = account.value.balance
  } catch (cause) {
    fail(cause)
  }
}

async function adjust() {
  if (!account.value || busy.value) return
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    account.value = await api<Balance>(balancePath, {
      method: 'PATCH',
      body: JSON.stringify({
        balance: newBalance.value,
        expected_balance: account.value.balance,
        reason: adjustmentReason.value,
        current_password: adjustmentPassword.value,
      }),
    })
    adjustmentReason.value = ''
    message.value = 'Saldo atualizado. O ajuste ficou registado na carteira e na auditoria.'
    await load()
  } catch (cause) {
    fail(cause)
  } finally {
    adjustmentPassword.value = ''
    busy.value = false
  }
}

onMounted(async () => {
  await load()
  if (Number.isSafeInteger(userId) && userId > 0) await loadBalance()
})
</script>
<template>
  <section class="page-content wallet-page wallet-audit-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">GESTÃO DO CLUBE</p>
        <h1>Auditoria financeira<span class="green-dot">.</span></h1>
        <p>Operações simuladas, comissões e verificação de resultados pendentes.</p>
      </div>
      <RouterLink to="/admin" class="button-secondary">Voltar à administração</RouterLink>
    </div>
    <p class="wallet-simulation">
      Serviço académico simulado. Os valores em euros não representam pagamentos reais.
    </p>
    <div v-if="error" class="alert error" role="alert">{{ error }}</div>
    <p v-if="message" role="status" class="alert success">{{ message }}</p>
    <section v-if="account" class="surface wallet-resolution">
      <h2>Ajustar saldo de {{ account.name }}</h2>
      <p>
        Saldo atual {{ coins(account.balance) }} DM · Reservado {{ coins(account.reserved) }} DM
      </p>
      <form @submit.prevent="adjust">
        <label
          >Novo saldo (DM)<input
            v-model.number="newBalance"
            type="number"
            :min="account.reserved"
            max="1000000000"
            required
        /></label>
        <label
          >Motivo do ajuste<textarea
            v-model="adjustmentReason"
            minlength="10"
            maxlength="500"
            required
          />
        </label>
        <label
          >Palavra-passe do administrador<input
            v-model="adjustmentPassword"
            type="password"
            autocomplete="current-password"
            required
        /></label>
        <div class="wallet-resolution-actions">
          <button class="button-primary" :disabled="busy || newBalance === account.balance">
            Guardar ajuste
          </button>
          <button type="button" class="button-secondary" :disabled="busy" @click="loadBalance">
            Atualizar saldo
          </button>
        </div>
      </form>
    </section>
    <template v-if="response"
      ><div class="wallet-audit-stats">
        <article class="surface">
          <span>Saldo nas carteiras</span><strong>{{ coins(response.stats.balance) }} DM</strong>
        </article>
        <article class="surface">
          <span>Saldo reservado</span><strong>{{ coins(response.stats.reserved) }} DM</strong>
        </article>
        <article class="surface">
          <span>Comissões de carregamentos e levantamentos</span
          ><strong>{{ coins(response.stats.fees) }} DM</strong>
        </article>
        <article class="surface">
          <span>A verificar</span><strong>{{ response.stats.review }}</strong>
        </article>
      </div>
      <p class="player-stats-note">
        Totais de todo o histórico · Carregamentos confirmados:
        {{ euros(response.stats.payments_euros) }} · Levantamentos confirmados:
        {{ euros(response.stats.withdrawals_euros) }}. Receita de torneios concluídos:
        <strong>{{ coins(response.stats.tournament_revenue) }} DM</strong>. Total de comissões:
        <strong>{{ coins(response.stats.fees + response.stats.tournament_revenue) }} DM</strong>.
      </p></template
    >
    <form class="surface wallet-audit-filters" @submit.prevent="load()">
      <label
        >Pesquisar<input
          v-model="search"
          placeholder="Nome do jogador ou ID do pedido"
          maxlength="100" /></label
      ><label
        >Estado<select v-model="status">
          <option value="">Todos</option>
          <option v-for="(label, key) in walletStatus" :key="key" :value="key">{{ label }}</option>
        </select></label
      ><label
        >Operação<select v-model="kind">
          <option value="">Todas</option>
          <option value="deposit">Carregamentos</option>
          <option value="withdrawal">Levantamentos</option>
          <option value="entry">Entradas</option>
          <option value="refund">Reembolsos</option>
          <option value="prize">Prémios</option>
          <option value="adjustment">Ajustes de saldo</option>
        </select></label
      ><button class="button-primary" :disabled="loading">Filtrar</button>
    </form>
    <section
      v-if="selected"
      class="surface wallet-resolution"
      aria-label="Verificar resultado pendente"
    >
      <p class="eyebrow">CONFIRMAÇÃO DO RESULTADO</p>
      <h2>
        {{ selected.name }} · {{ selected.kind === 'deposit' ? 'Carregamento' : 'Levantamento' }} de
        {{ euros(selected.amount_euros) }}
      </h2>
      <p class="wallet-operation-id">Pedido {{ selected.id }}</p>
      <p>
        Confirma o resultado no serviço simulado ou com o responsável pelo serviço. A plataforma não
        volta a enviar esta operação. Não concluas o pedido com base apenas numa tentativa de
        ligação.
      </p>
      <form @submit.prevent="resolve">
        <label
          >Resultado confirmado<select v-model="outcome" :disabled="busy">
            <option value="failed">Não foi executada — recusar e libertar a reserva</option>
            <option value="completed">Foi executada — concluir e aplicar ao saldo</option>
          </select></label
        ><label
          >Motivo e evidência da confirmação<textarea
            v-model="reason"
            required
            minlength="10"
            maxlength="500"
            rows="3"
            :disabled="busy"
            placeholder="Indica como confirmaste o resultado, sem dados bancários ou palavras-passe."
          /></label
        ><label
          >Palavra-passe do administrador<input
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            :disabled="busy" /></label
        ><label class="wallet-confirm-check"
          ><input v-model="confirmed" type="checkbox" required :disabled="busy" />Confirmei o
          resultado externo e os dados deste pedido.</label
        >
        <div class="wallet-resolution-actions">
          <button class="button-primary" :disabled="busy || !confirmed">
            {{ busy ? 'A registar…' : 'Registar resultado confirmado' }}</button
          ><button type="button" class="button-secondary" :disabled="busy" @click="cancel">
            Cancelar
          </button>
        </div>
      </form>
    </section>
    <section v-if="response" class="surface wallet-history">
      <div class="card-heading">
        <div>
          <h2>Registo de operações</h2>
          <p>{{ response.meta.total }} resultados · referências apresentadas parcialmente</p>
        </div>
        <button class="button-small" :disabled="loading" @click="load(response.meta.current_page)">
          Atualizar
        </button>
      </div>
      <p v-if="!response.data.length" class="practice-empty">Não foram encontradas operações.</p>
      <article v-for="op in response.data" :key="op.id" class="wallet-audit-row">
        <div>
          <strong>{{ op.name }}</strong>
          <p>
            {{ walletKinds[op.kind] }} ·
            {{ op.method === 'CLUB' ? op.reference : euros(op.amount_euros) }} ·
            {{ paymentMethods[op.method]?.name ?? op.method }} ·
            {{ op.reference }}
          </p>
          <small>{{ walletDate(op.created_at) }} · Comissão {{ coins(op.fee_coins) }} DM</small>
          <p class="wallet-operation-id">{{ op.id }}</p>
          <p v-if="op.gateway_status" class="wallet-gateway-result">
            Resposta do serviço: HTTP {{ op.gateway_status }}
          </p>
          <p v-if="op.resolution_reason">
            Confirmação administrativa #{{ op.resolved_by }}: {{ op.resolution_reason }}
          </p>
        </div>
        <div class="wallet-movement-result">
          <span class="wallet-status" :class="op.status">{{ walletStatus[op.status] }}</span
          ><strong v-if="op.delta !== null"
            >{{ op.delta > 0 ? '+' : '' }}{{ coins(op.delta) }} DM</strong
          ><button
            v-if="op.status === 'review'"
            class="button-small"
            :disabled="busy"
            @click="open(op)"
          >
            Verificar resultado
          </button>
        </div>
      </article>
      <div v-if="response.meta.last_page > 1" class="player-history-pages">
        <button
          class="button-small"
          :disabled="loading || response.meta.current_page === 1"
          @click="load(response.meta.current_page - 1)"
        >
          Anterior</button
        ><span>{{ response.meta.current_page }} / {{ response.meta.last_page }}</span
        ><button
          class="button-small"
          :disabled="loading || response.meta.current_page === response.meta.last_page"
          @click="load(response.meta.current_page + 1)"
        >
          Seguinte
        </button>
      </div>
    </section>
  </section>
</template>
