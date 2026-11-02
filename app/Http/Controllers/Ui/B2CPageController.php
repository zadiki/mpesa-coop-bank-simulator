<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Daraja\B2CController;
use App\Models\Shortcode;
use App\Models\Transaction;
use App\Services\B2CService;
use App\Support\Daraja;
use App\Support\Scenario;
use Illuminate\Http\Request;

class B2CPageController extends Controller
{
    public function index()
    {
        return view('b2c', [
            'shortcodes' => Shortcode::where('type', 'b2c')->orderBy('shortcode')->get(),
            'transactions' => Transaction::where('type', 'b2c')->latest('id')->limit(10)->get(),
            'magic' => Scenario::MAGIC_MSISDNS,
            'scenarios' => Scenario::B2C,
        ]);
    }

    public function send(Request $request, B2CService $b2c)
    {
        $data = $request->validate(B2CController::rules(false));
        $data['OriginatorConversationID'] = $data['OriginatorConversationID'] ?: Daraja::originatorConversationId();

        $transaction = $b2c->initiate($data);

        return redirect()->route('transactions.show', $transaction)->with('ok', 'B2C request accepted. The result callback will fire shortly.');
    }
}
