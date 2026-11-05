<?php

namespace App\Services\Coop;

use App\Jobs\ProcessCoopTransfer;
use App\Models\BankAccount;
use App\Models\CoopTransfer;
use App\Models\CoopTransferDestination;
use App\Models\ForcedOutcome;
use App\Models\SimSetting;
use App\Services\CallbackDispatcher;
use App\Support\Coop;
use Illuminate\Support\Facades\DB;

class CoopTransferService
{
    public function __construct(
        private BankLedger $ledger,
        private CallbackDispatcher $dispatcher,
        private CoopCollectionService $collections,
    ) {}

    /**
     * Synchronous part of a funds transfer: the checks Co-op makes before accepting.
     *
     * @return array{0: int, 1: array, 2: ?CoopTransfer} HTTP status, body, created transfer
     */
    public function initiate(string $rail, array $request): array
    {
        $ref = $request['MessageReference'];
        $source = $request['Source'];
        $destinations = $request['Destinations'];

        if (CoopTransfer::where('message_reference', $ref)->exists()) {
            return [400, Coop::envelope($ref, '-3'), null];
        }

        $account = BankAccount::find11((string) $source['AccountNumber']);
        if (! $account) {
            return [400, Coop::envelope($ref, '-1', 'INVALID ACCOUNT NUMBER: SOURCE'), null];
        }
        if (! $account->is_own) {
            return [400, Coop::envelope($ref, '-8'), null];
        }
        if (! $account->isActive()) {
            return [400, Coop::envelope($ref, '-6', 'ACCOUNT INACTIVE: SOURCE '.strtoupper($account->status)), null];
        }

        $total = round(array_sum(array_map(fn ($d) => (float) $d['Amount'], $destinations)), 2);
        if (abs($total - Coop::money($source['Amount'])) > 0.001) {
            return [400, Coop::envelope($ref, '-5'), null];
        }

        $forced = $this->takeForcedOutcome();
        if ($forced === 'service_down') {
            return [503, Coop::envelope($ref, '-11'), null];
        }

        $transfer = DB::transaction(function () use ($rail, $request, $ref, $source, $destinations, $forced) {
            $transfer = CoopTransfer::create([
                'message_reference' => $ref,
                'rail' => $rail,
                'source_account' => (string) $source['AccountNumber'],
                'amount' => Coop::money($source['Amount']),
                'currency' => $source['TransactionCurrency'] ?? 'KES',
                'narration' => $source['Narration'] ?? null,
                'callback_url' => $request['CallBackUrl'] ?? null,
                'request_payload' => $request,
                'scenario' => 'success',
            ]);

            $transferScenario = $forced && in_array($forced, Coop::TRANSFER_LEVEL, true) ? $forced : null;

            foreach ($destinations as $d) {
                $identifier = (string) ($d['MobileNumber'] ?? $d['AccountNumber'] ?? '');
                $magic = Coop::MAGIC_DESTINATIONS[$identifier] ?? null;
                $scenario = ($forced && ! in_array($forced, Coop::TRANSFER_LEVEL, true)) ? $forced : ($magic ?? 'success');

                if (in_array($scenario, Coop::TRANSFER_LEVEL, true)) {
                    $transferScenario ??= $scenario; // magic destination that drives the whole transfer
                    $scenario = 'success';
                }

                $transfer->destinations()->create([
                    'reference_number' => $d['ReferenceNumber'] ?? null,
                    'account_number' => $d['AccountNumber'] ?? null,
                    'bank_code' => $rail === 'pesalink' ? BankAccount::normalizeBankCode($d['BankCode'] ?? null) : ($rail === 'ift' ? BankAccount::COOP_BANK_CODE : null),
                    'mobile_number' => $d['MobileNumber'] ?? null,
                    'amount' => Coop::money($d['Amount']),
                    'currency' => $d['TransactionCurrency'] ?? 'KES',
                    'narration' => $d['Narration'] ?? null,
                    'scenario' => $scenario,
                ]);
            }

            $transfer->update([
                'scenario' => $transferScenario ?? 'success',
                'response_payload' => Coop::envelope($ref, '0', 'REQUEST ACCEPTED FOR PROCESSING'),
            ]);

            return $transfer;
        });

        $delay = $transfer->scenario === 'delayed' ? config('simulator.long_delay_seconds') : SimSetting::callbackDelay();
        ProcessCoopTransfer::dispatch($transfer->id)->delay(now()->addSeconds($delay));

        return [200, $transfer->response_payload, $transfer];
    }

