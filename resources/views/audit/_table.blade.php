{{-- Tableau du journal d'audit (SCRUM-60), partagé avec l'audit de la plateforme (SCRUM-85, colonne Team) --}}
<table class="table table-hover align-middle mb-0">
    <thead>
        <tr>
            <th class="ps-3 text-nowrap">Date</th>
            @if ($showTeam ?? false)<th>Team</th>@endif
            <th>Author</th>
            <th>Action</th>
            <th>Object</th>
            <th class="pe-3">Details</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($logs as $log)
            <tr>
                <td class="ps-3 small text-nowrap">{{ $log->created_at->toDisplay()->format('d/m/Y H:i:s') }}</td>
                @if ($showTeam ?? false)
                    <td class="small">
                        @if ($log->team)
                            <a href="{{ route('admin.teams.show', $log->team) }}">{{ $log->team->name }}</a>
                        @elseif ($log->isFromDeletedTeam() || $log->action === 'team.delete')
                            <span class="badge text-bg-secondary fw-normal">Deleted team</span>
                        @else
                            <span class="badge text-bg-primary fw-normal">Platform</span>
                        @endif
                    </td>
                @endif
                <td class="small">
                    {{ $log->user?->name ?? ($log->user_id === null && ($log->details['via'] ?? null) === 'console' ? 'Console' : 'Deleted user') }}
                    @if ($log->ip_address)<div class="text-body-secondary">{{ $log->ip_address }}</div>@endif
                </td>
                <td><span class="badge text-bg-secondary fw-normal">{{ $log->actionLabel() }}</span></td>
                <td class="small">
                    @if ($log->subject instanceof \App\Models\MonitoringTarget)
                        <a href="{{ route('targets.show', $log->subject) }}">{{ $log->subjectLabel() }}</a>
                    @elseif (($showTeam ?? false) && $log->subject instanceof \App\Models\User)
                        <a href="{{ route('admin.users.show', $log->subject) }}">{{ $log->subjectLabel() }}</a>
                    @else
                        {{ $log->subjectLabel() }}
                    @endif
                </td>
                <td class="pe-3 small">
                    @foreach ($log->detailLines() as $line)
                        <div>
                            <span class="text-body-secondary">{{ $line['label'] }}:</span>
                            @if (array_key_exists('new', $line))
                                <span class="text-decoration-line-through text-body-secondary">{{ $line['old'] }}</span>
                                <i class="bi bi-arrow-right mx-1"></i>{{ $line['new'] }}
                            @else
                                {{ $line['value'] }}
                            @endif
                        </div>
                    @endforeach
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
