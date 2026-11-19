<script setup lang="ts">
import { ref, onUnmounted } from 'vue'
import { io } from 'socket.io-client'
const email = ref('teste@example.test'), password = ref(''), token = ref('')
const apiResult = ref('Ainda sem pedidos.'), message = ref('Olá, DAD!'), echo = ref('Ainda sem mensagens.')
const connected = ref(false), busy = ref(false)
const socket = io({ autoConnect: false, transports: ['websocket'] })
socket.on('connect', () => { connected.value = true; echo.value = 'Ligado. Envia uma mensagem.' })
socket.on('disconnect', () => { connected.value = false; echo.value = 'Desligado.' })
socket.on('connect_error', () => { echo.value = 'Não foi possível ligar ao servidor Socket.IO.' })
socket.on('echo', (value: string) => { echo.value = value })
onUnmounted(() => socket.disconnect())
async function request(path: string, method = 'GET', body?: object) {
  busy.value = true
  try {
    const response = await fetch('/api' + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token.value ? { Authorization: 'Bearer ' + token.value } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
    const data = response.status === 204 ? null : await response.json()
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${data.message}`)
    return data
  } finally { busy.value = false }
}
async function login() {
  try { const data = await request('/auth/login', 'POST', { email: email.value, password: password.value }); token.value = data.token; password.value = ''; apiResult.value = 'Login efetuado. Testa o endpoint autenticado.' }
  catch (error) { apiResult.value = String(error) }
}
async function me() {
  try { apiResult.value = JSON.stringify(await request('/auth/me'), null, 2) }
  catch (error) { apiResult.value = String(error) }
}
async function logout() {
  try { await request('/auth/logout', 'POST'); token.value = ''; apiResult.value = 'Sessão terminada.' }
  catch (error) { apiResult.value = String(error) }
}
</script>
<template>
  <main>
    <header><p>DAD · Entrega intermédia</p><h1>Teste dos componentes</h1><p>Laravel + SQLite · Node.js + Socket.IO · Vue.js</p></header>
    <section><h2>R1 · API REST</h2>
      <form @submit.prevent="login"><label>Email<input v-model="email" type="email" required autocomplete="username" /></label><label>Palavra-passe<input v-model="password" type="password" required autocomplete="current-password" /></label><button :disabled="busy">Entrar</button></form>
      <div class="actions"><button :disabled="busy" @click="me">Testar GET /api/auth/me</button><button :disabled="busy || !token" @click="logout">Sair</button></div>
      <p>{{ token ? 'Autenticado' : 'Sem autenticação' }}</p><pre role="status">{{ apiResult }}</pre>
    </section>
    <section><h2>R2 · Eco WebSocket</h2><p role="status">{{ connected ? 'Ligado' : 'Desligado' }}</p>
      <div class="actions"><button :disabled="connected" @click="socket.connect()">Ligar</button><button :disabled="!connected" @click="socket.disconnect()">Desligar</button></div>
      <form @submit.prevent="socket.emit('echo', message)"><label>Mensagem<input v-model="message" maxlength="1000" required /></label><button :disabled="!connected">Enviar eco</button></form>
      <pre role="status">{{ echo }}</pre>
    </section>
  </main>
</template>
