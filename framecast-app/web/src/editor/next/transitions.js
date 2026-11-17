// Transitions between scenes (owner, 2026-11-17). The list mirrors api SceneTransitions::CATALOGUE, and the preview
// draws each with the same formula ffmpeg's xfade uses at export, so what the picker shows is what the video gets.
// A scene's transition_rule is the cut after it: "type:seconds", empty for a plain cut.

export const TRANSITIONS = [
  { key: "fade", label: "Fade" },
  { key: "dissolve", label: "Dissolve" },
  { key: "fadeblack", label: "Through black" },
  { key: "fadewhite", label: "Through white" },
  { key: "wipeleft", label: "Wipe left" },
  { key: "wiperight", label: "Wipe right" },
  { key: "wipeup", label: "Wipe up" },
  { key: "wipedown", label: "Wipe down" },
  { key: "slideleft", label: "Slide left" },
  { key: "slideright", label: "Slide right" },
  { key: "slideup", label: "Slide up" },
  { key: "slidedown", label: "Slide down" },
  { key: "smoothleft", label: "Soft wipe left" },
  { key: "smoothright", label: "Soft wipe right" },
  { key: "circleopen", label: "Circle" },
  { key: "radial", label: "Clock" },
  { key: "pixelize", label: "Pixelate" },
  { key: "zoomin", label: "Zoom" },
  { key: "hblur", label: "Blur" },
  { key: "squeezeh", label: "Squeeze" },
];
export const TRANSITION_SECONDS = [0.3, 0.5, 0.8, 1, 1.5];
export const DEFAULT_SECONDS = 0.5;

export function parseRule(rule) {
  const [type, s] = String(rule || "").split(":");
  if (!TRANSITIONS.some((t) => t.key === type)) return null;
  const seconds = Number(s);
  return { type, seconds: Number.isFinite(seconds) && seconds > 0 ? seconds : DEFAULT_SECONDS };
}
export const transitionLabel = (type) => TRANSITIONS.find((t) => t.key === type)?.label ?? "Cut";

