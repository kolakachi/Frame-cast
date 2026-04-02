<p>Hello {{ $user->name ?: $user->email }},</p>
<p>Use the link below to sign in to Framecast. It expires in 15 minutes.</p>
<p><a href="{{ $magicLink }}">{{ $magicLink }}</a></p>
