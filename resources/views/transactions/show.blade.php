@extends('layouts.app')
@section('title', 'Transaction #'.$transaction->id)
@if ($transaction->status === 'pending')
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif
@section('content')
    <p><a href="{{ route('transactions.index') }}">← Transactions</a></p>
    <div class="row-between">
        <div>
            <h1>{{ strtoupper($transaction->type) }} #{{ $transaction->id }}
                <span class="badge b-{{ $transaction->status }}">{{ str_replace('_', ' ', $transaction->status) }}</span></h1>
            <p class="sub" style="margin:0">
                @if ($transaction->status === 'pending')
                    Waiting for the callback delay to pass. This page reloads every 2 seconds.
                @else
                    {{ $transaction->result_desc }}
                @endif
            </p>
        </div>
        <div class="actions" style="margin:0">
            @if ($transaction->status === 'pending')
                <form method="post" action="{{ route('transactions.process', $transaction) }}" class="inline">@csrf
                    <button type="submit">Send callback now</button></form>
            @elseif ($transaction->callback_payload && $transaction->status !== 'rejected')
                <form method="post" action="{{ route('transactions.resend', $transaction) }}" class="inline">@csrf
                    <button type="submit" class="ghost">Resend callback</button></form>
            @endif
        </div>
    </div>

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Details</h2>
            <dl class="kv">
                <dt>Amount</dt><dd>KES {{ number_format($transaction->amount, 2) }}</dd>
                <dt>Command</dt><dd>{{ $transaction->command_id }}</dd>
                <dt>Phone</dt><dd class="mono">{{ $transaction->msisdn }} @if($transaction->customer_name)<span class="muted">({{ $transaction->customer_name }})</span>@endif</dd>
                <dt>Shortcode</dt><dd class="mono">{{ $transaction->shortcode }}</dd>
                @if ($transaction->bill_ref_number)<dt>{{ $transaction->type === 'b2c' ? 'Occasion' : 'Account' }}</dt><dd>{{ $transaction->bill_ref_number }}</dd>@endif
                <dt>Receipt</dt><dd class="mono">{{ $transaction->receipt ?? '—' }}</dd>
                @if ($transaction->conversation_id)<dt>{{ $transaction->type === 'stk' ? 'CheckoutRequestID' : 'ConversationID' }}</dt><dd class="mono">{{ $transaction->conversation_id }}</dd>@endif
                <dt>OriginatorConversationID</dt><dd class="mono">{{ $transaction->originator_conversation_id }}</dd>
                <dt>Result code</dt><dd class="mono">{{ $transaction->result_code ?? '—' }}</dd>
                <dt>Scenario</dt><dd>{{ str_replace('_', ' ', $transaction->scenario) }}</dd>
                <dt>Created</dt><dd>{{ $transaction->created_at }}</dd>
            </dl>
        </div>
        <div class="card stack">
            <div><h2>Request</h2><pre>{{ json_encode($transaction->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
            <div><h2>Synchronous response</h2><pre>{{ json_encode($transaction->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Callbacks sent ({{ $transaction->attempts->count() }})</h2>
        @forelse ($transaction->attempts as $a)
            <div style="border-top:1px solid var(--border); padding:12px 0">
                <div class="row-between" style="margin-bottom:4px">
                    <div>
                        <strong style="text-transform:capitalize">{{ $a->kind }}</strong>
                        <span class="mono muted">POST {{ $a->url }}</span>
                    </div>
                    <div>
                        @if ($a->http_status)
                            <span class="badge {{ $a->succeeded() ? 'b-2xx' : 'b-err' }}">HTTP {{ $a->http_status }}</span>
                        @else
                            <span class="badge b-err">No response</span>
                        @endif
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
                @if ($transaction->status === 'pending') No callbacks yet.
                @elseif ($transaction->status === 'no_callback') This scenario does not send any callback.
                @else No callbacks were sent. @endif
            </p>
        @endforelse
    </div>
@endsection
