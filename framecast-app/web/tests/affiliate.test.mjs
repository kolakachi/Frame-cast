import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { captureAffiliate } from '../src/services/affiliate.js'
const id = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa'
function browser() {
  const values = new Map()
  const timers = []
  const win = {
    location: { href: `https://app.wyvstudio.com/register?aff=partner&aff_visit=${id}` },
    crypto: { randomUUID: () => id },
    localStorage: { getItem: k => values.get(k), setItem: (k,v) => values.set(k,v), removeItem: k => values.delete(k) },
    history: { state: { position: 2 }, replaceState(state, _, url) { assert.equal(state.position, 2); win.location.href = url } },
    setTimeout: fn => timers.push(fn), addEventListener() {},
  }
  return { win, timers, values }
}
test('failed capture retains URL and durable event; retry clears only after acknowledgment', async () => {
  const { win, timers, values } = browser()
  let calls = 0
  const api = { async post(path, data) { assert.equal(data.event_id, id); if (++calls === 1) throw Error('offline') } }
  await captureAffiliate(api, win)
  assert.match(win.location.href, /aff=partner/)
  assert.ok(values.get('wyv_aff_pending'))
  await timers.shift()()
  assert.equal(calls, 2)
  assert.equal(win.location.href, 'https://app.wyvstudio.com/register')
  assert.equal(values.has('wyv_aff_pending'), false)
})
test('pending event recovers on a later app load without referral parameters', async () => {
  const { win, values } = browser()
  values.set('wyv_aff_pending', JSON.stringify({code:'partner', event_id:id, at:Date.now()}))
  win.location.href = 'https://app.wyvstudio.com/plans'
  let received
  await captureAffiliate({ async post(_, body) { received = body } }, win)
  assert.equal(received.event_id, id)
  assert.equal(values.size, 0)
})
test('marketing arrival records before CTA click and carries the same event into app link', async () => {
  const { win } = browser()
  win.location = {href:'https://wyvstudio.com/?ref=partner',search:'?ref=partner',pathname:'/'}
  const anchor = {href:'https://app.wyvstudio.com/register?plan=creator',dataset:{}}
  const calls = []
  const context = { window:win, location:win.location, crypto:win.crypto, URL, URLSearchParams, Date,
    setTimeout() {}, fetch: async (url, options) => { calls.push({url, body:JSON.parse(options.body)}); return {ok:true,json:async()=>({data:{tracked:true}})} },
    document:{readyState:'complete',querySelectorAll:()=>[anchor],addEventListener(){}},
  }
  vm.runInNewContext(readFileSync(new URL('../../marketing/affiliate-ref.js', import.meta.url),'utf8'), context)
  assert.equal(calls.length,1)
  assert.equal(calls[0].url,'/api/v1/affiliate/click')
  assert.equal(calls[0].body.event_id,id)
  assert.equal(new URL(anchor.href).searchParams.get('aff_visit'),id)
  assert.equal(new URL(anchor.href).searchParams.get('aff'),'partner')
})
