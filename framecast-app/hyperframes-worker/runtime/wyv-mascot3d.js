/* WyvStudio parametric 3D mascot for Remotion clips (three.js via @remotion/three).
 * A character is a spec (head, hair, eyes, brows, mouth, nose, cheeks, body, outfit, palette, finish) built
 * from 3D primitives, so it is rigged by construction: the head turns, tilts and nods on the neck, the eyes
 * blink, wink and look around, and the mouth changes shape on the narration's words. Everything is a
 * function of the frame: seekable and exact. No image generation, no network.
 *
 *   <ThreeCanvas ...><Mascot3D spec={spec} words={words} at={0} pose={{yaw, pitch, tilt, x, y}} expressions={[...]} /></ThreeCanvas>
 */
import React, {useMemo} from 'react';
import {useCurrentFrame, useVideoConfig} from 'remotion';
import * as THREE from 'three';
const h = React.createElement;

export const FINISHES = ['clay', 'dither', 'toon'];
export const DEFAULT_SPEC = {
  seed: 7,
  head: {shape: 'sphere', skin: '#efe6dc'},
  hair: {style: 'curls', color: '#3a3a3a', volume: 1},
  eyes: {style: 'disc', size: 1, spacing: 1, color: '#141414', highlight: true},
  brows: {style: 'bar', color: '#141414'},
  mouth: {color: '#1b1b1b', teeth: '#ffffff', tongue: '#c97a7a'},
  nose: {style: 'button'},
  cheeks: {color: '#e9a5a0'},
  body: {outfit: 'sweater', color: '#9a9a9a', collar: 'turtleneck', pocket: true},
  finish: 'clay',
};
const merge = (a, b) => { const o = {...a}; for (const k of Object.keys(b || {})) o[k] = b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) ? {...a[k], ...b[k]} : b[k]; return o; };

// --- The face over time: mouth per syllable (as WyvMascot.face), blinks, winks and expressions. ---
const VOWEL = c => /[a]/.test(c) ? 'open' : /[ouw]/.test(c) ? 'oh' : /[eiy]/.test(c) ? 'ee' : null;
export function mouthCues(words, at = 0) {
  const cues = []; let last = null; const put = (t, s) => { if (s !== last) { cues.push({t: +t.toFixed(3), s}); last = s; } };
  (words || []).forEach((w, i) => {
    const a = at + Number(w.start), b = at + Number(w.end); if (!(b > a)) return;
    const parts = (String(w.text || '').toLowerCase().replace(/[^a-z]/g, '').match(/[aeiouy]+/g) || ['e']).map(g => VOWEL(g[0]) || 'open');
    const step = (b - a) / parts.length;
    parts.forEach((s, k) => { put(a + k * step, s); if (step > 0.16 && k < parts.length - 1) put(a + k * step + step * 0.7, 'rest'); });
    const next = words[i + 1]; if (!next || at + Number(next.start) - b >= 0.09) put(b, 'rest');
  });
  return cues;
}
export function autoBlinks(duration, start = 0.9) { const out = []; for (let t = start, k = 0; t < duration - 0.2; k++, t += 2.7 + ((k * 7919) % 11) / 10) out.push(+t.toFixed(2)); return out; }
/** {mouth, left, right, brows, gaze} at time t (seconds). expressions: [{at, duration, face: 'wink'|'smile'|'laugh'|'surprised', gaze: [x, y]}]. */
export function faceAt(t, {cues = [], blinks = [], expressions = []} = {}) {
  let mouth = 'rest'; for (const c of cues) if (c.t <= t) mouth = c.s;
  let left = 1, right = 1, brows = 0, gaze = [0, 0];
  for (const b of blinks) { const d = t - b; if (d >= 0 && d < 0.14) { const k = 1 - Math.sin(d / 0.14 * Math.PI); left = right = Math.max(0.08, k); } }
  for (const e of expressions) {
    if (t < e.at || t >= e.at + (e.duration ?? 0.6)) continue;
    if (e.gaze) gaze = e.gaze;
    if (e.face === 'wink') { right = 0.08; mouth = 'smile'; }
    if (e.face === 'smile') mouth = 'smile';
    if (e.face === 'laugh') { left = right = 0.25; mouth = 'open'; brows = 0.4; }
    if (e.face === 'surprised') { mouth = 'oh'; brows = 1; }
  }
  return {mouth, left, right, brows, gaze};
}

