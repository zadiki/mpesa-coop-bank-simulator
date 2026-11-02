<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL's JSON type re-orders object keys on storage. Payloads are replayed to clients
 * (resends, status enquiries), so they are kept as text to preserve the exact key order.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'transactions' => ['request_payload', 'response_payload', 'callback_payload'],
        'coop_transfers' => ['request_payload', 'response_payload', 'callback_payload'],
        'callback_attempts' => ['request_body'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->longText($column)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->json($column)->nullable()->change();
                }
            });
        }
    }
};
