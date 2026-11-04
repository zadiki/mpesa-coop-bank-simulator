<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co-op collections: money arriving in an own account (a customer's transfer, PesaLink from another bank, M-Pesa
 * paybill-to-bank or a cash deposit), and the payment notification (IPN) the bank sends the account holder for each
 * credit. The IPN address, and the Basic auth it is sent with, are set per account, as Co-op sets them up per account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->text('ipn_url')->nullable()->after('od_limit');
            $table->string('ipn_username')->nullable()->after('ipn_url');
            $table->string('ipn_password')->nullable()->after('ipn_username');
        });

        Schema::create('coop_collections', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // the bank's payment reference, echoed as PaymentRef
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete(); // the own account credited
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete(); // its statement line
            $table->foreignId('coop_transfer_id')->nullable()->constrained()->nullOnDelete(); // when it came from a simulated transfer
            $table->string('channel'); // ift | pesalink | mpesa | branch
            $table->string('payer_name')->nullable();
            $table->string('payer_account')->nullable();
            $table->string('payer_bank_code')->nullable();
            $table->string('payer_phone')->nullable();
            $table->decimal('amount', 17, 2);
            $table->string('narration')->nullable(); // what the payer typed: e.g. the loan number
            $table->string('scenario')->default('success');
            $table->string('ipn_status')->default('pending'); // pending | sent | failed | not_configured | no_ipn
            $table->text('ipn_url')->nullable();
            $table->longText('ipn_payload')->nullable();
            $table->timestamps();
        });

        Schema::table('callback_attempts', function (Blueprint $table) {
            $table->foreignId('coop_collection_id')->nullable()->after('coop_transfer_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('callback_attempts', fn (Blueprint $table) => $table->dropConstrainedForeignId('coop_collection_id'));
        Schema::dropIfExists('coop_collections');
        Schema::table('bank_accounts', fn (Blueprint $table) => $table->dropColumn(['ipn_url', 'ipn_username', 'ipn_password']));
    }
};
