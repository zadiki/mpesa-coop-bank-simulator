<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\DarajaApp;
use App\Models\RegisteredUrl;
use App\Models\Shortcode;
use App\Services\Coop\BankLedger;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Idempotent: safe to run on every container start. */
    public function run(): void
    {
        DarajaApp::firstOrCreate(
            ['consumer_key' => config('simulator.default_consumer_key')],
            ['provider' => 'mpesa', 'name' => 'Default', 'consumer_secret' => config('simulator.default_consumer_secret')],
        );

        DarajaApp::firstOrCreate(
            ['consumer_key' => config('simulator.coop_consumer_key')],
            ['provider' => 'coop', 'name' => 'Default Co-op', 'consumer_secret' => config('simulator.coop_consumer_secret')],
        );

        Shortcode::firstOrCreate(['shortcode' => '600000'], ['type' => 'paybill', 'name' => 'Test Paybill', 'balance' => 0]);
        Shortcode::firstOrCreate(['shortcode' => '600981'], ['type' => 'b2c', 'name' => 'Test B2C', 'balance' => Shortcode::DEFAULT_B2C_BALANCE]);

        $base = rtrim(config('app.url'), '/');
        RegisteredUrl::firstOrCreate(['shortcode' => '600000'], [
            'response_type' => 'Completed',
            'confirmation_url' => $base.'/sim/catch/c2b/confirmation',
            'validation_url' => $base.'/sim/catch/c2b/validation',
        ]);

        $this->seedBankAccounts();
    }

    private function seedBankAccounts(): void
    {
        $accounts = [
            // Accounts the API client owns (can be a transfer Source and be queried).
            ['01100000000100', '11', 'TAP DISBURSEMENT ACCOUNT', true, 5_000_000],
            ['01100000000200', '11', 'TAP COLLECTIONS ACCOUNT', true, 0],
            // Recipients, for transfers and PesaLink name checks.
            ['01100000012345', '11', 'JANE WANJIKU ENTERPRISES', false, 0],
            ['1234567890', '1', 'ACME SUPPLIERS LTD', false, 0],       // KCB
            ['0987654321', '68', 'MAMA MBOGA WHOLESALERS', false, 0],  // Equity
        ];

        foreach ($accounts as [$number, $bank, $name, $own, $opening]) {
            $account = BankAccount::firstOrCreate(
                ['bank_code' => $bank, 'account_number' => $number],
                ['account_name' => $name, 'is_own' => $own],
            );

            if ($opening > 0 && $account->wasRecentlyCreated) {
                app(BankLedger::class)->adjust($account, 'C', $opening, 'OPENING BALANCE');
            }
        }

        BankAccount::firstOrCreate(
            ['bank_code' => '11', 'account_number' => '01100000099999'],
            ['account_name' => 'DORMANT TEST ACCOUNT', 'status' => 'dormant'],
        );
    }
}
