// Proof of 3D props (runtime/wyv-mascot3d.js): the reference's three product-card objects, modelled in code the
// way the builder would write them, in the dither finish, each with the whip-then-drift spin, landing in turn.
import React from 'react';
import {registerRoot, Composition, AbsoluteFill, useVideoConfig} from 'remotion';
import {ThreeCanvas} from '@remotion/three';
import {Prop3D} from './wyv-mascot3d.js';

// A laptop: base with a keyboard inset, lid hinged at the back, black screen with a play mark.
const laptop = ({THREE, shapes, mesh}) => {
  const g = new THREE.Group();
  const base = mesh(shapes.roundedBox(2.6, 0.12, 1.7, 0.05), '#d6d6d6'); g.add(base);
  const keys = mesh(shapes.roundedBox(2.2, 0.02, 0.9, 0.01), '#9a9a9a'); keys.position.set(0, 0.07, -0.15); g.add(keys);
  const hinge = new THREE.Group(); hinge.position.set(0, 0.06, -0.85); hinge.rotation.x = -0.32; g.add(hinge);
  const lid = mesh(shapes.roundedBox(2.6, 1.7, 0.08, 0.05), '#cfcfcf'); lid.position.y = 0.85; hinge.add(lid);
  const screen = mesh(shapes.panel(2.35, 1.48, 0.01, 0.03), '#0b0b0b', 1); screen.position.set(0, 0.85, 0.046); hinge.add(screen);
  const play = mesh(shapes.extrude([[-0.13, -0.17], [0.2, 0], [-0.13, 0.17]], 0.02, 0.004), '#f2f2f2', 1); play.position.set(0, 0.85, 0.06); hinge.add(play);
  g.position.y = -0.4; return g;
};
// A stack of four books, each a cover box with a lighter page block, slightly turned against each other.
const books = ({THREE, shapes, mesh}) => {
  const g = new THREE.Group();
  [[2.4, 0.34, 1.7, 0.0, '#a8a8a8'], [2.2, 0.28, 1.6, 0.12, '#cfcfcf'], [2.3, 0.3, 1.55, -0.08, '#8f8f8f'], [2.0, 0.26, 1.45, 0.2, '#dddddd']].reduce((y, [w, hgt, d, rot, c]) => {
    const b = new THREE.Group(); b.position.y = y + hgt / 2; b.rotation.y = rot; g.add(b);
    b.add(mesh(shapes.roundedBox(w, hgt, d, 0.03), c));
    const pages = mesh(shapes.roundedBox(w - 0.12, hgt * 0.72, d - 0.06, 0.01), '#f0f0f0'); pages.position.x = 0.07; b.add(pages);
    return y + hgt + 0.01;
  }, -0.6);
  return g;
};
// App blocks: a grid of rounded tiles at different heights, like app icons rising off a base.
const blocks = ({THREE, shapes, mesh}) => {
  const g = new THREE.Group();
  const heights = [[0.9, 0.5, 1.2], [0.6, 1.0, 0.7]];
  heights.forEach((row, r) => row.forEach((hgt, c) => {
    const b = mesh(shapes.roundedBox(0.72, hgt, 0.72, 0.12), ['#d0d0d0', '#a0a0a0', '#ececec'][(r + c) % 3]);
    b.position.set((c - 1) * 0.86, -0.5 + hgt / 2, (r - 0.5) * 0.86); g.add(b);
  }));
  return g;
};
const OBJECTS = {laptop, books, blocks};

// One object filling a square clip, as in its card; `at` is when its card lands.
const Card = ({object = 'laptop', at = 0, finish = 'dither'}) => {
  const {width, height} = useVideoConfig();
  return <AbsoluteFill style={{background: '#ffffff'}}><ThreeCanvas width={width} height={height} camera={{fov: 30, position: [0, 0, 8]}} gl={{antialias: false, preserveDrawingBuffer: true}}>
    <Prop3D name={object} build={OBJECTS[object]} finish={finish} spin={{at, turns: 1, settle: 0.9, drift: 0.12, rest: 0.55}} pose={{pitch: 0.4, scale: 1.1}} />
  </ThreeCanvas></AbsoluteFill>;
};
// The three cards in a row, landing 0.4 s apart: one whips while the others drift.
const Row = () => {
  const {width, height} = useVideoConfig();
  return <AbsoluteFill style={{background: '#3a6ff0', flexDirection: 'row', gap: 24, padding: 40, alignItems: 'center', justifyContent: 'center'}}>
    {['laptop', 'books', 'blocks'].map((o, i) => <div key={o} style={{width: 560, height: 560, borderRadius: 28, overflow: 'hidden', background: '#fff'}}>
      <ThreeCanvas width={560} height={560} camera={{fov: 30, position: [0, 0, 8]}} gl={{antialias: false, preserveDrawingBuffer: true}}>
        <Prop3D name={o} build={OBJECTS[o]} finish="dither" spin={{at: 0.2 + i * 0.4, turns: 1, settle: 0.9, drift: 0.12, rest: 0.55}} pose={{pitch: 0.4, scale: 1.1}} />
      </ThreeCanvas></div>)}
  </AbsoluteFill>;
};

registerRoot(() => <>
  <Composition id="Card" component={Card} durationInFrames={150} fps={60} width={720} height={720} defaultProps={{object: 'laptop', at: 0}} />
  <Composition id="Row" component={Row} durationInFrames={180} fps={60} width={1920} height={720} />
</>);
