@extends('layouts.site')

@section('title', 'Subscriptions')

@section('width', '1000px')

@section('content')
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:.15rem">Subscriptions</h1>
            <p class="muted small" style="margin:0">
                Signed in as {{ auth()->user()->email }}
            </p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn-quiet" type="submit">Sign out</button>
        </form>
    </div>

    @include('admin.partials.nav', ['active' => 'subscriptions'])

    <div class="tabs">
        @foreach (['active' => 'Active', 'trialing' => 'Trialing', 'past_due' => 'Past due', 'canceled' => 'Canceled'] as $value => $label)
            <span>{{ $label }} ({{ $counts[$value] ?? 0 }})</span>
        @endforeach
    </div>

    <div class="table-wrap card" style="padding:0">
        <table>
            <thead>
            <tr>
                <th>User</th>
                <th>Status</th>
                <th>Plan (Stripe price)</th>
                <th>Trial ends</th>
                <th>Ends at</th>
                <th>Started</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($subscriptions as $subscription)
                <tr>
                    <td>
                        @if ($subscription->user)
                            {{ $subscription->user->name }}
                            <div class="muted small">{{ $subscription->user->email }}</div>
                        @else
                            <span class="muted">Unknown user</span>
                        @endif
                    </td>
                    <td><span class="tag tag-{{ $subscription->stripe_status }}">{{ $subscription->stripe_status }}</span></td>
                    <td><code>{{ $subscription->stripe_price }}</code></td>
                    <td>{{ $subscription->trial_ends_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ $subscription->ends_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ $subscription->created_at->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted" style="padding:1.5rem .7rem">No subscriptions yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($subscriptions->hasPages())
        <div class="tabs" style="margin-top:1.25rem">
            @if ($subscriptions->onFirstPage())
                <span class="muted small">Previous</span>
            @else
                <a href="{{ $subscriptions->previousPageUrl() }}" rel="prev">Previous</a>
            @endif
            <span class="muted small" style="align-self:center">
                Page {{ $subscriptions->currentPage() }} of {{ $subscriptions->lastPage() }}
            </span>
            @if ($subscriptions->hasMorePages())
                <a href="{{ $subscriptions->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="muted small">Next</span>
            @endif
        </div>
    @endif
@endsection
