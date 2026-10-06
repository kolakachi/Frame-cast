@if ($kind === 'digest')
<p>Vendor failures in the last 24 hours.</p>
<pre style="font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;background:#f6f6f7;padding:12px;border-radius:6px">{{ $text }}</pre>
@elseif ($kind === 'recovered')
<p><b>{{ $vendor }}</b> is working again. New work that needs it is no longer held.</p>
@else
<p><b>{{ $vendor }}</b> refused a call because of our account: {{ $kind === 'vendor_credit' ? 'it is out of credit or its billing is off' : 'our key or account settings were rejected' }}.</p>
<p>Users see "temporarily unavailable on our side" and are not charged. New work that needs {{ $vendor }} is held for a few minutes at a time until a call works again.</p>
<pre style="font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;background:#f6f6f7;padding:12px;border-radius:6px">{{ $text }}</pre>
@if (! empty($extra['runs']))<p>Runs hit in the last hour: {{ implode(', ', array_map(fn ($r) => substr($r, 0, 8), $extra['runs'])) }}</p>@endif
@if (! empty($extra['fix']))<p>Fix it here: <a href="{{ $extra['fix'] }}">{{ $extra['fix'] }}</a></p>@endif
<p>You will get at most one of these an hour per problem, and a short note when it recovers.</p>
@endif
