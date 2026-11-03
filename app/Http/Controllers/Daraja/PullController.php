<?php

namespace App\Http\Controllers\Daraja;

use App\Http\Controllers\Controller;
use App\Services\PaybillStatement;
use App\Support\Daraja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Daraja's Pull Transactions API: a shortcode's own receipts for a period. */
class PullController extends Controller
{
    public const PAGE_SIZE = 1000;

    /** POST /pulltransactions/v1/register */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'ShortCode' => ['required', 'digits_between:5,7'],
            'RequestType' => ['required', 'string'],
            'NominatedNumber' => ['required', 'string'],
            'CallBackURL' => ['required', 'url'],
        ]);

        return response()->json(['ResponseRefID' => Daraja::requestId(), 'ResponseStatus' => '1000', 'ShortCode' => $request->input('ShortCode'), 'ResponseDescription' => 'Shortcode Registered Successfully']);
    }

    /** POST /pulltransactions/v1/query: StartDate / EndDate as "YYYY-MM-DD HH:mm:ss" Kenyan time; OffSetValue pages. */
    public function query(Request $request, PaybillStatement $statement): JsonResponse
    {
        $data = $request->validate([
            'ShortCode' => ['required', 'digits_between:5,7'],
            'StartDate' => ['required', 'date_format:Y-m-d H:i:s'],
            'EndDate' => ['required', 'date_format:Y-m-d H:i:s'],
            'OffSetValue' => ['required', 'numeric', 'min:0'],
        ]);

        $from = Carbon::createFromFormat('Y-m-d H:i:s', $data['StartDate'], Daraja::TIMEZONE)->utc();
        $to = Carbon::createFromFormat('Y-m-d H:i:s', $data['EndDate'], Daraja::TIMEZONE)->utc();

        $rows = $statement->receipts((string) $data['ShortCode'], $from, $to)
            ->slice((int) $data['OffSetValue'], self::PAGE_SIZE)
            ->map(fn ($t) => [
                'transactionId' => $t->receipt,
                'trxDate' => $t->updated_at->copy()->setTimezone(Daraja::TIMEZONE)->format('Y-m-d\TH:i:s.000P'),
                'msisdn' => (int) $t->msisdn,
                'sender' => strtoupper((string) $t->customer_name),
                'transactiontype' => $t->type === 'stk' ? 'c2b-pay-bill-online' : 'c2b-pay-bill-debit',
                'billreference' => (string) $t->bill_ref_number,
                'amount' => number_format((float) $t->amount, 1, '.', ''),
                'organizationname' => 'TAP SIMULATED PAYBILL',
            ])->values();

        return response()->json([
            'ResponseRefID' => Daraja::requestId(),
            'ResponseCode' => '1000',
            'ResponseMessage' => 'Success',
            'Response' => [$rows->all()],
        ]);
    }
}
