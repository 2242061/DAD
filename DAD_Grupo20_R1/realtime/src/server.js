import { createServer } from 'node:http'
import { pathToFileURL } from 'node:url'
import { Server } from 'socket.io'

/** Cria o servidor de eco. O R2 não necessita de autenticação. */
export function createEchoServer() {
  const httpServer = createServer((req, res) => {
    if (req.url === '/health') {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' })
      res.end(JSON.stringify({ status: 'ok', service: 'r2-echo' }))
      return
    }
    res.writeHead(404, { 'Content-Type': 'application/json; charset=utf-8' })
    res.end(JSON.stringify({ error: 'not found' }))
  })

  const defaultOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://web-dad-group-20-172.22.21.253.sslip.io',
    'https://web-dad-group-20-172.22.21.253.sslip.io',
  ]
  const origins = process.env.CORS_ORIGINS
    ? process.env.CORS_ORIGINS.split(',').map(x => x.trim()).filter(Boolean)
    : defaultOrigins

  const io = new Server(httpServer, {
    cors: { origin: origins, methods: ['GET', 'POST'] },
    maxHttpBufferSize: 4096,
    pingInterval: 25000,
    pingTimeout: 20000,
  })

  io.on('connection', socket => {
    // O servidor devolve a mesma mensagem APENAS ao cliente que a enviou.
    socket.on('echo', message => {
      if (typeof message !== 'string' || message.length > 1000) return
      socket.emit('echo', message)
    })
  })

  return { httpServer, io }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const { httpServer, io } = createEchoServer()
  const port = Number(process.env.PORT || 3001)
  httpServer.listen(port, '0.0.0.0', () => {
    console.log(`R2 WebSocket ativo na porta ${port}`)
  })
  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => io.close(() => process.exit(0)))
  }
}
