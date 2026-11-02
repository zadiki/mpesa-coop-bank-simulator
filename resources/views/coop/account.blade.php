@extends('layouts.app')
@section('title', $account->account_name)
@section('content')
    @include('coop._nav')
    <div class="row-between">
        <div>
            <h1>{{ $account->account_name }} <span class="badge b-{{ $account->status }}">{{ $account->status }}</span></h1>
            <p class="sub" style="margin:0"><span class="mono">{{ $account->account_number }}</span> · bank {{ $account->bank_code }} · {{ $account->is_own ? 'owned by the API client' : 'recipient' }}</p>
        </div>
    </div>

    <div class="grid g4" style="margin-top:16px">
        <div class="card stat"><div class="n">{{ number_format($account->available(), 2) }}</div><div class="l">Available (KES)</div></div>
        <div class="card stat"><div class="n">{{ number_format($account->balance, 2) }}</div><div class="l">Booked balance</div></div>
        <div class="card stat"><div class="n">{{ number_format($account->blocked_amount, 2) }}</div><div class="l">Blocked</div></div>
        <div class="card stat"><div class="n">{{ number_format($account->od_limit, 2) }}</div><div class="l">Overdraft limit</div></div>
    </div>

    @if ($failed->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <h2>Failed transfers from this account</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>MessageReference</th><th style="text-align:right">Amount</th><th>Destination</th><th>Reason</th><th>When</th></tr></thead>
                    <tbody>
                    @foreach ($failed as $t)
                        @foreach ($t->destinations->reject->succeeded() as $d)
                            <tr>
                                <td><a class="mono" href="{{ route('coop.transfers.show', $t) }}">{{ $t->message_reference }}</a></td>
                                <td class="num">{{ number_format($d->amount, 2) }}</td>
                                <td class="mono">{{ $d->label() }}</td>
                                <td class="reason"><span class="mono">{{ $d->response_code }}</span> {{ $d->response_description }}
                                    @if ($d->response_code === '-2')<span class="muted" style="font-weight:400"> (available was below {{ number_format($t->destinations->sum('amount'), 2) }})</span>@endif
                                </td>
                                <td class="muted">{{ $t->created_at->diffForHumans(short: true) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card" style="margin-top:16px">
        <div class="row-between">
            <h2>Statement</h2>
            <form method="get" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap">
                <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}"></div>
                <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}"></div>
                <button type="submit" class="ghost">Show</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Transaction ID</th><th>Reference</th><th>Narration</th><th style="text-align:right">Debit</th><th style="text-align:right">Credit</th><th style="text-align:right">Balance</th></tr></thead>
                <tbody>
                @forelse ($lines as $l)
                    <tr>
                        <td>{{ $l->transaction_date->format('Y-m-d H:i:s') }}</td>
                        <td class="mono">{{ $l->transaction_id }}</td>
                        <td class="mono">@if ($l->coop_transfer_id)<a href="{{ route('coop.transfers.show', $l->coop_transfer_id) }}">{{ $l->transaction_reference }}</a>@else{{ $l->transaction_reference }}@endif</td>
                        <td>{{ $l->narration }}</td>
                        <td class="num neg">{{ $l->type === 'D' ? number_format($l->amount, 2) : '' }}</td>
                        <td class="num pos">{{ $l->type === 'C' ? number_format($l->amount, 2) : '' }}</td>
                        <td class="num">{{ number_format($l->running_balance, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No transactions in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $lines->links() }}</div>
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Deposit or withdraw</h2>
            <form method="post" action="{{ route('coop.accounts.adjust', $account) }}" class="fields">
                @csrf
                <div>
                    <label for="type">Type</label>
                    <select id="type" name="type"><option value="C">Deposit (credit)</option><option value="D">Withdraw (debit)</option></select>
                </div>
                <div><label for="amt">Amount (KES)</label><input id="amt" name="amount" type="number" min="0.01" step="0.01" required></div>
                <div class="wide"><label for="nar">Narration</label><input id="nar" name="narration" placeholder="e.g. TREASURY TOP-UP"></div>
                <div><button type="submit">Post</button></div>
            </form>
        </div>
        <div class="card">
            <h2>Account settings</h2>
            <form method="post" action="{{ route('coop.accounts.update', $account) }}" class="fields">
                @csrf @method('put')
                <div class="wide"><label for="an">Account name</label><input id="an" name="account_name" value="{{ $account->account_name }}" required></div>
                <div>
                    <label for="st">Status</label>
                    <select id="st" name="status">@foreach (\App\Models\BankAccount::STATUSES as $s)<option @selected($account->status === $s)>{{ $s }}</option>@endforeach</select>
                </div>
                <div><label for="bl">Blocked amount</label><input id="bl" name="blocked_amount" type="number" min="0" step="0.01" value="{{ $account->blocked_amount }}"></div>
                <div><label for="od">Overdraft limit</label><input id="od" name="od_limit" type="number" min="0" step="0.01" value="{{ $account->od_limit }}"></div>
                <div style="align-self:end">
                    <label style="display:flex; gap:8px; align-items:center; font-weight:400; color:var(--text)">
                        <input type="checkbox" name="is_own" value="1" style="width:auto" @checked($account->is_own)> Owned by the API client
                    </label>
                </div>
                <div><button type="submit">Save</button></div>
            </form>
        </div>
    </div>
@endsection
