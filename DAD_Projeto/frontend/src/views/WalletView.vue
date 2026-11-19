<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { api, ApiError } from '@/lib/api'
import {
  type WalletResponse,
  type WalletOperation,
  paymentMethods,
  walletStatus,
  walletKinds,
  coins,
  euros,
  walletDate,
  operationMessage,
} from '@/lib/wallet'
const auth = useAuth(),
  response = ref<WalletResponse | null>(null),
  kind = ref<'deposit' | 'withdrawal'>('deposit'),
  type = ref('MBWAY'),
  value = ref(5),
  reference = ref(''),
  password = ref('')
const busy = ref(false),
  loading = ref(false),
  preview = ref(false),
  error = ref(''),
  message = ref(''),
  last = ref<WalletOperation | null>(null)
const operationKey = ref(crypto.randomUUID()),
  uncertain = ref(false)
const storageKey = `dama-wallet-intent-${auth.user.value?.id}`
let polling: ReturnType<typeof setInterval> | undefined,
  disposed = false
const gross = computed(
  () => Math.max(0, Number(value.value) || 0) * (response.value?.rules.coins_per_euro ?? 100),
)
const fee = computed(() =>
  Math.floor((gross.value * (response.value?.rules.fee_percent ?? 10)) / 100),
)
const net = computed(() => gross.value - fee.value),
  debit = computed(() => gross.value + fee.value)
