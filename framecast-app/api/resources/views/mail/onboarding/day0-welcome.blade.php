<p>Hey {{ $user->name ?: 'there' }},</p>

<p>Thanks for picking up {{ $planName ? "the $planName plan" : 'WyvStudio' }}. I'm Amara, the Co-founder.</p>

<p>Quick orientation so you're not staring at a blank canvas:</p>

<ol>
  <li><strong>Start from anything.</strong> A script, a product page URL, a podcast clip, or a one-line prompt. WyvStudio writes the script, breaks it into scenes, generates voice + visuals, and exports for every platform — YouTube, Reels, TikTok, LinkedIn, all aspect ratios in one go.</li>
  <li><strong>One brief, every format.</strong> The same project outputs 9:16, 1:1, 4:5, and 16:9 with captions, transitions, and music auto-matched to each format. No re-editing per platform.</li>
  @if($credits > 0)
    <li><strong>You have {{ number_format($credits) }} credits{{ $recurring ? ', renewed every month' : '' }}.</strong> Generating uses credits as you go, and the editor shows the cost before you spend anything.</li>
  @endif
</ol>

<p>The fastest first win is to paste a product URL or topic into a new project, hit "Generate," and watch the scenes appear. Takes about 90 seconds.</p>

<p><a href="https://app.wyvstudio.com">→ Open WyvStudio and start your first project</a></p>

<p>Reply to this email if anything breaks or feels weird. It lands in my inbox directly.</p>

<p>— Amara<br>Co-founder, WyvStudio</p>
