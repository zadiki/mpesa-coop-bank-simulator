<?php

namespace Tests\Feature;

use App\Models\Shortcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Account Balance and B2B, called with the organisation's initiator; results to the ResultURL. */
class OrgRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function token(): string
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('sim_consumer_key:sim_consumer_secret'))
            ->getJson('/oauth/v1/generate?grant_type=client_credentials')->json('access_token');
    }

    private function b2b(string $amount, string $to = '600999'): array
    {
        return [
            'Initiator' => 'testapi', 'SecurityCredential' => 'cred', 'CommandID' => 'BusinessPayBill',
            'SenderIdentifierType' => '4', 'RecieverIdentifierType' => '4', 'Amount' => $amount,
            'PartyA' => '600000', 'PartyB' => $to, 'AccountReference' => '0110012345670', 'Remarks' => 'Sweep',
            'QueueTimeOutURL' => 'https://tap.test/timeout', 'ResultURL' => 'https://tap.test/result',
        ];
    }

    public function test_the_balance_is_the_shortcodes_utility_account(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        Shortcode::resolve('600000', 'paybill')->update(['balance' => 12500]);

        $conversation = $this->withToken($this->token())->postJson('/mpesa/accountbalance/v1/query', [
            'Initiator' => 'testapi', 'SecurityCredential' => 'cred', 'CommandID' => 'AccountBalance', 'PartyA' => '600000',
            'IdentifierType' => '4', 'Remarks' => 'Balance', 'QueueTimeOutURL' => 'https://tap.test/timeout', 'ResultURL' => 'https://tap.test/result',
        ])->assertOk()->assertJsonPath('ResponseCode', '0')->json('ConversationID');

        Http::assertSent(fn (Request $r) => $r['Result']['ConversationID'] === $conversation && $r['Result']['ResultCode'] === 0
            && str_contains($r['Result']['ResultParameters']['ResultParameter'][0]['Value'], 'Utility Account|KES|12500.00|12500.00'));
    }

    public function test_b2b_moves_money_between_shortcodes_and_fails_without_enough(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        Shortcode::resolve('600000', 'paybill')->update(['balance' => 10000]);
        Shortcode::resolve('600999', 'b2c')->update(['balance' => 0]);
        $token = $this->token();

        $this->withToken($token)->postJson('/mpesa/b2b/v1/paymentrequest', $this->b2b('8000'))->assertOk()->assertJsonPath('ResponseCode', '0');
        $this->assertSame(['2000.00', '8000.00'], [(string) Shortcode::where('shortcode', '600000')->value('balance'), (string) Shortcode::where('shortcode', '600999')->value('balance')]);
        Http::assertSent(fn (Request $r) => $r['Result']['ResultCode'] === 0 && strlen($r['Result']['TransactionID']) === 10);

        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $this->withToken($token)->postJson('/mpesa/b2b/v1/paymentrequest', $this->b2b('5000'))->assertOk();
        Http::assertSent(fn (Request $r) => $r['Result']['ResultCode'] === 1);

        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $this->withToken($token)->postJson('/mpesa/b2b/v1/paymentrequest', $this->b2b('1111'))->assertOk();
        Http::assertSent(fn (Request $r) => $r['Result']['ResultCode'] === 2001);
    }
}
