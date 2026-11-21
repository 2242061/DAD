// Teste manual automatizado. Local: npm run teste:eco
// Cluster: npm run teste:eco -- http://ws-dad-group-20-172.22.21.253.sslip.io
import { io } from 'socket.io-client'

const url = process.argv[2] || 'http://127.0.0.1:3001'
const mensagem = 'Olá DAD — Grupo 20!'
const socket = io(url, { transports: ['websocket'], reconnection: false, timeout: 8000 })
const timer = setTimeout(() => fail('Tempo limite: não recebemos o eco.'), 12000)

function finish(exitCode) {
  clearTimeout(timer)
  socket.disconnect()
  process.exitCode = exitCode
}
function fail(reason) {
  console.error(`ERRO R2: ${reason}`)
  finish(1)
}
socket.on('connect', () => {
  console.log(`Ligação WebSocket: OK (${url})`)
  console.log(`Enviada: ${mensagem}`)
  socket.emit('echo', mensagem)
})
socket.once('echo', resposta => {
  console.log(`Recebida: ${resposta}`)
  if (resposta === mensagem) {
    console.log('ECO OK — R2 a funcionar!')
    finish(0)
  } else {
    fail('A mensagem recebida não é igual à enviada.')
  }
})
socket.once('connect_error', err => fail(`ligação recusada: ${err.message}`))
