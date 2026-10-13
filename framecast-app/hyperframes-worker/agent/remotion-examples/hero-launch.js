// Hero launch: a worked Remotion clip for the adapter (kit/remotion.md). Ported from snapcn's hero-launch
// (https://github.com/shadcn-labs/snapcn) to this adapter's rules: react and remotion only, inline styles,
// assets through staticFile, timing in seconds (scaled to the clip's length), any aspect.
// Copyright (c) 2026 Sri Nath (snap-cn). MIT License: permission is granted, free of charge, to use, copy, modify
// and distribute this software, provided this notice is kept. The software is provided "as is", without warranty.
//
//   0 - 22%   the first card HOLDS full-frame, flat and clear
//   22 - 40%  it recedes and angles in on its inner edge; the second card slides in; the two sit symmetric
//   52 - 68%  both breathe with a barely-there float, then fall off the lower edge together
//   70 - 92%  the headline reveals and eases forward
//
// Replace MEDIA with the project's own files (an image or an .mp4 each), HEADING with the approved words, and THEME
// with the brand's colours. Copy it as project/<name>.js and render it with the run tool.
import React, {useLayoutEffect, useRef, useState} from 'react';
import {AbsoluteFill, Easing, Img, OffthreadVideo, continueRender, delayRender, interpolate, staticFile, useCurrentFrame, useVideoConfig} from 'remotion';

const HEADING = 'Your launch line here';
const MEDIA = ['screen-1.png', 'screen-2.png'];
const THEME = {background: '#0b0b0f', foreground: '#f5f5f7', card: '#24242c', font: 'Inter, "Helvetica Neue", Arial, sans-serif'};

const TILT = 17; // degrees, rotateY about each card's inner edge only, so the centre gap stays an even channel
const OUT = Easing.bezier(0.22, 1, 0.36, 1); // cards: quick to move, lands exactly on target
const FALL = Easing.bezier(0.5, 0, 0.9, 0.4); // gravity-ish accelerate for the drop-off
// Type: a moderate decelerate. Quint/expo-out crawl at the end, and on a frame clock the crawl renders as identical
// frames: the words visibly stop and wait.
const TYPE = Easing.bezier(0.2, 0.6, 0.35, 1);

const hex = c => { const h = c.replace('#', ''); const f = h.length === 3 ? h.split('').map(x => x + x).join('') : h; return [0, 2, 4].map(i => parseInt(f.slice(i, i + 2), 16)); };
// Colours mixed in sRGB as [r, g, b]; rgb() and rgba() print them.
const mix = (a, b, t) => { const x = hex(a), y = hex(b); return x.map((v, i) => Math.round(v + (y[i] - v) * t)); };
const rgb = c => 'rgb(' + c.join(',') + ')';
const rgba = (c, a) => 'rgba(' + c.join(',') + ',' + a + ')';

