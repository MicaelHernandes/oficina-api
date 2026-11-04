<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('os_id')->unique()->constrained('order_services')->cascadeOnDelete();
            $table->string('state', 32)->index();
            $table->string('correlation_id')->nullable()->index();
            $table->jsonb('context')->default('{}');
            $table->jsonb('history')->default('[]');
            $table->text('failure_reason')->nullable();
            $table->timestamp('deadline_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_instances');
    }
};
