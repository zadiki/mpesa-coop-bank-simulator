<?php

namespace App\Services;

use App\Jobs\ProcessOrgRequest;
use App\Models\Shortcode;
use App\Models\SimSetting;
use App\Models\Transaction;
use App\Support\Daraja;
use Illuminate\Support\Facades\DB;

/**
 * Daraja's organisation APIs a client calls with its initiator: Account Balance and B2B (BusinessPayBill). Both answer
 * at once that the request was taken; the result goes to the ResultURL after the callback delay.
 *
 * Account Balance reports the shortcode's simulator balance as its Utility Account. B2B moves money from PartyA to
 * PartyB (credited when PartyB is a shortcode the simulator knows); not enough money on PartyA fails it with
 * ResultCode 1. Magic amount: a B2B of exactly 1,111 fails with ResultCode 2001 (wrong initiator).
 */
class OrgTransferService
{
    public const WRONG_INITIATOR_AMOUNT = 1111;

    public function __construct(private CallbackDispatcher $dispatcher) {}

    /** @param  array<string, mixed>  $request */
    public function balance(array $request): Transaction
    {
        return $this->accept('balance', $request, 0, null, (string) $request['PartyA']);
    }

    /** @param  array<string, mixed>  $request */
    public function b2b(array $request): Transaction
    {
        return $this->accept('b2b', $request, $request['Amount'], (string) $request['PartyB'], (string) $request['PartyA']);
    }

    public function process(Transaction $transaction): void
    {
        if ($transaction->status !== 'pending') {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $shortcode = Shortcode::resolve($transaction->shortcode, 'paybill');
            $shortcode = Shortcode::whereKey($shortcode->id)->lockForUpdate()->firstOrFail();
            [$code, $desc] = [0, 'The service request is processed successfully.'];
            $parameters = [];

            if ($transaction->type === 'balance') {
                $balance = number_format((float) $shortcode->balance, 2, '.', '');
                $parameters[] = ['Key' => 'AccountBalance', 'Value' => "Working Account|KES|0.00|0.00|0.00|0.00&Float Account|KES|0.00|0.00|0.00|0.00&Utility Account|KES|{$balance}|{$balance}|0.00|0.00&Charges Paid Account|KES|0.00|0.00|0.00|0.00&Organization Settlement Account|KES|0.00|0.00|0.00|0.00"];
                $parameters[] = ['Key' => 'BOCompletedTime', 'Value' => (int) now()->setTimezone(Daraja::TIMEZONE)->format('YmdHis')];
            } elseif ((int) $transaction->amount === self::WRONG_INITIATOR_AMOUNT) {
                [$code, $desc] = [2001, 'The initiator information is invalid.'];
            } elseif ($shortcode->balance < $transaction->amount) {
                [$code, $desc] = [1, 'The balance is insufficient for the transaction.'];
            } else {
                $shortcode->decrement('balance', $transaction->amount);
                Shortcode::where('shortcode', $transaction->msisdn)->increment('balance', $transaction->amount);
                $parameters[] = ['Key' => 'Amount', 'Value' => (float) $transaction->amount];
                $parameters[] = ['Key' => 'TransCompletedTime', 'Value' => (int) now()->setTimezone(Daraja::TIMEZONE)->format('YmdHis')];
                $parameters[] = ['Key' => 'ReceiverPartyPublicName', 'Value' => "{$transaction->msisdn} - Receiver"];
            }

            $transaction->fill([
                'receipt' => $code === 0 ? Daraja::receipt() : null,
                'result_code' => (string) $code,
                'result_desc' => $desc,
                'status' => $code === 0 ? 'completed' : 'failed',
            ]);
            $transaction->callback_payload = ['Result' => [
                'ResultType' => 0,
                'ResultCode' => $code,
                'ResultDesc' => $desc,
                'OriginatorConversationID' => $transaction->originator_conversation_id,
                'ConversationID' => $transaction->conversation_id,
                'TransactionID' => $transaction->receipt ?? Daraja::receipt(),
                'ResultParameters' => ['ResultParameter' => $parameters],
                'ReferenceData' => ['ReferenceItem' => ['Key' => 'QueueTimeoutURL', 'Value' => $transaction->queue_timeout_url]],
            ]];
            $transaction->save();
        });

        $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload);
    }

    public function resend(Transaction $transaction): void
    {
        $this->dispatcher->send($transaction, 'result', $transaction->result_url, $transaction->callback_payload);
    }

    /** @param  array<string, mixed>  $request */
    private function accept(string $type, array $request, mixed $amount, ?string $partyB, string $partyA): Transaction
    {
        Shortcode::resolve($partyA, 'paybill');

        $transaction = Transaction::create([
            'type' => $type,
            'command_id' => $request['CommandID'],
            'amount' => $amount,
            'msisdn' => $partyB,
            'shortcode' => $partyA,
            'bill_ref_number' => $request['AccountReference'] ?? null,
            'conversation_id' => Daraja::conversationId(),
            'originator_conversation_id' => Daraja::originatorConversationId(),
            'result_url' => $request['ResultURL'],
            'queue_timeout_url' => $request['QueueTimeOutURL'],
            'request_payload' => $request,
        ]);

        $transaction->update(['response_payload' => [
            'OriginatorConversationID' => $transaction->originator_conversation_id,
            'ConversationID' => $transaction->conversation_id,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]]);

        ProcessOrgRequest::dispatch($transaction->id)->delay(now()->addSeconds(SimSetting::callbackDelay()));

        return $transaction;
    }
}
