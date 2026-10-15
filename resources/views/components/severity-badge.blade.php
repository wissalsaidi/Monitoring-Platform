@props(['severity'])

<span {{ $attributes->class(['status-pill', 'severity-'.$severity->value]) }}><i class="bi {{ $severity->icon() }}" aria-hidden="true"></i>{{ $severity->label() }}</span>
