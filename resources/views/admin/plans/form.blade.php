@extends('layouts.site')

@section('title', $mode === 'create' ? 'New plan' : "Edit {$plan->name}")

@section('content')
    <h1>{{ $mode === 'create' ? 'New plan' : "Edit {$plan->name}" }}</h1>

    @if ($mode === 'edit')
        <p class="muted small">
            Changing price or interval creates a new Stripe Price and archives the old one —
            existing subscribers keep paying their original price until they change plans.
        </p>
    @endif

    @if ($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="card" method="POST"
          action="{{ $mode === 'create' ? route('admin.plans.store') : route('admin.plans.update', $plan) }}">
        @csrf

        <div class="field">
            <label for="slug">Slug</label>
            <input id="slug" name="slug" type="text" required pattern="[a-z0-9-]+"
                   value="{{ old('slug', $plan->slug) }}">
            <small>Lowercase letters, numbers, hyphens only. Used as the Stripe Price's lookup key.</small>
        </div>

        <div class="field">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" required value="{{ old('name', $plan->name) }}">
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description">{{ old('description', $plan->description) }}</textarea>
        </div>

        <div class="field">
            <label for="price_amount">Price (in cents)</label>
            <input id="price_amount" name="price_amount" type="number" min="1" required
                   value="{{ old('price_amount', $plan->price_amount) }}">
            <small>e.g. 499 = $4.99. Billed in {{ strtoupper(config('cashier.currency')) }}.</small>
        </div>

        <div class="field">
            <label for="interval">Billing interval</label>
            <select id="interval" name="interval" required>
                @foreach (['month' => 'Monthly', 'year' => 'Yearly'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('interval', $plan->interval) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="trial_days">Free trial (days)</label>
            <input id="trial_days" name="trial_days" type="number" min="0" max="3650"
                   value="{{ old('trial_days', $plan->trial_days) }}">
            <small>Leave blank for no trial. Only applies to a user's first subscription.</small>
        </div>

        <div class="field">
            <label for="features">Features (one per line)</label>
            <textarea id="features" name="features" rows="5">{{ old('features', $plan->features ? implode("\n", $plan->features) : '') }}</textarea>
        </div>

        <div class="field">
            <label for="sort_order">Sort order</label>
            <input id="sort_order" name="sort_order" type="number" min="0"
                   value="{{ old('sort_order', $plan->sort_order ?? 0) }}">
        </div>

        <div class="check">
            <input id="is_active" name="is_active" type="checkbox" value="1"
                   @checked(old('is_active', $plan->exists ? $plan->is_active : true))>
            <label for="is_active">Active — visible to new subscribers</label>
        </div>

        <button class="btn-primary" type="submit">{{ $mode === 'create' ? 'Create plan' : 'Save changes' }}</button>
        <a class="btn-quiet" href="{{ route('admin.plans.index') }}" style="margin-left:.5rem">Cancel</a>
    </form>
@endsection
