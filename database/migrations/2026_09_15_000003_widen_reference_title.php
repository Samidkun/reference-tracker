<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The form and the FormRequest allowed titles up to 500 characters, but
     * the column was the default VARCHAR(255). Anything between 256 and 500
     * characters passed validation and then died inside the database with an
     * unhandled 1062/1406 error. Validation and storage must agree.
     */
    public function up(): void
    {
        Schema::table('references', function (Blueprint $table) {
            $table->string('title', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('references', function (Blueprint $table) {
            $table->string('title', 255)->change();
        });
    }
};
