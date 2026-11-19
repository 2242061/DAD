import { loadEnvFile } from 'node:process'
import { fileURLToPath } from 'node:url'
import { createGameServer } from './game-server.js'
try { loadEnvFile(fileURLToPath(new URL('../.env', import.meta.url))) }
catch (error) { if (error.code !== 'ENOENT') throw error }
const secret = process.env.REALTIME_SECRET ?? ''
if (secret.length < 32) console.warn('Partidas online indisponíveis: executa node scripts/configure-realtime.mjs na pasta principal.')
const {server, io} = createGameServer(secret)
const port = Number(process.env.PORT ?? 3001)
const host = process.env.HOST ?? '127.0.0.1'
server.listen(port, host, () => console.log(`Servidor de tempo real: http://${host}:${port}`))
for (const signal of ['SIGINT','SIGTERM']) process.on(signal, () => io.close(() => process.exit(0)))