// --- Finishes: one small shader for every part; the part's colour, lit, then shaded by the finish. ---
const VERT = 'varying vec3 vN; void main(){ vN=normalize(normalMatrix*normal); gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.0); }';
const FRAG = `
uniform vec3 color; uniform int finish; uniform float cell; uniform float flatness;
varying vec3 vN;
float bayer4(vec2 p){ int x=int(mod(p.x,4.0)), y=int(mod(p.y,4.0)); int i=x+y*4;
  float m=0.0; if(i==0)m=0.;else if(i==1)m=8.;else if(i==2)m=2.;else if(i==3)m=10.;else if(i==4)m=12.;else if(i==5)m=4.;else if(i==6)m=14.;else if(i==7)m=6.;
  else if(i==8)m=3.;else if(i==9)m=11.;else if(i==10)m=1.;else if(i==11)m=9.;else if(i==12)m=15.;else if(i==13)m=7.;else if(i==14)m=13.;else m=5.; return (m+0.5)/16.0; }
void main(){
  vec3 n=normalize(vN), L=normalize(vec3(-0.4,0.6,0.7));
  float d=max(dot(n,L),0.0), rim=pow(1.0-max(n.z,0.0),2.0);
  float light=mix(0.42+0.62*d+0.12*rim, 1.0, flatness);
  vec3 c=color*light;
  if(finish==1){
    // Ordered dither, 1-bit, on a fixed screen grid: grey clay becomes black and white dots.
    float lum=dot(c,vec3(0.299,0.587,0.114));
    float t=bayer4(floor(gl_FragCoord.xy/cell));
    float ink=dot(color,vec3(0.299,0.587,0.114))<0.12?1.0:(lum<t?1.0:0.0);
    c=vec3(1.0-ink);
  } else if(finish==2){
    // Toon: three flat bands.
    float band=d>0.6?1.0:(d>0.25?0.9:0.8); c=color*mix(band,1.0,flatness);
  }
  gl_FragColor=vec4(c,1.0);
}`;
function material(color, finish, cell, flat = 0) {
  return new THREE.ShaderMaterial({vertexShader: VERT, fragmentShader: FRAG,
    uniforms: {color: {value: new THREE.Color(color)}, finish: {value: Math.max(0, FINISHES.indexOf(finish))}, cell: {value: cell}, flatness: {value: flat}}});
}

// Deterministic points over a sphere (Fibonacci), so hair is the same on every frame and every render.
function sphere(n) { const out = [], g = Math.PI * (3 - Math.sqrt(5)); for (let i = 0; i < n; i++) { const y = 1 - (i + 0.5) / n * 2, r = Math.sqrt(1 - y * y); out.push(new THREE.Vector3(Math.cos(g * i) * r, y, Math.sin(g * i) * r)); } return out; }
const rnd = seed => () => { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; };

/** A mouth shape as a flat geometry on the face (units of head radius). */
function mouthShapes() {
  const crescent = (w, deep, shallow) => { const s = new THREE.Shape(); s.moveTo(-w, 0); s.absellipse(0, 0, w, deep, Math.PI, 2 * Math.PI, false); s.absellipse(0, 0, w, shallow, 0, Math.PI, true); return s; };
  const ellipse = (rx, ry) => { const s = new THREE.Shape(); s.absellipse(0, 0, rx, ry, 0, Math.PI * 2, false); return s; };
  const rounded = (w, ht, r) => { const s = new THREE.Shape(); s.moveTo(-w + r, -ht); s.lineTo(w - r, -ht); s.quadraticCurveTo(w, -ht, w, -ht + r); s.lineTo(w, ht - r); s.quadraticCurveTo(w, ht, w - r, ht); s.lineTo(-w + r, ht); s.quadraticCurveTo(-w, ht, -w, ht - r); s.lineTo(-w, -ht + r); s.quadraticCurveTo(-w, -ht, -w + r, -ht); return s; };
  return {
    rest: [{shape: crescent(0.13, 0.035, 0.012), part: 'mouth'}],
    smile: [{shape: crescent(0.17, 0.085, 0.02), part: 'mouth'}],
    open: [{shape: ellipse(0.11, 0.095), part: 'mouth'}, {shape: ellipse(0.06, 0.03), part: 'tongue', y: -0.045}],
    oh: [{shape: ellipse(0.055, 0.07), part: 'mouth'}],
    ee: [{shape: rounded(0.15, 0.05, 0.035), part: 'mouth'}, {shape: rounded(0.12, 0.016, 0.01), part: 'teeth', y: 0.025}],
  };
}

