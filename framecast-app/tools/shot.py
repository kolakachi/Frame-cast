#!/usr/bin/env python3
"""
Screenshot a page at a real device viewport.

Chrome's --window-size does NOT give you a mobile layout: it lays the page out
at its own minimum width and crops the image, so a desktop-width page looks
like it is overflowing when it is merely cropped. That cost an hour once.
Everything here goes through Emulation.setDeviceMetricsOverride instead, which
is what actually changes the viewport the CSS sees.

  python3 tools/shot.py <url> <out.png> [width] [height] [--full] [--auth FILE]

--auth takes a JSON file of {"accessToken":..., "user":...} and writes it to
localStorage under framecast.auth before navigating, so authenticated pages
can be captured without driving a login form.

Needs Chrome already running with --remote-debugging-port=9222, or starts one.
"""
import base64, json, os, pathlib, socket, struct, subprocess, sys, time
from urllib.request import urlopen

PORT = 9222
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
COOKIE_KEY = 'wyv_cookie_notice_v1'


def ensure_chrome():
    try:
        urlopen(f"http://127.0.0.1:{PORT}/json/version", timeout=2).read()
        return None
    except Exception:
        # Its own profile directory, always. Sharing the default profile with
        # the Chrome the developer already has open makes the new instance
        # hand the URL to that one and exit, and the debugging port never
        # opens — which looks exactly like "chrome did not start".
        p = subprocess.Popen(
            [CHROME, "--headless", "--disable-gpu", f"--remote-debugging-port={PORT}",
             "--hide-scrollbars", "--no-first-run", "--no-default-browser-check",
             f"--user-data-dir={os.path.join(os.path.expanduser('~'), '.cache', 'wyv-shot-profile')}",
             "about:blank"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(120):
            time.sleep(0.25)
            try:
                urlopen(f"http://127.0.0.1:{PORT}/json/version", timeout=1).read()
                return p
            except Exception:
                pass
        raise RuntimeError("chrome did not start")


def ws_connect(url):
    hp, path = url.split("://")[1].split("/", 1)
    host, port = hp.split(":")
    s = socket.create_connection((host, int(port)), timeout=20)
    key = base64.b64encode(os.urandom(16)).decode()
    s.send(f"GET /{path} HTTP/1.1\r\nHost: {hp}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
           f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n".encode())
    buf = b""
    while b"\r\n\r\n" not in buf:
        buf += s.recv(4096)
    return s, buf.split(b"\r\n\r\n", 1)[1]


def send(s, i, method, params=None):
    msg = json.dumps({"id": i, "method": method, "params": params or {}}).encode()
    mask, n = os.urandom(4), len(msg)
    hdr = b"\x81"
    if n < 126:
        hdr += bytes([0x80 | n])
    elif n < 65536:
        hdr += bytes([0xFE]) + struct.pack(">H", n)
    else:
        hdr += bytes([0xFF]) + struct.pack(">Q", n)
    s.send(hdr + mask + bytes(b ^ mask[j % 4] for j, b in enumerate(msg)))


def frames(s, rest=b""):
    buf = rest
    while True:
        while len(buf) < 2:
            buf += s.recv(1 << 20)
        ln, off = buf[1] & 0x7F, 2
        if ln == 126:
            while len(buf) < 4:
                buf += s.recv(1 << 20)
            ln, off = struct.unpack(">H", buf[2:4])[0], 4
        elif ln == 127:
            while len(buf) < 10:
                buf += s.recv(1 << 20)
            ln, off = struct.unpack(">Q", buf[2:10])[0], 10
        while len(buf) < off + ln:
            buf += s.recv(1 << 20)
        yield buf[off:off + ln]
        buf = buf[off + ln:]


def wait(gen, want_id, timeout=40):
    end = time.time() + timeout
    while time.time() < end:
        try:
            d = json.loads(next(gen).decode("utf-8", "ignore"))
        except Exception:
            continue
        if d.get("id") == want_id:
            return d
    raise TimeoutError(f"no reply to id {want_id}")


def main():
    url, out = sys.argv[1], sys.argv[2]
    w = int(sys.argv[3]) if len(sys.argv) > 3 else 390
    h = int(sys.argv[4]) if len(sys.argv) > 4 else 844
    full = "--full" in sys.argv
    # Wait for a selector instead of guessing a sleep. Screenshotting a
    # loading skeleton and reading it as the page cost a round trip once.
    wait_for = sys.argv[sys.argv.index("--wait-for") + 1] if "--wait-for" in sys.argv else None
    auth = None
    if "--auth" in sys.argv:
        auth = pathlib.Path(sys.argv[sys.argv.index("--auth") + 1]).read_text().strip()

    ensure_chrome()
    tabs = json.loads(urlopen(f"http://127.0.0.1:{PORT}/json").read())
    ws = [t["webSocketDebuggerUrl"] for t in tabs if t["type"] == "page"][0]
    s, rest = ws_connect(ws)
    gen = frames(s, rest)

    send(s, 1, "Page.enable")
    send(s, 2, "Emulation.setDeviceMetricsOverride",
         {"width": w, "height": h, "deviceScaleFactor": 2, "mobile": True})
    wait(gen, 2)
    # Errors are collected in the page and read back afterwards. Listening for
    # protocol events meant draining the same frame stream the screenshot reply
    # arrives on, and the screenshot never came.
    send(s, 20, "Page.addScriptToEvaluateOnNewDocument", {"source":
         "window.__err = [];"
         "addEventListener('error', e => window.__err.push(String(e.message)));"
         "addEventListener('unhandledrejection', e => window.__err.push('promise: ' + String(e.reason)));"
         "const _ce = console.error;"
         "console.error = (...a) => { try { window.__err.push(a.map(String).join(' ').slice(0,200)) } catch {} ; _ce(...a) };"})
    wait(gen, 20)

    if auth:
        # Seed the session on the right origin, then load the page for real.
        origin = "/".join(url.split("/")[:3])
        send(s, 30, "Page.navigate", {"url": origin + "/login"})
        wait(gen, 30)
        time.sleep(1.5)
        send(s, 31, "Runtime.evaluate", {"returnByValue": True, "expression":
             "localStorage.setItem('framecast.auth', " + json.dumps(auth) + "); 'ok'"})
        wait(gen, 31)

    # The cookie notice is a fixed overlay that covers a third of a phone
    # screen, so every screenshot below it would be of the notice.
    send(s, 32, "Runtime.evaluate", {"returnByValue": True, "expression":
         "try{localStorage.setItem(%s, JSON.stringify({ts:Date.now()}))}catch(e){}; 'ok'" % json.dumps(COOKIE_KEY)})
    wait(gen, 32)

    send(s, 3, "Page.navigate", {"url": url})
    wait(gen, 3)
    time.sleep(2.0)
    if wait_for:
        for _ in range(180):                      # up to ~90s: local media streams take 3-4s each
            send(s, 40, "Runtime.evaluate", {"returnByValue": True,
                 "expression": "!!document.querySelector(" + json.dumps(wait_for) + ")"})
            if wait(gen, 40)["result"]["result"]["value"]:
                break
            time.sleep(0.5)
        else:
            print(f"!! timed out waiting for {wait_for}")
        time.sleep(1.0)
    else:
        time.sleep(2.5)

    # Report anything wider than the viewport while we are here — the whole
    # point of looking at a phone layout.
    send(s, 4, "Runtime.evaluate", {"returnByValue": True, "expression": """
      (() => {
        const vw = document.documentElement.clientWidth;
        // An element wider than the screen is only a bug if nothing can scroll
        // to it. A horizontal rail is SUPPOSED to run off the edge, so walk up
        // and ignore anything living inside a scrollable ancestor — otherwise
        // every carousel reads as breakage and the real ones get lost.
        const scrollable = (el) => {
          for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const ox = getComputedStyle(p).overflowX;
            if ((ox === 'auto' || ox === 'scroll') && p.scrollWidth > p.clientWidth + 1) return true;
          }
          return false;
        };
        const bad = [];
        document.querySelectorAll('*').forEach(el => {
          const r = el.getBoundingClientRect();
          if (r.right > vw + 1 && !scrollable(el)) {
            bad.push(el.tagName.toLowerCase() + '.' +
              ((el.className && el.className.toString ? el.className.toString() : '').split(' ')[0] || '?') +
              ' right=' + Math.round(r.right));
          }
        });
        return JSON.stringify({ vw, scrollWidth: document.documentElement.scrollWidth,
                                overflow: bad.slice(0, 10) }); })()"""})
    print(wait(gen, 4)["result"]["result"]["value"])

    # A silently expired token bounces to /login, and the screenshot below
    # would be of the login form rather than the page asked for.
    send(s, 44, "Runtime.evaluate", {"returnByValue": True,
         "expression": "location.pathname"})
    here = wait(gen, 44)["result"]["result"]["value"]
    want = "/" + url.split("://")[1].split("/", 1)[1].split("?")[0] if "/" in url.split("://")[1] else "/"
    if auth and here.rstrip("/") != want.rstrip("/"):
        print(f"!! redirected to {here} (asked for {want}) — token expired? re-mint it")

    send(s, 42, "Runtime.evaluate", {"returnByValue": True,
         "expression": "JSON.stringify((window.__err||[]).slice(0,6))"})
    errs = json.loads(wait(gen, 42)["result"]["result"]["value"] or "[]")
    for e in dict.fromkeys(errs):
        print("  console:", e)

    send(s, 5, "Page.captureScreenshot", {"format": "png", "captureBeyondViewport": full})
    data = wait(gen, 5, 60)["result"]["data"]
    with open(out, "wb") as f:
        f.write(base64.b64decode(data))
    print(f"wrote {out}")


if __name__ == "__main__":
    main()
