import { createServer } from 'node:http'
import { createHmac, timingSafeEqual } from 'node:crypto'
import { Server } from 'socket.io'

const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i
function safeEqual(a, b) {
  const left = Buffer.from(a), right = Buffer.from(b)
  return left.length === right.length && timingSafeEqual(left, right)
}
export function verifyTicket(ticket, secret, now = Math.floor(Date.now() / 1000)) {
  if (typeof ticket !== 'string' || ticket.length > 2048 || secret.length < 32) throw new Error('Invalid ticket')
  const parts = ticket.split('.')
  if (parts.length !== 2 || !safeEqual(createHmac('sha256', secret).update(parts[0]).digest('base64url'), parts[1])) throw new Error('Invalid signature')
  const data = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'))
  if (data.aud !== 'dama-game' || !uuid.test(data.game) || !['white','black','spectator'].includes(data.color) || !Number.isInteger(data.exp) || data.exp <= now || data.exp > now + 120) throw new Error('Expired or invalid ticket')
  return data
}

export function createGameServer(secret) {
  let io
  const server = createServer(async (request, response) => {
    response.setHeader('Content-Type', 'application/json')
    response.setHeader('Cache-Control', 'no-store')
    if (request.method === 'GET' && request.url === '/health') {
      response.end(JSON.stringify({status:'ok', service:'realtime'})); return
    }
    if (request.method !== 'POST' || request.url !== '/internal/games') {
      response.writeHead(404).end('{}'); return
    }
    if (secret.length < 32 || !safeEqual(request.headers.authorization ?? '', `Bearer ${secret}`)) {
      response.writeHead(403).end('{}'); request.resume(); return
    }
    try {
      let body = ''
      for await (const chunk of request) {
        body += chunk
        if (Buffer.byteLength(body) > 4096) { response.writeHead(413).end('{}'); return }
      }
      const message = JSON.parse(body)
      if (!uuid.test(message.game) || !Number.isInteger(message.revision) || message.revision < 0) throw new Error('Invalid event')
      io.to(`game:${message.game}`).emit('game:changed', {game:message.game, revision:message.revision})
      response.writeHead(202).end('{}')
    } catch { response.writeHead(400).end('{}') }
  })
  server.requestTimeout = 5000
  io = new Server(server, {maxHttpBufferSize: 8192})
  io.use((socket, next) => {
    if (!socket.handshake.auth?.ticket) return next() // Public health probe; never joins a game.
    try { socket.data.game = verifyTicket(socket.handshake.auth.ticket, secret); next() }
    catch { next(new Error('O acesso à sala expirou. Volta a ligar.')) }
  })
  io.on('connection', async socket => {
    if (socket.data.game) {
      await socket.join(`game:${socket.data.game.game}`)
      socket.emit('game:ready', {game:socket.data.game.game})
    }
    socket.emit('server:ready', {message:'Ligação em tempo real estabelecida.'})
  })
  return {server, io}
}
