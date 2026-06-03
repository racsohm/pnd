<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instance_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('instance_slug');
            $table->boolean('can_manage_users')->default(false);
            $table->boolean('can_generate_backups')->default(false);
            $table->boolean('can_view_stats')->default(true);
            $table->boolean('can_download_reports')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'instance_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_permissions');
    }
};
