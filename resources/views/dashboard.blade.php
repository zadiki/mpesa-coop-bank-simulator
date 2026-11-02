@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    <h1>Dashboard</h1>
    <p class="sub">Simulated Safaricom M-Pesa Daraja: C2B and B2C.</p>

    <div class="grid g4">
        @foreach ($stats as $label => $n)
            <div class="card stat"><div class="n">{{ number_format($n) }}</div><div class="l">{{ $label }}</div></div>
        @endforeach
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Connect your app</h2>
            <dl class="kv">
                <dt>Base URL (both)</dt><dd class="mono">{{ rtrim(config('app.url'), '/') }}</dd>
                <dt>M-Pesa key</dt><dd class="mono">{{ $app?->consumer_key ?? '—' }}</dd>
                <dt>M-Pesa secret</dt><dd class="mono">{{ $app?->consumer_secret ?? '—' }}</dd>
                <dt>Co-op key</dt><dd class="mono">{{ $coopApp?->consumer_key ?? '—' }}</dd>
                <dt>Co-op secret</dt><dd class="mono">{{ $coopApp?->consumer_secret ?? '—' }}</dd>
            </dl>
            <details>
                <summary>Endpoints</summary>
                <pre>GET  /oauth/v1/generate?grant_type=client_credentials
POST /mpesa/c2b/v1/registerurl   (also v2)
POST /mpesa/c2b/v1/simulate      (also v2)
POST /mpesa/b2c/v1/paymentrequest
POST /mpesa/b2c/v3/paymentrequest

POST /token
POST /FundsTransfer/Internal/A2A_v3/3.0.0/
POST /FundsTransfer/External/A2A/PesaLink_v4/4.0.0/
POST /FundsTransfer/External/A2M/Mpesa_v2/2.0.0/
POST /Enquiry/TransactionStatus/2.0.0/
POST /Enquiry/AccountBalance_v2/2.0.0/
POST /Enquiry/MiniStatement/Account_v2/2.0.0/
POST /Enquiry/AccountFullStatementPaginated/1.0.0/
POST /Enquiry/Validation/IPSL/1.0.0/</pre>
            </details>
        </div>

        <div class="card">
            <h2>Force the next outcome</h2>
            <form method="post" action="{{ route('forced.store') }}" class="fields">
                @csrf
                <div class="wide">
                    <label for="scenario">Outcome</label>
                    <select id="scenario" name="scenario">
                        @foreach ($scenarios as $type => $list)
                            <optgroup label="{{ strtoupper($type) }}">
                                @foreach ($list as $key => $def)
                                    @continue($key === 'success')
                                    <option value="{{ $type }}:{{ $key }}">{{ strtoupper($type) }}: {{ $def['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div><button type="submit">Queue outcome</button></div>
            </form>
            @if ($forced->isNotEmpty())
                <table style="margin-top:12px">
                    <thead><tr><th>Queued</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($forced as $f)
                        <tr>
                            <td><span class="badge b-type">{{ $f->type }}</span> {{ $scenarios[$f->type][$f->outcome]['label'] ?? $f->outcome }}</td>
                            <td style="text-align:right">
                                <form method="post" action="{{ route('forced.destroy', $f) }}" class="inline">@csrf @method('delete')
                                    <button class="small danger">Remove</button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted" style="margin:12px 0 0">Nothing queued. New transactions follow the magic phone numbers or succeed.</p>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Shortcode balances</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Shortcode</th><th>Type</th><th>Name</th><th style="text-align:right">Balance (KES)</th></tr></thead>
                <tbody>
                @forelse ($shortcodes as $s)
                    <tr><td class="mono">{{ $s->shortcode }}</td><td>{{ $s->type }}</td><td>{{ $s->name }}</td><td class="num">{{ number_format($s->balance, 2) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="empty">No shortcodes yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="row-between"><h2>Recent transactions</h2><a href="{{ route('transactions.index') }}">View all →</a></div>
        @include('transactions._table', ['refresh' => route('dashboard', ['partial' => 1])])
    </div>
@endsection
