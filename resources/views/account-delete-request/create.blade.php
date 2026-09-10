@extends('layouts.site')

@section('title', 'Request account deletion')

@section('content')
    <h1>Request account deletion</h1>
    <p class="muted">
        Enter the email address of your {{ config('app.name') }} account. We will remove the
        account and the data stored against it — your accounts, transactions, transfers,
        budgets, liabilities, categories and chat history.
    </p>

    @if ($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="card" method="POST" action="{{ route('account-delete-request.store') }}">
        @csrf

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" required autocomplete="email"
                   autocapitalize="off" spellcheck="false" value="{{ old('email') }}">
        </div>

        <div class="field">
            <label for="email_confirmation">Confirm email address</label>
            <input id="email_confirmation" name="email_confirmation" type="email" required
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   value="{{ old('email_confirmation') }}">
        </div>

        <div class="check">
            <input id="confirm" name="confirm" type="checkbox" value="1" required
                   @checked(old('confirm'))>
            <label for="confirm">
                I understand that my account and all of its data will be deleted, and that
                this cannot be undone from the app.
            </label>
        </div>

        <button class="btn-danger" type="submit">Request deletion</button>
    </form>

    <p class="muted small" style="margin-top:1.5rem">
        Requests are reviewed by our team before anything is removed. See our
        <a href="{{ route('privacy-policy') }}">Privacy Policy</a> for how we handle your data.
    </p>
@endsection
