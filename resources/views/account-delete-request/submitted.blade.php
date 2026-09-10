@extends('layouts.site')

@section('title', 'Deletion requested')

@section('content')
    <h1>Account deletion requested</h1>

    <div class="card">
        <p>
            We have received a request to delete
            @if ($email)
                <strong>{{ $email }}</strong>.
            @else
                your account.
            @endif
        </p>
        <p class="muted">
            Our team will review it and remove the account along with its data. You may keep
            using the app until then, and you can close this page — no further action is needed.
        </p>
    </div>

    <p class="muted small" style="margin-top:1.5rem">
        Submitted the wrong address?
        <a href="{{ route('account-delete-request.create') }}">Send another request</a>.
    </p>
@endsection
