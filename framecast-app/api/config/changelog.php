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