// ── The shader: ffmpeg's vf_xfade formulas. p runs 1 → 0 over the transition, as in ffmpeg; x, y are pixels from the
// top left. A is the outgoing scene, B the incoming one. ───────────────────────────────────────────────────────────
const FRAG = `
precision highp float;
uniform sampler2D A; uniform sampler2D B; uniform vec2 S; uniform float p; uniform int T;
vec4 a(vec2 q) { return texture2D(A, clamp(q, vec2(0.0), S - 1.0) / S); }
vec4 b(vec2 q) { return texture2D(B, clamp(q, vec2(0.0), S - 1.0) / S); }
vec4 mx(vec4 x, vec4 y, float m) { return x * m + y * (1.0 - m); }
float rnd(vec2 q) { return fract(sin(q.x * 12.9898 + q.y * 78.233) * 43758.545); }
void main() {
  vec2 q = vec2(gl_FragCoord.x - 0.5, S.y - gl_FragCoord.y - 0.5);
  float W = S.x, H = S.y, x = q.x, y = q.y;
  vec4 o;
  if (T == 0) o = mx(a(q), b(q), p);
  else if (T == 1) o = (rnd(q) * 2.0 + p * 2.0 - 1.5 >= 0.5) ? a(q) : b(q);
  else if (T == 2 || T == 3) {
    vec4 c = T == 2 ? vec4(0.0, 0.0, 0.0, 1.0) : vec4(1.0);
    o = mx(mx(a(q), c, smoothstep(0.8, 1.0, p)), mx(c, b(q), smoothstep(0.2, 1.0, p)), p);
  }
  else if (T == 4) o = x > W * p ? b(q) : a(q);
  else if (T == 5) o = x > W * (1.0 - p) ? a(q) : b(q);
  else if (T == 6) o = y > H * p ? b(q) : a(q);
  else if (T == 7) o = y > H * (1.0 - p) ? a(q) : b(q);
  else if (T == 8) { float z = x - p * W; o = (z >= 0.0 && z < W) ? b(vec2(z, y)) : a(vec2(z + W, y)); }
  else if (T == 9) { float z = x + p * W; o = (z >= 0.0 && z < W) ? b(vec2(z, y)) : a(vec2(z - W, y)); }
  else if (T == 10) { float z = y - p * H; o = (z >= 0.0 && z < H) ? b(vec2(x, z)) : a(vec2(x, z + H)); }
  else if (T == 11) { float z = y + p * H; o = (z >= 0.0 && z < H) ? b(vec2(x, z)) : a(vec2(x, z - H)); }
  else if (T == 12) o = mx(b(q), a(q), smoothstep(0.0, 1.0, 1.0 + x / W - p * 2.0));
  else if (T == 13) o = mx(b(q), a(q), smoothstep(0.0, 1.0, 1.0 + (W - 1.0 - x) / W - p * 2.0));
  else if (T == 14) {
    float s = length(vec2(x - W / 2.0, y - H / 2.0)) / length(vec2(W / 2.0, H / 2.0)) + (p - 0.5) * 3.0;
    o = mx(a(q), b(q), smoothstep(0.0, 1.0, s));
  }
  else if (T == 15) o = mx(b(q), a(q), smoothstep(0.0, 1.0, atan(x - W / 2.0, y - H / 2.0) - (p - 0.5) * 7.853982));
  else if (T == 16) {
    float d = min(p, 1.0 - p), dist = ceil(d * 50.0) / 50.0, sq = 2.0 * dist * min(W, H) / 20.0;
    vec2 r = dist > 0.0 ? vec2(min((floor(x / sq) + 0.5) * sq, W - 1.0), min((floor(y / sq) + 0.5) * sq, H - 1.0)) : q;
    o = mx(a(r), b(r), p);
  }
  else if (T == 17) {
    float zf = smoothstep(0.5, 1.0, p);
    vec2 r = vec2(((x / (W - 1.0)) - 0.5) * zf + 0.5, ((y / (H - 1.0)) - 0.5) * zf + 0.5) * (S - 1.0);
    o = mx(a(r), b(q), smoothstep(0.0, 0.5, p));
  }
  else if (T == 18) {
    float pr = p <= 0.5 ? p * 2.0 : (1.0 - p) * 2.0, size = 1.0 + (W / 2.0) * pr;
    vec4 sa = vec4(0.0), sb = vec4(0.0);
    for (int i = 0; i < 24; i++) { float dx = (float(i) / 23.0 - 0.5) * size; sa += a(vec2(x + dx, y)); sb += b(vec2(x + dx, y)); }
    o = mx(sa / 24.0, sb / 24.0, p);
  }
  else {
    float z = 0.5 + (y / H - 0.5) / max(p, 0.0001);
    o = (z < 0.0 || z > 1.0) ? b(q) : a(vec2(x, z * (H - 1.0)));
  }
  gl_FragColor = vec4(o.rgb, 1.0);
}`;
const VERT = "attribute vec2 v; void main() { gl_Position = vec4(v, 0.0, 1.0); }";

let renderer = null;
/** One WebGL canvas for every preview (browsers allow few contexts); each draw is copied onto a 2D canvas. */
function getRenderer() {
  if (renderer !== null) return renderer;
  const canvas = document.createElement("canvas");
  const gl = canvas.getContext("webgl", { preserveDrawingBuffer: true, premultipliedAlpha: false });
  if (!gl) return (renderer = false);
  const sh = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); return s; };
  const prog = gl.createProgram();
  gl.attachShader(prog, sh(gl.VERTEX_SHADER, VERT));
  gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, FRAG));
  gl.linkProgram(prog);
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) return (renderer = false);
  gl.useProgram(prog);
  gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
  const loc = gl.getAttribLocation(prog, "v");
  gl.enableVertexAttribArray(loc);
  gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
  const tex = [gl.createTexture(), gl.createTexture()];
  const u = (n) => gl.getUniformLocation(prog, n);
  gl.uniform1i(u("A"), 0);
  gl.uniform1i(u("B"), 1);
  renderer = { canvas, gl, tex, u: { S: u("S"), p: u("p"), T: u("T") }, sources: [null, null] };
  return renderer;
}

function upload(r, slot, source) {
  const { gl } = r;
  gl.activeTexture(slot ? gl.TEXTURE1 : gl.TEXTURE0);
  gl.bindTexture(gl.TEXTURE_2D, r.tex[slot]);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, source);
  r.sources[slot] = source;
}

