<?php

namespace App\Http\Controllers\Coop;

use App\Http\Controllers\Controller;
use App\Models\CoopTransfer;
use App\Models\DarajaApp;
use App\Services\Coop\CoopEnquiryService;
use App\Services\Coop\CoopTransferService;
use App\Support\Coop;
use App\Support\Daraja;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Co-op Bank Connect endpoints, at the same paths as openapi(-sit).co-opbank.co.ke. */
class CoopApiController extends Controller
{
    public function __construct(
        private CoopTransferService $transfers,
        private CoopEnquiryService $enquiries,
    ) {}

    /** POST /token (form: grant_type=client_credentials, Basic auth). WSO2-style responses. */
    public function token(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'client_credentials') {
            return response()->json(['error_description' => 'Unsupported grant_type value', 'error' => 'unsupported_grant_type'], 400);
        }

        $app = DarajaApp::where('provider', 'coop')->where('consumer_key', (string) $request->getUser())->first();

        if (! $app || ! hash_equals($app->consumer_secret, (string) $request->getPassword())) {
            return response()->json(['error_description' => 'Client Authentication failed.', 'error' => 'invalid_client'], 401);
        }

        $token = $app->tokens()->create(['token' => Daraja::token().Daraja::token(), 'expires_at' => now()->addSeconds(3600)]);

        return response()->json([
            'access_token' => $token->token,
            'scope' => 'am_application_scope default',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    public function internalTransfer(Request $request): JsonResponse
    {
        return $this->transfer($request, 'ift', [
            'Destinations.*.AccountNumber' => ['required', 'string', 'max:30'],
        ]);
    }

    public function pesalinkTransfer(Request $request): JsonResponse
    {
        return $this->transfer($request, 'pesalink', [
            'Destinations.*.AccountNumber' => ['required', 'string', 'max:30'],
            'Destinations.*.BankCode' => ['required', 'string', 'max:6'],
        ]);
    }

    public function mpesaTransfer(Request $request): JsonResponse
    {
        return $this->transfer($request, 'mpesa', [
            'Destinations.*.MobileNumber' => ['required', 'regex:/^254(7|1)\d{8}$/'],
        ]);
    }

    public function transactionStatus(Request $request): JsonResponse
    {
        if ($error = $this->invalid($request, ['MessageReference' => ['required', 'string']])) {
            return $error;
        }

        $transfer = CoopTransfer::where('message_reference', $request->input('MessageReference'))->first();

        return $transfer
            ? response()->json($this->transfers->statusPayload($transfer))
            : Coop::error($request->input('MessageReference'), '-9', 404);
    }

    public function accountBalance(Request $request): JsonResponse
    {
        return $this->onOwnAccount($request, [], fn ($ref, $account) => $this->enquiries->balance($ref, $account));
    }

    public function miniStatement(Request $request): JsonResponse
    {
        return $this->onOwnAccount($request, [], fn ($ref, $account) => $this->enquiries->miniStatement($ref, $account));
    }

    public function fullStatement(Request $request): JsonResponse
    {
        $rules = [
            'StartDate' => ['required', 'date_format:Y-m-d'],
            'EndDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:StartDate'],
            'PageNumber' => ['nullable', 'integer', 'min:1'],
            'PageSize' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];

        return $this->onOwnAccount($request, $rules, fn ($ref, $account) => $this->enquiries->fullStatement(
            $ref,
            $account,
            Carbon::parse($request->input('StartDate')),
            Carbon::parse($request->input('EndDate')),
            (int) $request->input('PageNumber', 1),
            (int) $request->input('PageSize', 100),
        ));
    }

    public function validateAccount(Request $request): JsonResponse
    {
        $rules = ['MessageReference' => ['required', 'string'], 'AccountNumber' => ['required', 'string'], 'RecipientBankIdentifier' => ['required', 'string']];
        if ($error = $this->invalid($request, $rules)) {
            return $error;
        }

        [$status, $body] = $this->enquiries->validateAccount(
            $request->input('MessageReference'),
            (string) $request->input('AccountNumber'),
            (string) $request->input('RecipientBankIdentifier'),
        );

        return response()->json($body, $status);
    }

    private function transfer(Request $request, string $rail, array $destinationRules): JsonResponse
    {
        $rules = array_merge([
            'MessageReference' => ['required', 'string', 'max:100'],
            'CallBackUrl' => ['required', 'url'],
            'Source' => ['required', 'array'],
            'Source.AccountNumber' => ['required', 'string'],
            'Source.Amount' => ['required', 'numeric', 'gt:0'],
            'Source.TransactionCurrency' => ['required', 'in:KES'],
            'Source.Narration' => ['nullable', 'string', 'max:100'],
            'Destinations' => ['required', 'array', 'min:1', 'max:50'],
            'Destinations.*.ReferenceNumber' => ['required', 'string'],
            'Destinations.*.Amount' => ['required', 'numeric', 'gt:0'],
            'Destinations.*.Narration' => ['nullable', 'string', 'max:100'],
        ], $destinationRules);

        if ($error = $this->invalid($request, $rules)) {
            return $error;
        }

        [$status, $body] = $this->transfers->initiate($rail, $request->all());

        return response()->json($body, $status);
    }

    private function onOwnAccount(Request $request, array $rules, callable $respond): JsonResponse
    {
        $rules = array_merge(['MessageReference' => ['required', 'string'], 'AccountNumber' => ['required', 'string']], $rules);
        if ($error = $this->invalid($request, $rules)) {
            return $error;
        }

        $ref = $request->input('MessageReference');
        [$account, $error] = $this->enquiries->ownAccount($ref, (string) $request->input('AccountNumber'));

        return $error ? response()->json($error, 400) : response()->json($respond($ref, $account));
    }

    private function invalid(Request $request, array $rules): ?JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->passes()) {
            return null;
        }

        $field = array_key_first($validator->errors()->toArray());

        return Coop::error($request->input('MessageReference'), '-4', 400, 'INVALID REQUEST: '.$field);
    }
}
