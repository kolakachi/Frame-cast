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

@if($isLaunchpad)
  <h2>How to sign in</h2>
  <ol>
    <li><a href="{{ $loginUrl }}">Open the WyvStudio sign-in page</a>.</li>
    <li>Enter <strong>{{ $user->email }}</strong>, the email linked to your purchase, and choose <strong>Send Magic Link</strong>.</li>
    <li>Check your inbox and spam folder, then open the newest WyvStudio sign-in email. The link works once and expires after 15 minutes.</li>
  </ol>
  <p>No password or new purchase is needed. If you already use WyvStudio, sign in to the account with this email to access your purchase.</p>
  <p><a href="{{ $launchGuideUrl }}">Read the thank-you and getting-started guide →</a></p>
  <p>If you cannot sign in or your purchase is missing, email <a href="mailto:support@wyvstudio.com">support@wyvstudio.com</a> with your checkout email and Launchpad receipt or transaction ID.</p>
@else
  <p><a href="{{ $accountUrl }}">Open WyvStudio and get started →</a></p>
@endif
<p>Reply to this email if you need help getting started.</p>
<p>— Amara<br>Co-founder, WyvStudio</p>
