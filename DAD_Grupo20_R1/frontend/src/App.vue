<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { io } from 'socket.io-client'

const email = ref('teste@example.test')
const password = ref('')
const token = ref('') // apenas em memória: não guardamos o token no browser.
const user = ref(null)
const loginBusy = ref(false)
const authMessage = ref('Ainda não iniciou sessão.')
const authError = ref(false)
const meBusy = ref(false)
const logoutBusy = ref(false)

const message = ref('Olá DAD — Grupo 20!')
const sentMessage = ref('')
const receivedMessage = ref('')
const wsStatus = ref('desligado')
const wsError = ref('')
const echoBusy = ref(false)
let socket = null
let pendingTimer = null

const hasToken = computed(() => Boolean(token.value))
const echoMatches = computed(() =>
  Boolean(sentMessage.value) && receivedMessage.value === sentMessage.value,
)

async function jsonRequest(path, method = 'GET', body = null, authorization = '') {
  const headers = { Accept: 'application/json' }
  if (body !== null) headers['Content-Type'] = 'application/json'
  if (authorization) headers.Authorization = `Bearer ${authorization}`
  const response = await fetch(path, {
    method,
    headers,
    body: body === null ? undefined : JSON.stringify(body),
  })
  let data = null
  const text = await response.text()
  if (text) {
    try { data = JSON.parse(text) } catch { data = { message: text.slice(0, 200) } }
  }
  if (!response.ok) {
    const detail = data?.message || data?.errors?.email?.[0] || `Erro HTTP ${response.status}`
    throw new Error(`${response.status}: ${detail}`)
  }
  return data
}

async function login() {
  if (!email.value || !password.value) {
    authError.value = true
    authMessage.value = 'Preencha o email e a palavra-passe.'
    return
  }
  loginBusy.value = true
  authError.value = false
  authMessage.value = 'A autenticar...'
  try {
    const result = await jsonRequest('/api/auth/login', 'POST', {
      email: email.value.trim(),
      password: password.value,
    })
    if (!result?.token) throw new Error('A resposta não contém um token.')
    token.value = result.token
    password.value = ''
    user.value = null
    authMessage.value = 'LOGIN OK — token recebido. Pode consultar o utilizador.'
  } catch (error) {
    token.value = ''
    user.value = null
    authError.value = true
    authMessage.value = `Não foi possível iniciar sessão: ${error.message}`
  } finally {
    loginBusy.value = false
  }
}

async function getMe() {
  if (!token.value) return
  meBusy.value = true
  authError.value = false
  try {
    user.value = await jsonRequest('/api/auth/me', 'GET', null, token.value)
    authMessage.value = 'GET /api/auth/me — pedido autenticado concluído.'
  } catch (error) {
    user.value = null
    authError.value = true
    authMessage.value = `Erro ao consultar o utilizador: ${error.message}`
  } finally {
    meBusy.value = false
  }
}

async function logout() {
  if (!token.value) return
  logoutBusy.value = true
  authError.value = false
  try {
    await jsonRequest('/api/auth/logout', 'POST', null, token.value)
    token.value = ''
    user.value = null
    authMessage.value = 'LOGOUT OK — token invalidado pelo servidor.'
  } catch (error) {
    authError.value = true
    authMessage.value = `Erro ao terminar sessão: ${error.message}`
  } finally {
    logoutBusy.value = false
  }
}

function closeSocket() {
  clearTimeout(pendingTimer)
  pendingTimer = null
  echoBusy.value = false
  if (socket) {
    socket.removeAllListeners()
    socket.disconnect()
    socket = null
  }
  wsStatus.value = 'desligado'
}

function connectSocket() {
  closeSocket()
  wsError.value = ''
  wsStatus.value = 'a ligar...'
  socket = io(window.location.origin, {
    path: '/socket.io',
    transports: ['websocket'],
    reconnection: false,
    timeout: 8000,
    autoConnect: true,
  })
  socket.on('connect', () => {
    wsStatus.value = 'ligado'
    wsError.value = ''
  })
  socket.on('connect_error', error => {
    wsStatus.value = 'erro'
    wsError.value = `Não foi possível ligar: ${error.message}`
    echoBusy.value = false
    clearTimeout(pendingTimer)
  })
  socket.on('disconnect', () => {
    wsStatus.value = 'desligado'
    echoBusy.value = false
    clearTimeout(pendingTimer)
  })
  socket.on('echo', value => {
    receivedMessage.value = typeof value === 'string' ? value : String(value)
    echoBusy.value = false
    clearTimeout(pendingTimer)
    pendingTimer = null
    if (receivedMessage.value !== sentMessage.value) {
      wsError.value = 'A resposta é diferente da mensagem enviada.'
    }
  })
}

function sendEcho() {
  if (!socket?.connected || !message.value.trim()) return
  sentMessage.value = message.value
  receivedMessage.value = ''
  wsError.value = ''
  echoBusy.value = true
  clearTimeout(pendingTimer)
  pendingTimer = setTimeout(() => {
    echoBusy.value = false
    wsError.value = 'Tempo limite: o servidor não devolveu a mensagem.'
  }, 8000)
  socket.emit('echo', sentMessage.value)
}

