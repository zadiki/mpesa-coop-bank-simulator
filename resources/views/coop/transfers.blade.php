@extends('layouts.app')
@section('title', 'Co-op transfers')
@section('content')
    <h1>Co-op Bank</h1>
    <p class="sub">Every funds transfer request, with Co-op's result. This list refreshes by itself.</p>
    @include('coop._nav')
    <div class="card stack">
        <form method="get" class="fields">
            <div><label for="q">Search</label><input id="q" name="q" value="{{ request('q') }}" placeholder="MessageReference, account, phone"></div>
            <div>
                <label for="rail">Rail</label>
                <select id="rail" name="rail"><option value="">All</option>
                    @foreach (\App\Models\CoopTransfer::RAILS as $k => $l)<option value="{{ $k }}" @selected(request('rail') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status"><option value="">All</option>
                    @foreach (\App\Models\CoopTransfer::STATUSES as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach
                </select>
            </div>
            <div style="align-self:end"><button type="submit">Filter</button></div>
        </form>
        @include('coop._transfer_table', ['refresh' => request()->fullUrlWithQuery(['partial' => 1])])
        <div class="pagination">{{ $transfers->links() }}</div>
    </div>
@endsection
