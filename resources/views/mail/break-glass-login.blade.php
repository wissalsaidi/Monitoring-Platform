<x-mail::message>
# Emergency access used

**{{ $name }}** ({{ $email }}) logged in with the emergency access account (password, without single sign-on) on {{ $at }}, from IP address {{ $ip }}.

This account is only meant for when single sign-on is unavailable. If you did not expect this login, check the audit log and contact this person right away.

You receive this email because you are a Super Admin of {{ config('app.name') }}.
</x-mail::message>
