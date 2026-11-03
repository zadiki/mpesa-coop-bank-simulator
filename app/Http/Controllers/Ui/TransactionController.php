<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\B2CService;
use App\Services\C2BService;
use App\Services\StkService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = Transaction::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($q) => $q->where('receipt', 'like', "%$term%")
                    ->orWhere('msisdn', 'like', "%$term%")
                    ->orWhere('bill_ref_number', 'like', "%$term%")
                    ->orWhere('conversation_id', 'like', "%$term%")
                    ->orWhere('originator_conversation_id', 'like', "%$term%"));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        if ($request->boolean('partial')) {
            return view('transactions._rows', compact('transactions'));
        }

        return view('transactions.index', compact('transactions'));
    }

    public function show(Transaction $transaction)
    {
        return view('transactions.show', ['transaction' => $transaction->load('attempts')]);
    }

    public function resend(Transaction $transaction, B2CService $b2c, C2BService $c2b, StkService $stk)
    {
        if (! $transaction->callback_payload || $transaction->status === 'rejected') {
            return back()->with('error', 'This transaction has no callback to resend.');
        }

        match ($transaction->type) {
            'b2c' => $b2c->resend($transaction),
            'stk' => $stk->resend($transaction),
            default => $c2b->resend($transaction),
        };

        return back()->with('ok', 'Callback resent.');
    }

    /** Skip the remaining delay and run the callback flow right now. */
    public function processNow(Transaction $transaction, B2CService $b2c, C2BService $c2b, StkService $stk)
    {
        match ($transaction->type) {
            'b2c' => $b2c->process($transaction),
            'stk' => $stk->process($transaction),
            default => $c2b->process($transaction),
        };

        return back()->with('ok', 'Processed.');
    }
}
