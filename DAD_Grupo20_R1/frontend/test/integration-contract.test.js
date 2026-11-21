import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8')
const vite = readFileSync(new URL('../vite.config.js', import.meta.url), 'utf8')
const deploy = readFileSync(new URL('../../deploy/kubernetes-r3.yaml', import.meta.url), 'utf8')

test('A SPA contém os três endpoints do R1', () => {
  for (const path of ['/api/auth/login', '/api/auth/me', '/api/auth/logout']) {
    assert.ok(app.includes(path), `Rota ausente: ${path}`)
  }
})
test('A SPA usa o evento echo do R2', () => {
  assert.ok(app.includes("socket.emit('echo'"))
  assert.ok(app.includes("socket.on('echo'"))
})
test('Vite e Ingress encaminham API e WebSocket', () => {
  for (const path of ['/api', '/socket.io']) {
    assert.ok(vite.includes(path))
    assert.ok(deploy.includes(path))
  }
})
