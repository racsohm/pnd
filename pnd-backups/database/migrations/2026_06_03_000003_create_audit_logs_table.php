<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('action');
            $table->string('instance_slug')->nullable();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('target_name')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('action');
            $table->index('instance_slug');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
