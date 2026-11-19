import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { createEchoServer } from '../src/server.js'
const require = createRequire(new URL('../../frontend/package.json', import.meta.url))
const { io: client } = require('socket.io-client')
test('eco por WebSocket devolve a mensagem apenas ao emissor', async () => {
  const { server, io } = createEchoServer()
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  const socket = client(`http://127.0.0.1:${server.address().port}`, { transports: ['websocket'] })
  try {
    await new Promise((resolve, reject) => { socket.once('connect', resolve); socket.once('connect_error', reject) })
    const result = new Promise(resolve => socket.once('echo', resolve))
    socket.emit('echo', 'Olá, DAD!')
    assert.equal(await result, 'Olá, DAD!')
  } finally { socket.disconnect(); await new Promise(resolve => io.close(resolve)) }
})
