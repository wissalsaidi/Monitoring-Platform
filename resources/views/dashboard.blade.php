@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="h3 mb-4">Dashboard</h1>

    {{-- KPIs du cahier des charges (§20) : valeurs factices tant que les targets n'existent pas --}}
    <div class="row g-3">
        @foreach ([
            ['label' => 'Targets',       'value' => 0, 'icon' => 'bi-hdd-stack',             'color' => 'primary'],
            ['label' => 'UP',            'value' => 0, 'icon' => 'bi-check-circle-fill',     'color' => 'success'],
            ['label' => 'Warning',       'value' => 0, 'icon' => 'bi-exclamation-triangle-fill', 'color' => 'warning'],
            ['label' => 'Critical',      'value' => 0, 'icon' => 'bi-x-octagon-fill',        'color' => 'danger'],
            ['label' => 'Active alerts', 'value' => 0, 'icon' => 'bi-bell-fill',             'color' => 'secondary'],
        ] as $kpi)
            <div class="col-6 col-lg">
                <div class="card kpi-card h-100 shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="text-body-secondary small">{{ $kpi['label'] }}</span>
                            <i class="bi {{ $kpi['icon'] }} text-{{ $kpi['color'] }}"></i>
                        </div>
                        <div class="kpi-value mt-2">{{ $kpi['value'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
