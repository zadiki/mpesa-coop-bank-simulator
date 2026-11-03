<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;

class StkAndStatementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function token(): string
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('sim_consumer_key:sim_consumer_secret'))
            ->getJson('/oauth/v1/generate?grant_type=client_credentials')->json('access_token');
    }

    private function push(string $phone, string $amount = '500'): array
    {
        $timestamp = now()->format('YmdHis');

        return [
            'BusinessShortCode' => '600000',
            'Password' => base64_encode('600000'.'passkey'.$timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => '600000',
            'PhoneNumber' => $phone,
            'CallBackURL' => 'https://tap.test/stk',
            'AccountReference' => 'TL000123',
            'TransactionDesc' => 'Loan repay',
        ];
    }

    public function test_a_paid_prompt_calls_back_with_the_receipt_and_the_query_agrees(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $token = $this->token();

        $checkout = $this->withToken($token)->postJson('/mpesa/stkpush/v1/processrequest', $this->push('254708374149'))
            ->assertOk()->assertJsonPath('ResponseCode', '0')->json('CheckoutRequestID');

        Http::assertSent(function (Request $r) use ($checkout) {
            $cb = $r['Body']['stkCallback'];
            $items = collect($cb['CallbackMetadata']['Item'])->pluck('Value', 'Name');

            return $cb['CheckoutRequestID'] === $checkout && $cb['ResultCode'] === 0
                && strlen($items['MpesaReceiptNumber']) === 10 && $items['Amount'] == 500 && $items['PhoneNumber'] === 254708374149;
        });

        $timestamp = now()->format('YmdHis');
        $this->withToken($token)->postJson('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => '600000', 'Password' => base64_encode('600000pk'.$timestamp), 'Timestamp' => $timestamp, 'CheckoutRequestID' => $checkout,
        ])->assertOk()->assertJsonPath('ResultCode', '0');
    }

    public function test_a_cancelled_prompt_has_no_receipt(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $this->withToken($this->token())->postJson('/mpesa/stkpush/v1/processrequest', $this->push('254700000201'))->assertOk();

        Http::assertSent(fn (Request $r) => $r['Body']['stkCallback']['ResultCode'] === 1032 && ! isset($r['Body']['stkCallback']['CallbackMetadata']));
        $this->assertNull(Transaction::first()->receipt);
    }

    public function test_a_bad_password_is_refused(): void
    {
        $body = $this->push('254708374149');
        $body['Password'] = base64_encode('nonsense');

        $this->withToken($this->token())->postJson('/mpesa/stkpush/v1/processrequest', $body)
            ->assertStatus(400)->assertJsonPath('errorMessage', 'Bad Request - Invalid Password');
    }

    public function test_paid_prompts_and_c2b_payments_are_on_the_statement_and_the_pull_api_but_unpaid_ones_are_not(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $token = $this->token();
        $this->withToken($token)->postJson('/mpesa/stkpush/v1/processrequest', $this->push('254708374149', '700'));
        $this->withToken($token)->postJson('/mpesa/stkpush/v1/processrequest', $this->push('254700000205', '300')); // paid, no callback
        $this->withToken($token)->postJson('/mpesa/stkpush/v1/processrequest', $this->push('254700000201', '900')); // cancelled
        $this->withToken($token)->postJson('/mpesa/c2b/v1/simulate', ['ShortCode' => '600000', 'CommandID' => 'CustomerPayBillOnline', 'Amount' => 120, 'Msisdn' => '254708374149', 'BillRefNumber' => 'TL000123']);

        $today = now('Africa/Nairobi')->toDateString();
        $csv = $this->get("/c2b/statement?shortcode=600000&from={$today}&to={$today}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/"?Receipt No\."?,"?Completion Time"?/', $csv);
        $this->assertSame(3, substr_count($csv, ',Completed,'));
        $this->assertStringNotContainsString('900.00', $csv);

        $rows = $this->withToken($token)->postJson('/pulltransactions/v1/query', [
            'ShortCode' => '600000', 'StartDate' => "{$today} 00:00:00", 'EndDate' => "{$today} 23:59:59", 'OffSetValue' => '0',
        ])->assertOk()->assertJsonPath('ResponseCode', '1000')->json('Response.0');

        $this->assertEqualsCanonicalizing(['700.0', '300.0', '120.0'], array_column($rows, 'amount'));
        $this->assertSame('TL000123', $rows[0]['billreference']);
    }

    public function test_the_statement_downloads_as_excel_in_the_portals_layout(): void
    {
        Http::fake(['tap.test/*' => Http::response(['ok' => true])]);
        $token = $this->token();
        $this->withToken($token)->postJson('/mpesa/c2b/v1/simulate', ['ShortCode' => '600000', 'CommandID' => 'CustomerPayBillOnline', 'Amount' => 1200.5, 'Msisdn' => '254708374149', 'BillRefNumber' => 'TL000123']);
        $paid = Transaction::query()->where('type', 'c2b')->sole();

        $today = now('Africa/Nairobi')->toDateString();
        $response = $this->get("/c2b/statement?shortcode=600000&from={$today}&to={$today}&format=xlsx")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("ORG_600000_Statement_{$today}_{$today}.xlsx", $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getSheet(0);

        $this->assertSame('600000', (string) $sheet->getCell('B2')->getValue());
        $this->assertSame('Receipt No.', $sheet->getCell('A8')->getValue());
        $this->assertSame('A/C No.', $sheet->getCell('M8')->getValue());
        $this->assertSame($paid->receipt, $sheet->getCell('A9')->getValue());
        // Amounts are numbers and times are date cells, in Kenyan time.
        $this->assertSame(1200.5, (float) $sheet->getCell('F9')->getValue());
        $this->assertTrue(ExcelDate::isDateTime($sheet->getCell('B9')));
        $this->assertSame(
            $paid->updated_at->copy()->setTimezone('Africa/Nairobi')->format('d-m-Y H:i:s'),
            ExcelDate::excelToDateTimeObject($sheet->getCell('B9')->getValue())->format('d-m-Y H:i:s'),
        );
        $this->assertSame('TL000123', $sheet->getCell('M9')->getValue());
        $this->assertNull($sheet->getCell('A10')->getValue());

        $this->get("/c2b/statement?shortcode=600000&from={$today}&to={$today}&format=pdf")->assertSessionHasErrors('format');
    }
}