function Media({src}) {
  const fill = {position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover'};
  if (!src) return <div style={{...fill, background: 'linear-gradient(135deg,' + rgb(mix(THEME.background, THEME.card, 0.55)) + ',' + rgb(mix(THEME.background, THEME.card, 0.2)) + ')'}} />;
  return /\.(mp4|webm|mov|m4v)$/i.test(src) ? <OffthreadVideo src={staticFile(src)} muted style={fill} /> : <Img src={staticFile(src)} style={fill} />;
}

function Card({src, s}) {
  const shade = mix(THEME.background, '#000000', 0.75);
  return <div style={{position: 'relative', width: '100%', height: '100%', overflow: 'hidden', borderRadius: 30 * s, background: rgb(mix(THEME.background, THEME.card, 0.35)),
    boxShadow: '0 ' + 20 * s + 'px ' + 52 * s + 'px -' + 28 * s + 'px ' + rgba(shade, 0.9) + ', inset 0 0 0 1px ' + rgba(hex(THEME.foreground), 0.22)}}>
    <Media src={src} />
    <div style={{position: 'absolute', inset: 0, background: 'linear-gradient(to top, rgba(0,0,0,.28) 0%, transparent 32%)'}} />
    <div style={{position: 'absolute', left: 26 * s, right: 26 * s, top: 0, height: 1, background: 'linear-gradient(90deg,transparent,' + rgba(hex(THEME.foreground), 0.5) + ',transparent)'}} />
  </div>;
}

export default function Clip() {
  const frame = useCurrentFrame();
  const {fps, width, height, durationInFrames} = useVideoConfig();
  // Designed as a 6 s beat at 1280x720; times and sizes scale to the clip.
  const k = durationInFrames / fps / 6;
  const s = Math.min(width / 1280, height / 720);
  const portrait = height > width;
  // Landscape sets the cards side by side; portrait stacks them, the same move turned through 90 degrees.
  const gap = 92 * s;
  const cardW = portrait ? 0.86 * width : 1000 * s;
  const cardH = cardW * 0.62;
  const centerLeft = (width - cardW) / 2;
  const cardTop = portrait ? 0.56 * height - cardH / 2 : 0.3 * height;
  const rest = ((portrait ? cardH : cardW) + gap) / 2;

  // One motion primitive: a clamped, eased 0 -> 1 segment, start and length in seconds of the 6 s design.
  const seg = (start, dur, easing = OUT) => interpolate(frame, [start * k * fps, (start + dur) * k * fps], [0, 1], {easing, extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const recede = seg(1.33, 1.0), angle = seg(1.53, 1.2), slideL = seg(1.53, 1.2), slideR = seg(2.0, 1.33);
  const drop = seg(4.07, 1.13, FALL), title = seg(4.2, 1.33, TYPE), vignette = seg(1.67, 1.33, Easing.linear);
  const floatEnv = seg(3.13, 0.73) * (1 - drop);
  const groupY = interpolate(drop, [0, 1], [0, 0.16 * height]) + Math.sin(frame / (k * fps) * 2.7) * 3.5 * s * floatEnv;
  const leftOpacity = interpolate(frame, [0.07 * k * fps, 0.4 * k * fps], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const rightOpacity = interpolate(slideR, [0, 0.35], [0, 1], {extrapolateRight: 'clamp'});

  // The headline scales from its measured baseline: a scale that moves the baseline makes the type climb the pixel
  // grid in whole-pixel jumps. Measured once, before the first frame is captured.
  const lineRef = useRef(null), baseRef = useRef(null);
  const [baseline, setBaseline] = useState(null);
  const [handle] = useState(() => delayRender('headline baseline'));
  useLayoutEffect(() => {
    if (baseline === null && baseRef.current && lineRef.current) {
      setBaseline(baseRef.current.getBoundingClientRect().top - lineRef.current.getBoundingClientRect().top);
    } else if (baseline !== null) continueRender(handle);
  }, [baseline, handle]);

  const box = (d, opacity) => ({position: 'absolute', left: centerLeft, top: cardTop, width: cardW, height: cardH, opacity, transform: (portrait ? 'translateY(' : 'translateX(') + d + 'px)'});
  // Each card tilts back about its inner edge only, so the gap between them stays an even channel.
  const tilt = (deg, origin) => ({width: '100%', height: '100%', transform: 'perspective(' + 1000 * s + 'px) ' + (portrait ? 'rotateX(' + -deg + 'deg)' : 'rotateY(' + deg + 'deg)'), transformOrigin: origin});
  const inner = portrait ? ['50% 100%', '50% 0%'] : ['100% 50%', '0% 50%'];
  const fade = g => ({position: 'absolute', inset: 0, background: g});
  const fontSize = (portrait ? 56 * width / 1080 : 42 * s);

  return <AbsoluteFill style={{backgroundColor: THEME.background, fontFamily: THEME.font}}>
    <AbsoluteFill style={{transform: 'translateY(' + groupY + 'px)'}}>
      <div style={box(interpolate(slideL, [0, 1], [0, -rest]), leftOpacity)}>
        <div style={{width: '100%', height: '100%', transform: 'scale(' + interpolate(recede, [0, 1], [1.5, 1]) + ')', transformOrigin: 'center center'}}>
          <div style={tilt(interpolate(angle, [0, 1], [0, -TILT]), inner[0])}><Card src={MEDIA[0]} s={s} /></div>
        </div>
      </div>
      <div style={box(interpolate(slideR, [0, 1], [1.08 * (portrait ? height : width), rest]), rightOpacity)}>
        <div style={tilt(TILT, inner[1])}><Card src={MEDIA[1]} s={s} /></div>
      </div>
    </AbsoluteFill>
    <AbsoluteFill style={{opacity: vignette, pointerEvents: 'none'}}>
      <div style={fade('linear-gradient(to left, ' + THEME.background + ' 0%, transparent 30%)')} />
      <div style={fade('linear-gradient(to right, ' + THEME.background + ' 0%, transparent 22%)')} />
      <div style={fade('linear-gradient(to top, ' + THEME.background + ' 0%, transparent 30%)')} />
    </AbsoluteFill>
    <div style={{position: 'absolute', left: 0, right: 0, top: (portrait ? 0.14 : 0.22) * height, padding: '0 ' + 40 * s + 'px', textAlign: 'center',
      color: THEME.foreground, fontWeight: 600, letterSpacing: '-0.02em', fontSize, lineHeight: 1.15, opacity: title,
      filter: 'blur(' + interpolate(title, [0, 1], [10, 0]) + 'px)', transform: 'translateY(' + interpolate(title, [0, 1], [40 * s, 0]) + 'px)'}}>
      <span ref={lineRef} style={{display: 'inline-block', textRendering: 'geometricPrecision',
        transform: 'scale(' + interpolate(title, [0, 1], [0.82, 1]) + ')', transformOrigin: '50% ' + (baseline ?? fontSize) + 'px'}}>
        {HEADING}<span ref={baseRef} style={{display: 'inline-block', width: 0, height: 0}} />
      </span>
    </div>
  </AbsoluteFill>;
}
