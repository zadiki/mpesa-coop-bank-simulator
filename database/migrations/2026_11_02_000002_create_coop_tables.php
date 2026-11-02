<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One credentials table for both simulated providers.
        Schema::table('daraja_apps', function (Blueprint $table) {
            $table->string('provider')->default('mpesa')->after('id')->index(); // mpesa | coop
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number');
            $table->string('bank_code')->default('11'); // 11 = Co-operative Bank
            $table->string('account_name');
            $table->boolean('is_own')->default(false); // owned by the API client: may be a transfer Source and be queried
            $table->string('status')->default('active'); // active | dormant | closed | blocked
            $table->string('currency', 3)->default('KES');
            $table->string('product_name')->default('CURRENT ACCOUNT');
            $table->string('branch_name')->default('HEAD OFFICE');
            $table->string('branch_sort_code')->default('11000');
            $table->decimal('balance', 17, 2)->default(0);       // booked balance
            $table->decimal('blocked_amount', 17, 2)->default(0); // funds on hold
            $table->decimal('od_limit', 17, 2)->default(0);
            $table->timestamps();
            $table->unique(['bank_code', 'account_number']);
        });

        Schema::create('coop_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('message_reference')->unique();
            $table->string('rail'); // ift | pesalink | mpesa
            $table->string('status')->default('pending'); // pending | success | partial | failed | no_callback
            $table->string('scenario')->default('success');
            $table->string('source_account');
            $table->decimal('amount', 17, 2);
            $table->decimal('charge', 17, 2)->default(0);
            $table->string('currency', 3)->default('KES');
            $table->string('narration')->nullable();
            $table->text('callback_url')->nullable();
            $table->string('message_code')->nullable();
            $table->string('message_description')->nullable();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coop_transfer_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coop_transfer_id')->constrained()->cascadeOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('mobile_number')->nullable();
            $table->decimal('amount', 17, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('narration')->nullable();
            $table->string('scenario')->default('success');
            $table->string('transaction_id')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            $table->timestamps();
        });

        // Ledger: every movement on a bank account. Statements are read from here.
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coop_transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->char('type', 1); // C credit | D debit
            $table->decimal('amount', 17, 2);
            $table->decimal('running_balance', 17, 2);
            $table->string('narration');
            $table->string('transaction_reference')->nullable()->index();
            $table->string('transaction_id')->unique();
            $table->string('service_point')->default('API');
            $table->timestamp('transaction_date');
            $table->date('value_date');
            $table->timestamps();
            $table->index(['bank_account_id', 'transaction_date']);
        });

        Schema::table('callback_attempts', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->change();
            $table->foreignId('coop_transfer_id')->nullable()->after('transaction_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('callback_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coop_transfer_id');
        });
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('coop_transfer_destinations');
        Schema::dropIfExists('coop_transfers');
        Schema::dropIfExists('bank_accounts');
        Schema::table('daraja_apps', fn (Blueprint $table) => $table->dropColumn('provider'));
    }
};
