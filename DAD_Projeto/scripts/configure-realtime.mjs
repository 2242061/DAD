import { randomBytes } from 'node:crypto'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve, dirname } from 'node:path'
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const backend = resolve(root, 'backend/.env')
const realtime = resolve(root, 'realtime/.env')
const source = readFileSync(backend, 'utf8')
const existing = source.match(/^REALTIME_SECRET=([a-f0-9]{64})$/m)?.[1]
const secret = existing ?? randomBytes(32).toString('hex')
if (!existing) writeFileSync(backend, source.replace(/^REALTIME_SECRET=.*\n?/m, '') + `\nREALTIME_SECRET=${secret}\n`, {mode:0o600})
const nodeEnv = existsSync(realtime) ? readFileSync(realtime, 'utf8') : ''
writeFileSync(realtime, nodeEnv.replace(/^REALTIME_SECRET=.*\n?/m, '') + `\nREALTIME_SECRET=${secret}\n`, {mode:0o600})
console.log('Ligação privada Laravel ↔ Socket.IO configurada. Reinicia os dois serviços se já estavam ligados.')
