<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->enum('status', ['uploading', 'uploaded', 'transcribing', 'analyzing', 'clips_ready', 'rendering', 'done', 'failed'])->default('uploading');

            // Source video
            $table->string('source_url');            // Original video URL
            $table->integer('source_duration')->nullable(); // Duration in seconds
            $table->string('source_resolution')->nullable(); // e.g. "1920x1080"

            // Transcription
            $table->string('transcribe_job_id')->nullable(); // JSON2Video transcribe job ID
            $table->text('srt_url')->nullable();              // Generated SRT file URL
            $table->text('srt_content')->nullable();          // Raw SRT content

            // Settings (from AI conversation)
            $table->json('settings')->nullable();
            /*
             * settings JSON:
             * {
             *   "clip_count": 10,
             *   "clip_duration": "short",        // "short" (30s-1min), "medium" (1-3min)
             *   "format": "vertical",            // "vertical" (1080x1920), "horizontal" (1920x1080)
             *   "broll_enabled": true,
             *   "sound_fx_enabled": true,
             *   "subtitles_enabled": true,
             *   "background_music": "energetic",  // "energetic", "calm", "dramatic", null
             *   "subtitle_style": {
             *     "font_size": 48,
             *     "color": "#ffffff",
             *     "highlight_color": "auto",
             *     "glow_opacity": 0.7
             *   }
             * }
             */

            // AI conversation
            $table->json('conversation_history')->nullable(); // Chat history

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
