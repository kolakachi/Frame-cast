// How long the render may be (2026-10-19, a customer's presenter video): the approved length, unless the page itself is
// longer because the presenter's spoken take needs it. Brett's 15 s explainer had a 17 s take; the build stretched the
// video to 16.3 s so the call to action finished, and the render check (approved length ±0.15 s) refused it on every
// retry, charging each time. A page up to a quarter longer than approved renders at its own length; anything else
// keeps the approved one. Never shorter: a page shorter than approved is still refused.

/** The length the page declares on its root composition (data-composition-id), in seconds, or null. */
export function declaredLength(html){
 const tag=String(html||'').match(/<[a-z][^>]*\bdata-composition-id=["'][^"']*["'][^>]*>/i)?.[0];
 const d=Number(tag?.match(/\bdata-duration=["']([\d.]+)["']/i)?.[1]);
 return Number.isFinite(d)&&d>0?d:null;
}

/** The length to render and check against: the page's own when it is longer than approved by at most 25%. */
export function renderLength(html,approved){
 const d=declaredLength(html);
 return d&&d>approved+0.15&&d<=approved*1.25?d:approved;
}
