# UGC verification

The UGC director supports continuous talking takes, demonstrations, stories and
silent 5/10-second reactions. It remains behind the existing internal-only gate.

## Automated checks

From `framecast-app/api`, run `php vendor/bin/phpunit`. UGC execution tests create
their own in-memory SQLite database, fake job dispatch and block external HTTP.
They never use the application's configured database or paid generation models.

For browser checks, use a Node runtime compatible with Vite **and matching the
architecture of the installed native dependencies**. Start Vite locally with a
dummy realtime key (the current app bootstrap requires a key even for mocks):

```sh
VITE_REVERB_APP_KEY=ugc-local-test VITE_REVERB_HOST=127.0.0.1 npm run dev -- --host 127.0.0.1 --port 5179 --strictPort
```

Then run `node tests/ugc-flow.mjs` from `web`. It intercepts all API calls with
fixtures, blocks other external HTTP, and checks plan approval, invalidation,
generation payloads, take persistence and generic voice samples. It requires
Playwright; set `PLAYWRIGHT_MODULE` to an existing installation if not local.
Optionally set `CHROME_PATH` to an installed Chrome executable.

Run `node tests/ugc-headline.mjs` to render a real one-second MP4 through the
production FFmpeg scene renderer and compare headline bounds with the actual Vue
component. This requires PHP dependencies, FFmpeg/ffprobe, Playwright and pngjs
(`PNGJS_MODULE` may point to an existing installation). It checks the valid silent
audio stream and headline geometry within four pixels at 1080×1920. Both browser
scripts accept `UGC_TEST_URL` and write evidence into fresh temporary directories.

## What still requires a live acceptance run

Mocked tests do not judge model performance. Generate one reaction and one talking
take before trying a batch; check identity, expression, speech pacing, lip-sync,
headroom, and the final export. Motion prompts request behavior but cannot
guarantee exact gesture timing. Review any generated claims against the brief.

Multi-shot speech is still synthesized per shot; direct-to-camera is the supported
continuous-audio path. Headlines persist for a whole shot, not arbitrary timed
text beats. Stock/upload cutaways must be selected from the workspace library;
stock is not automatically searched/imported. Generated cutaways are explicitly
illustrative stills. There is no automated semantic video-quality scoring yet.

No migration or new provider credential is required. Deploy the API and web
together and restart queue workers so old workers do not execute new UGC jobs
with stale code. This change does not publish the feature to non-internal users.
