<?php

namespace App\Support;

/**
 * Catalogue of outcomes the simulator can produce.
 *
 * Outcome precedence for a new transaction:
 *   1. a queued "forced next outcome" (set from the UI or /sim API)
 *   2. a magic phone number (see MAGIC_MSISDNS)
 *   3. real state, e.g. a B2C larger than the shortcode balance -> insufficient_funds
 *   4. success
 */
class Scenario
{
    public const B2C = [
        'success' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Success'],
        'insufficient_funds' => ['code' => 1, 'desc' => 'The balance is insufficient for the transaction.', 'label' => 'Insufficient funds'],
        'invalid_initiator' => ['code' => 2001, 'desc' => 'The initiator information is invalid.', 'label' => 'Invalid initiator / credential'],
        'unregistered_customer' => ['code' => 2040, 'desc' => 'Credit Party customer type (Unregistered or Registered Customer) can\'t be supported by the service.', 'label' => 'Recipient not registered on M-Pesa'],
        'limit_exceeded' => ['code' => 2006, 'desc' => 'Declined due to limit rule: Credit party has reached its account limit.', 'label' => 'Recipient limit exceeded'],
        'timeout' => ['code' => 'SFC_IC0003', 'desc' => 'The service request timed out.', 'label' => 'Timeout (QueueTimeOutURL is called)'],
        'no_callback' => ['code' => null, 'desc' => 'No callback sent.', 'label' => 'No callback at all'],
        'duplicate' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Success, result sent twice'],
        'delayed' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Success, after a long delay'],
    ];

    public const C2B = [
        'success' => ['label' => 'Success (validation, if enabled, decides)'],
        'no_callback' => ['label' => 'No callback at all'],
        'duplicate' => ['label' => 'Confirmation sent twice'],
        'delayed' => ['label' => 'Confirmation after a long delay'],
    ];

    /** STK push (Lipa na M-Pesa Online): what the customer does with the prompt on their phone. */
    public const STK = [
        'success' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Customer enters PIN: paid'],
        'cancelled' => ['code' => 1032, 'desc' => 'Request cancelled by user', 'label' => 'Customer cancels the prompt'],
        'timeout' => ['code' => 1037, 'desc' => 'DS timeout user cannot be reached', 'label' => 'Phone unreachable / no answer'],
        'insufficient_funds' => ['code' => 1, 'desc' => 'The balance is insufficient for the transaction', 'label' => 'Not enough money in M-Pesa'],
        'wrong_pin' => ['code' => 2001, 'desc' => 'The initiator information is invalid.', 'label' => 'Wrong PIN'],
        'no_callback' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Paid, but no callback (query or statement only)'],
        'duplicate' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Paid, callback sent twice'],
        'delayed' => ['code' => 0, 'desc' => 'The service request is processed successfully.', 'label' => 'Paid, callback after a long delay'],
    ];

    /** STK phone numbers that always produce an outcome. */
    public const MAGIC_STK_MSISDNS = [
        '254700000201' => 'cancelled',
        '254700000202' => 'timeout',
        '254700000203' => 'insufficient_funds',
        '254700000204' => 'wrong_pin',
        '254700000205' => 'no_callback',
        '254700000206' => 'duplicate',
        '254700000207' => 'delayed',
    ];

    /** Phone numbers that always trigger a given outcome. */
    public const MAGIC_MSISDNS = [
        '254700000001' => 'insufficient_funds',
        '254700000002' => 'invalid_initiator',
        '254700000003' => 'unregistered_customer',
        '254700000004' => 'timeout',
        '254700000005' => 'no_callback',
        '254700000006' => 'duplicate',
        '254700000007' => 'delayed',
        '254700000008' => 'limit_exceeded',
    ];

    public static function all(string $type): array
    {
        return match ($type) {
            'b2c' => self::B2C,
            'stk' => self::STK,
            'coop' => Coop::SCENARIOS,
            'coop_ipn' => Coop::IPN_SCENARIOS,
            default => self::C2B,
        };
    }

    public static function exists(string $type, string $outcome): bool
    {
        return array_key_exists($outcome, self::all($type));
    }

    public static function fromMsisdn(string $type, ?string $msisdn): ?string
    {
        $outcome = ($type === 'stk' ? self::MAGIC_STK_MSISDNS : self::MAGIC_MSISDNS)[$msisdn] ?? null;

        return $outcome && self::exists($type, $outcome) ? $outcome : null;
    }
}
