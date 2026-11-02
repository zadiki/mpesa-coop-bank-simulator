@extends('layouts.app')
@section('title', 'Settings')
@section('content')
    <h1>Settings</h1>
    <p class="sub">API credentials, shortcodes, Co-op charges and callback timing.</p>

    <div class="grid g2">
        <div class="card">
            <h2>Callbacks and Co-op</h2>
            <form method="post" action="{{ route('settings.update') }}" class="fields">
                @csrf
                <div class="wide">
                    <label for="delay">Delay before callbacks (seconds)</label>
                    <input id="delay" name="callback_delay_seconds" type="number" min="0" max="600" value="{{ $delay }}">
                </div>
                <div><label for="c_ift">Co-op charge: internal (KES)</label><input id="c_ift" name="coop_charge_ift" type="number" min="0" step="0.01" value="{{ $coop['charge_ift'] }}"></div>
                <div><label for="c_pl">Co-op charge: PesaLink (KES)</label><input id="c_pl" name="coop_charge_pesalink" type="number" min="0" step="0.01" value="{{ $coop['charge_pesalink'] }}"></div>
                <div><label for="c_mp">Co-op charge: M-Pesa (KES)</label><input id="c_mp" name="coop_charge_mpesa" type="number" min="0" step="0.01" value="{{ $coop['charge_mpesa'] }}"></div>
                <div class="wide">
                    <label style="display:flex; gap:8px; align-items:center; font-weight:400; color:var(--text)">
                        <input type="checkbox" name="coop_auto_create_destinations" value="1" style="width:auto" @checked($coop['auto_create'])>
                        Create unknown recipient accounts automatically (off: transfers to them fail with -1 INVALID ACCOUNT NUMBER)
                    </label>
                </div>
                <div><button type="submit">Save</button></div>
            </form>
            <p class="muted" style="margin-bottom:0">The "delayed" scenario waits {{ config('simulator.long_delay_seconds') }}s. Your callback endpoint gets {{ config('simulator.callback_timeout_seconds') }}s to respond.</p>
        </div>

        <div class="card">
            <h2>Add or update a shortcode</h2>
            <form method="post" action="{{ route('settings.shortcodes.save') }}" class="fields">
                @csrf
                <div><label for="s_code">Shortcode</label><input id="s_code" name="shortcode" required></div>
                <div>
                    <label for="s_type">Type</label>
                    <select id="s_type" name="type"><option value="paybill">Paybill</option><option value="till">Till</option><option value="b2c">B2C</option></select>
                </div>
                <div><label for="s_name">Name</label><input id="s_name" name="name"></div>
                <div><label for="s_bal">Balance (KES)</label><input id="s_bal" name="balance" type="number" min="0" step="0.01" value="1000000"></div>
                <div><button type="submit">Save</button></div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="row-between">
            <h2>API apps</h2>
            <form method="post" action="{{ route('settings.apps.store') }}" style="display:flex; gap:8px">
                @csrf
                <select name="provider" style="width:130px"><option value="mpesa">M-Pesa</option><option value="coop">Co-op Bank</option></select>
                <input name="name" placeholder="App name, e.g. tap_be" required style="width:200px">
                <button type="submit">Create app</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Provider</th><th>Name</th><th>Consumer key</th><th>Consumer secret</th><th></th></tr></thead>
                <tbody>
                @foreach ($apps as $a)
                    <tr>
                        <td><span class="badge b-type">{{ $a->provider }}</span></td>
                        <td>{{ $a->name }}</td>
                        <td class="mono">{{ $a->consumer_key }}</td>
                        <td class="mono">{{ $a->consumer_secret }}</td>
                        <td style="text-align:right">
                            <form method="post" action="{{ route('settings.apps.destroy', $a) }}" class="inline" onsubmit="return confirm('Delete this app? Its tokens stop working.')">
                                @csrf @method('delete')<button class="small danger">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Shortcodes</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Shortcode</th><th>Type</th><th>Name</th><th style="text-align:right">Balance (KES)</th><th>C2B validation</th></tr></thead>
                <tbody>
                @forelse ($shortcodes as $s)
                    <tr>
                        <td class="mono">{{ $s->shortcode }}</td><td>{{ $s->type }}</td><td>{{ $s->name }}</td>
                        <td class="num">{{ number_format($s->balance, 2) }}</td>
                        <td>{{ $s->type === 'b2c' ? '—' : ($s->validation_enabled ? 'On' : 'Off') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">Shortcodes are created automatically the first time a client uses one.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
