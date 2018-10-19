<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('parent_id')->default(0);
                $table->string('name', 255);
                $table->string('related_model', 255);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unsignedInteger('deleted_at')->nullable();

                // Add indexes
                $table->index('parent_id');
                $table->index('name');
                $table->index('related_model');
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
        if (Schema::hasTable('categories')) {
            // Backup table
            Schema::rename('categories', 'categories_' . Carbon::now()->timestamp);
        }
    }
}
