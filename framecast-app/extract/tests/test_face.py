import cv2
import numpy as np
import pytest

import face


def _png(image) -> bytes:
    ok, buf = cv2.imencode(".png", image)
    assert ok
    return buf.tobytes()


def test_a_photo_without_a_face_has_no_box():
    assert face.box(_png(np.full((400, 300, 3), 200, dtype=np.uint8))) == {"face": False, "faces": 0, "box": None}


def test_something_that_is_not_an_image_is_refused():
    with pytest.raises(ValueError):
        face.box(b"not an image")
