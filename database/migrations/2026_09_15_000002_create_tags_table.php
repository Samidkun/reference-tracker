<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();

            // a user cannot have the same tag twice
            $table->unique(['user_id', 'name']);
        });

        Schema::create('reference_tag', function (Blueprint $table) {
            $table->foreignId('reference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['reference_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_tag');
        Schema::dropIfExists('tags');
    }
};
