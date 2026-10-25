<x-mail::message>
# {{ $alert->severity->label() }} alert on {{ $target->name }}

{{ $alert->message }}

<x-mail::table>
| | |
|:--|:--|
| **Severity** | {{ $alert->severity->label() }} |
| **Target** | {{ $target->name }} ({{ $target->type->label() }}) |
| **Team** | {{ $team }} |
| **Metric** | {{ $alert->metricLabel() }} |
| **Measured value** | {{ $alert->valueLabel() ?? '—' }} |
| **Threshold** | {{ $alert->thresholdLabel() ?? '—' }} |
| **Started** | {{ $started }} |
</x-mail::table>

<x-mail::button :url="$url" color="error">
View the alert
</x-mail::button>

You receive this email because you are a member of the **{{ $team }}** team: its notification rules send {{ $alert->severity->label() }} alerts by email.
</x-mail::message>
