<?php

namespace App\Services;

use App\Jobs\ProcessC2BPayment;
use App\Models\RegisteredUrl;
use App\Models\Shortcode;
use App\Models\SimSetting;
use App\Models\Transaction;
use App\Support\Daraja;
use Illuminate\Support\Facades\DB;

class C2BService
{
    public function __construct(
        private ScenarioResolver $scenarios,
        private CallbackDispatcher $dispatcher,
    ) {}

    public function register(array $data): RegisteredUrl
    {
        Shortcode::resolve((string) $data['ShortCode'], 'paybill');

        return RegisteredUrl::updateOrCreate(
            ['shortcode' => (string) $data['ShortCode']],
            [
                'response_type' => ucfirst(strtolower($data['ResponseType'])),
                'confirmation_url' => $data['ConfirmationURL'],
                'validation_url' => $data['ValidationURL'] ?? null,
            ],
        );
    }

    /** A customer pays the shortcode. Returns the transaction holding the sync acknowledgement. */
    public function pay(string $shortcode, string $commandId, float|string $amount, string $msisdn, ?string $billRef): Transaction
    {
        Shortcode::resolve($shortcode, $commandId === 'CustomerBuyGoodsOnline' ? 'till' : 'paybill');
        [$first, , $last] = Daraja::customerName($msisdn);

        $transaction = Transaction::create([
            'type' => 'c2b',
            'command_id' => $commandId,
            'amount' => $amount,
            'msisdn' => $msisdn,
            'shortcode' => $shortcode,
            'bill_ref_number' => $billRef,
            'customer_name' => "$first $last",
            'receipt' => Daraja::receipt(),
            'originator_conversation_id' => Daraja::originatorConversationId(),
            'request_payload' => [
                'ShortCode' => $shortcode,
                'CommandID' => $commandId,
                'Amount' => $amount,
                'Msisdn' => $msisdn,
                'BillRefNumber' => $billRef,
            ],
        ]);

        $outcome = $this->scenarios->assign($transaction);

        $transaction->update(['response_payload' => [
            'OriginatorCoversationID' => $transaction->originator_conversation_id, // sic: Daraja's spelling
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]]);

        $delay = $outcome === 'delayed' ? config('simulator.long_delay_seconds') : SimSetting::callbackDelay();
        ProcessC2BPayment::dispatch($transaction->id)->delay(now()->addSeconds($delay));

        return $transaction;
    }

    public function process(Transaction $transaction): void
    {
        if ($transaction->status !== 'pending') {
            return;
        }

        $urls = RegisteredUrl::where('shortcode', $transaction->shortcode)->first();
        $shortcode = Shortcode::where('shortcode', $transaction->shortcode)->firstOrFail();

        if (! $urls) {
            $transaction->update(['status' => 'failed', 'result_desc' => 'No URLs registered for this shortcode.']);

            return;
        }

        $payload = $this->payload($transaction, (float) $shortcode->balance + (float) $transaction->amount);

        if ($shortcode->validation_enabled && $urls->validation_url) {
            [$accepted, $code, $desc] = $this->validate($transaction, $urls, $payload);

            if (! $accepted) {
                $transaction->update(['status' => 'rejected', 'result_code' => $code, 'result_desc' => $desc, 'callback_payload' => $payload]);

                return;
            }
        }

        DB::transaction(function () use ($transaction, $payload) {
            Shortcode::where('shortcode', $transaction->shortcode)->lockForUpdate()->first()
                ->increment('balance', $transaction->amount);

            $transaction->update([
                'status' => $transaction->scenario === 'no_callback' ? 'no_callback' : 'completed',
                'result_code' => '0',
                'result_desc' => 'Payment completed',
                'callback_payload' => $payload,
            ]);
        });

        if ($transaction->scenario === 'no_callback') {
            return;
        }

        $this->dispatcher->send($transaction, 'confirmation', $urls->confirmation_url, $payload);

        if ($transaction->scenario === 'duplicate') {
            $this->dispatcher->send($transaction, 'confirmation', $urls->confirmation_url, $payload);
        }
    }

    public function resend(Transaction $transaction): void
    {
        $urls = RegisteredUrl::where('shortcode', $transaction->shortcode)->firstOrFail();

        $this->dispatcher->send($transaction, 'confirmation', $urls->confirmation_url, $transaction->callback_payload);
    }

    /** @return array{0: bool, 1: ?string, 2: string} accepted, result code, description */
    private function validate(Transaction $transaction, RegisteredUrl $urls, array $payload): array
    {
        $attempt = $this->dispatcher->send($transaction, 'validation', $urls->validation_url, $payload);

        if (! $attempt->succeeded()) {
            // Validation URL unreachable: Daraja applies the registered default action.
            $accept = $urls->response_type === 'Completed';

            return [$accept, $accept ? '0' : 'C2B00016', 'Validation URL unreachable; applied ResponseType '.$urls->response_type];
        }

        $body = json_decode((string) $attempt->response_body, true) ?: [];
        $code = (string) ($body['ResultCode'] ?? '');

        return [$code === '0', $code, (string) ($body['ResultDesc'] ?? 'Rejected by validation URL')];
    }

    private function payload(Transaction $transaction, float $balanceAfter): array
    {
        [$first, $middle, $last] = Daraja::customerName($transaction->msisdn);

        return [
            'TransactionType' => $transaction->command_id === 'CustomerBuyGoodsOnline' ? 'Buy Goods' : 'Pay Bill',
            'TransID' => $transaction->receipt,
            'TransTime' => $transaction->created_at->format('YmdHis'),
            'TransAmount' => Daraja::money($transaction->amount),
            'BusinessShortCode' => $transaction->shortcode,
            'BillRefNumber' => (string) $transaction->bill_ref_number,
            'InvoiceNumber' => '',
            'OrgAccountBalance' => Daraja::money($balanceAfter),
            'ThirdPartyTransID' => '',
            'MSISDN' => $transaction->msisdn,
            'FirstName' => $first,
            'MiddleName' => $middle,
            'LastName' => $last,
        ];
    }
}
