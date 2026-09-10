@extends('layouts.site')

@section('title', 'Admin sign in')

@section('width', '380px')

@section('content')
    <h1>{{ config('app.name') }}</h1>
    <p class="muted">Admin sign in</p>

    @if ($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="card" method="POST" action="{{ route('login.store') }}">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autofocus
                   autocomplete="username" autocapitalize="off" spellcheck="false"
                   value="{{ old('email') }}">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <div class="check">
            <input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember'))>
            <label for="remember">Remember me</label>
        </div>

        <button class="btn-primary" type="submit">Sign in</button>
    </form>
@endsection
