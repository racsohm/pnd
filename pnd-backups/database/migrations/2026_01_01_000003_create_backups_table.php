<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('instance_slug')->index();
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('source')->default('dump');   // dump | upload
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['instance_slug', 'filename']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
