<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\RegisteredUrl;
use App\Models\Shortcode;
use App\Models\Transaction;
use App\Services\C2BService;
use Illuminate\Http\Request;

class C2BPageController extends Controller
{
    public function __construct(private C2BService $c2b) {}

    public function index()
    {
        return view('c2b', [
            'registrations' => RegisteredUrl::orderBy('shortcode')->get(),
            'shortcodes' => Shortcode::whereIn('type', ['paybill', 'till'])->orderBy('shortcode')->get()->keyBy('shortcode'),
            'transactions' => Transaction::where('type', 'c2b')->latest('id')->limit(10)->get(),
        ]);
    }

    public function pay(Request $request)
    {
        $data = $request->validate([
            'shortcode' => ['required', 'digits_between:5,7'],
            'command_id' => ['required', 'in:CustomerPayBillOnline,CustomerBuyGoodsOnline'],
            'msisdn' => ['required', 'regex:/^254(7|1)\d{8}$/'],
            'amount' => ['required', 'numeric', 'min:1'],
            'bill_ref_number' => ['nullable', 'string', 'max:20'],
        ]);

        $transaction = $this->c2b->pay($data['shortcode'], $data['command_id'], $data['amount'], $data['msisdn'], $data['bill_ref_number'] ?? null);

        return redirect()->route('transactions.show', $transaction)->with('ok', 'Payment received. Callbacks will fire shortly.');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'ShortCode' => ['required', 'digits_between:5,7'],
            'ResponseType' => ['required', 'in:Completed,Cancelled'],
            'ConfirmationURL' => ['required', 'url'],
            'ValidationURL' => ['nullable', 'url'],
        ]);

        $this->c2b->register($data);

        return back()->with('ok', "URLs saved for {$data['ShortCode']}.");
    }

    public function toggleValidation(Shortcode $shortcode)
    {
        $shortcode->update(['validation_enabled' => ! $shortcode->validation_enabled]);

        return back()->with('ok', "Validation ".($shortcode->validation_enabled ? 'enabled' : 'disabled')." for {$shortcode->shortcode}.");
    }
}
