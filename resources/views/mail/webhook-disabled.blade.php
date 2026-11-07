<x-mail::message>
# Webhook disabled

The **{{ $name }}** webhook ({{ $type }}) of the **{{ $team }}** team failed {{ $failures }} times in a row, so it was disabled automatically. Alerts are no longer sent to it.

@if ($lastError)
Last error: {{ $lastError }}
@endif

Check the address on the receiving side (Slack app, Teams workflow or internal tool). Then edit the webhook if needed, click **Send test**, and enable it again.

<x-mail::button :url="$url">
Open the webhooks
</x-mail::button>

[See its deliveries]({{ $history }})

You receive this email because you are an Admin of the {{ $team }} team on {{ config('app.name') }}.
</x-mail::message>