const method = computed(() => paymentMethods[type.value]!)
function clearIntent() {
  uncertain.value = false
  operationKey.value = crypto.randomUUID()
  try {
    sessionStorage.removeItem(storageKey)
  } catch {
    /* Storage may be unavailable. */
  }
}
function saveIntent() {
  uncertain.value = true
  try {
    sessionStorage.setItem(
      storageKey,
      JSON.stringify({
        key: operationKey.value,
        kind: kind.value,
        type: type.value,
        value: value.value,
      }),
    )
  } catch {
    /* The request key remains in memory. */
  }
}
async function recover() {
  if (!uncertain.value) return false
  try {
    const result = await api<{ data: WalletOperation }>(
      `/api/wallet/requests/${operationKey.value}`,
    )
    if (disposed) return
    last.value = result.data
    message.value = operationMessage(result.data)
    preview.value = false
    reference.value = ''
    clearIntent()
    return true
  } catch (cause) {
    if (!(cause instanceof ApiError && cause.status === 404)) throw cause
    return false
  }
}
async function load(page = response.value?.meta.current_page ?? 1) {
  if (loading.value) return
  loading.value = true
  try {
    await recover()
    const result = await api<WalletResponse>(`/api/wallet?page=${page}`)
    if (!disposed) response.value = result
  } catch (cause) {
    if (!disposed)
      error.value = cause instanceof Error ? cause.message : 'Não foi possível carregar a carteira.'
  } finally {
    loading.value = false
  }
}
function changeKind(next: 'deposit' | 'withdrawal') {
  if (busy.value || uncertain.value) return
  kind.value = next
  preview.value = false
  password.value = ''
  error.value = ''
  message.value = ''
}
function review() {
  error.value = ''
  preview.value = true
}
async function submit() {
  if (busy.value || !preview.value || response.value?.unresolved) return
  busy.value = true
  error.value = ''
  message.value = ''
  saveIntent()
  try {
    const result = await api<{ data: WalletOperation }>(
      `/api/wallet/${kind.value === 'deposit' ? 'deposits' : 'withdrawals'}`,
      {
        method: 'POST',
        body: JSON.stringify({
          idempotency_key: operationKey.value,
          type: type.value,
          reference: reference.value.trim(),
          value: Number(value.value),
          ...(kind.value === 'withdrawal' ? { current_password: password.value } : {}),
        }),
      },
    )
    last.value = result.data
    message.value = operationMessage(result.data)
    clearIntent()
    reference.value = ''
    preview.value = false
    await load(1)
  } catch (cause) {
    error.value =
      cause instanceof ApiError && Object.keys(cause.errors).length
        ? Object.values(cause.errors).flat().join(' ')
        : cause instanceof Error
          ? cause.message
          : 'Não foi possível concluir o pedido.'
    if (cause instanceof ApiError && [422, 401, 403, 419].includes(cause.status)) {
      clearIntent()
      preview.value = false
    } else if (cause instanceof ApiError && cause.status === 409) {
      try {
        const found = await recover()
        if (!found) {
          clearIntent()
          preview.value = false
        }
      } catch {
        /* Keep the same request key until the server can be consulted. */
      }
      await load(1)
    }
  } finally {
    password.value = ''
    busy.value = false
  }
}
onMounted(() => {
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null')
    if (
      saved &&
      typeof saved.key === 'string' &&
      ['deposit', 'withdrawal'].includes(saved.kind) &&
      paymentMethods[saved.type]
    ) {
      operationKey.value = saved.key
      kind.value = saved.kind
      type.value = saved.type
      value.value = saved.value
      uncertain.value = true
    }
  } catch {
    /* A fresh request remains available. */
  }
  void load(1)
  polling = setInterval(() => {
    if (!busy.value && (response.value?.unresolved || uncertain.value)) void load()
  }, 5000)
})
onBeforeUnmount(() => {
  disposed = true
  clearInterval(polling)
  password.value = ''
  reference.value = ''
})
</script>
<template>
  <section class="page-content wallet-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">AS TUAS DAMAS</p>
        <h1>A tua carteira<span class="green-dot">.</span></h1>
        <p>Carrega, acompanha os movimentos e gere o teu saldo.</p>
      </div>
      <button class="button-secondary" :disabled="loading || busy" @click="load()">
        Atualizar saldo ↻
      </button>
    </div>
    <p class="wallet-simulation">
      <strong>Simulação académica.</strong> Não movimenta dinheiro real. Usa referências fictícias
      nos testes.
    </p>
    <div v-if="error" class="alert error" role="alert">{{ error }}</div>
    <div
      v-if="message"
      :class="[
        'alert',
        last?.status === 'completed' ? 'success' : last?.status === 'failed' ? 'error' : '',
      ]"
      role="status"
    >
      {{ message }}<small v-if="last" class="wallet-operation-id">Pedido {{ last.id }}</small>
    </div>
    <p v-if="loading && !response" role="status">A carregar a carteira…</p>
    <template v-if="response">
      <section class="wallet-balance-grid">
        <div class="surface wallet-balance">
          <span class="eyebrow">DISPONÍVEL PARA UTILIZAR</span>
          <h2>{{ coins(response.wallet.available) }} <small>DM</small></h2>
          <p>{{ response.rules.currency }} · 1 € = {{ response.rules.coins_per_euro }} DM</p>
        </div>
        <div class="surface wallet-balance-detail">
          <div>
            <span>Saldo total</span><strong>{{ coins(response.wallet.balance) }} DM</strong>
          </div>
          <div>
            <span>Reservado para levantamentos</span
            ><strong>{{ coins(response.wallet.reserved) }} DM</strong>
          </div>
          <p>Uma reserva fica indisponível até o levantamento ser confirmado ou recusado.</p>
        </div>
      </section>
      <p v-if="response.unresolved" class="alert wallet-pending" role="status">
        Há uma operação em processamento ou verificação. Aguarda a confirmação antes de iniciar
        outra. Os detalhes e o identificador estão no histórico.
      </p>
      <p v-if="uncertain" class="alert wallet-pending" role="status">
        O último pedido desta sessão ainda não teve resposta confirmada. Atualiza o saldo ou repete
        com os mesmos dados; o identificador do pedido é conservado para evitar duplicações.
      </p>
      <div class="wallet-main-grid">
        <section class="surface wallet-form-card">
          <div class="card-heading">
            <div>
              <h2>{{ kind === 'deposit' ? 'Carregar carteira' : 'Levantar saldo' }}</h2>
              <p>
                {{
                  kind === 'deposit'
                    ? 'Recebe Damas após a comissão.'
                    : 'Escolhe o valor a receber em euros.'
                }}
              </p>
            </div>
          </div>
          <div class="page-tabs wallet-tabs" aria-label="Operação da carteira">
            <button
              :aria-pressed="kind === 'deposit'"
              :class="{ active: kind === 'deposit' }"
              :disabled="busy || uncertain"
              @click="changeKind('deposit')"
            >
              Carregar</button
            ><button
              :aria-pressed="kind === 'withdrawal'"
              :class="{ active: kind === 'withdrawal' }"
              :disabled="busy || uncertain"
              @click="changeKind('withdrawal')"
            >
              Levantar
            </button>
          </div>
          <form @submit.prevent="preview ? submit() : review()">
            <fieldset :disabled="busy || response.unresolved" class="wallet-fields">
              <label
                >Valor {{ kind === 'withdrawal' ? 'a receber ' : '' }}(€)<input
                  v-model.number="value"
                  type="number"
                  min="1"
                  max="99"
                  step="1"
                  required
                  :readonly="preview || uncertain"
                /><small>Entre 1 e 99 €, em euros inteiros.</small></label
              >
              <label
                >Método<select v-model="type" :disabled="preview || uncertain">
                  <option v-for="(option, key) in paymentMethods" :key="key" :value="key">
                    {{ option.name }}
                  </option>
                </select></label
              >
              <label
                >{{
                  type === 'PAYPAL'
                    ? 'Email de referência'
                    : type === 'IBAN'
                      ? 'IBAN de referência'
                      : type === 'MB'
                        ? 'Entidade e referência'
                        : 'Referência do método'
                }}<input
                  v-model="reference"
                  type="text"
                  :placeholder="method.sample"
                  maxlength="254"
                  required
                  :readonly="preview"
                  autocomplete="off"
                  spellcheck="false"
                /><small>{{ method.hint }}</small></label
              >
              <label v-if="kind === 'withdrawal'"
                >Palavra-passe atual<input
                  v-model="password"
                  type="password"
                  required
                  autocomplete="current-password"
              /></label>
              <div class="wallet-quote" aria-label="Resumo da operação">
                <div>
                  <span>{{ kind === 'deposit' ? 'Valor convertido' : 'Valor a receber' }}</span
                  ><strong
                    >{{ coins(gross) }} DM <small>({{ euros(Number(value) || 0) }})</small></strong
                  >
                </div>
                <div>
                  <span>Comissão {{ response.rules.fee_percent }}%</span
                  ><strong>{{ kind === 'deposit' ? '−' : '+' }}{{ coins(fee) }} DM</strong>
                </div>
                <div class="wallet-quote-total">
                  <span>{{ kind === 'deposit' ? 'Entra na carteira' : 'Sai da carteira' }}</span
                  ><strong>{{ coins(kind === 'deposit' ? net : debit) }} DM</strong>
                </div>
              </div>
              <p v-if="preview" class="wallet-review-note">
                Confirma o valor, o método e a referência antes de enviar o pedido ao serviço
                simulado.
              </p>
              <button
                class="button-primary"
                :disabled="
                  busy ||
                  response.unresolved ||
                  !!(kind === 'withdrawal' && debit > response.wallet.available)
                "
              >
                {{
                  busy
                    ? 'A processar…'
                    : preview
                      ? 'Confirmar operação simulada'
                      : 'Rever operação ↗'
                }}
              </button>
              <button
                v-if="preview && !busy"
                type="button"
                class="button-text"
                @click="preview = false"
              >
                Voltar aos dados
              </button>
              <p
                v-if="kind === 'withdrawal' && debit > response.wallet.available"
                class="wallet-inline-error"
              >
                Saldo disponível insuficiente para este levantamento e a comissão.
              </p>
            </fieldset>
          </form>
        </section>
        <aside class="surface wallet-explanation">
          <p class="eyebrow">TUDO À VISTA</p>
          <h2>Uma moeda para o clube.</h2>
          <p>
            O saldo da carteira é separado dos teus pontos de rank. Carregar ou levantar Damas não
            altera o teu escalão.
          </p>
          <dl>
            <dt>Carregamento de 10 €</dt>
            <dd>1 000 DM − 100 DM de comissão = <strong>900 DM na carteira.</strong></dd>
            <dt>Levantamento para receber 10 €</dt>
            <dd>1 000 DM + 100 DM de comissão = <strong>1 100 DM debitadas.</strong></dd>
          </dl>
          <p>
            A comissão é calculada sobre o valor em euros da operação. As entradas e prémios dos
            jogos serão ligados à carteira na próxima etapa.
          </p>
          <RouterLink to="/ranking" class="inline-link">Ver o meu rank ↗</RouterLink>
        </aside>
      </div>
      <section class="surface wallet-history">
        <div class="card-heading">
          <div>
            <h2>Histórico de movimentos</h2>
            <p>{{ response.meta.total }} operações · referências protegidas</p>
          </div>
        </div>
        <p v-if="!response.data.length" class="practice-empty">
          Ainda não há movimentos. O primeiro carregamento aparece aqui.
        </p>
        <article v-for="op in response.data" :key="op.id" class="wallet-history-row">
          <div class="wallet-movement-icon" :class="op.kind">
            {{ op.kind === 'deposit' ? '↙' : '↗' }}
          </div>
          <div class="wallet-movement-main">
            <strong
              >{{ walletKinds[op.kind] }} ·
              {{ op.method === 'CLUB' ? op.reference : euros(op.amount_euros) }}</strong
            >
            <p>
              {{ paymentMethods[op.method]?.name ?? op.method }} · {{ op.reference }} ·
              {{ walletDate(op.created_at) }}
            </p>
            <small>Comissão {{ coins(op.fee_coins) }} DM</small>
            <details>
              <summary>Detalhes do pedido</summary>
              <p class="wallet-operation-id">{{ op.id }}</p>
              <p>{{ operationMessage(op) }}</p>
              <p v-if="op.resulting_balance !== null">
                Saldo após a operação: {{ coins(op.resulting_balance) }} DM
              </p>
              <p v-if="op.resolved">Resultado confirmado por um administrador.</p>
            </details>
          </div>
          <div class="wallet-movement-result">
            <span class="wallet-status" :class="op.status">{{ walletStatus[op.status] }}</span
            ><strong v-if="op.delta !== null"
              >{{ op.delta > 0 ? '+' : '' }}{{ coins(op.delta) }} DM</strong
            ><small v-else>{{
              op.kind === 'withdrawal' && ['review', 'processing'].includes(op.status)
                ? `${coins(op.debit_coins)} DM reservadas`
                : 'Sem movimento de saldo'
            }}</small>
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
    </template>
  </section>
</template>
