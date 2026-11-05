@extends('layouts.app')
@section('title', 'Co-op collections')
@section('content')
    <h1>Co-op Bank</h1>
    <p class="sub">Money arriving in your own accounts, and the payment notification (IPN) the bank sends your app for each one.</p>
    @include('coop._nav')

    <div class="tip">
        <strong>How it works.</strong> Pick the account that receives the money and how the customer pays. The account is
        credited at once (see its statement), and after the callback delay the IPN is POSTed to the account's IPN address
        (set it on the <a href="{{ route('coop.accounts') }}">account page</a>). Put the loan number in <em>Reference</em>:
        it is what the customer types, and it arrives as <code>PaymentRef</code>. A transfer you send to one of your own
        accounts (e.g. disbursement → collections) is a collection too and is notified the same way.
        <a href="{{ route('coop.guide') }}#collections">Step-by-step guide</a>.
    </div>

    <div class="grid g2">
        <div class="card">
            <h2>Receive money</h2>
            <form method="post" action="{{ route('coop.collections.store') }}" class="fields">
                @csrf
                <div class="wide">
                    <label for="account">Into (own account)</label>
                    <select id="account" name="account" required>
                        @foreach ($own as $a)
                            <option value="{{ $a->id }}" @selected((string) old('account') === (string) $a->id || (! old('account') && str_contains($a->account_name, 'COLLECTION')))>
                                {{ $a->account_number }} · {{ $a->account_name }}{{ $a->ipn_url ? '' : ' · no IPN address' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="wide">
                    <label for="channel">How the customer pays</label>
                    <select id="channel" name="channel" onchange="document.querySelectorAll('[data-channel]').forEach(e => e.hidden = !e.dataset.channel.split(' ').includes(this.value))">
                        @foreach (\App\Models\CoopCollection::CHANNELS as $k => $l)<option value="{{ $k }}" @selected(old('channel', 'ift') === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div data-channel="ift pesalink">
                    <label for="payer_account">Payer's account</label>
                    <input id="payer_account" name="payer_account" list="payers" value="{{ old('payer_account', '01100000012345') }}">
                    <datalist id="payers">@foreach ($payers as $p)<option value="{{ $p->account_number }}">{{ $p->account_name }} · KES {{ number_format($p->available(), 2) }}</option>@endforeach</datalist>
                </div>
                <div data-channel="pesalink" hidden>
                    <label for="payer_bank_code">Payer's bank code</label>
                    <input id="payer_bank_code" name="payer_bank_code" value="{{ old('payer_bank_code', '68') }}">
                </div>
                <div data-channel="mpesa" hidden>
                    <label for="payer_phone">Payer's phone</label>
                    <input id="payer_phone" name="payer_phone" value="{{ old('payer_phone', '254708374149') }}">
                </div>
                <div><label for="payer_name">Payer's name</label><input id="payer_name" name="payer_name" value="{{ old('payer_name') }}" placeholder="from the account when blank"></div>
                <div><label for="amount">Amount (KES)</label><input id="amount" name="amount" type="number" min="1" step="0.01" value="{{ old('amount', 1000) }}" required></div>
                <div><label for="narration">Reference (what the customer types)</label><input id="narration" name="narration" value="{{ old('narration', 'TL000123') }}"></div>
                <div class="wide muted">From a simulated Co-op account the money leaves that account too, and is refused if it holds too little. From another bank, M-Pesa or cash it just arrives.</div>
                <div><button type="submit">Receive</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Force the next collection's IPN</h2>
            <form method="post" action="{{ route('forced.store') }}" class="fields">
                @csrf
                <div class="wide">
                    <label for="ipn_scenario">Outcome</label>
                    <select id="ipn_scenario" name="scenario">
                        @foreach (\App\Support\Coop::IPN_SCENARIOS as $key => $def)
                            @continue($key === 'success')
                            <option value="coop_ipn:{{ $key }}">{{ $def['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div><button type="submit">Queue outcome</button></div>
            </form>
            @foreach ($forced as $f)
                <div class="row-between" style="margin:10px 0 0">
                    <span>Queued: {{ \App\Support\Coop::IPN_SCENARIOS[$f->outcome]['label'] ?? $f->outcome }}</span>
                    <form method="post" action="{{ route('forced.destroy', $f) }}" class="inline">@csrf @method('delete')<button class="small danger">Remove</button></form>
                </div>
            @endforeach
            <p class="muted" style="margin-top:12px">
                Your app should answer the IPN with HTTP 2xx. Anything else shows as <em>failed</em> here, and you can
                resend it from the collection's page, as you would ask the bank to.
            </p>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="row-between">
            <h2>Collections</h2>
            <form method="get" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap">
                <div><label for="f_account">Account</label><select id="f_account" name="account"><option value="">All</option>@foreach ($own as $a)<option value="{{ $a->account_number }}" @selected(request('account') === $a->account_number)>{{ $a->account_number }}</option>@endforeach</select></div>
                <div><label for="f_ipn">IPN</label><select id="f_ipn" name="ipn"><option value="">Any</option>@foreach (\App\Models\CoopCollection::IPN_STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('ipn') === $k)>{{ $l }}</option>@endforeach</select></div>
                <button type="submit" class="ghost">Filter</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>Into</th><th>From</th><th>How</th><th>Customer's reference</th><th style="text-align:right">Amount</th><th>IPN</th><th>When</th></tr></thead>
                <tbody>
                @forelse ($collections as $c)
                    <tr>
                        <td><a class="mono" href="{{ route('coop.collections.show', $c) }}">{{ $c->reference }}</a></td>
                        <td class="mono">{{ $c->account->account_number }}</td>
                        <td>{{ $c->payer() }}</td>
                        <td>{{ \App\Models\CoopCollection::CHANNELS[$c->channel] ?? $c->channel }}</td>
                        <td class="mono">{{ $c->narration }}</td>
                        <td class="num">{{ number_format($c->amount, 2) }}</td>
                        <td><span class="badge b-{{ $c->ipn_status }}">{{ str_replace('_', ' ', $c->ipn_status) }}</span></td>
                        <td class="muted">{{ $c->created_at->diffForHumans(short: true) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">No money received yet. Use "Receive money" above.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $collections->links() }}</div>
    </div>
    <script>document.getElementById('channel').dispatchEvent(new Event('change'));</script>
@endsection
