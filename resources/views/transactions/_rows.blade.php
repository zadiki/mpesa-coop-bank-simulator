@forelse ($transactions as $t)
    <tr>
        <td><a href="{{ route('transactions.show', $t) }}" class="mono">#{{ $t->id }}</a></td>
        <td><span class="badge b-type">{{ $t->type }}</span></td>
        <td><span class="badge b-{{ $t->status }}">{{ str_replace('_', ' ', $t->status) }}</span></td>
        <td class="mono">{{ $t->receipt ?? '—' }}</td>
        <td class="num">{{ number_format($t->amount, 2) }}</td>
        <td class="mono">{{ $t->msisdn }}</td>
        <td class="mono">{{ $t->shortcode }}</td>
        <td>{{ $t->bill_ref_number ?? '' }}</td>
        <td class="muted">{{ $t->scenario !== 'success' ? str_replace('_', ' ', $t->scenario) : '' }}</td>
        <td class="muted" title="{{ $t->created_at }}">{{ $t->created_at->diffForHumans(short: true) }}</td>
    </tr>
@empty
    <tr><td colspan="10" class="empty">No transactions yet.</td></tr>
@endforelse
