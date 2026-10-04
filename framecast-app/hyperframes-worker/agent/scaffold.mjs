// The starting files for an exact copy, built from the plan before the builder starts: every reference element as a
// positioned slot with its data-ref in its beat's section, the beats shown and hidden on the timeline, one commented
// line per moment with its move, and the 3D stubs. The builder fills in content, look and motion instead of writing
// the whole composition (a first draft was ~75k tokens and ~12 minutes), and slots are where the reference has them.

const DIMS = {'9:16': [1080, 1920], '16:9': [1920, 1080], '1:1': [1080, 1080], '4:5': [1080, 1350]};
const RECIPE = {iris: "WM.iris(tl, '#<next beat>', AT, {from: '#<element>'})", through: "WM.through(tl, {a: '#<this beat>', from: '#<element>', b: '#<next beat>', to: '#<element>', at: AT})",
  device: "WM.device(tl, '#<panel>', AT, {to: {left, top, width, height, radius}, content: '#<layer>'})", toss: "WM.toss(tl, SLOT, AT)", pop: "WM.pop(tl, SLOT, AT)",
  words: "WM.words(tl, SLOT + ' .w', [<word times>])", write_on: "WM.writeOn(tl, SLOT, AT, {underline: '#<line>'})", stamp: "WM.stamp(tl, SLOT, AT, {rotation: -6})",
  fly: "WM.fly(tl, '#<chip>', '#<target>', AT)", type: "WM.type(tl, SLOT, '<text>', AT, 20)", count: "WM.count(tl, SLOT, 0, <to>, AT, .6)",
  cursor: "WM.cursor(tl, '#cursor', [{x, y, at: AT, click: true}])", press: "WM.press(tl, SLOT, AT)", wipe: "WM.wipe(tl, ...)", push: "WM.push(tl, ...)", whip: "WM.whip(tl, ...)"};
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
const comment = s => String(s ?? '').replace(/--/g, '-').replace(/\*\//g, '* /');
const iou = (a, b) => { const x = Math.max(0, Math.min(a[0] + a[2], b[0] + b[2]) - Math.max(a[0], b[0])), y = Math.max(0, Math.min(a[1] + a[3], b[1] + b[3]) - Math.max(a[1], b[1])), i = x * y; return i / (a[2] * a[3] + b[2] * b[3] - i || 1); };

export function exactScaffold({plan, settings, words = [], audio = null}) {
  const layout = plan?.reference_match === 'exact' ? (plan.reference_layout || []) : [];
  if (!layout.length) return null;
  const [W, H] = DIMS[settings?.aspect_ratio] || DIMS['16:9'], duration = Number(settings?.duration_seconds) || 15;
  const scenes = (plan.scenes || []).filter(s => s && Number.isFinite(Number(s.start))).map((s, i) => ({label: String(s.label || 'Beat ' + (i + 1)), start: Number(s.start), end: Number(s.end)})).sort((a, b) => a.start - b.start);
  if (!scenes.length) scenes.push({label: 'Video', start: 0, end: duration});
  scenes.forEach((s, i) => { s.id = 'b' + (i + 1); s.end = Number.isFinite(s.end) ? s.end : (scenes[i + 1]?.start ?? duration); s.slots = []; s.moments = []; });
  const beatFor = m => scenes.find(s => s.label.toLowerCase() === String(m.beat || '').toLowerCase()) || scenes.findLast(s => s.start <= Number(m.at ?? m.start ?? 0)) || scenes[0];
  const has3d = !!(plan.mascot3d || plan.props3d?.length);
  const propMoments = new Map();
  for (const p of plan.props3d || []) for (const m of p.moments || []) if (!propMoments.has(m)) propMoments.set(m, p);
  for (const m of layout) {
    const beat = beatFor(m), at = Number(m.at ?? m.start ?? beat.start);
    beat.moments.push({...m, at});
    (m.elements || []).forEach((e, k) => {
      if (!Array.isArray(e.box) || e.box.length !== 4) return;
      const ref = m.moment + ':' + k, box = e.box.map(Number);
      // One element seen across several moments (the mascot, a header) is one slot carrying all its refs.
      const same = beat.slots.find(s => s.role === e.role && iou(s.box, box) > 0.55);
      if (same) { same.refs.push(ref); same.labels.push(m.moment.split(':').pop() + ': ' + e.label); return; }
      beat.slots.push({role: e.role || 'other', box, refs: [ref], labels: [m.moment.split(':').pop() + ': ' + e.label], first: at, prop: e.role === 'card' ? propMoments.get(m.moment) : null});
    });
  }
  let n = 0;
  const px = s => [Math.round(s.box[0] * W), Math.round(s.box[1] * H), Math.round(s.box[2] * W), Math.round(s.box[3] * H)];
  const usedProps = new Set();
  const sections = scenes.map(s => {
    const items = s.slots.map(slot => {
      const [x, y, w, h] = px(slot), id = 's' + (++n), style = `left:${x}px;top:${y}px;width:${w}px;height:${h}px`;
      slot.id = id; slot.px = [x, y, w, h];
      const note = `<!-- ${comment(slot.role)} · ${comment(slot.labels.join(' | ').slice(0, 220))} -->`;
      if (slot.role === 'mascot' && plan.mascot3d) return `  ${note}\n  <canvas class="slot" id="${id}" width="${w}" height="${h}" style="${style}" data-ref="${esc(slot.refs.join(' '))}"></canvas>`;
      let inner = '';
      if (slot.prop && !usedProps.has(slot.prop.name)) { usedProps.add(slot.prop.name); slot.propId = id + 'p'; const c = Math.round(Math.min(w, h) * 0.62); inner = `<canvas id="${slot.propId}" width="${c}" height="${c}" style="left:${Math.round((w - c) / 2)}px;top:${Math.round(h * 0.16)}px"></canvas>`; }
      return `  ${note}\n  <div class="slot" id="${id}" style="${style}" data-ref="${esc(slot.refs.join(' '))}">${inner}</div>`;
    }).join('\n');
    return `<section class="beat" id="${s.id}" data-beat="${esc(s.label)}" data-start="${s.start}" data-duration="${+(s.end - s.start).toFixed(2)}">\n${items}\n</section>`;
  }).join('\n');
  const head = `<script src="gsap.min.js"></script><script src="wyv-motion.js"></script>${has3d ? '<script src="three-wyv.js"></script><script src="wyv-3d.js"></script>' : ''}`;
  const index = `<!doctype html>
<html><head><meta charset="utf-8">
${head}
<link rel="stylesheet" href="style.css">
</head><body>
<main id="main" data-composition-id="main" data-width="${W}" data-height="${H}" data-duration="${duration}">
${sections}
${audio?.narration ? `<audio id="vo" class="clip" src="${esc(audio.narration)}" data-start="${audio.narration_start ?? 0.3}" data-duration="${+(duration - (audio.narration_start ?? 0.3)).toFixed(2)}" data-track-index="1"></audio>\n` : ''}${audio?.music ? `<audio id="bed" class="clip" src="${esc(audio.music)}" data-start="0" data-duration="${duration}" data-volume="1" data-track-index="2"></audio>\n` : ''}<svg id="cursor" viewBox="0 0 24 24"><path d="M4 2l15 9-7 1.6L8.6 20z" fill="#111" stroke="#fff" stroke-width="1.4"/></svg>
</main>
<script src="main.js"></script>
<script>window.__timelines = window.__timelines || {}; window.__timelines.main = window.wyvBuild();</script>
</body></html>
`;
  const style = `@font-face{font-family:Inter;src:url(inter.ttf);font-weight:100 900}
@font-face{font-family:Playfair;src:url(playfair.ttf);font-weight:400 900}
*{box-sizing:border-box;margin:0}
body{background:#fff}
#main{width:${W}px;height:${H}px;position:relative;overflow:hidden;background:#fff;font-family:Inter,sans-serif;color:#111}
/* Each beat is a full-frame section, hidden until the timeline shows it (children inherit; never visibility:visible). */
.beat{position:absolute;left:0;top:0;width:${W}px;height:${H}px;overflow:hidden;visibility:hidden}
.slot{position:absolute}
canvas{position:absolute;display:block}
#cursor{position:absolute;left:0;top:0;width:44px;height:44px;visibility:hidden;z-index:30}
`;
  const lines = ['// Built from the reference layout: every slot sits where the reference has it. Fill in each slot\'s content, look',
    '// and motion on the moment lines below; keep the slots, their data-ref and the beat timing.',
    '// The checker may load scripts before the page markup, so everything runs inside wyvBuild, called at the end of index.html.',
    'window.wyvBuild = () => {', 'const tl = gsap.timeline({paused: true});', ''];
  scenes.forEach((s, i) => {
    lines.push(`// ── ${s.label} (${s.start.toFixed(2)}–${s.end.toFixed(2)} s) ─────────────`);
    lines.push(`tl.set('#${s.id}', {autoAlpha: 1}, ${s.start});${i < scenes.length - 1 ? `\ntl.set('#${s.id}', {autoAlpha: 0}, ${s.end});` : ''}`);
    for (const m of s.moments.sort((a, b) => a.at - b.at)) {
      const here = s.slots.filter(x => x.refs.some(r => r.startsWith(m.moment + ':'))), slotsHere = here.map(x => '#' + x.id);
      // The move acts on what the moment brings in: a new slot of the role the move suits, else any new slot.
      const suits = {toss: ['card'], words: ['headline'], write_on: ['headline'], stamp: ['sticker', 'other'], pop: ['sticker', 'tile', 'button', 'mascot', 'card'], type: ['text', 'other'], press: ['button']}[m.move] || [];
      const fresh = here.filter(x => x.refs[0].startsWith(m.moment + ':')), target = fresh.find(x => suits.includes(x.role)) || fresh[0] || here.find(x => suits.includes(x.role)) || here[0];
      const recipe = m.move && RECIPE[m.move] ? '  ' + RECIPE[m.move].replace(/AT/g, m.at.toFixed(2)).replace(/SLOT/g, `'${target ? '#' + target.id : '#<slot>'}'`) : '';
      lines.push(`// ${m.moment} ${m.at.toFixed(2)} s · ${m.move || 'custom'} · ${comment(m.content || '').slice(0, 160)} · slots ${slotsHere.join(' ') || '—'}${recipe ? '\n//' + recipe : ''}`);
    }
    lines.push('');
  });
  if (plan.mascot3d) {
    lines.push('// The 3D mascot, one canvas per beat it appears in (kit/three.md): place(t) gives its head centre and radius in the canvas.');
    lines.push(`const SPEC = ${JSON.stringify(plan.mascot3d.spec)};`);
    lines.push(`const WORDS = ${JSON.stringify(words)}; // the narration's words in composition seconds: the mouth follows them`);
    for (const s of scenes) for (const slot of s.slots.filter(x => x.role === 'mascot')) {
      const [, , w, h] = slot.px;
      lines.push(`W3D.mascot('#${slot.id}', {spec: SPEC, words: WORDS, active: [${s.start}, ${s.end}], expressions: [], place: t => ({cx: ${Math.round(w / 2)}, cy: ${Math.round(h * 0.34)}, r: ${Math.round(Math.min(w, h) * 0.26)}})});`);
    }
    lines.push('');
  }
  for (const s of scenes) for (const slot of s.slots.filter(x => x.propId)) {
    lines.push(`// ${slot.prop.name}: ${comment(slot.prop.looks || '')}`);
    lines.push(`W3D.prop('#${slot.propId}', {active: [${s.start}, ${s.end}], ${slot.prop.spin ? `spin: {at: ${slot.first.toFixed(2)}}, ` : ''}build: ({THREE, shapes, mesh}) => { const g = new THREE.Group(); /* model it here */ return g; }});`);
  }
  if (has3d) lines.push(`W3D.clock(tl, ${duration});`);
  lines.push('return tl;', '};');
  return {files: {'index.html': index, 'style.css': style, 'main.js': lines.join('\n') + '\n'}, beats: scenes.length, slots: n};
}