/**
 * Draws `type` at `t` (0 = all outgoing scene, 1 = all incoming) from frames `a` and `b` (same-size canvases) onto
 * the 2D canvas `target`. False when this browser has no WebGL.
 */
export function drawTransition(target, a, b, type, t) {
  const r = getRenderer();
  if (!r) return false;
  const w = a.width, h = a.height;
  if (r.canvas.width !== w || r.canvas.height !== h) { r.canvas.width = w; r.canvas.height = h; }
  if (r.sources[0] !== a) upload(r, 0, a);
  if (r.sources[1] !== b) upload(r, 1, b);
  const { gl } = r;
  gl.viewport(0, 0, w, h);
  gl.uniform2f(r.u.S, w, h);
  gl.uniform1f(r.u.p, 1 - Math.max(0, Math.min(1, t)));
  gl.uniform1i(r.u.T, Math.max(0, TRANSITIONS.findIndex((x) => x.key === type)));
  gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  const ctx = target.getContext("2d");
  ctx.drawImage(r.canvas, 0, 0, target.width, target.height);
  return true;
}

/** A plain stand-in frame: the scene's number on a colour, for scenes whose picture can't be read. */
function standIn(w, h, n, hue) {
  const c = document.createElement("canvas");
  c.width = w; c.height = h;
  const x = c.getContext("2d");
  const g = x.createLinearGradient(0, 0, w, h);
  g.addColorStop(0, `hsl(${hue} 70% 45%)`);
  g.addColorStop(1, `hsl(${hue + 40} 70% 25%)`);
  x.fillStyle = g;
  x.fillRect(0, 0, w, h);
  x.fillStyle = "rgba(255,255,255,0.9)";
  x.font = `700 ${Math.round(h / 4)}px system-ui, sans-serif`;
  x.textAlign = "center";
  x.textBaseline = "middle";
  x.fillText(String(n), w / 2, h / 2);
  return c;
}

const frameCache = new Map();
/**
 * A scene's picture as a w×h canvas for the preview: its image, or its clip's first (`at` = "start") or last frame.
 * Falls back to a numbered stand-in when the picture can't be read across origins.
 */
export async function sceneFrame(scene, n, w, h, at = "start") {
  const a = scene?.visual_asset;
  const isVideo = a && (a.asset_type === "video" || String(a.mime_type || "").startsWith("video/"));
  const url = a ? (isVideo ? a.storage_url : a.thumbnail_url || a.storage_url) : null;
  const key = `${url}|${w}x${h}|${isVideo ? at : ""}`;
  if (url && frameCache.has(key)) return frameCache.get(key);
  const fallback = standIn(w, h, n, n % 2 ? 210 : 20);
  if (!url) return fallback;
  const draw = (src, sw, sh) => {
    const c = document.createElement("canvas");
    c.width = w; c.height = h;
    const s = Math.max(w / sw, h / sh);
    c.getContext("2d").drawImage(src, (w - sw * s) / 2, (h - sh * s) / 2, sw * s, sh * s);
    c.getContext("2d").getImageData(0, 0, 1, 1); // throws when the picture is from another origin without CORS
    return c;
  };
  const frame = await new Promise((resolve) => {
    const done = (v) => resolve(v || fallback);
    setTimeout(() => done(null), 8000);
    try {
      if (isVideo) {
        const v = document.createElement("video");
        v.crossOrigin = "anonymous";
        v.muted = true;
        v.preload = "auto";
        v.onloadedmetadata = () => { v.currentTime = at === "end" ? Math.max(0, v.duration - 0.1) : Math.min(0.1, v.duration / 2); };
        v.onseeked = () => { try { done(draw(v, v.videoWidth, v.videoHeight)); } catch { done(null); } };
        v.onerror = () => done(null);
        v.src = url;
      } else {
        const img = new Image();
        img.crossOrigin = "anonymous";
        img.onload = () => { try { done(draw(img, img.naturalWidth, img.naturalHeight)); } catch { done(null); } };
        img.onerror = () => done(null);
        img.src = url;
      }
    } catch { done(null); }
  });
  frameCache.set(key, frame);
  return frame;
}
