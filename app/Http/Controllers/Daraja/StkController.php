<?php

namespace App\Http\Controllers\Daraja;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\StkService;
use App\Support\Daraja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StkController extends Controller
{
    public function __construct(private StkService $stk) {}

    /** POST /mpesa/stkpush/v1/processrequest */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'BusinessShortCode' => ['required', 'digits_between:5,7'],
            'Password' => ['required', 'string'],
            'Timestamp' => ['required', 'digits:14'],
            'TransactionType' => ['required', 'in:CustomerPayBillOnline,CustomerBuyGoodsOnline'],
            'Amount' => ['required', 'numeric', 'min:1'],
            'PartyA' => ['required', 'regex:/^254(7|1)\d{8}$/'],
            'PartyB' => ['required', 'digits_between:5,7'],
            'PhoneNumber' => ['required', 'regex:/^254(7|1)\d{8}$/'],
            'CallBackURL' => ['required', 'url'],
            'AccountReference' => ['required', 'string', 'max:12'],
            'TransactionDesc' => ['required', 'string', 'max:13'],
        ]);

        if (! StkService::passwordLooksRight((string) $data['BusinessShortCode'], $data['Password'], (string) $data['Timestamp'])) {
            return Daraja::error('400.002.02', 'Bad Request - Invalid Password', 400);
        }

        return response()->json($this->stk->initiate($data)->response_payload);
    }

    /** POST /mpesa/stkpushquery/v1/query */
    public function query(Request $request): JsonResponse
    {
        $data = $request->validate([
            'BusinessShortCode' => ['required', 'digits_between:5,7'],
            'Password' => ['required', 'string'],
            'Timestamp' => ['required', 'digits:14'],
            'CheckoutRequestID' => ['required', 'string'],
        ]);

        $transaction = Transaction::where('type', 'stk')->where('conversation_id', $data['CheckoutRequestID'])->first();

        if (! $transaction) {
            return Daraja::error('400.002.02', 'Bad Request - Invalid CheckoutRequestID', 400);
        }

        $answer = $this->stk->queryAnswer($transaction);

        return $answer === null
            ? Daraja::error('500.001.1001', 'The transaction is being processed', 500)
            : response()->json($answer);
    }
}
