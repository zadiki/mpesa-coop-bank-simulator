<?php

namespace App\Services;

use App\Jobs\ProcessB2CResult;
use App\Models\Shortcode;
use App\Models\SimSetting;
use App\Models\Transaction;
use App\Support\Daraja;
use App\Support\Scenario;
use Illuminate\Support\Facades\DB;

class B2CService
{
    public function __construct(
        private ScenarioResolver $scenarios,
        private CallbackDispatcher $dispatcher,
    ) {}

    /** Accepts a payment request and returns the synchronous acknowledgement. */
    public function initiate(array $request): Transaction
    {
        Shortcode::resolve((string) $request['PartyA'], 'b2c');

        $transaction = Transaction::create([
            'type' => 'b2c',
            'command_id' => $request['CommandID'],
            'amount' => $request['Amount'],
            'msisdn' => (string) $request['PartyB'],
            'shortcode' => (string) $request['PartyA'],
            'bill_ref_number' => $request['Occasion'] ?? null,
            'conversation_id' => Daraja::conversationId(),
            'originator_conversation_id' => $request['OriginatorConversationID'] ?? Daraja::originatorConversationId(),
            'result_url' => $request['ResultURL'],
            'queue_timeout_url' => $request['QueueTimeOutURL'],
            'request_payload' => $request,
        ]);

        $outcome = $this->scenarios->assign($transaction);

        $transaction->update(['response_payload' => [
            'ConversationID' => $transaction->conversation_id,
            'OriginatorConversationID' => $transaction->originator_conversation_id,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]]);

        $delay = $outcome === 'delayed' ? config('simulator.long_delay_seconds') : SimSetting::callbackDelay();
        ProcessB2CResult::dispatch($transaction->id)->delay(now()->addSeconds($delay));

        return $transaction;
    }

    /** Runs when the delay expires: settles the payment and calls the client back. */
    public function process(Transaction $transaction): void
    {
        if ($transaction->status !== 'pending') {
            return;
        }

        $outcome = DB::transaction(function () use ($transaction) {
            $shortcode = Shortcode::where('shortcode', $transaction->shortcode)->lockForUpdate()->firstOrFail();
            $outcome = $transaction->scenario;

            if ($this->paysOut($outcome) && $shortcode->balance < $transaction->amount) {
                $outcome = 'insufficient_funds';
            }

            if ($this->paysOut($outcome)) {
                $shortcode->decrement('balance', $transaction->amount);
                $shortcode->refresh();
            }

            $definition = Scenario::B2C[$outcome];
            [$first, , $last] = Daraja::customerName($transaction->msisdn);

            $transaction->fill([
                'scenario' => $outcome,
                'receipt' => Daraja::receipt(),
                'customer_name' => "$first $last",
                'result_code' => $definition['code'] === null ? null : (string) $definition['code'],
                'result_desc' => $definition['desc'],
                'status' => match ($outcome) {
                    'success', 'duplicate', 'delayed' => 'completed',
                    'timeout' => 'timeout',
                    'no_callback' => 'no_callback',
                    default => 'failed',
                },
            ]);
            $transaction->callback_payload = $this->resultPayload($transaction, $shortcode, $outcome);
            $transaction->save();

            return $outcome;
        });

        match ($outcome) {
            'no_callback' => null,
            'timeout' => $this->dispatcher->send($transaction, 'timeout', $transaction->queue_timeout_url, $transaction->callback_payload),
            'duplicate' => [
                $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload),
                $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload),
            ],
            default => $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload),
        };
    }

    public function resend(Transaction $transaction): void
    {
        $kind = $transaction->status === 'timeout' ? 'timeout' : 'result';
        $url = $kind === 'timeout' ? $transaction->queue_timeout_url : $transaction->result_url;

        $this->dispatcher->send($transaction, $kind, $url, $transaction->callback_payload);
    }

    private function paysOut(string $outcome): bool
    {
        return in_array($outcome, ['success', 'duplicate', 'delayed', 'no_callback'], true);
    }

    private function resultPayload(Transaction $transaction, Shortcode $shortcode, string $outcome): array
    {
        $result = [
            'ResultType' => $outcome === 'timeout' ? 1 : 0,
            'ResultCode' => Scenario::B2C[$outcome]['code'] ?? 0,
            'ResultDesc' => Scenario::B2C[$outcome]['desc'],
            'OriginatorConversationID' => $transaction->originator_conversation_id,
            'ConversationID' => $transaction->conversation_id,
            'TransactionID' => $transaction->receipt,
        ];

        if ($this->paysOut($outcome)) {
            $result['ResultParameters'] = ['ResultParameter' => [
                ['Key' => 'TransactionAmount', 'Value' => (float) $transaction->amount],
                ['Key' => 'TransactionReceipt', 'Value' => $transaction->receipt],
                ['Key' => 'ReceiverPartyPublicName', 'Value' => $transaction->msisdn.' - '.$transaction->customer_name],
                ['Key' => 'TransactionCompletedDateTime', 'Value' => now()->format('d.m.Y H:i:s')],
                ['Key' => 'B2CUtilityAccountAvailableFunds', 'Value' => (float) $shortcode->balance],
                ['Key' => 'B2CWorkingAccountAvailableFunds', 'Value' => 0.00],
                ['Key' => 'B2CRecipientIsRegisteredCustomer', 'Value' => 'Y'],
                ['Key' => 'B2CChargesPaidAccountAvailableFunds', 'Value' => 0.00],
            ]];
        }

        $result['ReferenceData'] = ['ReferenceItem' => [
            'Key' => 'QueueTimeoutURL',
            'Value' => $transaction->queue_timeout_url,
        ]];

        return ['Result' => $result];
    }
}
