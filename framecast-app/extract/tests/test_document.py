"""Run inside the extract image: python tests/test_document.py. Builds real sample documents and checks the reads."""
import base64, io, os, subprocess, sys, tempfile
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import fitz
import document

def pdf_bytes(doc):
    b = doc.tobytes(); doc.close(); return b

def picture(w, h, rgb=(200, 80, 40), bands=()):
    pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, w, h), 0)
    pix.set_rect(pix.irect, rgb)
    for y0, y1 in bands:  # white gaps inside a tall picture
        pix.set_rect(fitz.IRect(0, y0, w, y1), (255, 255, 255))
    return pix

ok = 0
def check(cond, label):
    global ok
    if not cond: raise SystemExit("FAIL: " + label)
    ok += 1; print("ok -", label)

# 1. A brochure: text pages, a photo, a tiny icon, the same photo again on another page.
d = fitz.open()
for n in range(3):
    pg = d.new_page()
    pg.insert_text((72, 72), ("Glow Serum, 30 ml, vitamin C 15%. Visible glow in 7 days. " * 4) + f"Page {n+1}.", fontsize=11)
photo, icon = picture(600, 800), picture(40, 40, (0, 0, 0))
d[0].insert_image(fitz.Rect(72, 200, 372, 600), pixmap=photo)
d[0].insert_image(fitz.Rect(400, 72, 420, 92), pixmap=icon)
d[2].insert_image(fitz.Rect(72, 200, 372, 600), pixmap=photo)
r = document.analyse(pdf_bytes(d), "brochure.pdf")
check(r["source"] == "pdf" and r["page_count"] == 3, "brochure: 3 pages read as PDF")
check(len(r["pictures"]) == 1, "brochure: one photo, icon skipped, repeat dropped")
check(all(len(p["parts"]) == 1 for p in r["pages"]), "brochure: normal pages are one part each")
check("Visible glow in 7 days" in r["pages"][0]["text"], "brochure: text kept per page")
check(r["text_only"] is False, "brochure: has pictures, so not text only")

# 2. One page thirty times taller than wide, with blank gaps between product blocks.
d = fitz.open(); W = 400; H = W * 30
pg = d.new_page(width=W, height=H)
block = 520
y = 20
while y + block < H:
    pg.draw_rect(fitz.Rect(20, y, W - 20, y + block), color=(0, 0, 0), fill=(0.2, 0.4, 0.8))
    y += block + 60   # a 60pt gap after each product block
r = document.analyse(pdf_bytes(d), "sheet.pdf")
parts = r["pages"][0]["parts"]
check(len(parts) > 10, f"tall page: cut into {len(parts)} parts")
in_gap = 0
for p in parts[:-1]:
    yy = p["y1"]; rel = (yy - 20) % (block + 60)
    in_gap += rel > block  # the cut lands in a gap, not inside a block
check(in_gap >= len(parts) - 2, f"tall page: {in_gap} of {len(parts)-1} cuts land in the gaps between blocks")

# 3. A tall picture (an infographic strip) comes in parts too.
d = fitz.open(); pg = d.new_page(width=400, height=800)
strip = picture(500, 3500, (30, 120, 60), bands=[(800, 860), (1700, 1760), (2600, 2660)])
pg.insert_image(fitz.Rect(20, 20, 380, 780), pixmap=strip)
r = document.analyse(pdf_bytes(d), "infographic.pdf")
check(len(r["pictures"]) == 1 and len(r["pictures"][0]["parts"]) >= 3, "tall picture: offered in parts")

# 4. A scanned page: the page picture is a page, not a picture.
d = fitz.open(); pg = d.new_page()
pg.insert_image(pg.rect, pixmap=picture(1200, 1600, (240, 240, 230)))
r = document.analyse(pdf_bytes(d), "scan.pdf")
check(r["pages"][0]["kind"] == "scanned" and r["pictures"] == [] and r["scanned_units"] == 1, "scan: one page to read with vision, no picture")

