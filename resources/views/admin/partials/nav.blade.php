@php $active ??= null; @endphp
<div class="tabs">
    <a href="{{ route('admin.home') }}" @if ($active === 'home') aria-current="page" @endif>Home</a>
    <a href="{{ route('admin.account-delete-requests.index') }}" @if ($active === 'account-delete-requests') aria-current="page" @endif>Account deletions</a>
    <a href="{{ route('admin.subscriptions.index') }}" @if ($active === 'subscriptions') aria-current="page" @endif>Subscriptions</a>
    <a href="{{ route('admin.plans.index') }}" @if ($active === 'plans') aria-current="page" @endif>Plans</a>
</div>
