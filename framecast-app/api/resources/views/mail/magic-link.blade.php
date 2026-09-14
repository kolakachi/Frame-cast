@if($firstRun)
{{-- ── DRAFT COPY — edit freely ───────────────────────────────────────── --}}
<p>Hey {{ $user->name ?: 'there' }},</p>

<p>Welcome to WyvStudio. I'm Amara, the Co-founder.</p>

<p>Here's your sign-in link. It expires in 15 minutes.</p>

<p><a href="{{ $magicLink }}">→ Sign in to WyvStudio</a></p>

@if($planLabel)
  <p>You picked <strong>{{ $planLabel }}</strong>. Opening that link takes you
  straight to checkout for it, and your credits are there the moment it clears.</p>
@elseif($requiresPlan)
  <p>Once you're in, pick a plan and your credits land immediately. There's no
  free tier — everything you make runs on real credits rather than a trial that
  runs out halfway through a project.</p>
@endif

<p>Then the first thing worth doing: paste a product page URL, a topic, or a
rough script into a new project and hit Generate. WyvStudio writes the script,
splits it into scenes, generates the voice and visuals, and exports 9:16, 1:1,
4:5 and 16:9 in one go. Takes about 90 seconds to see something finished.</p>

<p>Reply to this email if anything breaks or feels weird. It lands in my inbox
directly.</p>

<p>— Amara<br>Co-founder, WyvStudio</p>
{{-- ── END DRAFT COPY ─────────────────────────────────────────────────── --}}
@else
<p>Hello {{ $user->name ?: $user->email }},</p>
<p>Use the link below to sign in to WyvStudio. It expires in 15 minutes.</p>
<p><a href="{{ $magicLink }}">{{ $magicLink }}</a></p>
@endif
