<p>Thank you for your purchase!</p>

<p>Your payment for <strong>{{ $productName }}</strong> is confirmed. Your WyvStudio access is linked to <strong>{{ $purchaseEmail }}</strong>.</p>

<h2>Sign in to WyvStudio</h2>
<ol>
  <li><a href="{{ $loginUrl }}">Open WyvStudio</a>.</li>
  <li>Enter <strong>{{ $purchaseEmail }}</strong> and select <strong>Send Magic Link</strong>.</li>
  <li>Open the newest WyvStudio sign-in email. Check your spam folder if it does not appear. That sign-in link works once and expires after 15 minutes.</li>
</ol>

<p>No password or new purchase is needed. You can use the sign-in page above whenever you are ready, even if you closed checkout before reaching the thank-you page.</p>
<p>If you already have a WyvStudio account with this email, your purchase has been added to it. Check your plan and credit balance after signing in.</p>
<p><a href="{{ $guideUrl }}">Open your thank-you and getting-started guide →</a></p>

<p>Launchpad transaction: <strong>{{ $receipt }}</strong></p>
<p>Need help accessing your purchase? Email <a href="mailto:support@wyvstudio.com">support@wyvstudio.com</a> with your checkout email and this transaction ID.</p>
<p>— The WyvStudio team</p>
