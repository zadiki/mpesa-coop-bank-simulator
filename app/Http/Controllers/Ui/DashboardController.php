<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\CallbackAttempt;
use App\Models\CoopTransfer;
use App\Models\DarajaApp;
use App\Models\ForcedOutcome;
use App\Models\Shortcode;
use App\Models\Transaction;
use App\Support\Scenario;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $recent = Transaction::latest('id')->limit(15)->get();

        if ($request->boolean('partial')) {
            return view('transactions._rows', ['transactions' => $recent]);
        }

        $today = Transaction::whereDate('created_at', today());

        return view('dashboard', [
            'transactions' => $recent,
            'stats' => [
                'C2B today' => (clone $today)->where('type', 'c2b')->count(),
                'B2C today' => (clone $today)->where('type', 'b2c')->count(),
                'Co-op transfers today' => CoopTransfer::whereDate('created_at', today())->count(),
                'Pending' => Transaction::where('status', 'pending')->count() + CoopTransfer::where('status', 'pending')->count(),
                'Failed callbacks' => CallbackAttempt::where(fn ($q) => $q->whereNull('http_status')->orWhere('http_status', '>=', 300))->whereDate('created_at', today())->count(),
            ],
            'shortcodes' => Shortcode::orderBy('shortcode')->get(),
            'forced' => ForcedOutcome::whereNull('consumed_at')->orderBy('id')->get(),
            'app' => DarajaApp::where('provider', 'mpesa')->first(),
            'coopApp' => DarajaApp::where('provider', 'coop')->first(),
            'scenarios' => ['b2c' => Scenario::B2C, 'c2b' => Scenario::C2B, 'stk' => Scenario::STK, 'coop' => Scenario::all('coop'), 'coop_ipn' => Scenario::all('coop_ipn')],
        ]);
    }

    public function force(Request $request)
    {
        $request->validate(['scenario' => ['required', 'regex:/^(c2b|b2c|stk|coop|coop_ipn):[a-z_]+$/']]);
        [$type, $outcome] = explode(':', $request->input('scenario'));
        abort_unless(Scenario::exists($type, $outcome), 422, 'Unknown outcome');
        $data = ['type' => $type, 'outcome' => $outcome];

        ForcedOutcome::create($data);

        $what = $data['type'] === 'coop_ipn' ? 'Co-op collection' : strtoupper($data['type']).' transaction';

        return back()->with('ok', "The next {$what} will be: ".Scenario::all($data['type'])[$data['outcome']]['label']);
    }

    public function unforce(ForcedOutcome $forcedOutcome)
    {
        $forcedOutcome->delete();

        return back()->with('ok', 'Forced outcome removed.');
    }
}
