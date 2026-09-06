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
            'slug'  => '2026-09-06-instagram-and-facebook-publishing',
            'date'  => '2026-09-06',
            'tag'   => 'new',
            'title' => 'Publish and schedule to Instagram and Facebook',
            'body'  => 'You can now connect an Instagram account or a Facebook Page and send '
                .'your finished videos straight there as Reels — publish immediately, or pick a '
                .'date and time and let it go out on its own. That joins YouTube and TikTok, so '
                .'all four platforms now work the same way from the Schedule button and the '
                .'Calendar. Connect an account under Channels, and you will approve access on '
                .'the platform own login screen.',
        ],

        [
            'slug'  => '2026-09-06-schedule-in-any-timezone',
            'date'  => '2026-09-06',
            'tag'   => 'improved',
            'title' => 'Choose the timezone when you schedule a post',
            'body'  => 'The scheduler now asks which timezone you mean, instead of quietly '
                .'assuming the one your computer is set to. Useful when you are posting for an '
                .'audience in another country — pick their timezone and set the time you want it '
                .'to land there. Every timezone is available, each labelled with its current '
                .'offset, and your choice is remembered. Underneath the picker you will see '
                .'exactly when the post goes out, shown in your own timezone too when the two '
                .'differ.',
        ],

        [
            'slug'  => '2026-09-05-find-your-characters-faster',
            'date'  => '2026-09-05',
            'tag'   => 'improved',
            'title' => 'Your characters now have their own tab in the asset library',
            'body'  => 'Open the library from Visual Source and there is a Characters chip beside '
                .'All, Video and Image. It lists the reference photos of every character in your '
                .'workspace, so putting a saved face on a scene no longer means scrolling through '
                .'every image you have ever used. The library also loads more as you scroll, and '
                .'the search box now searches your whole library rather than only what is on '
                .'screen — so older uploads are reachable again if you have a lot of them.',
        ],

        [
            'slug'  => '2026-09-05-one-time-plans',
            'date'  => '2026-09-05',
            'tag'   => 'new',
            'title' => 'Buy credits once, with no subscription',
            'body'  => 'Alongside the monthly plans there are now one-time credit packs — pay '
                .'once, keep the credits, nothing recurring and nothing to cancel. Click Upgrade '
                .'in Settings to see both side by side, with what each pack is worth in finished '
                .'videos. If you already hold a one-time plan or an AppSumo deal, you will be '
                .'offered the larger packs only, and buying one adds its credits to your balance.',
        ],

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
