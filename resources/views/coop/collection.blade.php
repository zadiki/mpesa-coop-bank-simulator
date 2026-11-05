@extends('layouts.app')
@section('title', $collection->reference)
@if ($collection->ipn_status === 'pending')
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif
@section('content')
    @include('coop._nav')
    <div class="row-between">
        <div>
            <h1><span class="mono">{{ $collection->reference }}</span> <span class="badge b-{{ $collection->ipn_status }}">IPN {{ str_replace('_', ' ', $collection->ipn_status) }}</span></h1>
            <p class="sub" style="margin:0">
                KES {{ number_format($collection->amount, 2) }} into {{ $collection->account->account_name }} ·
                {{ \App\Models\CoopCollection::IPN_STATUSES[$collection->ipn_status] }}
                @if ($collection->ipn_status === 'pending') (this page reloads every 2 seconds) @endif
            </p>
        </div>
        <div class="actions" style="margin:0">
            @if ($collection->ipn_status === 'pending')
                <form method="post" action="{{ route('coop.collections.send', $collection) }}" class="inline">@csrf<button type="submit">Send IPN now</button></form>
            @else
                <form method="post" action="{{ route('coop.collections.resend', $collection) }}" class="inline">@csrf<button type="submit" class="ghost">Resend IPN</button></form>
            @endif
        </div>
    </div>

    @if ($collection->ipn_status === 'not_configured')
        <div class="flash error" style="margin-top:16px">
            {{ $collection->account->account_name }} has no IPN address, so nothing was sent.
            Set one on the <a href="{{ route('coop.accounts.show', $collection->account) }}#ipn">account page</a>, then click "Resend IPN".
        </div>
    @endif

    <div class="grid g2" style="margin-top:16px">
        <div class="card">
            <h2>Details</h2>
            <dl class="kv">
                <dt>Into</dt><dd><a class="mono" href="{{ route('coop.accounts.show', $collection->account) }}">{{ $collection->account->account_number }}</a> <span class="muted">{{ $collection->account->account_name }}</span></dd>
                <dt>Amount</dt><dd>KES {{ number_format($collection->amount, 2) }}</dd>
                <dt>How</dt><dd>{{ \App\Models\CoopCollection::CHANNELS[$collection->channel] ?? $collection->channel }}</dd>
                <dt>From</dt><dd>{{ $collection->payer() }}</dd>
                <dt>Customer's reference</dt><dd class="mono">{{ $collection->narration }}</dd>
                <dt>Bank transaction ID</dt><dd class="mono">{{ $collection->line?->transaction_id }}</dd>
                <dt>Balance after</dt><dd>{{ $collection->line ? 'KES '.number_format($collection->line->running_balance, 2) : '' }}</dd>
                @if ($collection->transfer)<dt>From transfer</dt><dd><a class="mono" href="{{ route('coop.transfers.show', $collection->transfer) }}">{{ $collection->transfer->message_reference }}</a></dd>@endif
                <dt>IPN outcome</dt><dd>{{ \App\Support\Coop::IPN_SCENARIOS[$collection->scenario]['label'] ?? $collection->scenario }}</dd>
                <dt>IPN address</dt><dd class="mono">{{ $collection->ipn_url ?? $collection->account->ipn_url ?? 'not set' }}@if ($collection->account->ipn_username) <span class="muted">(Basic auth as {{ $collection->account->ipn_username }})</span>@endif</dd>
                <dt>Received</dt><dd>{{ $collection->created_at }}</dd>
            </dl>
        </div>
        <div class="card">
            <h2>IPN body</h2>
            <pre>{{ json_encode($collection->ipn_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <p class="muted" style="margin-bottom:0">Modelled on Co-op's IPN: the exact field names come with Co-op's onboarding pack, so check them against it before going live.</p>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>IPNs sent ({{ $collection->attempts->count() }})</h2>
        @forelse ($collection->attempts as $a)
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
                <div><label>Your app replied</label><pre>{{ $a->response_body ?? '' }}</pre></div>
            </div>
        @empty
            <p class="empty">
                @if ($collection->ipn_status === 'pending') Not sent yet: it goes out after the callback delay, or click "Send IPN now".
                @elseif ($collection->ipn_status === 'no_ipn') This collection was forced to send no IPN. Your app learns of it from the statement.
                @else No IPN was sent. @endif
            </p>
        @endforelse
    </div>
@endsection
