<x-mail::message>
# You are invited

{{ $inviter }} invited you to join the **{{ $team }}** team as **{{ $role }}**.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

If you do not have an account yet, create one with this email address, then open the link again.

This link expires on {{ $expires }}. If you were not expecting this invitation, you can ignore this email.
</x-mail::message>
