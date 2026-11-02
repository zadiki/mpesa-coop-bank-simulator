<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daraja_apps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('consumer_key')->unique();
            $table->string('consumer_secret');
            $table->timestamps();
        });

        Schema::create('access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daraja_app_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('shortcodes', function (Blueprint $table) {
            $table->id();
            $table->string('shortcode')->unique();
            $table->string('type'); // paybill | till | b2c
            $table->string('name')->nullable();
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('validation_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('registered_urls', function (Blueprint $table) {
            $table->id();
            $table->string('shortcode')->unique();
            $table->string('response_type')->default('Completed'); // Completed | Cancelled
            $table->text('confirmation_url');
            $table->text('validation_url')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // c2b | b2c
            $table->string('status')->default('pending');
            $table->string('scenario')->default('success');
            $table->string('command_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('msisdn')->nullable();
            $table->string('shortcode');
            $table->string('bill_ref_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('receipt')->nullable()->unique();
            $table->string('conversation_id')->nullable()->index();
            $table->string('originator_conversation_id')->nullable()->index();
            $table->string('result_code')->nullable();
            $table->string('result_desc')->nullable();
            $table->text('result_url')->nullable();
            $table->text('queue_timeout_url')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('callback_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // validation | confirmation | result | timeout
            $table->text('url');
            $table->json('request_body');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        Schema::create('forced_outcomes', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // c2b | b2c
            $table->string('outcome');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sim_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['sim_settings', 'forced_outcomes', 'callback_attempts', 'transactions', 'registered_urls', 'shortcodes', 'access_tokens', 'daraja_apps'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
