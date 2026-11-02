@extends('layouts.app')
@section('title', 'Transactions')
@section('content')
    <h1>Transactions</h1>
    <p class="sub">Every C2B payment and B2C request the simulator has handled. This list refreshes by itself.</p>

    <div class="card stack">
        <form method="get" class="fields">
            <div>
                <label for="q">Search</label>
                <input id="q" name="q" value="{{ request('q') }}" placeholder="Receipt, phone, account, conversation ID">
            </div>
            <div>
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">All</option>
                    @foreach (['c2b' => 'C2B', 'b2c' => 'B2C'] as $v => $l)
                        <option value="{{ $v }}" @selected(request('type') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All</option>
                    @foreach (\App\Models\Transaction::STATUSES as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="align-self:end"><button type="submit">Filter</button></div>
        </form>

        @include('transactions._table', ['refresh' => request()->fullUrlWithQuery(['partial' => 1])])
        <div class="pagination">{{ $transactions->links() }}</div>
    </div>
@endsection
