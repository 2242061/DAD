import test from 'node:test'
import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { once } from 'node:events'
import { io as client } from '../../frontend/node_modules/socket.io-client/build/esm/index.js'
import { createGameServer, verifyTicket } from '../src/game-server.js'
const secret = 'a'.repeat(64)
const room = '11111111-1111-4111-8111-111111111111'
const otherRoom = '22222222-2222-4222-8222-222222222222'
function ticket(game = room, overrides = {}, key = secret) {
  const payload = Buffer.from(JSON.stringify({aud:'dama-game', game, color:'white', exp:Math.floor(Date.now()/1000)+90, ...overrides})).toString('base64url')
  return `${payload}.${createHmac('sha256',key).update(payload).digest('base64url')}`
}
test('signed tickets reject tampering, expired access, invalid rooms and wrong audience', () => {
  assert.equal(verifyTicket(ticket(), secret).game, room)
  for (const bad of [ticket(room, {}, 'wrong'), ticket(room, {exp:1}), ticket(room, {exp:Math.floor(Date.now()/1000)+500}), ticket('arbitrary'), ticket(room, {aud:'other'}), ticket(room, {color:'admin'}), 'bad', ticket()+'.extra']) assert.throws(() => verifyTicket(bad,secret))
})

test('only server-authorized rooms receive updates; reconnection joins again', {timeout:15000}, async t => {
  const {server, io} = createGameServer(secret)
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve))
  const base = `http://127.0.0.1:${server.address().port}`
  const sockets = []
  t.after(async () => { sockets.forEach(s => s.disconnect()); await new Promise(resolve => io.close(resolve)) })
  async function connect(auth) {
    const socket = client(base, {auth, transports:['websocket'], reconnection:false, autoConnect:false})
    sockets.push(socket)
    const ready = once(socket, auth?.ticket ? 'game:ready' : 'server:ready', {signal:AbortSignal.timeout(3000)})
    socket.connect(); await ready; return socket
  }
  const first = await connect({ticket:ticket()})
  const second = await connect({ticket:ticket(room,{color:'black'})})
  const spectator = await connect({ticket:ticket(room,{color:'spectator'})})
  const other = await connect({ticket:ticket(otherRoom)})
  const probe = await connect({})
  const received = [0,0,0,0,0]
  ;[first,second,other,probe,spectator].forEach((s,i) => s.on('game:changed', () => received[i]++))
  const publish = (key = secret) => fetch(`${base}/internal/games`, {method:'POST', headers:{authorization:`Bearer ${key}`,'content-type':'application/json'},body:JSON.stringify({game:room,revision:2})})
  assert.equal((await publish('invalid')).status,403)
  const firstUpdate = once(first,'game:changed',{signal:AbortSignal.timeout(3000)})
  const secondUpdate = once(second,'game:changed',{signal:AbortSignal.timeout(3000)})
  const spectatorUpdate = once(spectator,'game:changed',{signal:AbortSignal.timeout(3000)})
  assert.equal((await publish()).status,202)
  assert.equal((await firstUpdate)[0].revision,2); await secondUpdate
  assert.equal((await spectatorUpdate)[0].revision,2)
  assert.deepEqual(received,[1,1,0,0,1])
  first.emit('game:changed',{game:otherRoom,revision:99})
  first.disconnect()
  const ready = once(first,'game:ready',{signal:AbortSignal.timeout(3000)})
  first.connect(); await ready
  const update = once(first,'game:changed',{signal:AbortSignal.timeout(3000)})
  await publish(); await update
  assert.equal(received[0],2)
  assert.equal(received[2],0)
  assert.equal(received[3],0)
  const rejected = client(base,{auth:{ticket:ticket(room,{},'wrong')},transports:['websocket'],reconnection:false,autoConnect:false})
  sockets.push(rejected)
  const denied = once(rejected,'connect_error',{signal:AbortSignal.timeout(3000)})
  rejected.connect(); assert.match((await denied)[0].message,/expirou/)
})
