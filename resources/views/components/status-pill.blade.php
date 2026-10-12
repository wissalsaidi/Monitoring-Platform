@props(['status'])

<span {{ $attributes->class(['status-pill', 'status-'.$status->value]) }}><i class="bi {{ $status->icon() }}" aria-hidden="true"></i>{{ $status->label() }}</span>
