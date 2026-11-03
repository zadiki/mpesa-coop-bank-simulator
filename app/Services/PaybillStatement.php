<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\Daraja;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** What Safaricom's own records show for a shortcode: money paid in by customers (C2B and STK), for statements and the Pull API. */
class PaybillStatement
{
    /** @return Collection<int, Transaction> */
    public function receipts(string $shortcode, Carbon $from, Carbon $to): Collection
    {
        return Transaction::query()
            ->where('shortcode', $shortcode)
            ->whereIn('type', ['c2b', 'stk'])
            ->whereIn('status', ['completed', 'no_callback'])
            ->whereNotNull('receipt')
            ->whereBetween('updated_at', [$from, $to])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();
    }

    public static function details(Transaction $t): string
    {
        $kind = $t->type === 'stk' ? 'Pay Bill Online' : ($t->command_id === 'CustomerBuyGoodsOnline' ? 'Merchant Payment' : 'Pay Bill');

        return "{$kind} from {$t->msisdn} - ".strtoupper((string) $t->customer_name).($t->bill_ref_number ? " Acc. {$t->bill_ref_number}" : '');
    }

    private const HEADINGS = ['Receipt No.', 'Completion Time', 'Initiation Time', 'Details', 'Transaction Status', 'Paid In', 'Withdrawn', 'Balance', 'Balance Confirmed', 'Reason Type', 'Other Party Info', 'Linked Transaction ID', 'A/C No.'];

    /**
     * The statement as the M-Pesa org portal lays it out: a header block, the column headings, then one row per
     * transaction. Times are Kenyan Carbons and amounts floats, for each format to write in its own way.
     *
     * @return array{header: list<array{0: string, 1: string}>, rows: list<list<mixed>>}
     */
    public function lines(string $shortcode, Carbon $from, Carbon $to): array
    {
        $local = fn (Carbon $c) => $c->copy()->setTimezone(Daraja::TIMEZONE);
        $header = [
            ['Account Holder:', 'TAP SIMULATED PAYBILL'],
            ['Short Code:', $shortcode],
            ['Account:', 'Utility Account'],
            ['Time Period:', 'From '.$local($from)->format('d-m-Y H:i:s').' To '.$local($to)->format('d-m-Y H:i:s')],
            ['Operator:', 'simulator'],
            ['Report Date:', $local(now())->format('d-m-Y H:i:s')],
        ];

        $rows = [];
        $balance = 0.0;
        foreach ($this->receipts($shortcode, $from, $to) as $t) {
            $balance += (float) $t->amount;
            $rows[] = [
                $t->receipt,
                $local($t->updated_at),
                $local($t->created_at),
                self::details($t),
                'Completed',
                (float) $t->amount,
                null,
                $balance,
                'true',
                $t->type === 'stk' ? 'Pay Bill Online' : 'Pay Bill',
                "{$t->msisdn} - ".strtoupper((string) $t->customer_name),
                '',
                (string) $t->bill_ref_number,
            ];
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /** As the M-Pesa org portal exports a statement to CSV. */
    public function csv(string $shortcode, Carbon $from, Carbon $to): string
    {
        $statement = $this->lines($shortcode, $from, $to);
        $out = fopen('php://temp', 'r+');

        foreach ($statement['header'] as $line) {
            fputcsv($out, $line);
        }
        fputcsv($out, []);
        fputcsv($out, self::HEADINGS);

        foreach ($statement['rows'] as $row) {
            fputcsv($out, array_map(fn ($cell) => match (true) {
                $cell instanceof Carbon => $cell->format('d-m-Y H:i:s'),
                is_float($cell) => number_format($cell, 2),
                default => (string) $cell,
            }, $row));
        }

        rewind($out);

        return stream_get_contents($out);
    }

    /**
     * As the M-Pesa org portal exports a statement to Excel (.xlsx): the same layout, with the times as date cells
     * and the amounts as numbers, as a spreadsheet keeps them.
     */
    public function xlsx(string $shortcode, Carbon $from, Carbon $to): string
    {
        $statement = $this->lines($shortcode, $from, $to);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Statement');

        $sheet->fromArray($statement['header']);
        $first = count($statement['header']) + 2;
        $sheet->fromArray(self::HEADINGS, null, "A{$first}");
        $sheet->getStyle("A{$first}:M{$first}")->getFont()->setBold(true);

        foreach ($statement['rows'] as $i => $row) {
            $r = $first + 1 + $i;
            foreach ($row as $c => $cell) {
                $at = Coordinate::stringFromColumnIndex($c + 1).$r;
                if ($cell instanceof Carbon) {
                    // Excel keeps the wall-clock time; the portal's is Kenyan.
                    $sheet->setCellValue($at, ExcelDate::PHPToExcel(new \DateTime($cell->format('Y-m-d H:i:s'))));
                    $sheet->getStyle($at)->getNumberFormat()->setFormatCode('dd-mm-yyyy hh:mm:ss');
                } elseif (is_float($cell)) {
                    $sheet->setCellValue($at, $cell);
                    $sheet->getStyle($at)->getNumberFormat()->setFormatCode('#,##0.00');
                } elseif ($cell !== null) {
                    $sheet->setCellValueExplicit($at, (string) $cell, DataType::TYPE_STRING);
                }
            }
        }

        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $out = fopen('php://temp', 'r+');
        IOFactory::createWriter($book, 'Xlsx')->save($out);
        rewind($out);

        return stream_get_contents($out);
    }
}
