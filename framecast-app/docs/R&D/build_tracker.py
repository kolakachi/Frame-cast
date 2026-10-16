"""Builds README.md's tracker and tracker.html (the published progress page) from items.py. Run after any change."""
import html, json, datetime, pathlib
from items import ITEMS

HERE = pathlib.Path(__file__).parent
ORDER = ["check", "next", "open", "idea", "done", "skipped"]
LABEL = {"check": "Check", "next": "Next", "open": "Open", "idea": "Idea", "done": "Done", "skipped": "Skipped"}
today = datetime.date.today().isoformat()
rows = sorted(ITEMS, key=lambda r: (ORDER.index(r[4]), r[0]))

# README.md: the tracker section is rewritten; the notes below it are kept.
readme = (HERE / "README.md").read_text()
head = """# R&D

What we found while researching, what we decided to take, and what is still waiting. One list: this tracker, built
with the published progress page from `items.py` (`python3 build_tracker.py`). Status: **check** (said done, to be
confirmed), **next** (decided, not built), **open** (to build, not yet ordered), **idea** (worth considering, needs a
yes or no), **done**, **skipped** (decided against, reason in the note). Owner, 2026-10-15: clear this list.

## Tracker

| ID | Area | Item | From | Status | Note |
|----|------|------|------|--------|------|
"""
table = "".join(f"| {r[0]} | {r[1]} | {r[2]} | {r[3]} | {r[4]} | {r[5]} |\n" for r in rows)
tail = readme[readme.index("Run `php artisan create:move-gaps`"):] if "Run `php artisan create:move-gaps`" in readme else ""
(HERE / "README.md").write_text(head + table + "\nNotes per topic: [higgsfield-claude-motion.md](higgsfield-claude-motion.md), [kits-roadmap.md](kits-roadmap.md), "
    "[motion-skill-repos.md](motion-skill-repos.md), [opus-motion-harness.md](opus-motion-harness.md), [higgsfield-onboarding.md](higgsfield-onboarding.md), "
    "[follow-ups.md](follow-ups.md); repo licences in `docs/product/archive/2026-10-16/priorities.md` (Research repos we borrow from).\n\n" + tail)

data = [dict(zip(["id", "area", "item", "src", "status", "note"], r)) for r in rows]
page = (HERE / "tracker.template.html").read_text().replace("__DATA__", json.dumps(data)).replace("__UPDATED__", today)
(HERE / "tracker.html").write_text(page)
print(f"{len(rows)} items; " + ", ".join(f"{LABEL[s]} {sum(1 for r in rows if r[4] == s)}" for s in ORDER))
