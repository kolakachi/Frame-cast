<p>Hi {{ $user->name ?: 'there' }},</p>

<p>Your <strong>{{ $planName ?: 'WyvStudio' }}</strong> plan is active. Welcome to WyvStudio!</p>

@if($credits > 0)
  <p>You have <strong>{{ number_format($credits) }} credits{{ $recurring ? ', renewed every month' : '' }}</strong>{{ $recurring ? ' in your monthly allowance' : ' in your one-time credit balance' }}. The app shows your current balance and generation costs.</p>
@endif

@if($isTestPass)
  <p>Your Test Pass includes up to two UGC takes, each up to 15 seconds, subject to your credit balance. The 600-credit purchase covers one full-quality 15-second ad or two drafts. It is a one-time purchase with no monthly renewal.</p>
  <p>Start with your product, audience, and message. Review the script and credit estimate before generating.</p>
@else
  <p>Start with a script, a product link, or an idea. Build your first video, review the scenes, then export when you’re happy with it.</p>
  @if(!$recurring)
    <p>Your credits are a one-time balance, with no monthly refill. You can top up when you need more.</p>
  @endif
@endif

<p><a href="{{ $accountUrl }}">Open WyvStudio and get started →</a></p>
<p>Reply to this email if you need help getting started.</p>
<p>— Amara<br>Co-founder, WyvStudio</p>
