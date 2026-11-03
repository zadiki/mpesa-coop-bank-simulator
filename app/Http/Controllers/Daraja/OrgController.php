<?php

namespace App\Http\Controllers\Daraja;

use App\Http\Controllers\Controller;
use App\Services\OrgTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account Balance and B2B: requests made with the organisation's initiator. */
class OrgController extends Controller
{
    public function __construct(private OrgTransferService $service) {}

    public function balance(Request $request): JsonResponse
    {
        $request->validate([
            'Initiator' => ['required', 'string'],
            'SecurityCredential' => ['required', 'string'],
            'CommandID' => ['required', 'in:AccountBalance'],
            'PartyA' => ['required', 'digits_between:5,7'],
            'IdentifierType' => ['required', 'in:1,2,4'],
            'Remarks' => ['required', 'string', 'max:100'],
            'QueueTimeOutURL' => ['required', 'url'],
            'ResultURL' => ['required', 'url'],
        ]);

        return response()->json($this->service->balance($request->all())->response_payload);
    }

    public function b2b(Request $request): JsonResponse
    {
        $request->validate([
            'Initiator' => ['required', 'string'],
            'SecurityCredential' => ['required', 'string'],
            'CommandID' => ['required', 'in:BusinessPayBill,BusinessBuyGoods,DisburseFundsToBusiness,BusinessToBusinessTransfer,MerchantToMerchantTransfer'],
            'SenderIdentifierType' => ['required', 'in:4'],
            'RecieverIdentifierType' => ['required', 'in:2,4'],
            'Amount' => ['required', 'numeric', 'min:10', 'max:999999999'],
            'PartyA' => ['required', 'digits_between:5,7'],
            'PartyB' => ['required', 'digits_between:5,7'],
            'AccountReference' => ['required', 'string', 'max:13'],
            'Remarks' => ['required', 'string', 'max:100'],
            'QueueTimeOutURL' => ['required', 'url'],
            'ResultURL' => ['required', 'url'],
        ]);

        return response()->json($this->service->b2b($request->all())->response_payload);
    }
}
