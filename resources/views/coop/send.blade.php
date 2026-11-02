@extends('layouts.app')
@section('title', 'Send money')
@section('content')
    <h1>Co-op Bank</h1>
    <p class="sub">Send a transfer as an API client would. It goes through the same checks and callback flow.</p>
    @include('coop._nav')
    <div class="card" style="max-width:760px">
        <form method="post" action="{{ route('coop.send.store') }}" class="fields">
            @csrf
            <div>
                <label for="rail">Rail</label>
                <select id="rail" name="rail" onchange="document.querySelectorAll('[data-rail]').forEach(e => e.hidden = !e.dataset.rail.split(' ').includes(this.value))">
                    @foreach (\App\Models\CoopTransfer::RAILS as $k => $l)<option value="{{ $k }}" @selected(old('rail') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="source">From (own account)</label>
                <select id="source" name="source">
                    @foreach ($own as $a)<option value="{{ $a->account_number }}" @selected(old('source') === $a->account_number)>{{ $a->account_number }} · {{ $a->account_name }} · KES {{ number_format($a->available(), 2) }}</option>@endforeach
                </select>
            </div>
            <div data-rail="ift pesalink">
                <label for="account_number">To account</label>
                <input id="account_number" name="account_number" list="recipients" value="{{ old('account_number', '01100000012345') }}">
                <datalist id="recipients">@foreach ($recipients as $r)<option value="{{ $r->account_number }}">{{ $r->account_name }} (bank {{ $r->bank_code }})</option>@endforeach</datalist>
            </div>
            <div data-rail="pesalink" hidden>
                <label for="bank_code">Bank code</label>
                <input id="bank_code" name="bank_code" value="{{ old('bank_code', '1') }}">
            </div>
            <div data-rail="mpesa" hidden>
                <label for="mobile_number">To phone</label>
                <input id="mobile_number" name="mobile_number" value="{{ old('mobile_number', '254708374149') }}">
            </div>
            <div><label for="amount">Amount (KES)</label><input id="amount" name="amount" type="number" min="1" step="0.01" value="{{ old('amount', 1000) }}" required></div>
            <div class="wide"><label for="narration">Narration</label><input id="narration" name="narration" value="{{ old('narration', 'TEST PAYOUT') }}"></div>
            <div class="wide"><label for="callback_url">CallBackUrl</label><input id="callback_url" name="callback_url" value="{{ old('callback_url', rtrim(config('app.url'), '/').'/sim/catch/coop/callback') }}" required></div>
            <div><button type="submit">Send</button></div>
        </form>
    </div>
    <script>document.getElementById('rail').dispatchEvent(new Event('change'));</script>
@endsection
