// Proof of the parametric 3D mascot (runtime/wyv-mascot3d.js): one character built from a spec, rigged by
// construction. "Perform": slides in, turns to camera, says a line with its mouth on the words, blinks, winks.
// "Turnaround": the same character at a given angle. "Variant": a second spec, to show the parts are parametric.
import React from 'react';
import {registerRoot, Composition, AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate} from 'remotion';
import {ThreeCanvas} from '@remotion/three';
import {Mascot3D} from './wyv-mascot3d.js';

const ours = {seed: 11, head: {shape: 'sphere', skin: '#e9e2da'}, hair: {style: 'curls', color: '#626262', volume: 1},
  eyes: {style: 'disc', size: 1.05, spacing: 1}, brows: {style: 'bar'}, cheeks: {color: '#e0a8a2'}, nose: {style: 'button'},
  body: {outfit: 'sweater', color: '#a7a7a7', collar: 'turtleneck', pocket: true}, finish: 'dither'};
const variant = {seed: 3, head: {shape: 'egg', skin: '#f3d2b5'}, hair: {style: 'bob', color: '#2b2f6b'}, eyes: {style: 'oval', size: 1},
  brows: {style: 'bar', color: '#2b2f6b'}, cheeks: {color: '#f29c8e'}, nose: {style: 'button'},
  body: {outfit: 'sweater', color: '#ff6b35', collar: 'turtleneck', pocket: false}, finish: 'toon'};
const WORDS = [{text: 'Got', start: 1.25, end: 1.45}, {text: 'something', start: 1.48, end: 1.9}, {text: 'to', start: 1.93, end: 2.02}, {text: 'sell', start: 2.05, end: 2.45}];

const Stage = ({children, background = '#ffffff'}) => {
  const {width, height} = useVideoConfig();
  return <AbsoluteFill style={{background}}><ThreeCanvas width={width} height={height} camera={{fov: 30, position: [0, -0.4, 9]}} gl={{antialias: false, preserveDrawingBuffer: true}}>{children}</ThreeCanvas></AbsoluteFill>;
};

const Perform = () => {
  const frame = useCurrentFrame(), {fps} = useVideoConfig();
  const enter = spring({frame, fps, config: {damping: 15, stiffness: 120, mass: 0.9}});
  const turn = spring({frame: frame - 18, fps, config: {damping: 13, stiffness: 110}});
  const idle = Math.max(0, frame / fps - 0.9);
  const pose = {x: interpolate(enter, [0, 1], [7, 2.3]), y: Math.sin(idle * Math.PI * 1.6) * 0.03,
    bodyYaw: interpolate(enter, [0, 1], [-0.5, -0.12]), yaw: interpolate(turn, [0, 1], [-0.55, 0]) + Math.sin(idle * Math.PI * 0.7) * 0.06,
    tilt: Math.sin(idle * Math.PI * 0.9) * 0.05, pitch: Math.sin(idle * Math.PI * 1.2) * 0.03};
  return <Stage><Mascot3D spec={ours} words={WORDS} blinks={[0.85, 3.3]} expressions={[{at: 2.7, duration: 0.6, face: 'wink'}, {at: 1.0, duration: 0.25, gaze: [-0.6, 0]}]} pose={pose} /></Stage>;
};
const Turnaround = ({yaw = 0, finish = 'dither', face}) => <Stage><Mascot3D spec={{...ours, finish}} expressions={face ? [{at: 0, duration: 99, face}] : []} blinks={[]} pose={{yaw, bodyYaw: yaw * 0.6, y: 0}} /></Stage>;
const Variant = ({yaw = 0, face}) => <Stage background="#fff7f2"><Mascot3D spec={variant} expressions={face ? [{at: 0, duration: 99, face}] : []} blinks={[]} pose={{yaw, bodyYaw: yaw * 0.6}} /></Stage>;

registerRoot(() => <>
  <Composition id="Perform" component={Perform} durationInFrames={240} fps={60} width={1920} height={1080} />
  <Composition id="Turnaround" component={Turnaround} durationInFrames={1} fps={60} width={1080} height={1080} defaultProps={{yaw: 0}} />
  <Composition id="Variant" component={Variant} durationInFrames={1} fps={60} width={1080} height={1080} defaultProps={{yaw: 0}} />
</>);
