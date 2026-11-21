import { test } from 'node:test'
import assert from 'node:assert/strict'
import { io as client } from 'socket.io-client'
import { createEchoServer } from '../src/server.js'

async function startServer() {
  const { httpServer, io } = createEchoServer()
  await new Promise(resolve => httpServer.listen(0, '127.0.0.1', resolve))
  return { httpServer, io, address: `http://127.0.0.1:${httpServer.address().port}` }
}

function connect(address) {
  return new Promise((resolve, reject) => {
    const socket = client(address, { transports: ['websocket'], reconnection: false, timeout: 3000 })
    socket.once('connect', () => resolve(socket))
    socket.once('connect_error', reject)
  })
}

test('R2: devolve o texto enviado no evento echo', async () => {
  const { io, address } = await startServer()
  let socket
  try {
    socket = await connect(address)
    const answer = new Promise((resolve, reject) => {
      socket.once('echo', resolve)
      setTimeout(() => reject(new Error('O eco não foi recebido')), 2000).unref()
    })
    socket.emit('echo', 'Olá DAD!')
    assert.equal(await answer, 'Olá DAD!')
  } finally {
    socket?.disconnect()
    await new Promise(resolve => io.close(resolve))
  }
})

test('R2: endpoint health responde 200', async () => {
  const { io, address } = await startServer()
  try {
    const response = await fetch(`${address}/health`)
    assert.equal(response.status, 200)
    assert.deepEqual(await response.json(), { status: 'ok', service: 'r2-echo' })
  } finally {
    await new Promise(resolve => io.close(resolve))
  }
})
