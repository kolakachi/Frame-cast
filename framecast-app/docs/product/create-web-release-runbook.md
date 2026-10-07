# Create web build and browser recovery

Implemented locally, 2026-10-07. The Oracle deployment/rollback drill is still pending.

## Build from the lockfile

Use Node 22 and Yarn 1.22.22 with `web/yarn.lock`. In a clean checkout:

```sh
cd framecast-app/web
yarn install --frozen-lockfile
node --test tests/create-conversation.test.mjs tests/deployment-recovery.test.mjs
VITE_API_URL='' NAPI_RS_ENFORCE_VERSION_CHECK=1 yarn build
```

The empty API base above uses same-origin requests for the offline smoke. Production supplies its reviewed
`VITE_*` build arguments through Compose. Do not copy a development machine's `node_modules` into an image. The production nginx build and the web
Docker build now install from the frozen lockfile; their Docker contexts exclude host dependencies and local
environment files. The web `static` target still accepts an explicitly prebuilt `dist` directory.

The local failure `Cannot convert undefined or null to object` was reproduced with Vite 8.2.2 / Rolldown 1.2.6
loading a Darwin x64 binding for 1.0.0-rc.12. `NAPI_RS_ENFORCE_VERSION_CHECK=1` revealed the version mismatch.
The committed lockfile resolves Vite 8.0.3 / Rolldown 1.0.0-rc.12. A separate clean installation from that lockfile
builds successfully. The application no longer directly pins a platform-specific native binding; Rolldown's
optional dependencies select its matching binary. The existing mixed local installation was left untouched.

If an existing development install is mixed, stop the dev server and reinstall dependencies from the lockfile
in a clean checkout. Do not update dependency versions merely to hide this failure.

## Offline browser check

With Playwright available, run against the built output:

```sh
node tests/deployment-recovery.browser.mjs /absolute/path/to/dist
```

If Playwright is supplied by a separate tool runtime, set `NODE_PATH` to its `node_modules` directory.
`BROWSER_EXECUTABLE` can select an installed Chrome executable. The test starts an ephemeral localhost server,
uses an isolated browser context with fictitious authentication, mocks every API request and blocks external
traffic. It checks a missing Create route chunk, explicit reload, composer restoration and storage refusal,
and asserts that no API writes occurred. It does not contact production or a generation provider.

## Recovery behavior and boundaries

- A Vite preload or recognized router import failure displays a reload notice. It does not assume every network
  failure means an update. It never automatically reloads, navigates to the failed destination or repeats a POST.
- Reload retains the current URL. Create's composer is saved in tab-scoped session storage under user,
  workspace and conversation IDs. A failed storage write blocks the recovery button and asks the user to copy text.
- Create blocks this button during local requests/planning, with selected local files, changed plan selections
  or open editing panels. Save/copy edits before closing panels. Form fields outside the composer and local files
  are not serialized. Other app pages display the save-edits reminder; they do not yet register equivalent guards.
- There is no timer-driven reload loop. Repeated errors show one notice; one button activation causes one reload.
  If the file remains unavailable, the user sees the notice again and can retry when the connection/deploy is fixed.
- nginx serves the SPA entry HTML with `no-store`, while existing hashed assets remain cacheable. Missing assets
  return 404 rather than HTML. Verify CDN behavior separately; an edge rule can override origin headers.
- This handler needs the main app bundle to have loaded. A missing entry bundle during cold startup, browser
  storage eviction, closing the tab, and a manual browser refresh during unsaved work remain outside this recovery.

## Production release evidence still required

Use the [drain runbook](create-drain-runbook.md) before replacing API/worker code. Record API, web, worker,
sandbox and art-pack revisions. Preserve an old browser tab while deploying, then navigate to Create and confirm
recovery, draft restoration, playback and request status without duplicated work. Check actual CDN/origin HTML
headers and missing-chunk responses. Repeat after rollback. Test the ARM image build on the deployment platform.
This web work does not automate the separate worker deployment or make the existing full-stack deploy drain-safe.
