{{-- A free sign-up who has not started their free first ad, about two hours after signing up. --}}
<p>Hey {{ $user->name ?: 'there' }},</p>

<p>You signed up to make your first ad free, but you haven't started it yet. It takes about two minutes:</p>

<ol>
  <li>Pick a template marked "Free first ad".</li>
  <li>Add your product: its name, a line about it, and a photo or two.</li>
  <li>WyvStudio puts them into the template and makes your ad, with a new voiceover.</li>
</ol>

<p><a href="{{ $ctaUrl }}">→ Make my free ad</a></p>

<p>Your free ad carries a small WyvStudio mark. If you want it without the mark, or with new shots, voices and
presenters made for your product, the <a href="{{ $passUrl }}">Test Pass</a> is $9, paid once.</p>

<p>If something got in the way, reply and tell me. I read every reply.</p>

<p>— Amara<br>Co-founder, WyvStudio</p>
