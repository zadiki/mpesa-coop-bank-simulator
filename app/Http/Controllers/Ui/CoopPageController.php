<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CoopTransfer;
use App\Models\ForcedOutcome;
use App\Services\Coop\BankLedger;
use App\Services\Coop\CoopTransferService;
use App\Support\Coop;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CoopPageController extends Controller
{
    public function accounts()
    {
        return view('coop.accounts', [
            'own' => BankAccount::where('is_own', true)->orderBy('account_number')->get(),
            'others' => BankAccount::where('is_own', false)->orderBy('bank_code')->orderBy('account_number')->get(),
            'forced' => ForcedOutcome::where('type', 'coop')->whereNull('consumed_at')->orderBy('id')->get(),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'account_number' => ['required', 'string', 'max:30'],
            'bank_code' => ['required', 'string', 'max:6'],
            'account_name' => ['required', 'string', 'max:100'],
            'is_own' => ['nullable', 'boolean'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['bank_code'] = BankAccount::normalizeBankCode($data['bank_code']);

        if (BankAccount::where('bank_code', $data['bank_code'])->where('account_number', $data['account_number'])->exists()) {
            return back()->withInput()->with('error', 'That account already exists.');
        }

        $account = BankAccount::create([
            'account_number' => $data['account_number'],
            'bank_code' => $data['bank_code'],
            'account_name' => strtoupper($data['account_name']),
            'is_own' => $request->boolean('is_own'),
        ]);

        if (($data['opening_balance'] ?? 0) > 0) {
            app(BankLedger::class)->adjust($account, 'C', (float) $data['opening_balance'], 'OPENING BALANCE');
        }

        return redirect()->route('coop.accounts.show', $account)->with('ok', 'Account created.');
    }

    public function showAccount(Request $request, BankAccount $account)
    {
        $from = $request->date('from') ?? today()->subDays(30);
        $to = $request->date('to') ?? today();

        $lines = $account->lines()
            ->whereBetween('transaction_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        $failed = CoopTransfer::where('source_account', $account->account_number)
            ->whereIn('status', ['failed', 'partial'])
            ->with('destinations')
            ->latest('id')->limit(20)->get();

        return view('coop.account', compact('account', 'lines', 'failed', 'from', 'to'));
    }

    public function updateAccount(Request $request, BankAccount $account)
    {
        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:'.implode(',', BankAccount::STATUSES)],
            'blocked_amount' => ['required', 'numeric', 'min:0'],
            'od_limit' => ['required', 'numeric', 'min:0'],
        ]);
        $data['account_name'] = strtoupper($data['account_name']);
        $account->update($data + ['is_own' => $request->boolean('is_own')]);

        return back()->with('ok', 'Account updated.');
    }

    public function adjust(Request $request, BankAccount $account, BankLedger $ledger)
    {
        $data = $request->validate([
            'type' => ['required', 'in:C,D'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:100'],
        ]);

        $ledger->adjust($account, $data['type'], (float) $data['amount'], strtoupper($data['narration'] ?: ($data['type'] === 'C' ? 'CASH DEPOSIT' : 'CASH WITHDRAWAL')));

        return back()->with('ok', ($data['type'] === 'C' ? 'Deposited ' : 'Withdrew ').'KES '.number_format($data['amount'], 2).'.');
    }

    public function transfers(Request $request)
    {
        $transfers = CoopTransfer::query()
            ->withCount('destinations')
            ->when($request->query('rail'), fn ($q, $v) => $q->where('rail', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('account'), fn ($q, $v) => $q->where('source_account', $v))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('message_reference', 'like', "%$term%")
                ->orWhere('narration', 'like', "%$term%")
                ->orWhereHas('destinations', fn ($d) => $d->where('account_number', 'like', "%$term%")->orWhere('mobile_number', 'like', "%$term%"))))
            ->latest('id')->paginate(25)->withQueryString();

        if ($request->boolean('partial')) {
            return view('coop._transfer_rows', compact('transfers'));
        }

        return view('coop.transfers', compact('transfers'));
    }

    public function showTransfer(CoopTransfer $transfer, CoopTransferService $service)
    {
        $transfer->load('destinations', 'attempts');

        return view('coop.transfer', ['transfer' => $transfer, 'status' => $service->statusPayload($transfer)]);
    }

    public function processNow(CoopTransfer $transfer, CoopTransferService $service)
    {
        $service->process($transfer);

        return back()->with('ok', 'Processed.');
    }

    public function resend(CoopTransfer $transfer, CoopTransferService $service)
    {
        if (! $transfer->callback_payload || ! $transfer->callback_url) {
            return back()->with('error', 'This transfer has no callback to resend.');
        }
        $service->resend($transfer);

        return back()->with('ok', 'Callback resent.');
    }

    public function sendForm()
    {
        return view('coop.send', [
            'own' => BankAccount::where('is_own', true)->orderBy('account_number')->get(),
            'recipients' => BankAccount::where('is_own', false)->orderBy('account_name')->get(),
        ]);
    }

    public function send(Request $request, CoopTransferService $service)
    {
        $data = $request->validate([
            'rail' => ['required', 'in:ift,pesalink,mpesa'],
            'source' => ['required', 'string'],
            'account_number' => ['required_unless:rail,mpesa', 'nullable', 'string'],
            'bank_code' => ['required_if:rail,pesalink', 'nullable', 'string'],
            'mobile_number' => ['required_if:rail,mpesa', 'nullable', 'regex:/^254(7|1)\d{8}$/'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:100'],
            'callback_url' => ['required', 'url'],
        ]);

        $ref = 'SIM'.Str::upper(Str::random(9));
        $destination = array_filter([
            'ReferenceNumber' => $ref.'_1',
            'AccountNumber' => $data['rail'] === 'mpesa' ? null : $data['account_number'],
            'BankCode' => $data['rail'] === 'pesalink' ? $data['bank_code'] : null,
            'MobileNumber' => $data['rail'] === 'mpesa' ? $data['mobile_number'] : null,
            'Amount' => (float) $data['amount'],
            'TransactionCurrency' => 'KES',
            'Narration' => $data['narration'] ?? '',
        ], fn ($v) => $v !== null);

        [$status, $body, $transfer] = $service->initiate($data['rail'], [
            'MessageReference' => $ref,
            'UserId' => 'SIMULATOR',
            'ISO2CountryCode' => 'KE',
            'CallBackUrl' => $data['callback_url'],
            'Source' => ['AccountNumber' => $data['source'], 'Amount' => (float) $data['amount'], 'TransactionCurrency' => 'KES', 'Narration' => $data['narration'] ?? ''],
            'Destinations' => [$destination],
        ]);

        if (! $transfer) {
            return back()->withInput()->with('error', "Co-op refused the request (HTTP $status): {$body['MessageCode']} {$body['MessageDescription']}");
        }

        return redirect()->route('coop.transfers.show', $transfer)->with('ok', 'Transfer accepted. The callback will fire shortly.');
    }
}
