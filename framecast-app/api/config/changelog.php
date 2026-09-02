<?php

/*
|--------------------------------------------------------------------------
| Product changelog
|--------------------------------------------------------------------------
|
| User-facing release notes. Rendered in two places from this single source:
|   • in-app  — "What's new" in the sidebar (unread dot until the user opens it)
|   • public  — /changelog.html on the marketing site, via the unauthenticated
|               GET /api/v1/public/changelog endpoint
|
| Entries live in the repo rather than a database table on purpose: authoring
| is a commit (reviewable in a diff, versioned, no admin UI to build), and a
| release note ships with the release that introduced it.
|
| Rules for writing entries:
|   • Newest first — order here is the order shown.
|   • `date` is ISO (YYYY-MM-DD) and drives the "new since you last looked"
|     comparison against users.changelog_seen_at.
|   • `slug` must be unique and stable — it's the public anchor link.
|   • `tag` is one of: new | improved | fixed.
|   • Write for customers, not engineers. Say what changed for THEM. No
|     internal identifiers, model names only where the user can act on them.
|
*/

return [

    'entries' => [

        [
            'slug'  => '2026-09-02-captions-match-your-export',
            'date'  => '2026-09-02',
            'tag'   => 'fixed',
            'title' => 'Captions now look the same in your video as in the editor',
            'body'  => 'What you set up in the editor is what you get in the finished video. '
                .'Caption text was rendering smaller in exports than it looked while you were '
                .'editing, and the bolder fonts came out thinner than they should have. Both are '
                .'fixed, so sizes, weights and spacing now match the preview exactly — at every '
                .'caption size and in every aspect ratio.',
        ],

        [
            'slug'  => '2026-09-01-caption-effects',
            'date'  => '2026-09-01',
            'tag'   => 'new',
            'title' => 'Caption effects — 16 animated styles',
            'body'  => 'Captions can move now. Open the Captions panel and pick an effect: words '
                .'pop in one at a time, light up as they\'re spoken, type out like a terminal, '
                .'glow, glitch, or slide onto a news-style bar. Whatever you choose, the words '
                .'animate on your voiceover\'s real timing, so they land on the beat. Your font, '
                .'size, colours and position still apply on top of any effect — and Plain keeps '
                .'the classic look if you\'d rather have no animation. Pick from the row in the '
                .'Captions panel, or hit View all to preview every style on your own scene.',
        ],

        [
            'slug'  => '2026-08-29-ai-video-from-brief',
            'date'  => '2026-08-29',
            'tag'   => 'new',
            'title' => 'AI Video — fully animated videos from a brief',
            'body'  => 'The video wizard has a new visuals option: AI Video. Every scene gets an '
                .'AI-generated image in your chosen style, then comes to life as real motion video. '
                .'Pick the video model that fits your budget, and see the full credit cost before '
                .'anything is generated. Works with your recurring character too — pick one and '
                .'they appear in every animated scene.',
        ],

        [
            'slug'  => '2026-08-29-new-video-models',
            'date'  => '2026-08-29',
            'tag'   => 'new',
            'title' => 'Two new animation models: Veo 3.1 Fast and Seedance 2.5',
            'body'  => 'Google\'s Veo 3.1 Fast (sharp, natural motion — great with people) and '
                .'ByteDance\'s Seedance 2.5 flagship join the animation line-up, in the scene '
                .'editor and the video wizard. Each shows its per-scene price up front. If a '
                .'model declines an image, you\'re told plainly and nothing is charged.',
        ],

        [
            'slug'  => '2026-08-29-bulk-scene-actions',
            'date'  => '2026-08-29',
            'tag'   => 'new',
            'title' => 'Do it once, apply it everywhere',
            'body'  => 'Three new bulk actions in the editor: animate scenes in one go (pick all '
                .'or just some), apply an image style across the project, and re-record every '
                .'voiceover after a change. Each shows the exact credit cost and your balance '
                .'before you confirm — and scenes built on the same image share one animation '
                .'instead of paying to render it repeatedly. You can also apply one voice, '
                .'including its delivery direction, to every scene at once.',
        ],

        [
            'slug'  => '2026-08-29-audiogram-and-style-pickers',
            'date'  => '2026-08-29',
            'tag'   => 'improved',
            'title' => 'See what you\'re picking',
            'body'  => 'The video wizard now lets you choose your audiogram\'s design, color and '
                .'background up front — same picker as the editor, with live previews. And '
                .'generating images from a character now shows sample thumbnails for all 21 '
                .'styles instead of a nine-item dropdown.',
        ],

        [
            'slug'  => '2026-08-29-videos-match-their-length',
            'date'  => '2026-08-29',
            'tag'   => 'fixed',
            'title' => 'Longer videos actually come out longer',
            'body'  => 'Choosing 90 seconds or 3 minutes could still produce a much shorter video '
                .'— the script didn\'t grow with the target length, and long scripts could '
                .'silently lose their ending. Scripts and scene counts now scale with your '
                .'chosen duration, every line of the script makes it into the video, and long '
                .'videos get proper pacing: sections, re-hooks, and varied scene lengths.',
        ],

        [
            'slug'  => '2026-08-29-voice-direction-stays-silent',
            'date'  => '2026-08-29',
            'tag'   => 'fixed',
            'title' => 'Voice direction no longer read aloud',
            'body'  => 'Occasionally an expressive voice would speak its direction — "calm, '
                .'soothing, and relaxed…" — before the actual line. Directions are now phrased '
                .'to the voice engine as instructions, so they shape the delivery without '
                .'ending up in it.',
        ],

        [
            'slug'  => '2026-08-29-video-from-pdf',
            'date'  => '2026-08-29',
            'tag'   => 'new',
            'title' => 'Turn a PDF into a video',
            'body'  => 'Upload a PDF and we\'ll build a video from it — reports, guides, one-pagers, '
                .'decks. Long documents are condensed first, so the script covers the whole thing '
                .'rather than just the opening pages. Scanned PDFs work too: where a page is a '
                .'picture of text rather than text itself, we can read it with AI. You\'ll see how '
                .'many pages that affects and exactly what it costs before anything is charged, and '
                .'you can always choose to skip them.',
        ],

        [
            'slug'  => '2026-08-25-sharper-images-by-default',
            'date'  => '2026-08-25',
            'tag'   => 'improved',
            'title' => 'Sharper AI images, automatically',
            'body'  => 'Scene images now generate on a newer, higher-fidelity model by default, '
                .'instead of being something you had to go and select. If you want to trade '
                .'detail for credits, the model picker in the scene editor still offers cheaper '
                .'and faster options — including a 1-credit draft mode.',
        ],

        [
            'slug'  => '2026-08-25-link-import-reads-the-page',
            'date'  => '2026-08-25',
            'tag'   => 'fixed',
            'title' => 'Importing from a link actually reads the link',
            'body'  => 'Creating a video from a URL could quietly pull in a page\'s scaffolding '
                .'rather than its content, and write a script about the wrong subject entirely. '
                .'Link imports now extract the real article text, handle YouTube links properly, '
                .'and — if a page can\'t be read (paywalls and login-only pages, mostly) — tell '
                .'you so instead of generating something unrelated. A failed import costs no credits.',
        ],

        [
            'slug'  => '2026-08-25-background-music',
            'date'  => '2026-08-25',
            'tag'   => 'fixed',
            'title' => 'Background music tracks are available again',
            'body'  => 'Newer workspaces were created without the built-in music library, so the '
                .'music picker had nothing in it and selecting a track appeared to do nothing. '
                .'Every workspace now has the full set, and new signups get it automatically.',
        ],

    ],

];
