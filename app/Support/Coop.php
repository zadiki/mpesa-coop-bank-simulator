<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Co-op Bank (Co-op Bank Connect / openapi.co-opbank.co.ke) formats, codes and scenarios.
 *
 * Request shapes follow Co-op's SIT Postman collection. That collection has no sample
 * responses, so response and callback shapes follow Co-op's published API docs, and the
 * codes below are a mix of:
 *   - codes seen in Co-op samples: 0, -1, -5, -8
 *   - simulator-defined codes for the other cases (marked "sim")
 * Edit CODES when Co-op shares their full error list; nothing else needs to change.
 */
class Coop
{
    public const CODES = [
        '0' => 'Success',
        '-1' => 'INVALID ACCOUNT NUMBER',
        '-2' => 'INSUFFICIENT FUNDS',                       // sim
        '-3' => 'DUPLICATE MESSAGE REFERENCE',              // sim
        '-4' => 'INVALID REQUEST',                          // sim
        '-5' => 'DEBIT AND CREDIT AMOUNTS DO NOT BALANCE',
        '-6' => 'ACCOUNT INACTIVE',                         // sim: dormant, closed or blocked
        '-7' => 'TRANSACTION LIMIT EXCEEDED',               // sim
        '-8' => 'ACCOUNT AUTHORIZATION FAILURE',
        '-9' => 'MESSAGE REFERENCE NOT FOUND',              // sim
        '-10' => 'TRANSACTION PENDING',                     // sim
        '-11' => 'SERVICE TEMPORARILY UNAVAILABLE',         // sim
        '-12' => 'DESTINATION BANK UNREACHABLE',            // sim
    ];

    /** Async outcomes for a transfer: key => [code, label]. */
    public const SCENARIOS = [
        'success' => ['code' => '0', 'label' => 'Success'],
        'insufficient_funds' => ['code' => '-2', 'label' => 'Insufficient funds (whole transfer)'],
        'invalid_account' => ['code' => '-1', 'label' => 'Invalid destination account'],
        'account_inactive' => ['code' => '-6', 'label' => 'Destination account dormant/closed'],
        'limit_exceeded' => ['code' => '-7', 'label' => 'Transaction limit exceeded'],
        'bank_unreachable' => ['code' => '-12', 'label' => 'Destination bank unreachable'],
        'partial_failure' => ['code' => '0', 'label' => 'First destination succeeds, the rest fail'],
        'no_callback' => ['code' => '0', 'label' => 'Succeeds, but no callback (status check only)'],
        'duplicate_callback' => ['code' => '0', 'label' => 'Succeeds, callback sent twice'],
        'delayed' => ['code' => '0', 'label' => 'Succeeds, callback after a long delay'],
        'service_down' => ['code' => '-11', 'label' => 'Co-op down: request refused with HTTP 503'],
    ];

    /** Destination account numbers / phone numbers that always produce an outcome for that destination. */
    public const MAGIC_DESTINATIONS = [
        '01100000000001' => 'invalid_account',
        '01100000000002' => 'account_inactive',
        '01100000000003' => 'limit_exceeded',
        '01100000000004' => 'bank_unreachable',
        '01100000000005' => 'no_callback',
        '01100000000006' => 'duplicate_callback',
        '01100000000007' => 'delayed',
        '254700000101' => 'invalid_account',
        '254700000103' => 'limit_exceeded',
        '254700000105' => 'no_callback',
        '254700000106' => 'duplicate_callback',
        '254700000107' => 'delayed',
    ];

    /** Outcomes that affect the whole request rather than one destination. */
    public const TRANSFER_LEVEL = ['insufficient_funds', 'partial_failure', 'no_callback', 'duplicate_callback', 'delayed', 'service_down'];

    public static function description(string $code): string
    {
        return self::CODES[$code] ?? 'UNKNOWN';
    }

    public static function messageDateTime(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Bank-side transaction id, e.g. FT26306ABC12D. */
    public static function transactionId(): string
    {
        return 'FT'.now()->format('y').str_pad((string) now()->dayOfYear, 3, '0', STR_PAD_LEFT).Str::upper(Str::random(6));
    }

    public static function envelope(?string $messageReference, string $code, ?string $description = null): array
    {
        return [
            'MessageReference' => $messageReference,
            'MessageDateTime' => self::messageDateTime(),
            'MessageCode' => $code,
            'MessageDescription' => $description ?? self::description($code),
        ];
    }

    public static function error(?string $messageReference, string $code, int $status, ?string $description = null): JsonResponse
    {
        return response()->json(self::envelope($messageReference, $code, $description), $status);
    }

    /** WSO2 API Manager gateway fault, which is what Co-op's gateway returns for bad/expired tokens. */
    public static function gatewayFault(): JsonResponse
    {
        return response()->json(['fault' => [
            'code' => 900901,
            'message' => 'Invalid Credentials',
            'description' => 'Invalid Credentials. Make sure you have provided the correct security credentials',
        ]], 401);
    }

    public static function money(float|string $amount): float
    {
        return round((float) $amount, 2);
    }
}
