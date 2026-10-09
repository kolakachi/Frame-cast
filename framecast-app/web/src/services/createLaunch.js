// A creation set up in the dashboard's card modal (2026-10-09), handed to Create in the same tab: the brief, the
// settings, the chosen presenter, library picks (asset ids) and any new files (files cannot ride a link, so this stays in memory). Create takes it
// once, starts the conversation with those settings and sends the brief, so planning starts straight away.
let pending = null

export function setLaunch(launch) { pending = { ...launch, at: Date.now() } }

/** The launch, removed as it is read; a stale one (over 10 minutes) is dropped. */
export function takeLaunch(now = Date.now()) {
  const launch = pending
  pending = null
  return launch && now - launch.at < 10 * 60e3 ? launch : null
}
