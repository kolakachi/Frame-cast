<p>Hi {{ $customerName ?: 'there' }},</p>
<p>Your top-up is complete. We’ve added <strong>{{ number_format($creditsAdded) }} credits</strong> to {{ $workspaceName ?: 'your WyvStudio workspace' }}.</p>
<p>Your available balance immediately after this top-up was <strong>{{ number_format($balanceAfter) }} credits</strong>. Any generation since then may have changed it.</p>
<p>This is a one-time credit purchase. Your plan and subscription haven’t changed.</p>
<p><a href="{{ $accountUrl }}">View your credits and continue creating →</a></p>
<p>If something doesn’t look right, reply to this email and we’ll help.</p>
<p>— The WyvStudio team</p>
