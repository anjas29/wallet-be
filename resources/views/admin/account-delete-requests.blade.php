@extends('layouts.site')

@section('title', 'Account delete requests')

@section('width', '1000px')

@section('content')
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:.15rem">Account delete requests</h1>
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

    @include('admin.partials.nav', ['active' => 'account-delete-requests'])

    <div class="tabs">
        @foreach (['pending' => 'Pending', 'ignored' => 'Ignored', 'deleted' => 'Deleted', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.account-delete-requests.index', ['status' => $value]) }}"
               @if ($status === $value) aria-current="page" @endif>
                {{ $label }}@if ($value !== 'all') ({{ $counts[$value] ?? 0 }}) @endif
            </a>
        @endforeach
    </div>

    <div class="table-wrap card" style="padding:0">
        <table>
            <thead>
            <tr>
                <th>Email</th>
                <th>Account</th>
                <th>Requested</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($requests as $request)
                <tr>
                    <td>{{ $request->email }}</td>
                    <td>
                        @if ($request->user)
                            {{ $request->user->name }}
                            <div class="muted small"><code>{{ $request->user->id }}</code></div>
                        @else
                            <span class="muted">No matching account</span>
                        @endif
                    </td>
                    <td>
                        {{ $request->created_at->format('d M Y H:i') }}
                        @if ($request->ip_address)
                            <div class="muted small">{{ $request->ip_address }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="tag tag-{{ $request->status }}">{{ $request->status }}</span>
                        @if ($request->processed_at)
                            <div class="muted small">
                                {{ $request->processed_at->format('d M Y H:i') }}
                                @if ($request->processedBy)
                                    by {{ $request->processedBy->email }}
                                @endif
                            </div>
                        @endif
                    </td>
                    <td>
                        @if ($request->isPending())
                            <div class="actions">
                                <form class="inline-form" method="POST"
                                      action="{{ route('admin.account-delete-requests.ignore', $request) }}">
                                    @csrf
                                    <button class="btn-quiet" type="submit">Ignore</button>
                                </form>
                                <form class="inline-form" method="POST"
                                      action="{{ route('admin.account-delete-requests.destroy', $request) }}"
                                      onsubmit="return confirm('Delete {{ $request->email }} and all of its data?');">
                                    @csrf
                                    <button class="btn-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        @else
                            <span class="muted small">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="muted" style="padding:1.5rem .7rem">
                        No {{ $status === 'all' ? '' : $status }} requests.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Hand-rolled prev/next: the framework's paginator views are Tailwind-classed, and
         this layout ships its own CSS rather than pulling Tailwind in. --}}
    @if ($requests->hasPages())
        <div class="tabs" style="margin-top:1.25rem">
            @if ($requests->onFirstPage())
                <span class="muted small">Previous</span>
            @else
                <a href="{{ $requests->previousPageUrl() }}" rel="prev">Previous</a>
            @endif
            <span class="muted small" style="align-self:center">
                Page {{ $requests->currentPage() }} of {{ $requests->lastPage() }}
            </span>
            @if ($requests->hasMorePages())
                <a href="{{ $requests->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="muted small">Next</span>
            @endif
        </div>
    @endif

    <div class="note" style="margin-top:1.5rem">
        <strong>Delete</strong> soft-deletes the account and everything it owns — accounts,
        transactions, transfers, budgets, liabilities and payments, recurring templates,
        categories, attachments and AI chats — and revokes every session and token.
        <strong>Ignore</strong> closes the request without touching the account.
    </div>
@endsection
