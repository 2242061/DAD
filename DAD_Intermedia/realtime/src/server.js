import { createServer } from 'node:http'
import { Server } from 'socket.io'
import { pathToFileURL } from 'node:url'
export function createEchoServer() {
  const server = createServer((req, res) => {
    res.writeHead(req.url === '/health' ? 200 : 404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: req.url === '/health' ? 'ok' : 'not found' }))
  })
  const io = new Server(server, { maxHttpBufferSize: 4096 })
  io.on('connection', socket => {
    socket.on('echo', message => {
      if (typeof message === 'string' && message.length <= 1000) socket.emit('echo', message)
    })
  })
  return { server, io }
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const { server, io } = createEchoServer()
  server.listen(Number(process.env.PORT || 3001), process.env.HOST || '0.0.0.0')
  for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => io.close(() => process.exit(0)))
}
