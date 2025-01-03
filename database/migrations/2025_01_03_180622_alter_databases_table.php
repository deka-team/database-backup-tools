<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->string('database')->nullable()->after('name');


            $table->after('password', function(Blueprint $table){
                $table->boolean('is_selective')->default(false);
                $table->json('tables')->nullable();
                $table->json('views')->nullable();
            });
        });

        DB::statement(<<<SQL
            UPDATE `databases` SET `database` = `name`;
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn('is_selective', 'tables', 'views');
        });
    }
};