# 5. Renders: a page part and a picture part come back as JPEGs.
d = fitz.open(); pg = d.new_page(width=W, height=H); pg.draw_rect(fitz.Rect(20, 20, 380, 540), fill=(1, 0, 0))
pg2 = d.new_page(); pg2.insert_image(fitz.Rect(72, 72, 372, 472), pixmap=photo)
data = pdf_bytes(d)
r = document.analyse(data, "mix.pdf")
pid = r["pictures"][0]["id"]
imgs = document.render(data, "mix.pdf", [{"kind": "page", "number": 1, "part": 2}, {"kind": "picture", "id": pid, "part": 1}, {"kind": "page", "number": 9, "part": 1}])
check(len(imgs) == 2 and all(base64.b64decode(i["base64"])[:2] == b"\xff\xd8" for i in imgs), "render: two JPEGs, the missing page left out")
check(abs(imgs[0]["width"] - document.PAGE_RENDER_WIDTH) <= 2, "render: pages drawn at the render width")

# 6. Word: converted to PDF first, its text read.
work = tempfile.mkdtemp()
with open(os.path.join(work, "notes.txt"), "w") as fh: fh.write("Q4 offer: three months of Pro for the price of two. Ends December 31.\n")
subprocess.run(["soffice", f"-env:UserInstallation=file://{work}/p", "--headless", "--convert-to", "docx", "--outdir", work, os.path.join(work, "notes.txt")], check=True, capture_output=True, timeout=120)
docx = open(os.path.join(work, "notes.docx"), "rb").read()
r = document.analyse(docx, "notes.docx")
check(r["source"] == "docx" and "Q4 offer" in r["pages"][0]["text"] and r["pdf_base64"], "word: converted, text read, PDF returned")
check(r["text_only"] is True, "word notes: nothing to pick, text only")

# 6b. A page with a chart drawn as shapes is not text only.
d = fitz.open(); pg = d.new_page(); pg.insert_text((72, 72), "Results by month " * 10, fontsize=11)
for i in range(14): pg.draw_rect(fitz.Rect(80 + i * 30, 500 - i * 20, 100 + i * 30, 500), fill=(0.9, 0.4, 0.2))
r = document.analyse(pdf_bytes(d), "chart.pdf")
check(r["text_only"] is False and r["pages"][0]["drawings"] >= 12, "chart page: drawn chart keeps the drawer")

# A deck's speaker notes, by slide (made with LibreOffice from tests/fixtures/notes-deck.fodp).
deck = open(os.path.join(os.path.dirname(__file__), "fixtures", "notes-deck.pptx"), "rb").read()
r = document.analyse(deck, "notes-deck.pptx")
check(r["source"] == "pptx" and r["page_count"] == 3 and [n["slide"] for n in r["notes"]] == [1, 3], "deck: notes read for slides 1 and 3")
check(r["notes"][1]["text"].endswith("& no card needed.") and "Ask the room" in r["notes"][0]["text"], "deck: notes text kept whole, entities decoded")

# A long document is read to its first MAX_PAGES pages, not refused.
long = fitz.open()
for n in range(document.MAX_PAGES + 4):
    long.new_page(width=595, height=842).insert_text((50, 80), f"Page {n + 1} of the plan", fontsize=14)
r = document.analyse(pdf_bytes(long), "long.pdf")
check(r["page_count"] == document.MAX_PAGES and r["pages_total"] == document.MAX_PAGES + 4 and len(r["pages"]) == document.MAX_PAGES, "long: first pages read, total reported")

# 7. Refusals that say what to do.
for data, name, needle in [(b"hello", "x.txt", "PDF, Word"), (b"hello", "x.docx", "doesn't look like"), (b"%PDF-1.4 broken", "x.pdf", "couldn't read")]:
    try: document.analyse(data, name); raise SystemExit("FAIL: accepted " + name)
    except ValueError as e: check(needle in str(e), f"refuses {name}: {e}")
print(f"\nPASS {ok} checks")
