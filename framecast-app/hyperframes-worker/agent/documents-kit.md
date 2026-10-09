# WyvStudio documents kit (in wyv-motion.js)

Load wyv-motion.js after GSAP, as for every kit move.

## Documents: pages and slides as they look
For assets of kind document_page: the user's own pages or slides, chosen to be shown as they look. Place the image itself (never a rebuilt or restyled version), keep the document's order, and hold each long enough to be recognised (about 3 s or more).
- `WM.book(tl, '#book', [4.2, 8.6], {spread: false, duration: 0.9})`: pages that turn. `#book` is a positioned box (one page's size; two pages wide with `spread: true`, page 1 on the left), holding one `<div class="wm-page"><img src="page-1.jpg" style="width:100%;height:100%;object-fit:cover"></div>` per page in order. Each time in the list turns one page over the spine, with the light falling across it and a page sound. A portrait page in a 9:16 frame: one page at a time; an open book in 16:9: `spread: true`. Put the book on a surface (a desk, a soft shadow, a little tilt) rather than floating on a flat colour.
- `WM.slides(tl, '#deck', [5, 10.5], {transition: 'push'})`: a deck's slides, each filling the frame (`<div class="wm-slide"><img …></div>` per slide in `#deck`). Each time brings in the next slide: `push` (default), `fade` or `zoom`. In 9:16, show the slide whole in the upper part of the frame (it is 16:9) and use the rest for the presenter or a caption, or push in on its key part.
- `WM.pageFocus(tl, '#content', '#page-3', {x: .08, y: .52, w: .84, h: .3}, at, {hold: 2})`: the camera moves to a part of a page or slide (fractions of that page: x, y, w, h) and back, so the viewer can read what the voice is saying: a chart, a figure, a heading. `#content` is the full-frame layer holding the book or slides. Inspect the page to find the box. Time it to the words (data-spoken).
- A presenter in the corner (a talking take or the 3D mascot) sits above the book or slides, never covering the part in focus.
