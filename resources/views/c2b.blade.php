@extends('layouts.app')
@section('title', 'C2B')
@php $base = rtrim(config('app.url'), '/'); @endphp
@section('content')
    <h1>C2B: customer pays a business</h1>
    <p class="sub">Act as a customer paying a paybill or till. The simulator calls your registered validation and confirmation URLs.</p>

    <div class="grid g2">
        <div class="card">
            <h2>Make a payment</h2>
            <form method="post" action="{{ route('c2b.pay') }}" class="fields">
                @csrf
                <div>
                    <label for="shortcode">Shortcode</label>
                    <input id="shortcode" name="shortcode" list="shortcode-list" value="{{ old('shortcode', $registrations->first()?->shortcode ?? '600000') }}" required>
                    <datalist id="shortcode-list">
                        @foreach ($registrations as $r)<option value="{{ $r->shortcode }}">@endforeach
                    </datalist>
                </div>
                <div>
                    <label for="command_id">Type</label>
                    <select id="command_id" name="command_id">
                        <option value="CustomerPayBillOnline">Pay Bill</option>
                        <option value="CustomerBuyGoodsOnline" @selected(old('command_id') === 'CustomerBuyGoodsOnline')>Buy Goods (till)</option>
                    </select>
                </div>
                <div>
                    <label for="msisdn">Customer phone</label>
                    <input id="msisdn" name="msisdn" value="{{ old('msisdn', '254708374149') }}" pattern="254[17]\d{8}" required>
                </div>
                <div>
                    <label for="amount">Amount (KES)</label>
                    <input id="amount" name="amount" type="number" min="1" step="0.01" value="{{ old('amount', 100) }}" required>
                </div>
                <div class="wide">
                    <label for="bill_ref_number">Account number (BillRefNumber)</label>
                    <input id="bill_ref_number" name="bill_ref_number" value="{{ old('bill_ref_number') }}" maxlength="20" placeholder="e.g. INV-1001">
                </div>
                <div><button type="submit">Pay</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Register URLs</h2>
            <form method="post" action="{{ route('c2b.register') }}" class="fields">
                @csrf
                <div>
                    <label for="r_shortcode">Shortcode</label>
                    <input id="r_shortcode" name="ShortCode" value="{{ old('ShortCode', '600000') }}" required>
                </div>
                <div>
                    <label for="r_type">ResponseType (when validation is unreachable)</label>
                    <select id="r_type" name="ResponseType">
                        <option value="Completed">Completed: accept</option>
                        <option value="Cancelled" @selected(old('ResponseType') === 'Cancelled')>Cancelled: reject</option>
                    </select>
                </div>
                <div class="wide">
                    <label for="r_conf">ConfirmationURL</label>
                    <input id="r_conf" name="ConfirmationURL" value="{{ old('ConfirmationURL', $base.'/sim/catch/c2b/confirmation') }}" required>
                </div>
                <div class="wide">
                    <label for="r_val">ValidationURL</label>
                    <input id="r_val" name="ValidationURL" value="{{ old('ValidationURL', $base.'/sim/catch/c2b/validation') }}">
                </div>
                <div><button type="submit">Save</button></div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Registered shortcodes</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Shortcode</th><th>Confirmation URL</th><th>Validation URL</th><th>ResponseType</th><th>Validation</th></tr></thead>
                <tbody>
                @forelse ($registrations as $r)
                    @php $sc = $shortcodes[$r->shortcode] ?? null; @endphp
                    <tr>
                        <td class="mono">{{ $r->shortcode }}</td>
                        <td class="mono">{{ $r->confirmation_url }}</td>
                        <td class="mono">{{ $r->validation_url ?? '—' }}</td>
                        <td>{{ $r->response_type }}</td>
                        <td>
                            @if ($sc)
                                <form method="post" action="{{ route('c2b.validation', $sc) }}" class="inline">@csrf
                                    <button class="small {{ $sc->validation_enabled ? '' : 'ghost' }}" title="Daraja only calls the validation URL when Safaricom has enabled external validation for the shortcode">
                                        {{ $sc->validation_enabled ? 'On' : 'Off' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No URLs registered. Register them here or call <code>/mpesa/c2b/v1/registerurl</code> from your app.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Download a statement</h2>
            <form method="get" action="{{ route('c2b.statement') }}" class="fields">
                <div>
                    <label for="st_code">Shortcode</label>
                    <input id="st_code" name="shortcode" value="{{ $registrations->first()?->shortcode ?? '600000' }}" required>
                </div>
                <div><label for="st_from">From</label><input id="st_from" type="date" name="from" value="{{ today('Africa/Nairobi')->toDateString() }}" required></div>
                <div><label for="st_to">To</label><input id="st_to" type="date" name="to" value="{{ today('Africa/Nairobi')->toDateString() }}" required></div>
                <div style="align-self:end"><button type="submit" class="ghost">Download CSV</button></div>
            </form>
            <p class="muted" style="margin-bottom:0">Same layout as the M-Pesa org portal export: paid-in C2B and STK receipts.</p>
        </div>
        <div class="card">
            <h2>STK prompt test phones</h2>
            <table>
                <thead><tr><th>Phone</th><th>What the customer does</th><th>ResultCode</th></tr></thead>
                <tbody>
                @foreach ($stkMagic as $msisdn => $outcome)
                    <tr><td class="mono">{{ $msisdn }}</td><td>{{ $stkScenarios[$outcome]['label'] }}</td><td class="mono">{{ $stkScenarios[$outcome]['code'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="row-between"><h2>Recent C2B and STK</h2><a href="{{ route('transactions.index') }}">View all →</a></div>
        @include('transactions._table')
    </div>
@endsection
