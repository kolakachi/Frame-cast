<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 540px; margin: 0 auto; padding: 24px; color: #111;">
  <h2 style="font-size: 18px; margin: 0 0 12px;">A video is waiting for your review</h2>

  <p style="font-size: 14px; line-height: 1.55; color: #444;">
    Hi{{ $approval->reviewer_name ? ' ' . $approval->reviewer_name : '' }},
  </p>

  <p style="font-size: 14px; line-height: 1.55; color: #444;">
    <strong>{{ $requester->name ?: $requester->email }}</strong> has shared a video with you for approval:
  </p>

  <div style="background: #f5f5f7; border-radius: 8px; padding: 14px 16px; margin: 16px 0;">
    <div style="font-size: 15px; font-weight: 600;">{{ $project->title ?: 'Untitled project' }}</div>
    @if($approval->comment)
      <div style="font-size: 13px; color: #555; margin-top: 8px;">
        {{ $approval->comment }}
      </div>
    @endif
  </div>

  <p style="font-size: 14px;">
    <a href="{{ $publicUrl }}" style="background: #ff6b35; color: #fff; padding: 11px 22px; border-radius: 7px; text-decoration: none; font-weight: 600; display: inline-block;">
      Review video →
    </a>
  </p>

  <p style="font-size: 12px; color: #888; margin-top: 24px;">
    This link expires {{ $approval->expires_at?->diffForHumans() }}.
    You don't need an account — just open the link to view the video and approve or reject.
  </p>

  <p style="font-size: 11px; color: #aaa; margin-top: 32px;">
    Powered by WyvStudio
  </p>
</div>
