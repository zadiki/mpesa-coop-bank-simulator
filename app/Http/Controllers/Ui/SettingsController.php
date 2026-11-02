<?php

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Models\DarajaApp;
use App\Models\Shortcode;
use App\Models\SimSetting;
use App\Support\Scenario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings', [
            'apps' => DarajaApp::orderBy('provider')->orderBy('id')->get(),
            'shortcodes' => Shortcode::orderBy('shortcode')->get(),
            'delay' => SimSetting::callbackDelay(),
            'coop' => [
                'charge_ift' => SimSetting::get('coop_charge_ift', 0),
                'charge_pesalink' => SimSetting::get('coop_charge_pesalink', 0),
                'charge_mpesa' => SimSetting::get('coop_charge_mpesa', 0),
                'auto_create' => SimSetting::get('coop_auto_create_destinations', '1') === '1',
            ],
            'magic' => Scenario::MAGIC_MSISDNS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'callback_delay_seconds' => ['required', 'integer', 'min:0', 'max:600'],
            'coop_charge_ift' => ['required', 'numeric', 'min:0'],
            'coop_charge_pesalink' => ['required', 'numeric', 'min:0'],
            'coop_charge_mpesa' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data as $key => $value) {
            SimSetting::put($key, $value);
        }
        SimSetting::put('coop_auto_create_destinations', $request->boolean('coop_auto_create_destinations') ? '1' : '0');

        return back()->with('ok', 'Settings saved.');
    }

    public function storeApp(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', 'in:mpesa,coop'],
        ]);

        DarajaApp::create([
            'provider' => $data['provider'],
            'name' => $data['name'],
            'consumer_key' => Str::random(48),
            'consumer_secret' => Str::random(64),
        ]);

        return back()->with('ok', 'App created.');
    }

    public function destroyApp(DarajaApp $app)
    {
        $app->delete();

        return back()->with('ok', 'App deleted.');
    }

    public function saveShortcode(Request $request)
    {
        $data = $request->validate([
            'shortcode' => ['required', 'digits_between:5,7'],
            'type' => ['required', 'in:paybill,till,b2c'],
            'name' => ['nullable', 'string', 'max:100'],
            'balance' => ['required', 'numeric', 'min:0'],
        ]);

        Shortcode::updateOrCreate(['shortcode' => $data['shortcode']], $data);

        return back()->with('ok', "Shortcode {$data['shortcode']} saved.");
    }
}
