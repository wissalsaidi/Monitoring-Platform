@extends('layouts.guest')

@section('title', 'Invitation')

@section('content')
    <div class="text-center">
        <i class="bi bi-envelope-x text-warning" style="font-size: 2.5rem"></i>
        <h2 class="h5 mt-3">Invitation not accepted</h2>
        <p class="text-body-secondary small mb-4">{{ $message }}</p>
        <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">Go to the dashboard</a>
    </div>
@endsection