    /** Async part: settle the money, then call the client back. */
    public function process(CoopTransfer $transfer): void
    {
        if ($transfer->status !== 'pending') {
            return;
        }

        DB::transaction(function () use ($transfer) {
            $source = BankAccount::where('bank_code', BankAccount::COOP_BANK_CODE)
                ->where('account_number', $transfer->source_account)
                ->lockForUpdate()
                ->firstOrFail();
            $destinations = $transfer->destinations()->get();

            // 1. Decide each destination's result.
            $results = [];
            foreach ($destinations as $i => $d) {
                $results[$d->id] = $this->destinationCode($transfer, $d, $i);
            }

            // 2. Funds check over everything that would go out, plus the charge.
            $outgoing = $destinations->filter(fn ($d) => $results[$d->id] === '0')->sum('amount');
            $charge = $outgoing > 0 ? $this->charge($transfer->rail) : 0.0;

            if ($outgoing > 0 && ($transfer->scenario === 'insufficient_funds' || $source->available() < $outgoing + $charge)) {
                $results = array_map(fn () => '-2', $results);
                $outgoing = 0;
                $charge = 0.0;
            }

            // 3. Move the money.
            foreach ($destinations as $d) {
                $code = $results[$d->id];
                $d->fill(['response_code' => $code, 'response_description' => Coop::description($code)]);

                if ($code === '0') {
                    $line = $this->ledger->post($source, 'D', (float) $d->amount, trim(($transfer->narration ?? 'TRANSFER').' TO '.$d->label()), $transfer->message_reference, $transfer->id);
                    $d->transaction_id = $line->transaction_id;
                    $this->creditDestination($transfer, $d, $source);
                }
                $d->save();
            }

            if ($charge > 0) {
                $this->ledger->post($source, 'D', $charge, strtoupper($transfer->rail).' CHARGE '.$transfer->message_reference, $transfer->message_reference, $transfer->id);
            }

            // 4. Overall result.
            $ok = collect($results)->filter(fn ($c) => $c === '0')->count();
            $firstFailure = collect($results)->first(fn ($c) => $c !== '0');

            [$code, $description, $status] = match (true) {
                $ok === count($results) => ['0', 'Success', $transfer->scenario === 'no_callback' ? 'no_callback' : 'success'],
                $ok > 0 => ['0', 'PARTIALLY SUCCESSFUL', 'partial'],
                default => [$firstFailure, Coop::description($firstFailure), 'failed'],
            };

            $transfer->fill([
                'status' => $status,
                'charge' => $charge,
                'message_code' => $code,
                'message_description' => $description,
                'processed_at' => now(),
            ]);
            $transfer->callback_payload = $this->callbackPayload($transfer, $destinations, $ok > 0 ? '0' : $firstFailure);
            $transfer->save();
        });

        if (! $transfer->callback_url || $transfer->scenario === 'no_callback') {
            return;
        }

        $this->dispatcher->send($transfer, 'result', $transfer->callback_url, $transfer->callback_payload);
        if ($transfer->scenario === 'duplicate_callback') {
            $this->dispatcher->send($transfer, 'result', $transfer->callback_url, $transfer->callback_payload);
        }
    }

    public function resend(CoopTransfer $transfer): void
    {
        $this->dispatcher->send($transfer, 'result', $transfer->callback_url, $transfer->callback_payload);
    }

