<x-mail::message>
# You are invited

{{ $inviter }} invited you to join the **{{ $team }}** team as **{{ $role }}**.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

If you do not have an account yet, the link lets you create one with this email address. If you already have one, log in and you will join the team.

This link expires on {{ $expires }}. If you were not expecting this invitation, you can ignore this email.
</x-mail::message>
