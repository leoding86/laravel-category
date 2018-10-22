<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddCategoryRelationships extends Migration
{
    private static $tablename = 'category_relationships';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable(static::$tablename)) {
            throw new \Exception('Table \'' . static::$tablename . '\' has exists.');
        } else {
            Schema::create(static::$tablename, function (Blueprint $table) {
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('parents_category_id');

                // Add indexes
                $table->index('category_id');
                $table->index('parents_category_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable(static::$tablename)) {
            // Change comment
            Schema::table(static::$tablename, function (Blueprint $table) {
                $table->comment('Backup table for ' . static::$tablename);
            });

            // Backup table
            Schema::rename(static::$tablename, static::$tablename . '_' . Carbon::now()->timestamp);
        }
    }
}