onBeforeUnmount(closeSocket)
</script>

<template>
  <div class="page-shell">
    <header class="site-header">
      <div class="brand"><span class="logo">20</span><div><strong>DAD · Grupo 20</strong><small>Engenharia Informática · ESTG</small></div></div>
      <span class="header-chip">Entrega intermédia · 2026/27</span>
    </header>

    <main class="container">
      <section class="hero">
        <div class="eyebrow">Plataforma de Damas Portuguesas</div>
        <h1>Validação dos componentes</h1>
        <p>Interface de demonstração dos requisitos R1 e R2, integrada numa aplicação Vue.js (R3).</p>
        <div class="pill-row">
          <span class="pill"><span class="dot green"></span> R1 · API REST</span>
          <span class="pill"><span class="dot blue"></span> R2 · WebSocket</span>
          <span class="pill"><span class="dot violet"></span> R3 · SPA Vue</span>
        </div>
      </section>

      <div class="cards">
        <section class="panel" aria-labelledby="auth-title">
          <div class="panel-head"><div><span class="kicker">REQUISITO R1</span><h2 id="auth-title">Autenticação Laravel</h2><p>Inicie sessão utilizando as credenciais de demonstração.</p></div><span class="number">01</span></div>
          <form @submit.prevent="login" class="form-grid">
            <label for="email">Email</label>
            <input id="email" v-model="email" type="email" autocomplete="username" required placeholder="teste@example.test" />
            <label for="password">Palavra-passe</label>
            <input id="password" v-model="password" type="password" autocomplete="current-password" required placeholder="Palavra-passe configurada no R1" />
            <button type="submit" class="btn primary" :disabled="loginBusy">{{ loginBusy ? 'A autenticar...' : 'Iniciar sessão' }}</button>
          </form>
          <div class="status" :class="authError ? 'error' : hasToken ? 'success' : ''" role="status">{{ authMessage }}</div>
          <div class="actions">
            <button class="btn secondary" :disabled="!hasToken || meBusy" @click="getMe">{{ meBusy ? 'A consultar...' : 'Consultar utilizador' }}</button>
            <button class="btn ghost" :disabled="!hasToken || logoutBusy" @click="logout">{{ logoutBusy ? 'A terminar...' : 'Terminar sessão' }}</button>
          </div>
          <div v-if="user" class="result">
            <div class="result-title">Dados da rota protegida <span>200 OK</span></div>
            <dl><dt>Identificador</dt><dd>{{ user.id }}</dd><dt>Nome</dt><dd>{{ user.name }}</dd><dt>Email</dt><dd>{{ user.email }}</dd></dl>
          </div>
          <p class="footnote">O token fica apenas na memória e é eliminado da interface após o logout ou ao atualizar a página.</p>
        </section>

        <section class="panel" aria-labelledby="ws-title">
          <div class="panel-head"><div><span class="kicker">REQUISITO R2</span><h2 id="ws-title">Eco WebSocket</h2><p>Envie uma mensagem e confirme a resposta do servidor.</p></div><span class="number">02</span></div>
          <div class="socket-row"><div class="connection-indicator" :class="wsStatus === 'ligado' ? 'active' : ''"><span class="dot" :class="wsStatus === 'ligado' ? 'green' : ''"></span> Ligação: {{ wsStatus }}</div><button class="btn secondary" @click="wsStatus === 'ligado' ? closeSocket() : connectSocket()">{{ wsStatus === 'ligado' ? 'Desligar' : 'Ligar WebSocket' }}</button></div>
          <label for="message">Mensagem</label>
          <textarea id="message" v-model="message" rows="3" maxlength="1000" placeholder="Escreva uma mensagem de teste"></textarea>
          <button class="btn primary send" :disabled="wsStatus !== 'ligado' || echoBusy || !message.trim()" @click="sendEcho">{{ echoBusy ? 'À espera da resposta...' : 'Enviar mensagem' }}</button>
          <div v-if="wsError" class="status error" role="alert">{{ wsError }}</div>
          <div class="message-list">
            <div class="message-row"><span>Enviada</span><div>{{ sentMessage || 'Ainda não foi enviada nenhuma mensagem.' }}</div></div>
            <div class="message-row"><span>Recebida</span><div>{{ receivedMessage || 'À espera do servidor.' }}</div></div>
          </div>
          <div v-if="echoMatches && !echoBusy && !wsError" class="status success" role="status">ECO OK — a mensagem recebida é igual à enviada.</div>
          <p class="footnote">Evento Socket.IO: <code>echo</code> · A ligação é feita através do mesmo endereço desta página.</p>
        </section>
      </div>
      <section class="info-strip"><div><strong>R3 — SPA de testes</strong><p>Esta interface usa <code>/api</code> para o Laravel e <code>/socket.io</code> para o Node.js, com encaminhamento pelo Vite (local) ou pelo Ingress (ESTG).</p></div><span class="info-icon">↗</span></section>
    </main>
    <footer>Projeto de Desenvolvimento de Aplicações Distribuídas · Grupo 20</footer>
  </div>
</template>
