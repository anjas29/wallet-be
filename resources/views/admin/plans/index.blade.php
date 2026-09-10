@extends('layouts.site')

@section('title', 'Subscription plans')

@section('width', '1000px')

@section('content')
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:.15rem">Subscription plans</h1>
            <p class="muted small" style="margin:0">
                Signed in as {{ auth()->user()->email }}
            </p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn-quiet" type="submit">Sign out</button>
        </form>
    </div>

    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif

    @include('admin.partials.nav', ['active' => 'plans'])

    <div class="tabs">
        <a href="{{ route('admin.plans.create') }}">+ New plan</a>
    </div>

    <div class="table-wrap card" style="padding:0">
        <table>
            <thead>
            <tr>
                <th>Plan</th>
                <th>Price</th>
                <th>Trial</th>
                <th>Status</th>
                <th>Order</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($plans as $plan)
                <tr>
                    <td>
                        {{ $plan->name }}
                        @if ($plan->is_anchor)
                            <span class="tag tag-active">anchor</span>
                        @endif
                        <div class="muted small"><code>{{ $plan->slug }}</code></div>
                    </td>
                    <td>
                        {{ number_format($plan->price_amount / 100, 2) }} /
                        {{ $plan->interval_count > 1 ? "{$plan->interval_count} {$plan->interval}s" : $plan->interval }}
                        <div class="muted small"><code>{{ $plan->stripe_price_id }}</code></div>
                    </td>
                    <td>{{ $plan->trial_days ? "{$plan->trial_days} days" : '—' }}</td>
                    <td><span class="tag tag-{{ $plan->is_active ? 'active' : 'ignored' }}">{{ $plan->is_active ? 'active' : 'archived' }}</span></td>
                    <td>{{ $plan->sort_order }}</td>
                    <td>
                        <div class="actions">
                            <a class="btn-quiet" href="{{ route('admin.plans.edit', $plan) }}">Edit</a>
                            @if ($plan->is_active)
                                <form class="inline-form" method="POST" action="{{ route('admin.plans.archive', $plan) }}"
                                      onsubmit="return confirm('Archive {{ $plan->name }}? New subscribers will no longer be able to pick it; existing subscribers are unaffected.');">
                                    @csrf
                                    <button class="btn-danger" type="submit">Archive</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted" style="padding:1.5rem .7rem">
                        No plans yet — <a href="{{ route('admin.plans.create') }}">create one</a>.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
