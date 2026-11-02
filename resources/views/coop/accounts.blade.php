@extends('layouts.app')
@section('title', 'Co-op accounts')
@section('content')
    <h1>Co-op Bank</h1>
    <p class="sub">Accounts and balances held by the simulated bank. Every movement is a statement line.</p>
    @include('coop._nav')

    <div class="grid g2">
        @forelse ($own as $a)
            <a class="card" href="{{ route('coop.accounts.show', $a) }}" style="color:inherit; text-decoration:none">
                <div class="row-between" style="margin-bottom:4px">
                    <strong>{{ $a->account_name }}</strong>
                    <span class="badge b-{{ $a->status }}">{{ $a->status }}</span>
                </div>
                <div class="mono muted">{{ $a->account_number }}</div>
                <div class="bal" style="margin-top:10px">KES {{ number_format($a->available(), 2) }}</div>
                <div class="muted">available · booked {{ number_format($a->balance, 2) }}@if ($a->blocked_amount > 0) · blocked {{ number_format($a->blocked_amount, 2) }}@endif @if ($a->od_limit > 0) · overdraft {{ number_format($a->od_limit, 2) }}@endif</div>
            </a>
        @empty
            <div class="card empty">No own accounts. Add one below with "Owned by the API client" ticked.</div>
        @endforelse
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Recipient accounts</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Bank</th><th>Account</th><th>Name</th><th>Status</th><th style="text-align:right">Balance</th></tr></thead>
                <tbody>
                @forelse ($others as $a)
                    <tr>
                        <td class="mono">{{ $a->bank_code === '11' ? 'Co-op (11)' : $a->bank_code }}</td>
                        <td><a class="mono" href="{{ route('coop.accounts.show', $a) }}">{{ $a->account_number }}</a></td>
                        <td>{{ $a->account_name }}</td>
                        <td><span class="badge b-{{ $a->status }}">{{ $a->status }}</span></td>
                        <td class="num">{{ number_format($a->balance, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">No recipient accounts.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Add an account</h2>
            <form method="post" action="{{ route('coop.accounts.store') }}" class="fields">
                @csrf
                <div><label for="an">Account number</label><input id="an" name="account_number" value="{{ old('account_number') }}" required></div>
                <div><label for="bc">Bank code</label><input id="bc" name="bank_code" value="{{ old('bank_code', '11') }}" required></div>
                <div class="wide"><label for="nm">Account name (returned by PesaLink validation)</label><input id="nm" name="account_name" value="{{ old('account_name') }}" required></div>
                <div><label for="ob">Opening balance (KES)</label><input id="ob" name="opening_balance" type="number" min="0" step="0.01" value="{{ old('opening_balance', 0) }}"></div>
                <div style="align-self:end">
                    <label style="display:flex; gap:8px; align-items:center; font-weight:400; color:var(--text)">
                        <input type="checkbox" name="is_own" value="1" style="width:auto"> Owned by the API client
                    </label>
                </div>
                <div><button type="submit">Add account</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Force the next transfer's outcome</h2>
            <form method="post" action="{{ route('forced.store') }}" class="fields">
                @csrf
                <div class="wide">
                    <label for="scenario">Outcome</label>
                    <select id="scenario" name="scenario">
                        @foreach (\App\Support\Coop::SCENARIOS as $key => $def)
                            @continue($key === 'success')
                            <option value="coop:{{ $key }}">{{ $def['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div><button type="submit">Queue outcome</button></div>
            </form>
            @foreach ($forced as $f)
                <div class="row-between" style="margin:10px 0 0">
                    <span>Queued: {{ \App\Support\Coop::SCENARIOS[$f->outcome]['label'] ?? $f->outcome }}</span>
                    <form method="post" action="{{ route('forced.destroy', $f) }}" class="inline">@csrf @method('delete')<button class="small danger">Remove</button></form>
                </div>
            @endforeach
            <details>
                <summary>Test destinations</summary>
                <table>
                    <thead><tr><th>Account / phone</th><th>Outcome</th></tr></thead>
                    <tbody>
                    @foreach (\App\Support\Coop::MAGIC_DESTINATIONS as $id => $outcome)
                        <tr><td class="mono">{{ $id }}</td><td>{{ \App\Support\Coop::SCENARIOS[$outcome]['label'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        </div>
    </div>
@endsection
