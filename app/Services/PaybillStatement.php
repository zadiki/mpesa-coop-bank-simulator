<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\Daraja;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** What Safaricom's own records show for a shortcode: money paid in by customers (C2B and STK), for statements and the Pull API. */
class PaybillStatement
{
    /** @return Collection<int, Transaction> */
    public function receipts(string $shortcode, Carbon $from, Carbon $to): Collection
    {
        return Transaction::query()
            ->where('shortcode', $shortcode)
            ->whereIn('type', ['c2b', 'stk'])
            ->whereIn('status', ['completed', 'no_callback'])
            ->whereNotNull('receipt')
            ->whereBetween('updated_at', [$from, $to])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();
    }

    public static function details(Transaction $t): string
    {
        $kind = $t->type === 'stk' ? 'Pay Bill Online' : ($t->command_id === 'CustomerBuyGoodsOnline' ? 'Merchant Payment' : 'Pay Bill');

        return "{$kind} from {$t->msisdn} - ".strtoupper((string) $t->customer_name).($t->bill_ref_number ? " Acc. {$t->bill_ref_number}" : '');
    }

    /** As the M-Pesa org portal exports a statement: a header block, then one row per transaction. */
    public function csv(string $shortcode, Carbon $from, Carbon $to): string
    {
        $out = fopen('php://temp', 'r+');
        $local = fn (Carbon $c) => $c->copy()->setTimezone(Daraja::TIMEZONE);

        fputcsv($out, ['Account Holder:', 'TAP SIMULATED PAYBILL']);
        fputcsv($out, ['Short Code:', $shortcode]);
        fputcsv($out, ['Account:', 'Utility Account']);
        fputcsv($out, ['Time Period:', 'From '.$local($from)->format('d-m-Y H:i:s').' To '.$local($to)->format('d-m-Y H:i:s')]);
        fputcsv($out, ['Operator:', 'simulator']);
        fputcsv($out, ['Report Date:', $local(now())->format('d-m-Y H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Receipt No.', 'Completion Time', 'Initiation Time', 'Details', 'Transaction Status', 'Paid In', 'Withdrawn', 'Balance', 'Balance Confirmed', 'Reason Type', 'Other Party Info', 'Linked Transaction ID', 'A/C No.']);

        $balance = 0.0;
        foreach ($this->receipts($shortcode, $from, $to) as $t) {
            $balance += (float) $t->amount;
            fputcsv($out, [
                $t->receipt,
                $local($t->updated_at)->format('d-m-Y H:i:s'),
                $local($t->created_at)->format('d-m-Y H:i:s'),
                self::details($t),
                'Completed',
                number_format((float) $t->amount, 2),
                '',
                number_format($balance, 2),
                'true',
                $t->type === 'stk' ? 'Pay Bill Online' : 'Pay Bill',
                "{$t->msisdn} - ".strtoupper((string) $t->customer_name),
                '',
                (string) $t->bill_ref_number,
            ]);
        }

        rewind($out);

        return stream_get_contents($out);
    }
}
