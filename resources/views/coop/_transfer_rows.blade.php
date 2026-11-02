@forelse ($transfers as $t)
    <tr>
        <td><a href="{{ route('coop.transfers.show', $t) }}" class="mono">{{ $t->message_reference }}</a></td>
        <td>{{ \App\Models\CoopTransfer::RAILS[$t->rail] }}</td>
        <td><span class="badge b-{{ $t->status }}">{{ str_replace('_', ' ', $t->status) }}</span></td>
        <td class="mono">{{ $t->source_account }}</td>
        <td class="num">{{ number_format($t->amount, 2) }}</td>
        <td class="num">{{ $t->destinations_count ?? $t->destinations()->count() }}</td>
        <td class="{{ in_array($t->status, ['failed', 'partial']) ? 'reason' : 'muted' }}">
            @if ($t->status === 'pending') — @else <span class="mono">{{ $t->message_code }}</span> {{ $t->message_description }} @endif
        </td>
        <td class="muted" title="{{ $t->created_at }}">{{ $t->created_at->diffForHumans(short: true) }}</td>
    </tr>
@empty
    <tr><td colspan="8" class="empty">No transfers yet.</td></tr>
@endforelse
