{{-- An agency inviting its client. The agency's name carries this email, not
     ours: the client agreed to work with them, not to sign up for a tool. --}}
<p>Hello {{ $user->name ?: $user->email }},</p>

<p>{{ $agencyName }} has set up a space for <strong>{{ $clientName }}</strong>
where you can watch the videos they're making for you, and approve them.</p>

<p><a href="{{ $magicLink }}">→ Open your videos</a></p>

<p>That link signs you in — there's no password to pick. It's good for 7 days;
after that, enter this email address on the sign-in page and we'll send a fresh
one.</p>

<p>You'll be able to see everything in your space and approve or send back
anything waiting on you. Making changes to a video stays with
{{ $agencyName }} — tell them what you'd like different and they'll handle it.</p>

<p>— WyvStudio, on behalf of {{ $agencyName }}</p>
