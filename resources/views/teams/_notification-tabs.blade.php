    {{-- Onglets de la page Notifications : règles (SCRUM-55) et historique (SCRUM-56) --}}
    <ul class="nav nav-underline mb-3">
        <li class="nav-item">
            <a href="{{ route('teams.notifications.index', $team) }}" class="nav-link @if (request()->routeIs('teams.notifications.index')) active @endif">
                <i class="bi bi-sliders me-1"></i>Rules
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('teams.notifications.history', $team) }}" class="nav-link @if (request()->routeIs('teams.notifications.history')) active @endif">
                <i class="bi bi-clock-history me-1"></i>History
            </a>
        </li>
    </ul>
