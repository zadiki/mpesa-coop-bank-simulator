<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Generators and response helpers that reproduce Daraja's formats. */
class Daraja
{
    private const FIRST_NAMES = ['John', 'Mary', 'Peter', 'Grace', 'James', 'Faith', 'Brian', 'Mercy', 'Kevin', 'Esther', 'Dennis', 'Wanjiku'];

    private const LAST_NAMES = ['Doe', 'Otieno', 'Kamau', 'Wanjiru', 'Mwangi', 'Achieng', 'Kiprop', 'Njoroge', 'Mutua', 'Odhiambo', 'Chebet', 'Kariuki'];

    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'requestId' => self::requestId(),
            'errorCode' => $code,
            'errorMessage' => $message,
        ], $status);
    }

    public static function requestId(): string
    {
        return random_int(1000, 99999).'-'.random_int(1000000, 99999999).'-1';
    }

    /** e.g. 29115-34620561-1 (what Daraja assigns when the client does not send one). */
    public static function originatorConversationId(): string
    {
        return self::requestId();
    }

    /** e.g. AG_20261102_0000b6fd0d3b8e4a5c71 */
    public static function conversationId(): string
    {
        return 'AG_'.now()->format('Ymd').'_'.Str::lower(Str::random(20));
    }

    /** M-Pesa receipt: 10 uppercase alphanumerics, e.g. TK2H4QX9ZP. */
    public static function receipt(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $receipt = 'T'.chr(ord('A') + now()->month - 1);
        for ($i = 0; $i < 8; $i++) {
            $receipt .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $receipt;
    }

    public static function token(): string
    {
        return Str::random(28);
    }

    /** Deterministic fake customer name per phone number. Returns [first, middle, last]. */
    public static function customerName(string $msisdn): array
    {
        $n = abs(crc32($msisdn));

        return [
            self::FIRST_NAMES[$n % count(self::FIRST_NAMES)],
            '',
            self::LAST_NAMES[intdiv($n, 7) % count(self::LAST_NAMES)],
        ];
    }

    /** Daraja writes its timestamps (TransTime, TransactionCompletedDateTime) in Kenyan time, not UTC. */
    public const TIMEZONE = 'Africa/Nairobi';

    public static function money(float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