/** Builds the character once per spec: a group of parts, with handles the rig moves each frame. */
export function buildMascot(specIn, {cell = 2} = {}) {
  const spec = merge(DEFAULT_SPEC, specIn || {});
  const F = spec.finish, R = 1, mat = (c, flat) => material(c, F, cell, flat), rand = rnd(spec.seed || 7);
  const root = new THREE.Group(), body = new THREE.Group(), head = new THREE.Group(); root.add(body); body.add(head);
  // Body: a lathed bust with rounded shoulders, a neck and a collar; the head pivots at the top of the neck.
  const prof = [[0, -2.35], [1.45, -2.35], [1.6, -1.95], [1.52, -1.5], [1.2, -1.2], [0.62, -1.05], [0, -1.02]].map(([x, y]) => new THREE.Vector2(x * R, y * R));
  const bust = new THREE.Mesh(new THREE.LatheGeometry(prof, 48), mat(spec.body.color)); body.add(bust);
  const neck = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.34, 0.5, 32), mat(spec.head.skin)); neck.position.y = -0.85; body.add(neck);
  if (spec.body.collar === 'turtleneck') { const c = new THREE.Mesh(new THREE.CylinderGeometry(0.42, 0.5, 0.36, 32), mat(spec.body.color)); c.position.y = -1.0; body.add(c); }
  if (spec.body.pocket) { const p = new THREE.Mesh(new THREE.BoxGeometry(0.34, 0.4, 0.04), mat(new THREE.Color(spec.body.color).multiplyScalar(0.85))); p.position.set(0.62, -1.65, 1.18); p.rotation.y = 0.42; p.rotation.x = -0.12; body.add(p); }
  head.position.y = -0.6; // pivot at the top of the neck
  const H = new THREE.Group(); H.position.y = 0.9; head.add(H);
  // Head shape.
  const skull = new THREE.Mesh(new THREE.SphereGeometry(R, 64, 48), mat(spec.head.skin));
  if (spec.head.shape === 'egg') skull.scale.set(0.95, 1.1, 0.95);
  if (spec.head.shape === 'round-square') skull.scale.set(1.05, 0.95, 0.95);
  H.add(skull);
  // A point on the face: direction from the head's centre (x across, y up, z forward), pushed just above the skin.
  const onFace = (x, y, lift = 1.005) => new THREE.Vector3(x, y, 1).normalize().multiplyScalar(R * lift);
  const facing = (obj, at) => { obj.position.copy(at); obj.lookAt(at.clone().multiplyScalar(2)); };
  // Hair.
  // Hair follows the head's shape (an egg head stretches its hair with it).
  const hairMat = mat(spec.hair.color), vol = spec.hair.volume ?? 1, hair = new THREE.Group(); hair.scale.copy(skull.scale); H.add(hair);
  if (spec.hair.style === 'curls' || spec.hair.style === 'waves') {
    for (const p of sphere(90)) {
      // Crown, sides and back; the face (front, below the hairline) stays clear.
      if (p.y < -0.25 || (p.z > 0.15 && p.y < 0.45) || (p.z > 0.5 && p.y < 0.62)) continue;
      const r = (spec.hair.style === 'curls' ? 0.27 : 0.33) * vol * (0.85 + rand() * 0.3);
      const m = new THREE.Mesh(new THREE.SphereGeometry(r, 20, 16), hairMat); m.position.copy(p.clone().multiplyScalar(R * (0.93 + 0.08 * vol))); hair.add(m);
    }
  } else if (spec.hair.style === 'bob') {
    // A long shell behind (to the jaw) and a cap on top whose front edge sits above the brows.
    const back = new THREE.Mesh(new THREE.SphereGeometry(R * 1.09, 48, 32, Math.PI, Math.PI, 0, Math.PI * 0.72), hairMat); hair.add(back);
    const cap = new THREE.Mesh(new THREE.SphereGeometry(R * 1.07, 48, 24, 0, Math.PI * 2, 0, Math.PI * 0.36), hairMat); hair.add(cap);
  } else if (spec.hair.style === 'spikes') {
    for (const p of sphere(50)) { if (p.y < 0.2 || (p.z > 0.55 && p.y < 0.6)) continue; const c = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.5 * vol, 12), hairMat); c.position.copy(p.clone().multiplyScalar(R * 0.95)); c.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p); hair.add(c); }
  } else if (spec.hair.style === 'bun') {
    const cap = new THREE.Mesh(new THREE.SphereGeometry(R * 1.04, 48, 32, 0, Math.PI * 2, 0, Math.PI * 0.45), hairMat); cap.rotation.x = -0.35; hair.add(cap);
    const bun = new THREE.Mesh(new THREE.SphereGeometry(0.38, 24, 16), hairMat); bun.position.set(0, 1.0, -0.35); hair.add(bun);
  }
  // Eyes: dark discs with a highlight; blinking and winking squash each eye to a line.
  const eyeSize = 0.13 * (spec.eyes.size ?? 1), spread = 0.34 * (spec.eyes.spacing ?? 1), eyeY = 0.02;
  const eyes = [-1, 1].map(side => {
    const g = new THREE.Group(); facing(g, onFace(side * spread, eyeY, 1.0)); H.add(g);
    const shape = spec.eyes.style === 'oval' ? [eyeSize * 0.8, eyeSize * 1.15] : spec.eyes.style === 'dot' ? [eyeSize * 0.55, eyeSize * 0.55] : [eyeSize, eyeSize];
    const ball = new THREE.Mesh(new THREE.SphereGeometry(1, 24, 16), mat(spec.eyes.color, 0.6)); ball.scale.set(shape[0], shape[1], eyeSize * 0.35); g.add(ball);
    let glint = null;
    if (spec.eyes.highlight) { glint = new THREE.Mesh(new THREE.SphereGeometry(eyeSize * 0.3, 12, 8), mat('#ffffff', 1)); glint.position.set(eyeSize * 0.35, eyeSize * 0.35, eyeSize * 0.3); g.add(glint); }
    return {g, ball, glint, base: shape};
  });
  // Brows: rounded bars above the eyes; they lift for surprise.
  const brows = spec.brows.style === 'none' ? [] : [-1, 1].map(side => {
    const b = new THREE.Mesh(new THREE.CapsuleGeometry(0.035, 0.17, 6, 12), mat(spec.brows.color, 0.6)); b.rotation.z = Math.PI / 2;
    const g = new THREE.Group(); facing(g, onFace(side * spread, eyeY + 0.27, 1.0)); g.add(b); H.add(g); return g;
  });
  // Nose and cheeks.
  if (spec.nose.style === 'button') { const n = new THREE.Mesh(new THREE.SphereGeometry(0.09, 20, 14), mat(new THREE.Color(spec.head.skin).multiplyScalar(0.94))); n.position.copy(onFace(0, -0.15, 1.04)); H.add(n); }
  if (spec.cheeks.color) for (const side of [-1, 1]) { const c = new THREE.Mesh(new THREE.SphereGeometry(1, 16, 10), mat(spec.cheeks.color, 0.3)); c.scale.set(0.11, 0.07, 0.02); facing(c, onFace(side * 0.5, -0.2, 0.995)); H.add(c); }
  // Mouths: every shape is built once; the rig shows one at a time.
  const mouth = new THREE.Group(); facing(mouth, onFace(0, -0.38, 1.008)); H.add(mouth);
  const mouthParts = {}, partColor = {mouth: spec.mouth.color, teeth: spec.mouth.teeth, tongue: spec.mouth.tongue};
  for (const [name, layers] of Object.entries(mouthShapes())) {
    const g = new THREE.Group(); g.visible = name === 'rest';
    layers.forEach((l, i) => { const m = new THREE.Mesh(new THREE.ShapeGeometry(l.shape, 24), mat(partColor[l.part], 1)); m.position.set(0, l.y || 0, 0.002 * (i + 1)); m.material.side = THREE.DoubleSide; g.add(m); });
    mouth.add(g); mouthParts[name] = g;
  }
  return {spec, root, body, head, eyes, brows, mouthParts};
}

