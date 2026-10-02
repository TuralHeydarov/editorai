<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'status',
        'source_url',
        'source_duration',
        'source_resolution',
        'transcribe_job_id',
        'srt_url',
        'srt_content',
        'settings',
        'conversation_history',
    ];

    protected $appends = ['playback_url'];

    public function getPlaybackUrlAttribute(): ?string
    {
        $source = $this->getRawOriginal('source_url');
        if (!is_string($source)) { return null; }
        return str_starts_with($source, '/') ? '/api/projects/'.$this->id.'/media' : $source;
    }

    protected $casts = [
        'settings' => 'array',
        'conversation_history' => 'array',
    ];

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class)->orderBy('order');
    }

    public function getDefaultSettings(): array
    {
        return [
            'clip_count' => 10,
            'clip_duration' => 'short',
            'format' => 'vertical',
            'broll_enabled' => true,
            'sound_fx_enabled' => true,
            'subtitles_enabled' => true,
            'background_music' => 'energetic',
            'subtitle_style' => [
                'font_size' => 48,
                'color' => '#ffffff',
                'highlight_color' => 'auto',
                'glow_opacity' => 0.7,
            ],
        ];
    }

    public function getResolution(): array
    {
        $format = $this->settings['format'] ?? 'vertical';
        return $format === 'vertical'
            ? ['width' => 1080, 'height' => 1920]
            : ['width' => 1920, 'height' => 1080];
    }
}
