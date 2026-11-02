<div class="table-wrap">
    <table>
        <thead><tr><th>MessageReference</th><th>Rail</th><th>Status</th><th>Source</th><th style="text-align:right">Amount</th><th style="text-align:right">Dest.</th><th>Result</th><th>When</th></tr></thead>
        <tbody @isset($refresh) data-refresh="{{ $refresh }}" @endisset>@include('coop._transfer_rows')</tbody>
    </table>
</div>
