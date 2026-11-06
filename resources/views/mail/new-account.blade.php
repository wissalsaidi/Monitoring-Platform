<x-mail::message>
# New account waiting for access

**{{ $name }}** ({{ $email }}) created an account on {{ $created }} and verified their email address.

They are not a member of any team yet, so they cannot see anything. If this person should have access, invite them to a team with the right role. Otherwise, no action is needed.

You receive this email because you are a Super Admin of {{ config('app.name') }}.
</x-mail::message>
