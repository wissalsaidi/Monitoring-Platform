@props(['status'])

<span {{ $attributes->class(['status-pill', 'status-'.$status->value]) }}>{{ $status->label() }}</span>
