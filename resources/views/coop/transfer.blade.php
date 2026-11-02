@extends('layouts.app')
@section('title', $transfer->message_reference)
@if ($transfer->status === 'pending')
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif
@section('content')
    @include('coop._nav')
    <div class="row-between">
        <div>
            <h1><span class="mono">{{ $transfer->message_reference }}</span> <span class="badge b-{{ $transfer->status }}">{{ str_replace('_', ' ', $transfer->status) }}</span></h1>
            <p class="sub" style="margin:0">
                {{ \App\Models\CoopTransfer::RAILS[$transfer->rail] }} ·
                @if ($transfer->status === 'pending') waiting for the callback delay; this page reloads every 2 seconds
                @else <span class="{{ in_array($transfer->status, ['failed', 'partial']) ? 'reason' : '' }}"><span class="mono">{{ $transfer->message_code }}</span> {{ $transfer->message_description }}</span>@endif
            </p>
        </div>
        <div class="actions" style="margin:0">
            @if ($transfer->status === 'pending')
                <form method="post" action="{{ route('coop.transfers.process', $transfer) }}" class="inline">@csrf<button type="submit">Process now</button></form>
            @elseif ($transfer->callback_payload)
                <form method="post" action="{{ route('coop.transfers.resend', $transfer) }}" class="inline">@csrf<button type="submit" class="ghost">Resend callback</button></form>
            @endif
        </div>
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Details</h2>
            <dl class="kv">
                <dt>Source account</dt><dd class="mono">@php $src = \App\Models\BankAccount::find11($transfer->source_account); @endphp
                    @if ($src)<a href="{{ route('coop.accounts.show', $src) }}">{{ $transfer->source_account }}</a> <span class="muted">{{ $src->account_name }}</span>@else{{ $transfer->source_account }}@endif</dd>
                <dt>Amount</dt><dd>KES {{ number_format($transfer->amount, 2) }}</dd>
                @if ($transfer->charge > 0)<dt>Charge</dt><dd>KES {{ number_format($transfer->charge, 2) }}</dd>@endif
                <dt>Narration</dt><dd>{{ $transfer->narration }}</dd>
                <dt>CallBackUrl</dt><dd class="mono">{{ $transfer->callback_url }}</dd>
                <dt>Scenario</dt><dd>{{ \App\Support\Coop::SCENARIOS[$transfer->scenario]['label'] ?? $transfer->scenario }}</dd>
                <dt>Received</dt><dd>{{ $transfer->created_at }}</dd>
                @if ($transfer->processed_at)<dt>Processed</dt><dd>{{ $transfer->processed_at }}</dd>@endif
            </dl>
        </div>
        <div class="card">
            <h2>Destinations</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Reference</th><th>To</th><th style="text-align:right">Amount</th><th>Result</th><th>Transaction ID</th></tr></thead>
                    <tbody>
                    @foreach ($transfer->destinations as $d)
                        <tr>
                            <td class="mono">{{ $d->reference_number }}</td>
                            <td class="mono">{{ $d->label() }}</td>
                            <td class="num">{{ number_format($d->amount, 2) }}</td>
                            <td class="{{ $d->response_code && ! $d->succeeded() ? 'reason' : '' }}">
                                @if ($d->response_code === null) <span class="muted">pending</span>
                                @else <span class="mono">{{ $d->response_code }}</span> {{ $d->response_description }} @endif
                            </td>
                            <td class="mono">{{ $d->transaction_id }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card stack">
            <div><h2>Request</h2><pre>{{ json_encode($transfer->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
            <div><h2>Synchronous response</h2><pre>{{ json_encode($transfer->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        </div>
        <div class="card">
            <h2>Transaction status enquiry would return</h2>
            <pre>{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Callbacks sent ({{ $transfer->attempts->count() }})</h2>
        @forelse ($transfer->attempts as $a)
            <div style="border-top:1px solid var(--border); padding:12px 0">
                <div class="row-between" style="margin-bottom:4px">
                    <span class="mono muted">POST {{ $a->url }}</span>
                    <div>
                        @if ($a->http_status)<span class="badge {{ $a->succeeded() ? 'b-2xx' : 'b-err' }}">HTTP {{ $a->http_status }}</span>
                        @else<span class="badge b-err">No response</span>@endif
                        <span class="muted">{{ $a->duration_ms }} ms · {{ $a->created_at->format('H:i:s') }}</span>
                    </div>
                </div>
                @if ($a->error)<div class="flash error" style="margin:8px 0">{{ $a->error }}</div>@endif
                <div class="grid g2">
                    <div><label>Sent</label><pre>{{ json_encode($a->request_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
                    <div><label>Your app replied</label><pre>{{ $a->response_body ?? '' }}</pre></div>
                </div>
            </div>
        @empty
            <p class="empty">
                @if ($transfer->status === 'pending') No callback yet.
                @elseif ($transfer->status === 'no_callback') This scenario sends no callback. Use the Transaction Status enquiry to get the result.
                @else No callbacks were sent. @endif
            </p>
        @endforelse
    </div>
@endsection
