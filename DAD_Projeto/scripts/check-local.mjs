import assert from 'node:assert/strict'
import { io } from '../frontend/node_modules/socket.io-client/build/esm/index.js'

// Executar com os três servidores locais ativos.
const base = 'http://127.0.0.1:5173'
const page = await fetch(base, { signal: AbortSignal.timeout(5000) })
assert.equal(page.status, 200)
assert.match(await page.text(), /Projeto DAD/)

const api = await fetch(`${base}/api/health`, { signal: AbortSignal.timeout(5000) })
assert.equal(api.status, 200)
assert.deepEqual(await api.json(), { status: 'ok', service: 'api', database: 'connected' })

const realtime = await fetch('http://127.0.0.1:3001/health', { signal: AbortSignal.timeout(5000) })
assert.equal(realtime.status, 200)
assert.equal((await realtime.json()).status, 'ok')

await new Promise((resolve, reject) => {
  const socket = io(base, { transports: ['websocket'], reconnection: false })
  const finish = (error) => {
    clearTimeout(timer)
    socket.disconnect()
    error ? reject(error) : resolve()
  }
  const timer = setTimeout(() => finish(new Error('Timeout Socket.IO')), 5000)
  socket.on('server:ready', () => finish())
  socket.on('connect_error', finish)
})

console.log('OK: frontend, API, SQLite e WebSocket através do proxy Vite.')
