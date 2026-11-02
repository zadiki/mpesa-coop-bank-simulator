<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>ID</th><th>Type</th><th>Status</th><th>Receipt</th><th style="text-align:right">Amount</th>
            <th>Phone</th><th>Shortcode</th><th>Account / Occasion</th><th>Scenario</th><th>When</th>
        </tr>
        </thead>
        <tbody @isset($refresh) data-refresh="{{ $refresh }}" @endisset>
            @include('transactions._rows')
        </tbody>
    </table>
</div>
