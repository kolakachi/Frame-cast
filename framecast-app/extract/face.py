"""
Where the face is in a presenter photo (2026-10-15), for the close-up the UGC video models get beside the dressed
picture. OpenCV's own frontal-face detector: local, free and steady, where a vision model's boxes were loose (it
boxed most of a portrait). Presenter photos face the camera, which is what this detector is built for.
"""

from __future__ import annotations

import cv2
import numpy as np

_DETECTOR = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")


def box(data: bytes) -> dict:
    """The largest face as [left, top, right, bottom] fractions of the image, and how many faces were found."""
    image = cv2.imdecode(np.frombuffer(data, dtype=np.uint8), cv2.IMREAD_GRAYSCALE)
    if image is None:
        raise ValueError("not an image")
    h, w = image.shape[:2]
    scale = min(1.0, 1024 / max(w, h))
    small = cv2.resize(image, (int(w * scale), int(h * scale))) if scale < 1 else image
    small = cv2.equalizeHist(small)
    min_side = max(24, int(min(small.shape[:2]) * 0.08))
    faces = _DETECTOR.detectMultiScale(small, scaleFactor=1.1, minNeighbors=6, minSize=(min_side, min_side))
    if len(faces) == 0:
        return {"face": False, "faces": 0, "box": None}
    x, y, fw, fh = max(faces, key=lambda f: f[2] * f[3])
    sh, sw = small.shape[:2]
    return {"face": True, "faces": int(len(faces)),
            "box": [round(x / sw, 4), round(y / sh, 4), round((x + fw) / sw, 4), round((y + fh) / sh, 4)]}
