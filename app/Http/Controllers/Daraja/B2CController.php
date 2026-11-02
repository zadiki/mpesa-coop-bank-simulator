<?php

namespace App\Http\Controllers\Daraja;

use App\Http\Controllers\Controller;
use App\Services\B2CService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class B2CController extends Controller
{
    public function __construct(private B2CService $b2c) {}

    public static function rules(bool $requireOriginatorId): array
    {
        return [
            'OriginatorConversationID' => [$requireOriginatorId ? 'required' : 'nullable', 'string', 'max:100'],
            'InitiatorName' => ['required', 'string'],
            'SecurityCredential' => ['required', 'string'],
            'CommandID' => ['required', 'in:BusinessPayment,SalaryPayment,PromotionPayment'],
            'Amount' => ['required', 'numeric', 'min:10', 'max:250000'],
            'PartyA' => ['required', 'digits_between:5,7'],
            'PartyB' => ['required', 'regex:/^254(7|1)\d{8}$/'],
            'Remarks' => ['required', 'string', 'max:100'],
            'QueueTimeOutURL' => ['required', 'url'],
            'ResultURL' => ['required', 'url'],
            'Occasion' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function v1(Request $request): JsonResponse
    {
        return $this->handle($request, false);
    }

    public function v3(Request $request): JsonResponse
    {
        return $this->handle($request, true);
    }

    private function handle(Request $request, bool $requireOriginatorId): JsonResponse
    {
        $request->validate(self::rules($requireOriginatorId));

        $transaction = $this->b2c->initiate($request->all());

        return response()->json($transaction->response_payload);
    }
}
