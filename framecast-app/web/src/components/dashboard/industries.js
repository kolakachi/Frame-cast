// What a workspace sells tunes the dashboard (D5, 2026-10-09): the order of the "What do you want to make?" cards
// and the example in each card's details box. The industry is learned from an existing workspace's videos and will be
// asked by the new onboarding. Unknown (or "other", "agency"): the default order and examples.
export const DEFAULT_ORDER = ['offer_ad', 'launch_promo', 'testimonial', 'explainer', 'listicle', 'reference']

export const ORDER = {
  beauty: ['testimonial', 'offer_ad', 'listicle', 'reference', 'launch_promo', 'explainer'],
  food: ['offer_ad', 'reference', 'testimonial', 'listicle', 'launch_promo', 'explainer'],
  fashion: ['reference', 'offer_ad', 'launch_promo', 'testimonial', 'listicle', 'explainer'],
  home: ['offer_ad', 'testimonial', 'reference', 'explainer', 'listicle', 'launch_promo'],
  health: ['testimonial', 'listicle', 'offer_ad', 'explainer', 'reference', 'launch_promo'],
  tech: ['launch_promo', 'explainer', 'offer_ad', 'testimonial', 'listicle', 'reference'],
  education: ['listicle', 'explainer', 'testimonial', 'offer_ad', 'reference', 'launch_promo'],
  local: ['offer_ad', 'testimonial', 'reference', 'listicle', 'explainer', 'launch_promo'],
}

export const EXAMPLES = {
  beauty: {
    offer_ad: 'Dewbloom vitamin C serum, 30 ml, for dull skin. 20% off this week.',
    launch_promo: 'Our new SPF 50 lip balm in three shades, out Friday.',
    testimonial: 'A customer on how our night cream calmed her redness in two weeks.',
    explainer: 'How to layer a serum and a moisturiser, and why the order matters.',
    listicle: '3 mistakes people make with retinol.',
    reference: 'The same pacing, for our autumn skincare set.',
  },
  food: {
    offer_ad: 'Our wood-fired margherita, two for one every Tuesday.',
    launch_promo: 'Our new oat-milk cold brew, in stores Monday.',
    testimonial: 'A regular on why our sourdough is worth the queue.',
    explainer: 'How we roast our beans, from green to cup.',
    listicle: '3 ways to use our chilli oil beyond noodles.',
    reference: 'The same energy, for our weekend brunch menu.',
  },
  fashion: {
    offer_ad: 'Linen shirts in five colours, 25% off this weekend.',
    launch_promo: 'Our autumn capsule collection, live online Thursday.',
    testimonial: 'A customer styling our wide-leg trousers three ways.',
    explainer: 'How to find your size in our jeans, without the guesswork.',
    listicle: '3 ways to wear one blazer this week.',
    reference: 'The same transitions, for our new sneaker drop.',
  },
  home: {
    offer_ad: 'The Oslo three-seater sofa in oat bouclé, free delivery this month.',
    launch_promo: 'Our new walnut dining table, open to order from Monday.',
    testimonial: 'A customer on how our modular shelving fits her small flat.',
    explainer: 'How to pick the right rug size for your living room.',
    listicle: '3 ways to make a small bedroom feel bigger.',
    reference: 'The same room reveal, for our new lounge chair.',
  },
  health: {
    offer_ad: 'Our 8-week strength plan, first month free.',
    launch_promo: 'Our new plant protein in salted caramel, out next week.',
    testimonial: 'A member on getting back to running after a knee injury.',
    explainer: 'How progressive overload works, in plain words.',
    listicle: '3 stretches for people who sit all day.',
    reference: 'The same workout pacing, for our morning class.',
  },
  tech: {
    offer_ad: 'Our invoicing app, free for your first three clients.',
    launch_promo: 'Dark mode and offline sync, shipping in version 3.0.',
    testimonial: 'A founder on cutting her bookkeeping from hours to minutes with our app.',
    explainer: 'How our app turns a photo of a receipt into an expense.',
    listicle: '3 shortcuts in our app most people miss.',
    reference: 'The same screen-led demo, for our new dashboard.',
  },
  education: {
    offer_ad: 'My 6-week public speaking course, early-bird price until Sunday.',
    launch_promo: 'My new Excel course for small businesses, enrolment opens Monday.',
    testimonial: 'A student on landing her first design job after my course.',
    explainer: 'How compound interest works, with one simple example.',
    listicle: '3 habits that helped my students study less and learn more.',
    reference: 'The same talking-head style, for my free webinar.',
  },
  local: {
    offer_ad: 'A spring boiler service at a fixed price, booked this month.',
    launch_promo: 'Our second salon opens on the High Street on Saturday.',
    testimonial: 'A client on how we fixed her leaking roof in a day.',
    explainer: 'What happens at your first physio appointment.',
    listicle: '3 signs your car needs its brakes checked.',
    reference: 'The same before-and-after style, for our cleaning service.',
  },
}

/** The cards in this industry's order, each with its example (null keeps the card's own wording). */
export function tune(cards, industry) {
  const order = ORDER[industry] || DEFAULT_ORDER
  const examples = EXAMPLES[industry] || {}
  return [...cards].sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key)).map(c => ({ ...c, example: examples[c.key] || null }))
}
