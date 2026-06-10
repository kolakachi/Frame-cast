// Reverb websocket load — many concurrent progress subscribers.
//
// During generation, every user on the progress page holds a websocket to
// Reverb. This opens N connections, subscribes to the public connection
// channel, holds them, and checks they don't get dropped. Hundreds of idle
// websockets are cheap; this confirms Reverb doesn't choke at launch scale.
import ws from 'k6/ws'
import { check } from 'k6'

const HOST = __ENV.WS_URL || 'wss://app.wyvstudio.com/app/framecast-app-key'

export const options = {
  stages: [
    { duration: '30s', target: 50 },
    { duration: '1m', target: 200 },
    { duration: '30s', target: 0 },
  ],
}

export default function () {
  const res = ws.connect(HOST, {}, function (socket) {
    socket.on('open', () => {
      // Pusher protocol: subscribe to a public channel.
      socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'load-test' } }))
    })
    socket.on('message', (msg) => {
      check(msg, { 'got a frame': (m) => m && m.length > 0 })
    })
    socket.setTimeout(() => socket.close(), 20000) // hold 20s then close
  })
  check(res, { 'ws connected (101)': (r) => r && r.status === 101 })
}
