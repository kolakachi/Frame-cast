{{-- An agency adding a team member. They make videos with the agency's credits, within the allowance it set. --}}
<p>Hello {{ $user->name ?: $user->email }},</p>

<p>{{ $agencyName }} added you to their team on WyvStudio, so you can make videos for them and the clients they've given you.</p>

<p><a href="{{ $magicLink }}">→ Open WyvStudio</a></p>

<p>That link signs you in — there's no password to pick. It's good for 7 days; after that, enter this email address on
the sign-in page and we'll send a fresh one.</p>

@if ($allowance !== null)
<p>You can use up to {{ number_format($allowance) }} of {{ $agencyName }}'s credits each month. Your dashboard shows what's left.</p>
@endif

<p>— WyvStudio, on behalf of {{ $agencyName }}</p>
