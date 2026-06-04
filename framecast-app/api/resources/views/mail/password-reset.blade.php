<p>Hey {{ $user->name ?: 'there' }},</p>

<p>You (or someone using your email) asked to reset the password on your WyvStudio account. Click the link below to set a new password. The link expires in 60 minutes and can only be used once.</p>

<p><a href="{{ $resetLink }}">{{ $resetLink }}</a></p>

<p>If you didn't ask to reset your password, ignore this email — your account stays as it is. No one can change your password without the link above.</p>

<p>Questions or trouble? Reply to this email.</p>

<p>— WyvStudio</p>
