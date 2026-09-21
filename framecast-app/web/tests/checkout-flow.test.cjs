const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')
const src = path.join(__dirname, '../src')
function harness(file, exposed, overrides = {}) {
  const store = new Map()
  const redirects = []
  const callbacks = []
  const context = vm.createContext({
    ref: value => ({ value }), reactive: value => value,
    computed: callback => ({ get value() { return callback() } }),
    onMounted: callback => callbacks.push(callback), onUnmounted: () => {},
    setTimeout: () => 1, clearTimeout: () => {},
    useRoute: () => ({ query: { pass: '1' } }),
    useRouter: () => ({ replace: async value => redirects.push(value) }),
    useAuthStore: () => ({ isAuthenticated: true }),
    localStorage: { getItem: k => store.get(k) ?? null, setItem: (k,v) => store.set(k,v), removeItem: k => store.delete(k) },
    window: { location: { href: '' } }, api: {}, ...overrides,
  })
  let code = fs.readFileSync(path.join(src, file), 'utf8')
  if (file.endsWith('.vue')) code = code.match(/<script setup>([\s\S]*?)<\/script>/)[1]
  code = code.replace(/^import .*$/gm, '').replace(/export async function/g, 'async function')
  vm.runInContext(code + `\nglobalThis.exposed = { ${exposed} };`, context)
  return { context, api: context.exposed, store, redirects, callbacks }
}
test('registration authenticates then routes the selected pass to checkout', async () => {
  let registered = false
  const h = harness('views/RegisterView.vue', 'submit, form, pendingPlan', {
    useAuthStore: () => ({ register: async (...args) => { registered = true; assert.equal(args[3], 'ugc_pass') } }),
  })
  h.api.form.name = 'Buyer'; h.api.form.email = 'buyer@example.com'; h.api.pendingPlan.value = 'ugc_pass'
  await h.api.submit()
  assert.equal(registered, true)
  assert.equal(h.redirects[0].name, 'continue-checkout')
  assert.equal(h.redirects[0].query.plan, 'ugc_pass')
})
test('checkout failure preserves intent and retry opens payment', async () => {
  let fail = true
  const h = harness('views/ContinueCheckoutView.vue', 'startCheckout, state', {
    api: { post: async (_, body) => { assert.equal(body.pass, true); if (fail) throw new Error('Provider unavailable'); return { data: { data: { url: 'https://pay.example/session' } } } } },
  })
  await h.api.startCheckout()
  assert.equal(h.api.state.value, 'failed')
  assert.equal(h.store.get('wyv_pending_plan'), 'ugc_pass')
  assert.equal(h.redirects.length, 0)
  fail = false; await h.api.startCheckout()
  assert.equal(h.context.window.location.href, 'https://pay.example/session')
  assert.equal(h.store.has('wyv_pending_plan'), false)
})
test('magic/password sign-in uses server intent on another device', async () => {
  const h = harness('composables/resumeCheckout.js', 'resumePendingCheckout', {
    api: { get: async () => ({ data: { data: { billing: { checkout_required: true, checkout_plan: 'ugc_pass' } } } }) },
  })
  assert.equal(await h.api.resumePendingCheckout(), true)
  assert.equal(h.context.window.location.href, '/continue?plan=ugc_pass')
})
test('billing failure goes to retry screen rather than dashboard', async () => {
  const h = harness('composables/resumeCheckout.js', 'resumePendingCheckout', {
    api: { get: async () => { throw new Error('offline') } },
  })
  assert.equal(await h.api.resumePendingCheckout(), true)
  assert.equal(h.context.window.location.href, '/continue')
})
test('paid customers without a selected purchase continue normally', async () => {
  const h = harness('composables/resumeCheckout.js', 'resumePendingCheckout', {
    api: { get: async () => ({ data: { data: { billing: { checkout_required: false } } } }) },
  })
  assert.equal(await h.api.resumePendingCheckout(), false)
})
test('signed-out checkout preserves the plan in the login URL', async () => {
  const h = harness('views/ContinueCheckoutView.vue', 'startCheckout', { useAuthStore: () => ({ isAuthenticated: false }) })
  await h.api.startCheckout()
  assert.equal(h.redirects[0].name, 'login')
  assert.equal(h.redirects[0].query.plan, 'ugc_pass')
})
test('router preserves checkout selection and exempts magic links/plans from onboarding', async () => {
  const source = fs.readFileSync(path.join(src, 'router/index.js'), 'utf8')
  let guard
  const context = vm.createContext({ router: { beforeEach: fn => { guard = fn } }, useAuthStore: () => ({ isAuthenticated: true, isOnboarded: false, user: { role: 'owner' } }) })
  vm.runInContext(source.slice(source.indexOf('router.beforeEach(')).replace('export default router', ''), context)
  const selected = await guard({ name: 'register', meta: { guestOnly: true }, query: { pass: '1' } })
  assert.equal(selected.name, 'continue-checkout'); assert.equal(selected.query.pass, '1')
  for (const name of ['magic-link', 'plans', 'continue-checkout']) {
    const line = source.split('\n').find(line => line.includes(`name: '${name}', component:`))
    const meta = vm.runInContext('(' + line.match(/meta: (\{[^}]+\})/)[1] + ')', context)
    assert.equal(await guard({ name, meta, query: {} }), true)
  }
})

test('confirmation waits for verified server receipt before onboarding', async () => {
  let confirmed = false
  const h = harness('views/PaymentConfirmationView.vue', 'checkPayment, state', {
    useRoute: () => ({ query: { attempt: 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa' } }),
    useAuthStore: () => ({ isAuthenticated: true, isOnboarded: false, refreshUser: async () => {} }),
    api: { get: async () => ({ data: { data: { confirmed } } }) },
  })
  await h.api.checkPayment()
  assert.equal(h.redirects.length, 0)
  confirmed = true
  await h.api.checkPayment()
  assert.equal(h.redirects[0].name, 'onboarding')
})
test('delayed confirmation stops polling and offers checking without another charge', async () => {
  const h = harness('views/PaymentConfirmationView.vue', 'checkPayment, state', {
    api: { get: async () => ({ data: { data: { confirmed: false } } }) },
  })
  for (let i = 0; i < 20; i++) await h.api.checkPayment()
  assert.equal(h.api.state.value, 'delayed')
  assert.equal(h.redirects.length, 0)
})
test('sign-in resumes a pending payment confirmation before starting another checkout', async () => {
  const h = harness('composables/resumeCheckout.js', 'resumePendingCheckout')
  const attempt = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa'
  h.store.set('wyv_pending_confirmation', attempt)
  assert.equal(await h.api.resumePendingCheckout(), true)
  assert.equal(h.context.window.location.href, `/payment/confirm?attempt=${attempt}`)
})
