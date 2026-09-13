<p>Hey {{ $user->name ?: 'there' }},</p>

@if ($isUpgrade)
    <p>You started an upgrade to <strong>{{ $planName }}</strong> but it didn't go through. Nothing changed — your current plan and credits are exactly as they were.</p>

    <p>If you still want the bigger plan, you can pick it up here:</p>
@else
    <p>You started checkout for <strong>{{ $planName }}</strong> but it didn't go through. Your account is still here and everything you've made is untouched.</p>

    <p>If you still want it, you can pick up where you left off:</p>
@endif

<p><a href="https://app.wyvstudio.com/plans">→ {{ $isUpgrade ? 'Finish the upgrade' : 'Finish choosing your plan' }}</a></p>

<p>But if something got in the way — the price, a payment step that failed, or you tried the product and it wasn't what you expected — I'd genuinely rather hear it. Just hit reply. One sentence is plenty, and it's more useful to us right now than the sale.</p>

<p>And if you've simply changed your mind, no problem at all. You won't get another email about this.</p>

<p>— The WyvStudio team</p>