/** Applies a pose and a face to a built mascot. pose: {x, y, yaw, pitch, tilt, bodyYaw}; face from faceAt. */
export function applyRig(m, pose = {}, face = {}) {
  m.root.position.set(pose.x || 0, pose.y || 0, 0);
  m.body.rotation.y = pose.bodyYaw || 0;
  m.head.rotation.set(pose.pitch || 0, pose.yaw || 0, pose.tilt || 0, 'YXZ');
  m.eyes.forEach((e, i) => {
    const open = i === 0 ? (face.left ?? 1) : (face.right ?? 1);
    e.ball.scale.y = e.base[1] * open; if (e.glint) e.glint.visible = open > 0.4;
    const [gx, gy] = face.gaze || [0, 0]; e.ball.position.set(gx * 0.05, gy * 0.04, 0); if (e.glint) e.glint.position.x = e.base[0] * 0.35 + gx * 0.05;
  });
  m.brows.forEach(b => { b.children[0].position.y = 0.05 * (face.brows || 0); });
  for (const [name, g] of Object.entries(m.mouthParts)) g.visible = name === (face.mouth || 'rest');
}

/** The mascot as a Remotion/three element: built once, posed and given its face every frame. */
export function Mascot3D({spec, at = 0, words = [], blinks, expressions = [], pose, cell}) {
  const frame = useCurrentFrame(), {fps, durationInFrames} = useVideoConfig(), t = frame / fps;
  const m = useMemo(() => buildMascot(spec, {cell: cell ?? 2}), [JSON.stringify(spec), cell]);
  const cues = useMemo(() => mouthCues(words, at), [JSON.stringify(words), at]);
  const bl = useMemo(() => (blinks ?? autoBlinks(durationInFrames / fps)), [JSON.stringify(blinks), durationInFrames, fps]);
  const p = typeof pose === 'function' ? pose(t, frame) : (pose || {});
  applyRig(m, p, faceAt(t, {cues, blinks: bl, expressions: expressions.map(e => ({...e, at: e.at + at}))}));
  return h('primitive', {object: m.root});
}
