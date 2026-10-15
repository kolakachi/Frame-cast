# WyvStudio social-native kit (wyv-motion.js + the registry)

For ads that should look like they live in the feed: a reply to a comment, a stitch, a chat, a note, a post. Load
wyv-motion.js after GSAP. Never draw a platform's logo or claim a real account; handles, names and numbers come from
the approved copy or are clearly generic (@yourbrand, "a customer").

## Moves
- `WM.replySticker(tl, '#reply', at, {rotate: -3})`: the "Reply to @name's comment" card pops on with a tilt and settles. Build it:
  `<div id="reply" class="reply"><div class="av"></div><div><b>Reply to @maya's comment</b><p>does it actually work on curly hair?</p></div></div>`
  White card, 22 px radius, avatar 64 px circle, 34-38 px bold label, 40-46 px comment; top third of the frame, 64 px from the sides. It opens the video and stays 2-4 s. With a stitch, put it below the corner thumbnail (top about 660 px), never over it.
- `WM.stitch(tl, '#quote', '#reply-scene', at, {corner: 'top-left', scale: .3})`: the clip being answered plays full frame, then shrinks into a rounded corner thumbnail while the reply fills the frame. Both are full-frame layers; `#reply-scene` sits under `#quote`. Put "Stitch" or the quoted line as a small label on the quote before the cut.
- `WM.heartBurst(tl, '#fx', at, {count: 10, from: {x: 930, y: 1190}})`: hearts float up from the like button and fade. `#fx` is an empty full-frame layer above the content. Once a video, on the payoff or the social-proof beat.

## From the registry (catalog the exact name first; kit/registry.md for the wiring)
- Comments typing on over footage: `yt-comment-card`. A conversation: `message-thread-reveal` (a full thread) or `chat-thread` / `chat-message` + `typing-indicator` (built up message by message).
- A notes-app confession or list: `notes-reveal`. Notifications piling up (the problem beat): `notification-stack`, `notification-pileup`, `native-notification-pop`.
- A post card with votes and comments: `reddit-post`. A follow prompt for the close: `tiktok-follow`, `instagram-follow`.
- Word-by-word captions with a highlight: `caption-highlight`.

## POV caption (the text, not a move)
"POV: you finally found a moisturiser that doesn't pill" in the top third: white box, black text, 8 px radius, 44-52 px semi-bold, max 80% of the frame wide, on from frame 1 and held while the shot plays. Animate it in with `WM.pop` or not at all; never over the face.

## Rules
- Feed-native means phone-shot energy: real footage or the user's own clips under these layers, not a designed slide.
- Keep platform chrome light: a reply card, a caption, a heart burst. Never a full fake app screen with a real platform's logo.
- Safe zones: nothing important in the right 140 px (the action rail) or the bottom 380 px (caption and buttons) of a 1080×1920 frame, except the heart burst, which comes from the rail.
