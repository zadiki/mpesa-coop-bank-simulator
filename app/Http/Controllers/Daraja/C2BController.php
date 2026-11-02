<?php

namespace App\Http\Controllers\Daraja;

use App\Http\Controllers\Controller;
use App\Services\C2BService;
use App\Support\Daraja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class C2BController extends Controller
{
    public function __construct(private C2BService $c2b) {}

    public function registerUrl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ShortCode' => ['required', 'digits_between:5,7'],
            'ResponseType' => ['required', 'regex:/^(completed|cancelled)$/i'],
            'ConfirmationURL' => ['required', 'url'],
            'ValidationURL' => ['nullable', 'url'],
        ]);

        $this->c2b->register($data);

        return response()->json([
            'OriginatorCoversationID' => Daraja::originatorConversationId(),
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success',
        ]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ShortCode' => ['required', 'digits_between:5,7'],
            'CommandID' => ['required', 'in:CustomerPayBillOnline,CustomerBuyGoodsOnline'],
            'Amount' => ['required', 'numeric', 'min:1'],
            'Msisdn' => ['required', 'regex:/^254(7|1)\d{8}$/'],
            'BillRefNumber' => ['nullable', 'string', 'max:20'],
        ]);

        $transaction = $this->c2b->pay(
            (string) $data['ShortCode'],
            $data['CommandID'],
            $data['Amount'],
            (string) $data['Msisdn'],
            $data['BillRefNumber'] ?? null,
        );

        return response()->json($transaction->response_payload);
    }
}
