<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CoopCollection;
use App\Models\ForcedOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Co-op collections: money received into an own account, and the payment notification (IPN) sent for it.
 */
class CoopCollectionsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const COLLECTIONS = '01100000000200'; // own, KES 0

    private const DISBURSEMENT = '01100000000100'; // own, KES 5,000,000

    private const CUSTOMER = '01100000012345'; // a recipient account

    private function collections(): BankAccount
    {
        return BankAccount::find11(self::COLLECTIONS);
    }

    private function withIpn(?string $user = null, ?string $password = null): BankAccount
    {
        $account = $this->collections();
        $this->put("/coop/accounts/{$account->id}/ipn", ['ipn_url' => 'https://tap.test/coop/ipn', 'ipn_username' => $user, 'ipn_password' => $password])->assertRedirect();

        return $account->refresh();
    }

    private function receive(array $data): CoopCollection
    {
        $this->post('/coop/collections', ['account' => $this->collections()->id, 'amount' => 1500, 'narration' => 'TL000123', ...$data])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');

        return CoopCollection::latest('id')->firstOrFail();
    }

    public function test_money_paid_in_credits_the_account_and_the_ipn_tells_the_app(): void
    {
        Http::fake(['tap.test/*' => Http::response(['status' => 'ok'])]);
        $this->withIpn('tap', 's3cret');

        $collection = $this->receive(['channel' => 'mpesa', 'payer_phone' => '254708374149', 'payer_name' => 'Jane Wanjiku']);

        $this->assertEquals(1500, (float) $this->collections()->refresh()->balance);
        $line = $this->collections()->lines()->sole();
        $this->assertSame(['C', 'MPESA'], [$line->type, $line->service_point]);
        $this->assertSame('sent', $collection->refresh()->ipn_status);
        $this->assertCount(1, $collection->attempts);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://tap.test/coop/ipn'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('tap:s3cret'))
            && $r['AcctNo'] === self::COLLECTIONS
            && $r['Amount'] == 1500
            && $r['EventType'] === 'CREDIT'
            && $r['PaymentRef'] === 'TL000123'
            && $r['CustMemoLine1'] === 'JANE WANJIKU'
            && $r['CustMemoLine2'] === '254708374149'
            && $r['TransactionId'] === $line->transaction_id
            && $r['BookedBalance'] == 1500);

        $this->get('/coop/collections')->assertOk()->assertSee($collection->reference);
        $this->get("/coop/collections/{$collection->id}")->assertOk()->assertSee('TL000123')->assertSee('HTTP 200');
    }

    public function test_from_a_simulated_co_op_account_the_money_leaves_it_and_too_little_is_refused(): void
    {
        Http::fake();
        $customer = BankAccount::find11(self::CUSTOMER);
        $customer->update(['balance' => 1000]);

        $this->post('/coop/collections', ['account' => $this->collections()->id, 'channel' => 'ift', 'payer_account' => self::CUSTOMER, 'amount' => 1500])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, CoopCollection::count());

        $collection = $this->receive(['channel' => 'ift', 'payer_account' => self::CUSTOMER, 'amount' => 600]);

        $this->assertEquals(400, (float) $customer->refresh()->balance);
        $this->assertEquals(600, (float) $this->collections()->refresh()->balance);
        $this->assertSame($customer->account_name, $collection->payer_name);
        // No IPN address on the account: nothing is sent, and the page says how to fix it.
        $this->assertSame('not_configured', $collection->refresh()->ipn_status);
        Http::assertNothingSent();
        $this->get("/coop/collections/{$collection->id}")->assertOk()->assertSee('has no IPN address');
    }

    public function test_forced_ipn_outcomes_and_resending(): void
    {
        Http::fake(['tap.test/*' => Http::sequence()->push('ok')->push('ok')->push('ok')->push('down', 500)]);
        $this->withIpn();

        ForcedOutcome::create(['type' => 'coop_ipn', 'outcome' => 'no_ipn']);
        $silent = $this->receive(['channel' => 'branch']);
        $this->assertSame('no_ipn', $silent->refresh()->ipn_status);
        Http::assertSentCount(0);

        ForcedOutcome::create(['type' => 'coop_ipn', 'outcome' => 'duplicate_ipn']);
        $twice = $this->receive(['channel' => 'pesalink', 'payer_account' => '0987654321', 'payer_bank_code' => '68']);
        $this->assertCount(2, $twice->attempts);
        $this->assertSame('68', $twice->payer_bank_code);
        Http::assertSentCount(2);

        // Resend: the app now answers 500, so it shows failed.
        $this->post("/coop/collections/{$twice->id}/resend")->assertRedirect();
        $this->post("/coop/collections/{$twice->id}/resend")->assertRedirect();
        $this->assertSame('failed', $twice->refresh()->ipn_status);
        $this->assertCount(4, $twice->attempts()->get());
    }

    public function test_a_transfer_into_an_own_account_is_notified_as_a_collection(): void
    {
        Http::fake(['tap.test/*' => Http::response('ok')]);
        $this->withIpn();
        $token = $this->withHeader('Authorization', 'Basic '.base64_encode('coop_consumer_key:coop_consumer_secret'))
            ->post('/token', ['grant_type' => 'client_credentials'])->json('access_token');

        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0', [
            'MessageReference' => 'TAP-SWEEP-1', 'UserId' => 'TAP', 'ISO2CountryCode' => 'KE', 'CallBackUrl' => 'https://tap.test/coop/callback',
            'Source' => ['AccountNumber' => self::DISBURSEMENT, 'Amount' => 2500, 'TransactionCurrency' => 'KES', 'Narration' => 'FLOAT'],
            'Destinations' => [['ReferenceNumber' => 'TAP-SWEEP-1_1', 'AccountNumber' => self::COLLECTIONS, 'Amount' => 2500, 'TransactionCurrency' => 'KES', 'Narration' => 'FLOAT']],
        ])->assertOk();

        $collection = CoopCollection::sole();
        $this->assertSame(['ift', self::DISBURSEMENT, 'FLOAT', 'sent'], [$collection->channel, $collection->payer_account, $collection->narration, $collection->ipn_status]);
        $this->assertNotNull($collection->coop_transfer_id);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://tap.test/coop/ipn' && $r['Amount'] == 2500);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://tap.test/coop/callback');

        // A transfer to a recipient account (not own) is no collection.
        $this->withToken($token)->postJson('/FundsTransfer/Internal/A2A_v3/3.0.0', [
            'MessageReference' => 'TAP-PAY-2', 'UserId' => 'TAP', 'ISO2CountryCode' => 'KE', 'CallBackUrl' => 'https://tap.test/coop/callback',
            'Source' => ['AccountNumber' => self::DISBURSEMENT, 'Amount' => 100, 'TransactionCurrency' => 'KES', 'Narration' => 'LOAN'],
            'Destinations' => [['ReferenceNumber' => 'TAP-PAY-2_1', 'AccountNumber' => self::CUSTOMER, 'Amount' => 100, 'TransactionCurrency' => 'KES', 'Narration' => 'LOAN']],
        ])->assertOk();
        $this->assertSame(1, CoopCollection::count());
    }

    public function test_the_guide_and_the_pages_explain_how_to_test(): void
    {
        $account = $this->collections();

        $this->get('/coop/guide')->assertOk()
            ->assertSee('Disbursement by internal transfer (IFT)')
            ->assertSee('Collections and payment notifications (IPN)')
            ->assertSee('coop_consumer_key')
            ->assertSee(self::COLLECTIONS);
        $this->get('/coop/collections')->assertOk()->assertSee('Receive money')->assertSee('Force the next collection');
        $this->get("/coop/accounts/{$account->id}")->assertOk()->assertSee('Payment notifications (IPN)');
        $this->get('/')->assertOk()->assertSee('COOP IPN');

        // Clearing the address turns notifications off; an empty password keeps the saved one.
        $this->withIpn('tap', 'pw');
        $this->put("/coop/accounts/{$account->id}/ipn", ['ipn_url' => 'https://tap.test/v2', 'ipn_username' => 'tap', 'ipn_password' => ''])->assertRedirect();
        $this->assertSame(['https://tap.test/v2', 'pw'], [$account->refresh()->ipn_url, $account->ipn_password]);
    }
}
