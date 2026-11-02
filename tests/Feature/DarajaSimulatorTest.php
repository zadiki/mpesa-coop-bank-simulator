<?php

namespace Tests\Feature;

use App\Models\ForcedOutcome;
use App\Models\Shortcode;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DarajaSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function token(): string
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('sim_consumer_key:sim_consumer_secret'))
            ->getJson('/oauth/v1/generate?grant_type=client_credentials')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'expires_in'])
            ->json('access_token');
    }

    private function b2cPayload(array $overrides = []): array
    {
        return array_merge([
            'OriginatorConversationID' => 'orig-123',
            'InitiatorName' => 'testapi',
            'SecurityCredential' => 'x',
            'CommandID' => 'BusinessPayment',
            'Amount' => 500,
            'PartyA' => '600981',
            'PartyB' => '254708374149',
            'Remarks' => 'Test',
            'QueueTimeOutURL' => 'https://client.test/timeout?id=9',
            'ResultURL' => 'https://client.test/result?id=9',
            'Occasion' => '',
        ], $overrides);
    }

    public function test_oauth_rejects_bad_credentials(): void
    {
        $this->withHeader('Authorization', 'Basic '.base64_encode('nope:nope'))
            ->getJson('/oauth/v1/generate?grant_type=client_credentials')
            ->assertStatus(400)
            ->assertJsonPath('errorCode', '400.008.01');
    }

    public function test_protected_endpoints_require_a_token(): void
    {
        $this->postJson('/mpesa/b2c/v3/paymentrequest', $this->b2cPayload())
            ->assertStatus(401)
            ->assertJsonPath('errorCode', '404.001.03');
    }

    public function test_b2c_success_acknowledges_then_posts_result_and_debits_balance(): void
    {
        Http::fake(['client.test/*' => Http::response(['ok' => true])]);
        $token = $this->token();

        $this->withToken($token)->postJson('/mpesa/b2c/v3/paymentrequest', $this->b2cPayload())
            ->assertOk()
            ->assertJsonPath('ResponseCode', '0')
            ->assertJsonPath('OriginatorConversationID', 'orig-123')
            ->assertJsonStructure(['ConversationID']);

        Http::assertSent(function (Request $request) {
            $result = $request['Result'];
            $params = collect($result['ResultParameters']['ResultParameter'])->pluck('Value', 'Key');

            return $request->url() === 'https://client.test/result?id=9'
                && $result['ResultCode'] === 0
                && $result['OriginatorConversationID'] === 'orig-123'
                && $params['TransactionAmount'] == 500
                && strlen($params['TransactionReceipt']) === 10;
        });

        $this->assertSame('completed', Transaction::first()->status);
        $this->assertEquals(Shortcode::DEFAULT_B2C_BALANCE - 500, (float) Shortcode::where('shortcode', '600981')->value('balance'));
    }

    public function test_b2c_validation_errors_use_daraja_format(): void
    {
        $this->withToken($this->token())
            ->postJson('/mpesa/b2c/v3/paymentrequest', $this->b2cPayload(['Amount' => 5]))
            ->assertStatus(400)
            ->assertJsonPath('errorCode', '400.002.02')
            ->assertJsonPath('errorMessage', 'Bad Request - Invalid Amount');
    }

    public function test_b2c_magic_number_fails_without_debiting(): void
    {
        Http::fake(['client.test/*' => Http::response(['ok' => true])]);

        $this->withToken($this->token())
            ->postJson('/mpesa/b2c/v3/paymentrequest', $this->b2cPayload(['PartyB' => '254700000002']))
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r['Result']['ResultCode'] === 2001 && ! isset($r['Result']['ResultParameters']));
        $this->assertSame('failed', Transaction::first()->status);
        $this->assertEquals(Shortcode::DEFAULT_B2C_BALANCE, (float) Shortcode::where('shortcode', '600981')->value('balance'));
    }

    public function test_b2c_forced_timeout_calls_queue_timeout_url(): void
    {
        Http::fake(['client.test/*' => Http::response(['ok' => true])]);
        ForcedOutcome::create(['type' => 'b2c', 'outcome' => 'timeout']);

        $this->withToken($this->token())->postJson('/mpesa/b2c/v3/paymentrequest', $this->b2cPayload())->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://client.test/timeout?id=9');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/result'));
        $this->assertNotNull(ForcedOutcome::first()->consumed_at);
    }

    public function test_b2c_more_than_balance_is_insufficient_funds(): void
    {
        Http::fake(['client.test/*' => Http::response(['ok' => true])]);
        Shortcode::where('shortcode', '600981')->update(['balance' => 100]);

        $this->withToken($this->token())->postJson('/mpesa/b2c/v3/paymentrequest', $this->b2cPayload())->assertOk();

        Http::assertSent(fn (Request $r) => $r['Result']['ResultCode'] === 1);
    }

    public function test_c2b_register_and_simulate_sends_validation_then_confirmation(): void
    {
        Http::fake([
            'client.test/validate' => Http::response(['ResultCode' => '0', 'ResultDesc' => 'Accepted']),
            'client.test/confirm' => Http::response(['ResultCode' => 0]),
        ]);
        $token = $this->token();

        $this->withToken($token)->postJson('/mpesa/c2b/v1/registerurl', [
            'ShortCode' => '600111',
            'ResponseType' => 'Completed',
            'ConfirmationURL' => 'https://client.test/confirm',
            'ValidationURL' => 'https://client.test/validate',
        ])->assertOk()->assertJsonPath('ResponseDescription', 'Success');
        Shortcode::where('shortcode', '600111')->update(['validation_enabled' => true]);

        $this->withToken($token)->postJson('/mpesa/c2b/v1/simulate', [
            'ShortCode' => '600111',
            'CommandID' => 'CustomerPayBillOnline',
            'Amount' => 250,
            'Msisdn' => '254708374149',
            'BillRefNumber' => 'INV-1',
        ])->assertOk()->assertJsonPath('ResponseCode', '0');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertSame(['https://client.test/validate', 'https://client.test/confirm'], $sent);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://client.test/confirm'
            && $r['TransactionType'] === 'Pay Bill'
            && $r['TransAmount'] === '250.00'
            && $r['BillRefNumber'] === 'INV-1'
            && $r['OrgAccountBalance'] === '250.00'
            && strlen($r['TransID']) === 10);

        $this->assertSame('completed', Transaction::first()->status);
    }

    public function test_c2b_rejected_by_validation_sends_no_confirmation(): void
    {
        Http::fake([
            'client.test/validate' => Http::response(['ResultCode' => 'C2B00012', 'ResultDesc' => 'Invalid Account']),
            'client.test/confirm' => Http::response(['ResultCode' => 0]),
        ]);
        $token = $this->token();

        $this->withToken($token)->postJson('/mpesa/c2b/v1/registerurl', [
            'ShortCode' => '600111', 'ResponseType' => 'Completed',
            'ConfirmationURL' => 'https://client.test/confirm', 'ValidationURL' => 'https://client.test/validate',
        ]);
        Shortcode::where('shortcode', '600111')->update(['validation_enabled' => true]);

        $this->withToken($token)->postJson('/mpesa/c2b/v1/simulate', [
            'ShortCode' => '600111', 'CommandID' => 'CustomerPayBillOnline', 'Amount' => 250, 'Msisdn' => '254708374149',
        ])->assertOk();

        Http::assertNotSent(fn (Request $r) => $r->url() === 'https://client.test/confirm');
        $transaction = Transaction::first();
        $this->assertSame('rejected', $transaction->status);
        $this->assertSame('C2B00012', $transaction->result_code);
        $this->assertEquals(0, (float) Shortcode::where('shortcode', '600111')->value('balance'));
    }

    public function test_ui_pages_render(): void
    {
        foreach (['/', '/transactions', '/c2b', '/b2c', '/settings'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_ui_c2b_payment_and_detail_page(): void
    {
        Http::fake(['*' => Http::response(['ResultCode' => 0])]);

        $this->post('/c2b/pay', [
            'shortcode' => '600000', 'command_id' => 'CustomerPayBillOnline',
            'msisdn' => '254708374149', 'amount' => 75, 'bill_ref_number' => 'ACC-9',
        ])->assertRedirect();

        $transaction = Transaction::first();
        $this->get("/transactions/{$transaction->id}")->assertOk()->assertSee('ACC-9')->assertSee('sim/catch/c2b/confirmation');
        $this->post("/transactions/{$transaction->id}/resend")->assertRedirect();
        $this->assertSame(2, $transaction->attempts()->where('kind', 'confirmation')->count());
    }
}
