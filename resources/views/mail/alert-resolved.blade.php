<x-mail::message>
# Resolved: {{ $target->name }}

The {{ $alert->severity->label() }} alert is over: {{ $alert->message }}

<x-mail::table>
| | |
|:--|:--|
| **Target** | {{ $target->name }} ({{ $target->type->label() }}) |
| **Team** | {{ $team }} |
| **Severity** | {{ $alert->severity->label() }} |
| **Metric** | {{ $alert->metricLabel() }} |
| **Started** | {{ $started }} |
| **Resolved** | {{ $resolved }} |
| **Duration** | {{ $duration }} |
| **Maximum** | {{ $maximum ?? '—' }} |
| **Threshold** | {{ $alert->thresholdLabel() ?? '—' }} |
</x-mail::table>

<x-mail::button :url="$url" color="success">
View the history
</x-mail::button>

You were notified when this alert opened, so you are told that it is resolved.
</x-mail::message>