    /** Body for /Enquiry/TransactionStatus. */
    public function statusPayload(CoopTransfer $transfer): array
    {
        if ($transfer->status === 'pending') {
            return Coop::envelope($transfer->message_reference, '-10');
        }

        return array_merge($transfer->callback_payload, ['MessageDateTime' => Coop::messageDateTime()]);
    }

    private function destinationCode(CoopTransfer $transfer, CoopTransferDestination $d, int $index): string
    {
        if ($transfer->scenario === 'partial_failure' && $index > 0) {
            return '-1';
        }

        if ($d->scenario !== 'success') {
            return Coop::SCENARIOS[$d->scenario]['code'];
        }

        if ($transfer->rail === 'mpesa') {
            return '0';
        }

        $account = BankAccount::where('bank_code', $d->bank_code)->where('account_number', $d->account_number)->first();

        if (! $account) {
            return SimSetting::get('coop_auto_create_destinations', '1') === '1' ? '0' : '-1';
        }

        return $account->isActive() ? '0' : '-6';
    }

    private function creditDestination(CoopTransfer $transfer, CoopTransferDestination $d, BankAccount $source): void
    {
        if ($transfer->rail === 'mpesa') {
            return;
        }

        // Paying into the source account itself: reuse the locked instance so its balance stays current.
        $isSource = $d->bank_code === $source->bank_code && $d->account_number === $source->account_number;
        $account = $isSource ? $source : BankAccount::where('bank_code', $d->bank_code)->where('account_number', $d->account_number)->lockForUpdate()->first()
            ?? BankAccount::create([
                'bank_code' => $d->bank_code,
                'account_number' => $d->account_number,
                'account_name' => 'ACCOUNT '.$d->account_number,
            ]);

        $line = $this->ledger->post($account, 'C', (float) $d->amount, trim(($d->narration ?? 'TRANSFER').' FROM '.$transfer->source_account), $d->reference_number ?? $transfer->message_reference, $transfer->id);

        // Money arriving in an own account is a collection: its holder gets the payment notification (IPN).
        $this->collections->fromTransfer($account, $line, $transfer, $d->narration ?? $transfer->narration ?? '');
    }

    private function charge(string $rail): float
    {
        return (float) SimSetting::get("coop_charge_$rail", 0);
    }

    private function takeForcedOutcome(): ?string
    {
        return DB::transaction(function () {
            $forced = ForcedOutcome::where('type', 'coop')->whereNull('consumed_at')->orderBy('id')->lockForUpdate()->first();
            $forced?->update(['consumed_at' => now()]);

            return $forced?->outcome;
        });
    }

    private function callbackPayload(CoopTransfer $transfer, $destinations, string $sourceCode): array
    {
        $source = $transfer->request_payload['Source'];

        return array_merge(
            Coop::envelope($transfer->message_reference, $transfer->message_code, $transfer->message_description),
            [
                'Source' => [
                    'AccountNumber' => $transfer->source_account,
                    'Amount' => (float) $transfer->amount,
                    'TransactionCurrency' => $transfer->currency,
                    'Narration' => $source['Narration'] ?? '',
                    'ResponseCode' => $sourceCode,
                    'ResponseDescription' => Coop::description($sourceCode),
                ],
                'Destinations' => $destinations->map(fn (CoopTransferDestination $d) => array_filter([
                    'ReferenceNumber' => $d->reference_number,
                    'AccountNumber' => $d->account_number,
                    'BankCode' => $transfer->rail === 'pesalink' ? $d->bank_code : null,
                    'MobileNumber' => $d->mobile_number,
                    'Amount' => (float) $d->amount,
                    'TransactionCurrency' => $d->currency,
                    'Narration' => $d->narration ?? '',
                    'TransactionID' => $d->transaction_id ?? '',
                    'ResponseCode' => $d->response_code,
                    'ResponseDescription' => $d->response_description,
                ], fn ($v) => $v !== null))->values()->all(),
            ],
        );
    }
}
