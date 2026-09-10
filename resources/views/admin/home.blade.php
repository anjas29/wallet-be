@extends('layouts.site')

@section('title', 'Admin')

@section('width', '1000px')

@section('content')
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:.15rem">Admin</h1>
            <p class="muted small" style="margin:0">
                Signed in as {{ auth()->user()->email }}
            </p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn-quiet" type="submit">Sign out</button>
        </form>
    </div>

    @include('admin.partials.nav', ['active' => 'home'])

    <div class="grid">
        <a class="card" href="{{ route('admin.account-delete-requests.index') }}">
            <h2 style="margin-top:0">Account deletions</h2>
            <p class="muted small" style="margin-bottom:0">
                {{ $pendingDeleteRequests }} pending request{{ $pendingDeleteRequests === 1 ? '' : 's' }}
            </p>
        </a>
        <a class="card" href="{{ route('admin.subscriptions.index') }}">
            <h2 style="margin-top:0">Subscriptions</h2>
            <p class="muted small" style="margin-bottom:0">
                {{ $activeSubscriptions }} active subscriber{{ $activeSubscriptions === 1 ? '' : 's' }}
            </p>
        </a>
        <a class="card" href="{{ route('admin.plans.index') }}">
            <h2 style="margin-top:0">Plans</h2>
            <p class="muted small" style="margin-bottom:0">
                {{ $activePlans }} active plan{{ $activePlans === 1 ? '' : 's' }}
            </p>
        </a>
    </div>
@endsection
