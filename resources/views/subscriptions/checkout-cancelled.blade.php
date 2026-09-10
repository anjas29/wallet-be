@extends('layouts.site')

@section('title', 'Checkout cancelled')

@section('content')
    <h1>Checkout cancelled</h1>

    <div class="card">
        <p>No payment was made — your subscription was not started.</p>
        <p class="muted">You can close this tab and return to the app to try again.</p>
    </div>
@endsection
