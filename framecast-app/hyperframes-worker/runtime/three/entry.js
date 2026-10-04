// The 3D runtime for plain HTML compositions: the mascot and prop builders without React or Remotion.
import * as THREE from 'three';
import {buildMascot, applyRig, faceAt, mouthCues, autoBlinks, shapes, finishMaterial, spinAt} from '../wyv-mascot3d.js';
window.W3 = {THREE, buildMascot, applyRig, faceAt, mouthCues, autoBlinks, shapes, finishMaterial, spinAt};
