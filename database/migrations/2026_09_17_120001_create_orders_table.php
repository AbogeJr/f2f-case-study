<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending')->index();

            // Money is stored as integers in the smallest currency unit.
            // The pricing breakdown is persisted so a historical order can always be
            // explained, even if the discount rules change later.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedTinyInteger('discount_percentage')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total');

            // Idempotency: an explicit client key, plus a payload fingerprint used as a
            // fallback for clients that retry without sending a key.
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('fingerprint', 64)->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
