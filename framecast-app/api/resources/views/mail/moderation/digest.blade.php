<p>The DetectAbusePatternsJob created <strong>{{ count($alerts) }}</strong> new pattern alert{{ count($alerts) === 1 ? '' : 's' }} in the last 24-hour scan.</p>

<p>Open the admin Trust &amp; Safety tab to review:<br>
<a href="https://app.wyvstudio.com/admin?tab=moderation">https://app.wyvstudio.com/admin?tab=moderation</a></p>

<h3>Summary</h3>
<ul>
@foreach($alerts as $a)
  <li>
    <strong>{{ ucfirst(str_replace('_', ' ', $a->operation ?? 'pattern')) }}</strong>
    @if($a->workspace_id) · workspace #{{ $a->workspace_id }} @endif
    @if($a->severity) · severity: <em>{{ $a->severity }}</em> @endif
    <br>
    <span style="color:#555;">{{ \Illuminate\Support\Str::limit($a->reason, 220) }}</span>
  </li>
@endforeach
</ul>

<p style="font-size:12px;color:#888;margin-top:24px;">This digest is sent only on days with new alerts. Quiet days are silent. Adjust thresholds in <code>config/moderation.php</code>.</p>
