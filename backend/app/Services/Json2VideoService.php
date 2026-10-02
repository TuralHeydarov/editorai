<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Json2VideoService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services_external.json2video.api_url');
        $this->apiKey = config('services_external.json2video.api_key');
    }

    /** Private uploads have no external-provider delivery mechanism. */
    public function assertProviderSource(string $sourceUrl): void
    {
        $url = parse_url($sourceUrl);
        $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        if (!is_array($url) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || in_array(strtolower($url['host']), array_filter([$appHost, 'editorai.tural.ai']), true)) {
            throw new \DomainException('Private uploaded video supports viewing and editing. External transcription and rendering are not enabled.');
        }
    }

    /**
     * Transcribe a video/audio URL to SRT
     */
    public function transcribe(string $sourceUrl, string $language = 'az'): array
    {
        $this->assertProviderSource($sourceUrl);
        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->post("{$this->baseUrl}/transcribe", [
                'src' => $sourceUrl,
                'language' => $language,
            ]);

        return $response->json();
    }

    /**
     * Get transcription job status
     */
    public function getTranscribeStatus(string $jobId): array
    {
        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->get("{$this->baseUrl}/transcribe/{$jobId}");

        return $response->json();
    }

    /**
     * Create a movie (render video)
     */
    public function createMovie(array $payload): array
    {
        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->timeout(30)
            ->post("{$this->baseUrl}/movies", $payload);

        return $response->json();
    }

    /**
     * Get movie render status
     */
    public function getMovieStatus(string $jobId): array
    {
        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->get("{$this->baseUrl}/movies/{$jobId}");

        return $response->json();
    }

    /**
     * Build JSON2Video payload for a clip
     */
    public function buildClipPayload(
        string $sourceVideoUrl,
        float $trimStart,
        float $trimEnd,
        array $settings,
        ?string $srtContent = null,
        array $brollItems = [],
        array $soundEffects = [],
        ?array $backgroundMusic = null,
        ?string $hookText = null
    ): array {
        $this->assertProviderSource($sourceVideoUrl);
        $resolution = ($settings['format'] ?? 'vertical') === 'vertical'
            ? ['width' => 1080, 'height' => 1920]
            : ['width' => 1920, 'height' => 1080];

        $scenes = [];

        // --- Scene 1: Hook (if exists) ---
        if ($hookText) {
            $hookScene = [
                'duration' => 3,
                'elements' => [
                    [
                        'type' => 'video',
                        'src' => $sourceVideoUrl,
                        'trim-start' => $trimStart,
                        'trim-end' => $trimStart + 3,
                        'mute' => false,
                    ],
                    [
                        'type' => 'text',
                        'text' => $hookText,
                        'font-size' => 64,
                        'color' => '#ffffff',
                        'stroke-color' => '#000000',
                        'stroke-width' => 3,
                        'position-y' => 'center',
                        'animation' => [
                            'type' => 'bounce',
                            'duration' => 0.5,
                        ],
                    ],
                ],
            ];

            // Add hook sound effect
            $hookSounds = array_filter($soundEffects, fn($fx) => ($fx['type'] ?? '') === 'hook');
            foreach ($hookSounds as $fx) {
                $hookScene['elements'][] = [
                    'type' => 'audio',
                    'src' => $fx['src'],
                    'volume' => $fx['volume'] ?? 0.6,
                ];
            }

            $scenes[] = $hookScene;
            $trimStart += 3; // Adjust remaining video start
        }

        // --- Main content scenes ---
        $mainScene = [
            'elements' => [],
        ];

        // Main video element
        $videoElement = [
            'type' => 'video',
            'src' => $sourceVideoUrl,
            'trim-start' => $trimStart,
            'trim-end' => $trimEnd,
            'mute' => false,
        ];

        // Auto-SRT if subtitles enabled
        if ($settings['subtitles_enabled'] ?? true) {
            $subtitleStyle = $settings['subtitle_style'] ?? [];
            $videoElement['subtitles'] = [
                'enabled' => true,
                'font-size' => $subtitleStyle['font_size'] ?? 48,
                'color' => $subtitleStyle['color'] ?? '#ffffff',
                'highlight-color' => $subtitleStyle['highlight_color'] ?? 'auto',
                'glow-opacity' => $subtitleStyle['glow_opacity'] ?? 0.7,
                'position-y' => 'bottom',
                'bottom' => 170,
                'animation' => [
                    'type' => 'bounce',
                    'duration' => 0.3,
                ],
            ];
        }

        $mainScene['elements'][] = $videoElement;

        // Background music
        if ($backgroundMusic && ($settings['background_music'] ?? null)) {
            $mainScene['elements'][] = [
                'type' => 'audio',
                'src' => $backgroundMusic['src'],
                'volume' => $backgroundMusic['volume'] ?? 0.15,
            ];
        }

        // Transition sounds
        $transitionSounds = array_filter($soundEffects, fn($fx) => ($fx['type'] ?? '') === 'transition');
        foreach ($transitionSounds as $fx) {
            $mainScene['elements'][] = [
                'type' => 'audio',
                'src' => $fx['src'],
                'volume' => $fx['volume'] ?? 0.5,
            ];
        }

        $scenes[] = $mainScene;

        // --- B-roll scenes (interspersed) ---
        foreach ($brollItems as $broll) {
            $brollScene = [
                'transition' => ['type' => 'fade', 'duration' => 0.3],
                'elements' => [],
            ];

            if ($broll['type'] === 'video') {
                $brollScene['elements'][] = [
                    'type' => 'video',
                    'src' => $broll['src'],
                    'duration' => $broll['duration'] ?? 3,
                    'mute' => true,
                ];
            } else {
                $element = [
                    'type' => 'image',
                    'src' => $broll['src'],
                    'duration' => $broll['duration'] ?? 3,
                ];
                if (isset($broll['effect'])) {
                    $element['effect'] = ['type' => $broll['effect']];
                } else {
                    $element['effect'] = ['type' => 'ken-burns'];
                }
                $brollScene['elements'][] = $element;
            }

            $scenes[] = $brollScene;
        }

        return [
            'resolution' => 'custom',
            'width' => $resolution['width'],
            'height' => $resolution['height'],
            'quality' => 'high',
            'scenes' => $scenes,
        ];
    }
}
