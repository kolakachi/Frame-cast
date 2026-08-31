<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — WyvStudio</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $canonical }}">

{{-- OpenGraph: what WhatsApp / Slack / iMessage / LinkedIn unfurl. --}}
<meta property="og:type" content="video.other">
<meta property="og:site_name" content="WyvStudio">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
@if ($poster)<meta property="og:image" content="{{ $poster }}">
<meta property="og:image:width" content="{{ $width }}">
<meta property="og:image:height" content="{{ $height }}">@endif
@if ($videoUrl)<meta property="og:video" content="{{ $videoUrl }}">
<meta property="og:video:secure_url" content="{{ $videoUrl }}">
<meta property="og:video:type" content="video/mp4">
<meta property="og:video:width" content="{{ $width }}">
<meta property="og:video:height" content="{{ $height }}">@endif

<meta name="twitter:card" content="{{ $poster ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@if ($poster)<meta name="twitter:image" content="{{ $poster }}">@endif

@if ($videoUrl)
{{-- VideoObject: what Google Video indexing reads. --}}
<script type="application/ld+json">
{!! json_encode(array_filter([
  '@context'     => 'https://schema.org',
  '@type'        => 'VideoObject',
  'name'         => $title,
  'description'  => $description,
  'thumbnailUrl' => $poster,
  'contentUrl'   => $videoUrl,
  'uploadDate'   => $uploadDate,
  'duration'     => $isoDuration,
]), JSON_UNESCAPED_SLASHES) !!}
</script>
@endif

<style>
  * { margin:0; box-sizing:border-box; }
  body { background:#0a0a0f; color:#ececf3; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px; gap:18px; }
  .player { position:relative; width:min(92vw, {{ $height > $width ? '380px' : '860px' }}); aspect-ratio: {{ $width }} / {{ $height }}; border-radius:16px; overflow:hidden; background:#17171f; box-shadow:0 24px 80px rgba(0,0,0,.55); cursor:pointer; }
  .player video { width:100%; height:100%; object-fit:cover; display:block; }
  .hint { position:absolute; left:50%; bottom:14px; transform:translateX(-50%); background:rgba(10,10,15,.72); backdrop-filter:blur(6px); color:#ececf3; font-size:12.5px; font-weight:600; padding:7px 14px; border-radius:999px; pointer-events:none; transition:opacity .25s; white-space:nowrap; }
  .hint.hidden { opacity:0; }
  h1 { font-size:17px; font-weight:700; text-align:center; max-width:640px; }
  .cta { display:inline-flex; align-items:center; gap:8px; background:#ff6b35; color:#fff; text-decoration:none; font-size:13.5px; font-weight:700; padding:11px 22px; border-radius:999px; }
  .cta:hover { background:#f97316; }
  .made { font-size:12px; color:#a1a1b5; }
  .missing { padding:60px 20px; text-align:center; color:#a1a1b5; font-size:14px; }
</style>
</head>
<body>
  <h1>{{ $title }}</h1>

  @if ($videoUrl)
  <div class="player" id="player">
    <video id="vid" src="{{ $videoUrl }}" @if($poster) poster="{{ $poster }}" @endif preload="metadata" playsinline loop muted></video>
    <div class="hint" id="hint">▶ Hover to preview · click for sound</div>
  </div>
  @else
  <div class="missing">This video is still rendering — check back shortly.</div>
  @endif

  <div class="made">Made with <strong>WyvStudio</strong> — AI short-video studio</div>
  <a class="cta" href="https://wyvstudio.com/?utm_source=share_page&utm_medium=video" rel="noopener">Make a video like this →</a>

@if ($videoUrl)
<script>
(function () {
  var p = document.getElementById('player'), v = document.getElementById('vid'), h = document.getElementById('hint');
  var touch = matchMedia('(hover: none)').matches;

  if (!touch) {
    // Desktop: hover = instant muted preview (feed behaviour); leaving pauses
    // unless sound was turned on — once they've committed, don't interrupt.
    p.addEventListener('mouseenter', function () { v.play().catch(function(){}); });
    p.addEventListener('mouseleave', function () { if (v.muted) { v.pause(); } });
    p.addEventListener('click', function () {
      if (v.muted) { v.muted = false; v.loop = false; v.currentTime = 0; v.controls = true; v.play().catch(function(){}); }
      h.classList.add('hidden');
    });
  } else {
    // Touch: no hover exists — first tap is play-with-sound, native controls.
    h.textContent = '▶ Tap to play';
    p.addEventListener('click', function () {
      v.muted = false; v.loop = false; v.controls = true; v.play().catch(function(){});
      h.classList.add('hidden');
    }, { once: true });
  }
})();
</script>
@endif
</body>
</html>
