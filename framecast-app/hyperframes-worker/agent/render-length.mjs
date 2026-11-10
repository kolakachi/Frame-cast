// How long the render may be (2026-10-19, a customer's presenter video): the approved length, unless the page itself is
// longer because the presenter's spoken take needs it. Brett's 15 s explainer had a 17 s take; the build stretched the
// video to 16.3 s so the call to action finished, and the render check (approved length ±0.15 s) refused it on every
// retry, charging each time. A page up to 30% longer than approved renders at its own length (LENGTH-TARGET: the
// chosen length is a target; lip-synced takes run up to 30% long); anything else
// keeps the approved one. Never shorter: a page shorter than approved is still refused.

/** The length the page declares on its root composition (data-composition-id), in seconds, or null. */
export function declaredLength(html){
 const tag=String(html||'').match(/<[a-z][^>]*\bdata-composition-id=["'][^"']*["'][^>]*>/i)?.[0];
 const d=Number(tag?.match(/\bdata-duration=["']([\d.]+)["']/i)?.[1]);
 return Number.isFinite(d)&&d>0?d:null;
}

/** The length to render and check against: the page's own whenever it declares one (owner, 2026-11-10: the chosen
 * length is a target; a video is delivered at its real length, never refused for it), else the approved one. */
export function renderLength(html,approved){
 return declaredLength(html)??approved;
}
