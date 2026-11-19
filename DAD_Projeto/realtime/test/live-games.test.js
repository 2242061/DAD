import test from 'node:test'
import assert from 'node:assert/strict'
import { once } from 'node:events'
import { io } from '../../frontend/node_modules/socket.io-client/build/esm/index.js'
const base = process.env.DAD_TEST_URL ?? 'http://127.0.0.1:5173'
function player() {
  const cookies = new Map()
  return async (path, method = 'GET', body) => {
    const response = await fetch(base + path, {
      method, headers: {Origin:base, Accept:'application/json', 'Content-Type':'application/json',
        Cookie:[...cookies].map(([k,v]) => `${k}=${v}`).join('; '),
        ...(cookies.has('XSRF-TOKEN') ? {'X-XSRF-TOKEN':decodeURIComponent(cookies.get('XSRF-TOKEN'))} : {}),
      }, ...(body ? {body:JSON.stringify(body)} : {}), signal:AbortSignal.timeout(5000),
    })
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(';')[0]; const split = pair.indexOf('='); cookies.set(pair.slice(0,split),pair.slice(split+1))
    }
    return {status:response.status, ...(await response.json().catch(() => ({})))}
  }
}

test('live Laravel + SQLite + Vite + Socket.IO: competing joins, two players, stale moves and resynchronization', {skip:process.env.DAD_LIVE_TESTS !== '1',timeout:25000}, async t => {
  const white = player(), first = player(), second = player()
  for (const person of [white,first,second]) assert.equal((await person('/sanctum/csrf-cookie')).status,204)
  const created = await white('/api/games','POST',{})
  assert.equal(created.status,201)
  const path = `/api/games/${created.data.code}`
  const joins = await Promise.all([first(path+'/join','POST',{}),second(path+'/join','POST',{})])
  assert.deepEqual(joins.map(r => r.status).sort(),[200,409])
  const black = joins[0].status === 200 ? first : second
  const intruder = joins[0].status === 200 ? second : first
  assert.equal((await intruder(path)).status,403)
  const ticket = (await black(path+'/ticket')).ticket
  const socket = io(base,{auth:{ticket},transports:['websocket'],reconnection:false,autoConnect:false})
  t.after(() => socket.disconnect())
  const ready = once(socket,'game:ready',{signal:AbortSignal.timeout(4000)})
  socket.connect(); await ready
  const update = once(socket,'game:changed',{signal:AbortSignal.timeout(4000)})
  const moved = await white(path,'PATCH',{revision:1,action:'move',path:[9,13]})
  assert.equal(moved.status,200)
  assert.equal((await update)[0].revision,2)
  assert.equal((await white(path,'PATCH',{revision:1,action:'move',path:[10,14]})).status,409)
  assert.equal((await white(path,'PATCH',{revision:2,action:'move',path:[22,18]})).status,403)
  const recovered = await black(path)
  assert.equal(recovered.data.history[0].notation,'9-13')
  assert.equal((await black(path,'PATCH',{revision:2,action:'move',path:[22,18]})).status,200)
  assert.equal((await white(path)).data.history[1].notation,'22-18')
  const ended = await black(path,'PATCH',{revision:3,action:'resign'})
  assert.equal(ended.data.winner,'white')
  assert.equal((await white(path)).data.phase,'finished')
})
