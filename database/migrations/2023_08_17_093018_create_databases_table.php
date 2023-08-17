<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100)->nullable()->index();
            $table->string('host')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('databases');
    }
};
