{{-- Onglets de la console d'administration (SCRUM-84, 85) --}}
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link @if (request()->routeIs('admin.users.*')) active @endif" href="{{ route('admin.users.index') }}"><i class="bi bi-person-gear me-1"></i>Users</a>
    </li>
    <li class="nav-item">
        <a class="nav-link @if (request()->routeIs('admin.teams.*')) active @endif" href="{{ route('admin.teams.index') }}"><i class="bi bi-people me-1"></i>Teams</a>
    </li>
    <li class="nav-item">
        <a class="nav-link @if (request()->routeIs('admin.audit')) active @endif" href="{{ route('admin.audit') }}"><i class="bi bi-journal-text me-1"></i>Platform audit</a>
    </li>
</ul>
