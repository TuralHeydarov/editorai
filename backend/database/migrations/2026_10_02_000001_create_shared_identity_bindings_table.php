<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_identity_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->uuid('subject');
            $table->unique(['issuer', 'subject']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_identity_bindings');
    }
};
