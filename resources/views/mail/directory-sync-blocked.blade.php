<x-mail::message>
# Directory sync refused

At the single sign-on of **{{ $name }}** ({{ $email }}), the company directory asked to change their membership of the **{{ $team }}** team (group `{{ $group }}`).

The change was refused: {{ $reason }}

Give the Admin role to another active member of the team, or update the directory groups, then ask this person to sign in again.

<x-mail::button :url="$url">
Open the team
</x-mail::button>

You receive this email because you are a Super Admin of {{ config('app.name') }}.
</x-mail::message>
