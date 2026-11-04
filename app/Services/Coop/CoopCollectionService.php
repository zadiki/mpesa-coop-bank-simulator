<?php

namespace App\Services\Coop;

use App\Jobs\ProcessCoopIpn;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CallbackAttempt;
use App\Models\CoopCollection;
use App\Models\CoopTransfer;
use App\Models\ForcedOutcome;
use App\Models\SimSetting;
use App\Services\CallbackDispatcher;
use App\Support\Coop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Collections: money arriving in an own account, and the payment notification (IPN) the bank sends its holder.
 *
 * Money arrives two ways: "Receive money" in the UI (a customer pays in from another Co-op account, another bank by
 * PesaLink, M-Pesa, or cash at a branch), or a simulated transfer that credits an own account (e.g. TAP's disbursement
 * account paying its collections account). Either way the account's statement gets a credit line and, when the account
 * has an IPN address, the IPN is POSTed there after the callback delay, Basic auth included when set.
 */
class CoopCollectionService
{
    public function __construct(
        private BankLedger $ledger,
        private CallbackDispatcher $dispatcher,
    ) {}

    /**
     * A customer pays into an own account. From a simulated Co-op account the money leaves that account (refused when
     * it does not hold enough); from anywhere else it simply arrives.
     *
     * @param  array{channel: string, amount: float|string, narration?: ?string, payer_name?: ?string, payer_account?: ?string, payer_bank_code?: ?string, payer_phone?: ?string}  $data
     */
    public function receive(BankAccount $account, array $data): CoopCollection
    {
        if (! array_key_exists($data['channel'], CoopCollection::CHANNELS)) {
            throw new InvalidArgumentException("Unknown channel {$data['channel']}.");
        }
        $amount = Coop::money($data['amount']);
        $reference = self::reference();

        $collection = DB::transaction(function () use ($account, $data, $amount, $reference) {
            $credited = BankAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! $credited->is_own) {
                throw new InvalidArgumentException('Money can only be received into an own account.');
            }

            if ($data['channel'] === 'ift' && ($payer = BankAccount::find11((string) ($data['payer_account'] ?? '')))) {
                $payer = BankAccount::whereKey($payer->id)->lockForUpdate()->firstOrFail();
                if ($payer->is($credited)) {
                    throw new InvalidArgumentException('The payer and the receiving account are the same.');
                }
                if ($payer->available() < $amount) {
                    throw new InvalidArgumentException("{$payer->account_name} has only KES ".number_format($payer->available(), 2).' available.');
                }
                $this->ledger->post($payer, 'D', $amount, trim('TRANSFER TO '.$credited->account_number.' '.($data['narration'] ?? '')), $reference, null, 'MOBILE');
                $data['payer_name'] ??= $payer->account_name;
                $data['payer_bank_code'] = BankAccount::COOP_BANK_CODE;
            }

            $line = $this->ledger->post($credited, 'C', $amount, self::narration($data), $reference, null, self::servicePoint($data['channel']));

            return $this->record($credited, $line, $reference, [
                'channel' => $data['channel'],
                'payer_name' => isset($data['payer_name']) ? strtoupper($data['payer_name']) : null,
                'payer_account' => $data['payer_account'] ?? null,
                'payer_bank_code' => $data['payer_bank_code'] ?? ($data['channel'] === 'pesalink' ? null : BankAccount::COOP_BANK_CODE),
                'payer_phone' => $data['payer_phone'] ?? null,
                'amount' => $amount,
                'narration' => $data['narration'] ?? null,
            ]);
        });

        $this->schedule($collection);

