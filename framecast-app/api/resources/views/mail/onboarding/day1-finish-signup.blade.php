{{-- Day 1 for an account that never got through checkout. The activation
     email that used to occupy this slot asks how their first video went, of
     someone who was never let in to make one. --}}
<p>Hey {{ $user->name ?: 'there' }},</p>

@if($planLabel)
  <p>You started signing up yesterday but never finished paying, so you haven't
  actually seen WyvStudio do anything yet. Worth two minutes of your time to
  fix that.</p>
@else
  <p>You made an account yesterday but haven't picked a plan, so you haven't
  actually seen WyvStudio do anything yet. Worth two minutes of your time to fix
  that.</p>
@endif

<p>What it does, plainly: you give it a product page URL, a topic, or a rough
script. It writes the script, breaks it into scenes, generates the voiceover and
the visuals, adds captions and music, and exports 9:16, 1:1, 4:5 and 16:9 in one
pass. No timeline, no re-editing per platform. The first one usually takes about
90 seconds.</p>

@if($planLabel)
  <p><a href="{{ $ctaUrl }}">→ Finish checkout for {{ $planLabel }}</a></p>
  <p style="font-size:13px;color:#666">That link takes you straight to payment for the plan
  you picked. Changed your mind? <a href="{{ $plansUrl }}">See the other plans</a>.</p>
@else
  <p><a href="{{ $ctaUrl }}">→ Pick a plan and make your first video</a></p>
@endif

<p>If you were expecting a free trial — we don't run one. Credits buy real
generation time from real models, and a trial that dies halfway through your
first project is worse than no trial. The smallest plan is deliberately small
enough to find out whether this works for you.</p>

<p>If it's not what you were looking for, just tell me what you were after — I
read every reply and it genuinely helps.</p>

<p>— Amara<br>Co-founder, WyvStudio</p>
