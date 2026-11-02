@extends('layouts.app')
@section('title', 'B2C')
@php $base = rtrim(config('app.url'), '/'); @endphp
@section('content')
    <h1>B2C: business pays a customer</h1>
    <p class="sub">Send a payout as a client app would. The result goes to the ResultURL after the callback delay.</p>

    <div class="grid g2">
        <div class="card">
            <h2>Send a payment request</h2>
            <form method="post" action="{{ route('b2c.send') }}" class="fields">
                @csrf
                <div>
                    <label for="PartyA">From shortcode (PartyA)</label>
                    <input id="PartyA" name="PartyA" list="b2c-list" value="{{ old('PartyA', $shortcodes->first()?->shortcode ?? '600981') }}" required>
                    <datalist id="b2c-list">@foreach ($shortcodes as $s)<option value="{{ $s->shortcode }}">@endforeach</datalist>
                </div>
                <div>
                    <label for="PartyB">To phone (PartyB)</label>
                    <input id="PartyB" name="PartyB" value="{{ old('PartyB', '254708374149') }}" pattern="254[17]\d{8}" required>
                </div>
                <div>
                    <label for="Amount">Amount (KES)</label>
                    <input id="Amount" name="Amount" type="number" min="10" max="250000" value="{{ old('Amount', 100) }}" required>
                </div>
                <div>
                    <label for="CommandID">CommandID</label>
                    <select id="CommandID" name="CommandID">
                        @foreach (['BusinessPayment', 'SalaryPayment', 'PromotionPayment'] as $c)
                            <option @selected(old('CommandID') === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="Remarks">Remarks</label>
                    <input id="Remarks" name="Remarks" value="{{ old('Remarks', 'Test payout') }}" required>
                </div>
                <div>
                    <label for="Occasion">Occasion</label>
                    <input id="Occasion" name="Occasion" value="{{ old('Occasion') }}">
                </div>
                <div class="wide">
                    <label for="ResultURL">ResultURL</label>
                    <input id="ResultURL" name="ResultURL" value="{{ old('ResultURL', $base.'/sim/catch/b2c/result') }}" required>
                </div>
                <div class="wide">
                    <label for="QueueTimeOutURL">QueueTimeOutURL</label>
                    <input id="QueueTimeOutURL" name="QueueTimeOutURL" value="{{ old('QueueTimeOutURL', $base.'/sim/catch/b2c/timeout') }}" required>
                </div>
                <input type="hidden" name="InitiatorName" value="testapi">
                <input type="hidden" name="SecurityCredential" value="simulated">
                <div><button type="submit">Send</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Test phone numbers</h2>
            <p class="muted" style="margin-top:0">B2C payouts to these numbers always produce the listed outcome. C2B payments from them do too, where the outcome applies.</p>
            <table>
                <thead><tr><th>Phone</th><th>Outcome</th><th>ResultCode</th></tr></thead>
                <tbody>
                @foreach ($magic as $msisdn => $outcome)
                    <tr>
                        <td class="mono">{{ $msisdn }}</td>
                        <td>{{ $scenarios[$outcome]['label'] }}</td>
                        <td class="mono">{{ $scenarios[$outcome]['code'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="row-between"><h2>Recent B2C</h2><a href="{{ route('transactions.index', ['type' => 'b2c']) }}">View all →</a></div>
        @include('transactions._table')
    </div>
@endsection