        return $collection;
    }

    /**
     * A simulated transfer credited an own account: it is a collection too, notified like one. Called inside the
     * transfer's DB transaction; the IPN is queued once that commits.
     */
    public function fromTransfer(BankAccount $account, BankTransaction $line, CoopTransfer $transfer, string $narration): void
    {
        if (! $account->is_own) {
            return;
        }

        $source = BankAccount::find11($transfer->source_account);
        $collection = $this->record($account, $line, self::reference(), [
            'channel' => $transfer->rail === 'pesalink' ? 'pesalink' : 'ift',
            'coop_transfer_id' => $transfer->id,
            'payer_name' => $source?->account_name,
            'payer_account' => $transfer->source_account,
            'payer_bank_code' => BankAccount::COOP_BANK_CODE,
            'amount' => (float) $line->amount,
            'narration' => $narration,
        ]);

        $this->schedule($collection, afterCommit: true);
    }

    /** Send the IPN now (the queued job, or "Send now" in the UI). Sent once; "Resend" sends again. */
    public function notify(CoopCollection $collection): void
    {
        if ($collection->ipn_status !== 'pending') {
            return;
        }

        $account = $collection->account;
        if ($collection->scenario === 'no_ipn') {
            $collection->update(['ipn_status' => 'no_ipn']);

            return;
        }
        if (! $account->ipn_url) {
            $collection->update(['ipn_status' => 'not_configured']);

            return;
        }

        $collection->update(['ipn_url' => $account->ipn_url]);
        $attempt = $this->send($collection);
        if ($collection->scenario === 'duplicate_ipn') {
            $this->send($collection);
        }

        $collection->update(['ipn_status' => $attempt->succeeded() ? 'sent' : 'failed']);
    }

    /** Send the IPN again, to the account's current address. */
    public function resend(CoopCollection $collection): void
    {
        $account = $collection->account;
        if (! $account->ipn_url) {
            throw new InvalidArgumentException('Set an IPN address on the account first.');
        }

        $collection->update(['ipn_url' => $account->ipn_url]);
        $attempt = $this->send($collection);
        $collection->update(['ipn_status' => $attempt->succeeded() ? 'sent' : 'failed']);
    }

    /**
     * The payment notification. Co-op's IPN is set up per account by the bank and its exact fields come with the
     * onboarding pack, so this is modelled on it: the account, the amount, the payer and what they typed (the
     * reference), the bank's ids and the balance after. Change it here when Co-op's spec says otherwise.
     *
     * @return array<string, mixed>
     */
    public static function payload(CoopCollection $collection): array
    {
        $line = $collection->line;
        $account = $collection->account;

        return [
            'MessageReference' => $collection->reference,
            'MessageDateTime' => Coop::messageDateTime(),
            'AcctNo' => $account->account_number,
            'AcctName' => $account->account_name,
            'Currency' => $account->currency ?? 'KES',
            'Amount' => (float) $collection->amount,
            'EventType' => 'CREDIT',
            'TransactionId' => $line?->transaction_id,
            'TransactionDate' => $line?->transaction_date?->format('Y-m-d\TH:i:s'),
            'ValueDate' => $line?->value_date?->toDateString(),
            'PaymentRef' => $collection->narration ?? '',
            'Narration' => $line?->narration,
            'CustMemoLine1' => $collection->payer_name ?? '',
            'CustMemoLine2' => $collection->payer_phone ?? $collection->payer_account ?? '',
            'CustMemoLine3' => strtoupper($collection->channel),
            'BookedBalance' => $line ? (float) $line->running_balance : null,
            'ClearedBalance' => $line ? (float) $line->running_balance : null,
            'ExchangeRate' => 1,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function record(BankAccount $account, BankTransaction $line, string $reference, array $attributes): CoopCollection
    {
        $collection = CoopCollection::create([
            ...$attributes,
            'reference' => $reference,
            'bank_account_id' => $account->id,
            'bank_transaction_id' => $line->id,
            'scenario' => $this->takeForcedOutcome() ?? 'success',
        ]);
        $collection->setRelation('account', $account)->setRelation('line', $line);
        $collection->update(['ipn_payload' => self::payload($collection)]);

        return $collection;
    }

    private function schedule(CoopCollection $collection, bool $afterCommit = false): void
    {
        $delay = $collection->scenario === 'delayed_ipn' ? config('simulator.long_delay_seconds') : SimSetting::callbackDelay();
        $job = ProcessCoopIpn::dispatch($collection->id)->delay(now()->addSeconds($delay));
        if ($afterCommit) {
            $job->afterCommit();
        }
    }

    private function send(CoopCollection $collection): CallbackAttempt
    {
        $account = $collection->account;
        $auth = $account->ipn_username ? [$account->ipn_username, (string) $account->ipn_password] : null;

        return $this->dispatcher->send($collection, 'ipn', $collection->ipn_url, $collection->ipn_payload, $auth);
    }

    private function takeForcedOutcome(): ?string
    {
        $forced = ForcedOutcome::where('type', 'coop_ipn')->whereNull('consumed_at')->orderBy('id')->lockForUpdate()->first();
        $forced?->update(['consumed_at' => now()]);

        return $forced?->outcome;
    }

    /** @param  array<string, mixed>  $data */
    private static function narration(array $data): string
    {
        $from = $data['payer_name'] ?? $data['payer_phone'] ?? $data['payer_account'] ?? '';
        $how = match ($data['channel']) {
            'ift' => 'TRANSFER FROM',
            'pesalink' => 'PESALINK FROM',
            'mpesa' => 'MPESA FROM',
            default => 'CASH DEPOSIT BY',
        };

        return strtoupper(trim("{$how} {$from} ".($data['narration'] ?? '')));
    }

    private static function servicePoint(string $channel): string
    {
        return match ($channel) {
            'branch' => 'BRANCH',
            'mpesa' => 'MPESA',
            'pesalink' => 'PESALINK',
            default => 'MOBILE',
        };
    }

    /** The bank's reference for the payment, e.g. CL26310K3J9QZ. */
    private static function reference(): string
    {
        return 'CL'.now()->format('y').str_pad((string) now()->dayOfYear, 3, '0', STR_PAD_LEFT).Str::upper(Str::random(6));
    }
}
