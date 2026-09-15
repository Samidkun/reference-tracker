<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('authors');              // array of "Surname, Given"
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('type', 32);           // journal|book|conference|thesis|web
            $table->string('doi')->nullable();
            $table->string('url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->string('cite_key')->nullable();  // explicit key, else derived
            $table->timestamps();

            $table->index(['user_id', 'year']);
            $table->index(['user_id', 'type']);
            // duplicate detection: same DOI for the same user
            $table->unique(['user_id', 'doi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('references');
    }
};
