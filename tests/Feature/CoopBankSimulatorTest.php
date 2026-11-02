<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CoopTransfer;
use App\Models\ForcedOutcome;
use App\Models\SimSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoopBankSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SOURCE = '01100000000100'; // seeded with KES 5,000,000

    private function token(): string
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('coop_consumer_key:coop_consumer_secret'))
            ->post('/token', ['grant_type' => 'client_credentials'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->json('access_token');
    }

    private function ift(array $overrides = [], ?array $destinations = null): array
    {
        $ref = $overrides['MessageReference'] ?? 'TAP-L10482-1';

        return array_merge([
            'MessageReference' => $ref,
            'UserId' => 'TAP',
            'ISO2CountryCode' => 'KE',
            'CallBackUrl' => 'https://tap.test/api/webhooks/coop/payouts',
            'Source' => ['AccountNumber' => self::SOURCE, 'Amount' => 1000, 'TransactionCurrency' => 'KES', 'Narration' => 'TAP L10482'],
            'Destinations' => $destinations ?? [[
                'ReferenceNumber' => $ref.'_1', 'AccountNumber' => '01100000012345',
                'Amount' => 1000, 'TransactionCurrency' => 'KES', 'Narration' => 'LOAN L10482',
            ]],
        ], $overrides);
    }

    private function balance(string $account): float
    {
        return (float) BankAccount::find11($account)->balance;
    }

    public function test_token_and_gateway_fault(): void
    {
        $this->withHeader('Authorization', 'Basic '.base64_encode('coop_consumer_key:wrong'))
            ->post('/token', ['grant_type' => 'client_credentials'])
            ->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        // An M-Pesa token must not open Co-op endpoints.
        $mpesaToken = $this->withHeader('Authorization', 'Basic '.base64_encode('sim_consumer_key:sim_consumer_secret'))
            ->getJson('/oauth/v1/generate?grant_type=client_credentials')->json('access_token');

        $this->withToken($mpesaToken)->postJson('/Enquiry/AccountBalance_v2/2.0.0/', ['MessageReference' => 'x', 'AccountNumber' => self::SOURCE])
            ->assertStatus(401)->assertJsonPath('fault.code', 900901);
    }

    public function test_internal_transfer_moves_money_writes_statements_and_calls_back(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $token = $this->token();

        // Trailing slash exactly as in Co-op's collection.
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift())
            ->assertOk()
            ->assertJsonPath('MessageCode', '0')
            ->assertJsonPath('MessageDescription', 'REQUEST ACCEPTED FOR PROCESSING');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://tap.test/api/webhooks/coop/payouts'
            && $r['MessageReference'] === 'TAP-L10482-1'
            && $r['MessageCode'] === '0'
            && $r['Source']['ResponseCode'] === '0'
            && $r['Destinations'][0]['ResponseCode'] === '0'
            && str_starts_with($r['Destinations'][0]['TransactionID'], 'FT'));

        $this->assertEquals(4_999_000, $this->balance(self::SOURCE));
        $this->assertEquals(1000, $this->balance('01100000012345'));

        $statement = $this->withToken($token)->postJson('/Enquiry/AccountFullStatementPaginated/1.0.0/', [
            'MessageReference' => 'stmt-1', 'UserId' => 'TAP', 'ISO2CountryCode' => 'KE', 'AccountNumber' => self::SOURCE,
            'StartDate' => today()->toDateString(), 'EndDate' => today()->toDateString(),
        ])->assertOk()->json();

        $this->assertSame(2, $statement['TotalRecords']); // opening deposit + payout
        $payout = collect($statement['Transactions'])->firstWhere('TransactionType', 'D');
        $this->assertSame('TAP-L10482-1', $payout['TransactionReference']);
        $this->assertEquals(1000, $payout['DebitAmount']);
        $this->assertEquals(4_999_000, $statement['ClosingBalance']);

        $this->withToken($token)->postJson('/Enquiry/AccountBalance_v2/2.0.0/', ['MessageReference' => 'bal-1', 'UserId' => 'TAP', 'AccountNumber' => self::SOURCE])
            ->assertOk()->assertJsonPath('AvailableBalance', 4999000)->assertJsonPath('AccountName', 'TAP DISBURSEMENT ACCOUNT');

        $this->withToken($token)->postJson('/Enquiry/MiniStatement/Account_v2/2.0.0/', ['MessageReference' => 'mini-1', 'UserId' => 'TAP', 'AccountNumber' => self::SOURCE])
            ->assertOk()->assertJsonCount(2, 'Transactions');
    }

    public function test_insufficient_funds_fails_with_reason_and_moves_nothing(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $token = $this->token();
        BankAccount::find11(self::SOURCE)->update(['blocked_amount' => 4_999_500]); // only 500 available

        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift())->assertOk();

        Http::assertSent(fn (Request $r) => $r['MessageCode'] === '-2'
            && $r['MessageDescription'] === 'INSUFFICIENT FUNDS'
            && $r['Destinations'][0]['ResponseCode'] === '-2');

        $transfer = CoopTransfer::first();
        $this->assertSame('failed', $transfer->status);
        $this->assertEquals(5_000_000, $this->balance(self::SOURCE));

        $this->get('/coop/accounts/'.BankAccount::find11(self::SOURCE)->id)->assertOk()->assertSee('INSUFFICIENT FUNDS');
    }

    public function test_synchronous_rejections(): void
    {
        $token = $this->token();

        // Amounts don't balance
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift(['Source' => ['AccountNumber' => self::SOURCE, 'Amount' => 999, 'TransactionCurrency' => 'KES']]))
            ->assertStatus(400)->assertJsonPath('MessageCode', '-5');

        // Source not owned by the client
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift(['Source' => ['AccountNumber' => '01100000012345', 'Amount' => 1000, 'TransactionCurrency' => 'KES']]))
            ->assertStatus(400)->assertJsonPath('MessageCode', '-8');

        // Missing field
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift(['CallBackUrl' => null]))
            ->assertStatus(400)->assertJsonPath('MessageCode', '-4')->assertJsonPath('MessageDescription', 'INVALID REQUEST: CallBackUrl');

        // Duplicate reference
        Http::fake();
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift())->assertOk();
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift())->assertStatus(400)->assertJsonPath('MessageCode', '-3');

        // Forced outage
        ForcedOutcome::create(['type' => 'coop', 'outcome' => 'service_down']);
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0/', $this->ift(['MessageReference' => 'TAP-L1-2']))
            ->assertStatus(503)->assertJsonPath('MessageCode', '-11');
    }

    public function test_pesalink_partial_failure_with_magic_destination_and_charge(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        SimSetting::put('coop_charge_pesalink', 50);

        $payload = $this->ift(['MessageReference' => 'TAP-PL-1', 'Source' => ['AccountNumber' => self::SOURCE, 'Amount' => 3000, 'TransactionCurrency' => 'KES', 'Narration' => 'PAYOUTS']], [
            ['ReferenceNumber' => 'TAP-PL-1_1', 'AccountNumber' => '1234567890', 'BankCode' => '01', 'Amount' => 2000, 'TransactionCurrency' => 'KES'],
            ['ReferenceNumber' => 'TAP-PL-1_2', 'AccountNumber' => '01100000000002', 'BankCode' => '11', 'Amount' => 1000, 'TransactionCurrency' => 'KES'],
        ]);

        $this->withToken($this->token())->postJson('/FundsTransfer/External/A2A/PesaLink_v4/4.0.0/', $payload)->assertOk();

        Http::assertSent(fn (Request $r) => $r['MessageDescription'] === 'PARTIALLY SUCCESSFUL'
            && $r['Destinations'][0]['ResponseCode'] === '0'
            && $r['Destinations'][0]['BankCode'] === '1'
            && $r['Destinations'][1]['ResponseCode'] === '-6');

        $this->assertSame('partial', CoopTransfer::first()->status);
        $this->assertEquals(5_000_000 - 2000 - 50, $this->balance(self::SOURCE));
        $this->assertEquals(2000, (float) BankAccount::where('bank_code', '1')->where('account_number', '1234567890')->value('balance'));
    }

    public function test_no_callback_scenario_is_answered_by_transaction_status(): void
    {
        Http::fake();
        ForcedOutcome::create(['type' => 'coop', 'outcome' => 'no_callback']);
        $token = $this->token();

        $this->withToken($token)->postJson('/FundsTransfer/External/A2M/Mpesa_v2/2.0.0/', $this->ift(['MessageReference' => 'TAP-F9'], [
            ['ReferenceNumber' => 'TAP-F9_1', 'MobileNumber' => '254708374149', 'Amount' => '1000', 'Narration' => 'REFUND'],
        ]))->assertOk();

        Http::assertNothingSent();

        $this->withToken($token)->postJson('/Enquiry/TransactionStatus/2.0.0/', ['MessageReference' => 'TAP-F9', 'UserId' => 'TAP'])
            ->assertOk()
            ->assertJsonPath('MessageCode', '0')
            ->assertJsonPath('Destinations.0.MobileNumber', '254708374149')
            ->assertJsonPath('Destinations.0.ResponseCode', '0');

        $this->withToken($token)->postJson('/Enquiry/TransactionStatus/2.0.0/', ['MessageReference' => 'nope'])
            ->assertStatus(404)->assertJsonPath('MessageCode', '-9');
    }

    public function test_pesalink_validation_returns_account_name(): void
    {
        $token = $this->token();

        $this->withToken($token)->postJson('/Enquiry/Validation/IPSL/1.0.0/', ['MessageReference' => 'v1', 'AccountNumber' => '0987654321', 'RecipientBankIdentifier' => '0068'])
            ->assertOk()->assertJsonPath('AccountName', 'MAMA MBOGA WHOLESALERS');

        $this->withToken($token)->postJson('/Enquiry/Validation/IPSL/1.0.0/', ['MessageReference' => 'v2', 'AccountNumber' => '000', 'RecipientBankIdentifier' => '0068'])
            ->assertStatus(400)->assertJsonPath('MessageCode', '-1');

        $this->withToken($token)->postJson('/Enquiry/Validation/IPSL/1.0.0/', ['MessageReference' => 'v3', 'AccountNumber' => '01100000099999', 'RecipientBankIdentifier' => '0011'])
            ->assertStatus(400)->assertJsonPath('MessageCode', '-6');
    }

    public function test_ui_pages_and_send_form(): void
    {
        Http::fake();
        $source = BankAccount::find11(self::SOURCE);

        foreach (['/coop', '/coop/transfers', '/coop/send', "/coop/accounts/{$source->id}", '/', '/settings'] as $page) {
            $this->get($page)->assertOk();
        }

        $this->post('/coop/send', [
            'rail' => 'ift', 'source' => self::SOURCE, 'account_number' => '01100000012345',
            'amount' => 250, 'narration' => 'UI TEST', 'callback_url' => 'https://tap.test/cb',
        ])->assertRedirect();

        $transfer = CoopTransfer::first();
        $this->get("/coop/transfers/{$transfer->id}")->assertOk()->assertSee($transfer->message_reference);

        $this->post("/coop/accounts/{$source->id}/adjust", ['type' => 'C', 'amount' => 100, 'narration' => 'top up'])->assertRedirect();
        $this->assertEquals(5_000_000 - 250 + 100, $this->balance(self::SOURCE));
    }
}
