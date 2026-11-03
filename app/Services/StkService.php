<?php

namespace App\Services;

use App\Jobs\ProcessStkResult;
use App\Models\Shortcode;
use App\Models\SimSetting;
use App\Models\Transaction;
use App\Support\Daraja;
use App\Support\Scenario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Lipa na M-Pesa Online (STK push): a prompt on the customer's phone, then a callback with the result. */
class StkService
{
    public function __construct(
        private ScenarioResolver $scenarios,
        private CallbackDispatcher $dispatcher,
    ) {}

    public function initiate(array $request): Transaction
    {
        $shortcode = (string) $request['BusinessShortCode'];
        Shortcode::resolve($shortcode, $request['TransactionType'] === 'CustomerBuyGoodsOnline' ? 'till' : 'paybill');
        [$first, , $last] = Daraja::customerName((string) $request['PhoneNumber']);

        $transaction = Transaction::create([
            'type' => 'stk',
            'command_id' => $request['TransactionType'],
            'amount' => $request['Amount'],
            'msisdn' => (string) $request['PhoneNumber'],
            'shortcode' => $shortcode,
            'bill_ref_number' => $request['AccountReference'] ?? null,
            'customer_name' => "$first $last",
            'conversation_id' => 'ws_CO_'.now()->format('dmYHis').random_int(100000000, 999999999),
            'originator_conversation_id' => Daraja::originatorConversationId(),
            'result_url' => $request['CallBackURL'],
            'request_payload' => $request,
        ]);

        $outcome = $this->scenarios->assign($transaction);

        $transaction->update(['response_payload' => [
            'MerchantRequestID' => $transaction->originator_conversation_id,
            'CheckoutRequestID' => $transaction->conversation_id,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]]);

        $delay = $outcome === 'delayed' ? config('simulator.long_delay_seconds') : SimSetting::callbackDelay();
        ProcessStkResult::dispatch($transaction->id)->delay(now()->addSeconds($delay));

        return $transaction;
    }

    /** The customer has acted on the prompt (or not): settle and call back. */
    public function process(Transaction $transaction): void
    {
        if ($transaction->status !== 'pending') {
            return;
        }

        $outcome = $transaction->scenario;
        $definition = Scenario::STK[$outcome];
        $paid = in_array($outcome, ['success', 'no_callback', 'duplicate', 'delayed'], true);

        DB::transaction(function () use ($transaction, $outcome, $definition, $paid) {
            if ($paid) {
                Shortcode::where('shortcode', $transaction->shortcode)->increment('balance', $transaction->amount);
                $transaction->receipt = Daraja::receipt();
            }

            $transaction->fill([
                'status' => match (true) {
                    $outcome === 'no_callback' => 'no_callback',
                    $paid => 'completed',
                    $outcome === 'timeout' => 'timeout',
                    default => 'failed',
                },
                'result_code' => (string) $definition['code'],
                'result_desc' => $definition['desc'],
            ]);
            $transaction->callback_payload = $this->callbackPayload($transaction, $paid);
            $transaction->save();
        });

        if ($outcome === 'no_callback') {
            return;
        }

        $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload);

        if ($outcome === 'duplicate') {
            $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload);
        }
    }

    public function resend(Transaction $transaction): void
    {
        $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload);
    }

    /**
     * What the STK query answers: null while the customer has not acted yet.
     *
     * @return array<string, string>|null
     */
    public function queryAnswer(Transaction $transaction): ?array
    {
        if ($transaction->status === 'pending') {
            return null;
        }

        return [
            'ResponseCode' => '0',
            'ResponseDescription' => 'The service request has been accepted successsfully',
            'MerchantRequestID' => $transaction->originator_conversation_id,
            'CheckoutRequestID' => $transaction->conversation_id,
            'ResultCode' => (string) $transaction->result_code,
            'ResultDesc' => (string) $transaction->result_desc,
        ];
    }

    /** Daraja's password: base64(shortcode + passkey + timestamp). The simulator has no passkey, so it checks the frame. */
    public static function passwordLooksRight(string $shortcode, string $password, string $timestamp): bool
    {
        $decoded = base64_decode($password, true);

        return $decoded !== false
            && Str::startsWith($decoded, $shortcode)
            && Str::endsWith($decoded, $timestamp)
            && strlen($decoded) > strlen($shortcode) + strlen($timestamp);
    }

    private function callbackPayload(Transaction $transaction, bool $paid): array
    {
        $callback = [
            'MerchantRequestID' => $transaction->originator_conversation_id,
            'CheckoutRequestID' => $transaction->conversation_id,
            'ResultCode' => (int) $transaction->result_code,
            'ResultDesc' => $transaction->result_desc,
        ];

        if ($paid) {
            $callback['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => (float) $transaction->amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $transaction->receipt],
                ['Name' => 'TransactionDate', 'Value' => (int) now()->setTimezone(Daraja::TIMEZONE)->format('YmdHis')],
                ['Name' => 'PhoneNumber', 'Value' => (int) $transaction->msisdn],
            ]];
        }

        return ['Body' => ['stkCallback' => $callback]];
    }
}
