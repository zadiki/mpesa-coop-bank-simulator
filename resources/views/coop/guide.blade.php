@extends('layouts.app')
@section('title', 'How to test Co-op Bank')
@section('content')
    <h1>Co-op Bank</h1>
    <p class="sub">How to test Co-op Bank with this simulator: transfers (IFT, PesaLink, M-Pesa), their callbacks, collections and their payment notifications (IPN), balances and statements.</p>
    @include('coop._nav')

    @php
        $disbursement = $own->first(fn ($a) => str_contains($a->account_name, 'DISBURSEMENT')) ?? $own->first();
        $collections = $own->first(fn ($a) => str_contains($a->account_name, 'COLLECTION')) ?? $own->last();
    @endphp

    <div class="guide">
        <div class="card">
            <h2>What you can do here</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>To test</th><th>Your app calls</th><th>The simulator then</th><th>Where to look</th></tr></thead>
                    <tbody>
                        <tr><td><a href="#ift">Disbursement by internal transfer (IFT)</a></td><td class="mono">/FundsTransfer/Internal/A2A_v3/3.0.0/</td><td>Moves the money between Co-op accounts and POSTs the result to your <code>CallBackUrl</code></td><td><a href="{{ route('coop.transfers') }}">Transfers &amp; callbacks</a></td></tr>
                        <tr><td><a href="#other-rails">PesaLink to another bank</a></td><td class="mono">/FundsTransfer/External/A2A/PesaLink_v4/4.0.0/</td><td>Same, to a bank code</td><td><a href="{{ route('coop.transfers') }}">Transfers &amp; callbacks</a></td></tr>
                        <tr><td><a href="#other-rails">Payout to M-Pesa</a></td><td class="mono">/FundsTransfer/External/A2M/Mpesa_v2/2.0.0/</td><td>Same, to a phone</td><td><a href="{{ route('coop.transfers') }}">Transfers &amp; callbacks</a></td></tr>
                        <tr><td><a href="#callbacks">Callbacks and status checks</a></td><td class="mono">/Enquiry/TransactionStatus/2.0.0/</td><td>Answers the result (or <code>-10</code> while pending)</td><td>The transfer's page</td></tr>
                        <tr><td><a href="#collections">Collections and IPN</a></td><td>nothing: you make the customer pay here</td><td>Credits your account and POSTs the IPN to the account's IPN address</td><td><a href="{{ route('coop.collections') }}">Collections &amp; IPN</a></td></tr>
                        <tr><td><a href="#statements">Balances and statements</a></td><td class="mono">/Enquiry/AccountBalance_v2/2.0.0/</td><td>Answers from the account's ledger</td><td><a href="{{ route('coop.accounts') }}">Accounts</a></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" id="connect" style="margin-top:16px">
            <h2>1. Connect your app</h2>
            <ol>
                <li>Base URL: <code>{{ $baseUrl }}</code>. The paths copy Co-op's SIT host (<code>openapi-sit.co-opbank.co.ke</code>), so your app only swaps the host.
                    If your app accepts only <code>https</code> addresses, put the simulator behind an HTTPS proxy, e.g. <code>valet proxy coop-sim {{ $baseUrl }} --secure</code>, and use <code>https://coop-sim.&lt;your tld&gt;</code>.</li>
                <li>Credentials: consumer key <code>coop_consumer_key</code>, secret <code>coop_consumer_secret</code> (Basic auth on <code>POST /token</code> with <code>grant_type=client_credentials</code>). The <code>UserId</code> can be anything. Add more apps under <a href="{{ route('settings') }}">Settings</a>.</li>
                <li>Use these as your own accounts (only own accounts can send money or be queried):
                    <ul>
                        @foreach ($own as $a)
                            <li><code>{{ $a->account_number }}</code> {{ $a->account_name }}, KES {{ number_format($a->available(), 2) }} available</li>
                        @endforeach
                    </ul>
                    Add more on <a href="{{ route('coop.accounts') }}">Accounts</a> (tick "Owned by the API client").</li>
                <li>Your <code>CallBackUrl</code> must be reachable from the simulator. To just watch callbacks without an app, use <code>{{ $baseUrl }}/sim/catch/coop/callback</code>.</li>
            </ol>
        </div>

        <div class="card" id="ift" style="margin-top:16px">
            <h2>2. Disbursement by internal transfer (IFT)</h2>
            <ol>
                <li>Make sure the source account has money: <a href="{{ $disbursement ? route('coop.accounts.show', $disbursement) : route('coop.accounts') }}">{{ $disbursement?->account_name ?? 'the source account' }}</a> › Deposit or withdraw.</li>
                <li>Trigger the payout from your app (e.g. pay out a loan), or without an app: <a href="{{ route('coop.send') }}">Send money</a> › Rail <em>Internal transfer</em>, from <code>{{ $disbursement?->account_number }}</code> to <code>01100000012345</code>.</li>
                <li>The request is checked at once. A bad request gets HTTP 400 with a code: <code>-1</code> unknown source, <code>-8</code> not your account, <code>-6</code> source inactive, <code>-5</code> amounts do not add up, <code>-3</code> duplicate <code>MessageReference</code>.</li>
                <li>After the callback delay ({{ \App\Models\SimSetting::callbackDelay() }} s, set in <a href="{{ route('settings') }}">Settings</a>) the money moves and the callback is POSTed. Open the transfer on <a href="{{ route('coop.transfers') }}">Transfers &amp; callbacks</a> to see the request, the callback sent and what your app replied. "Process now" skips the wait.</li>
                <li>Check both statements: the source is debited (plus the charge, if one is set in Settings) and the destination credited.</li>
            </ol>
            <p class="muted">To try failures, send to a test destination, or queue a forced outcome on <a href="{{ route('coop.accounts') }}">Accounts</a>:</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Destination account / phone</th><th>Outcome</th></tr></thead>
                    <tbody>
                    @foreach (\App\Support\Coop::MAGIC_DESTINATIONS as $id => $outcome)
                        <tr><td class="mono">{{ $id }}</td><td>{{ \App\Support\Coop::SCENARIOS[$outcome]['label'] }}</td></tr>
                    @endforeach
                        <tr><td>An amount above the source's available balance</td><td>Insufficient funds (<code>-2</code>): nothing moves</td></tr>
                        <tr><td>Forced "Co-op down"</td><td>HTTP 503 <code>-11</code> on the request itself</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" id="other-rails" style="margin-top:16px">
            <h2>3. PesaLink and M-Pesa payouts</h2>
            <ol>
                <li><strong>PesaLink:</strong> <a href="{{ route('coop.send') }}">Send money</a> › Rail <em>PesaLink</em>, with a bank code (e.g. <code>1</code> KCB, <code>68</code> Equity) and an account. <code>/Enquiry/Validation/IPSL/1.0.0/</code> returns the account's name first, as Co-op does.</li>
                <li><strong>M-Pesa:</strong> Rail <em>M-Pesa payout</em>, to a phone like <code>254708374149</code>. Phones <code>254700000101</code>, <code>…103</code> and <code>…105</code>–<code>…107</code> fail or misbehave as in the table above.</li>
                <li>Both call back exactly like IFT. Unknown recipient accounts are created on the fly unless you turn that off in <a href="{{ route('settings') }}">Settings</a>.</li>
            </ol>
        </div>

        <div class="card" id="callbacks" style="margin-top:16px">
            <h2>4. Callbacks</h2>
            <ol>
                <li>Every callback is kept on the transfer's page: the body sent, the HTTP status and what your app replied. Your app should answer 2xx.</li>
                <li><strong>Resend callback</strong> on the transfer's page sends it again, to test that your app handles a repeat.</li>
                <li><strong>No callback:</strong> send to <code>01100000000005</code> (or force it). Your app must find the result with <code>/Enquiry/TransactionStatus/2.0.0/</code> and the same <code>MessageReference</code>.</li>
                <li><strong>Twice:</strong> <code>01100000000006</code>. <strong>Late:</strong> <code>01100000000007</code> (after the long delay).</li>
                <li><strong>Partial:</strong> force "First destination succeeds, the rest fail" and send a transfer with several destinations.</li>
            </ol>
        </div>

        <div class="card" id="collections" style="margin-top:16px">
            <h2>5. Collections and payment notifications (IPN)</h2>
            <p class="muted" style="margin-top:0">A collection is money arriving in one of your accounts. The bank tells the account holder with an IPN: a POST to an address set up per account.</p>
            <ol>
                <li>Open <a href="{{ $collections ? route('coop.accounts.show', $collections).'#ipn' : route('coop.accounts') }}">{{ $collections?->account_name ?? 'your collections account' }}</a> › <em>Payment notifications (IPN)</em> and set the IPN address: your app's endpoint, or <code>{{ $baseUrl }}/sim/catch/coop/ipn</code> to watch them. Add a Basic auth username and password if your endpoint checks them.</li>
                <li>Go to <a href="{{ route('coop.collections') }}">Collections &amp; IPN</a> › <em>Receive money</em>. Choose the account and how the customer pays:
                    <ul>
                        @foreach (\App\Models\CoopCollection::CHANNELS as $k => $l)<li>{{ $l }}</li>@endforeach
                    </ul>
                    Put the loan number (or whatever your customers quote) in <em>Reference</em>.</li>
                <li>The account is credited at once (see its statement). After the callback delay the IPN is POSTed; the collection's page shows the body sent and what your app replied. "Send IPN now" skips the wait.</li>
                <li>The IPN body carries the account (<code>AcctNo</code>), <code>Amount</code>, <code>EventType: CREDIT</code>, the customer's reference (<code>PaymentRef</code>), the payer (<code>CustMemoLine1</code>, <code>CustMemoLine2</code>), the bank's <code>TransactionId</code> and the balance after. It is modelled on Co-op's IPN; check the field names against Co-op's onboarding pack.</li>
                <li>Failures to try (queue them on the Collections page): no IPN (your app must find the money on the statement), IPN sent twice (must be counted once), IPN after a long delay. "Resend IPN" on a collection sends it again.</li>
                <li>A transfer into one of your own accounts (e.g. <code>{{ $disbursement?->account_number }}</code> → <code>{{ $collections?->account_number }}</code> by IFT) is a collection too: the receiving account's IPN is sent the same way.</li>
            </ol>
        </div>

        <div class="card" id="statements" style="margin-top:16px">
            <h2>6. Balances and statements</h2>
            <ol>
                <li>Every movement is a statement line. See them on each account's page, by date.</li>
                <li>Your app reads them with <code>/Enquiry/AccountBalance_v2/2.0.0/</code>, <code>/Enquiry/MiniStatement/Account_v2/2.0.0/</code> (last 10) and <code>/Enquiry/AccountFullStatementPaginated/1.0.0/</code>.</li>
                <li>Change the state to test edge cases: on the account page, set a blocked amount, an overdraft limit or a status (dormant, closed, blocked). Available = booked − blocked + overdraft.</li>
            </ol>
        </div>
    </div>
@endsection
