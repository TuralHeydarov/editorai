<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Clip;
use App\Services\AIService;
use App\Services\FreesoundService;
use App\Services\Json2VideoService;
use App\Services\PexelsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    public function __construct(
        private Json2VideoService $json2video,
        private PexelsService $pexels,
        private FreesoundService $freesound,
        private AIService $ai,
    ) {
    }

    /**
     * GET /api/projects — List user projects
     */
    public function index(Request $request): JsonResponse
    {
        $projects = Project::where('user_id', $request->user()->id)
            ->latest()
            ->withCount('clips')
            ->get();

        return response()->json($projects);
    }

    /**
     * POST /api/projects — Create project (file upload or URL)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'source_url' => 'nullable|url|required_without:video_file',
            'video_file' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,audio/mpeg,audio/wav|max:512000|required_without:source_url',
            'title' => 'nullable|string|max:255',
        ]);

        // Handle file upload
        $sourceUrl = $request->source_url;
        $isLocalUpload = false;
        if ($request->hasFile('video_file')) {
            $file = $request->file('video_file');
            $filename = (string) \Illuminate\Support\Str::uuid().'.'.$file->extension();
            $file->storeAs('videos', $filename, 'public');
            $sourceUrl = '/storage/videos/' . $filename;
            $isLocalUpload = true;
        }

        if (!$isLocalUpload) {
            try { $this->json2video->assertProviderSource($sourceUrl); }
            catch (\DomainException $e) { return response()->json(['error' => $e->getMessage()], 422); }
        }

        $project = Project::create([
            'user_id' => $request->user()->id,
            'title' => $request->title ?? ($isLocalUpload ? $request->file('video_file')->getClientOriginalName() : 'Untitled Project'),
            'source_url' => $sourceUrl,
            'status' => $isLocalUpload ? 'uploaded' : 'transcribing',
            'settings' => (new Project)->getDefaultSettings(),
            'conversation_history' => [
                [
                    'role' => 'assistant',
                    'content' => $isLocalUpload
                        ? 'Salam! 👋 Videonuz yükləndi ✅ Nə etmək istəyirsiniz? Məsələn: "Bu videodan maraqlı kliplər çıxar" deyə bilərsiniz.'
                        : 'Salam! Videonuz qəbul olundu ✅ Transkripsiya başladı, bir az gözləyin...',
                ],
            ],
        ]);

        // Only start transcription for external URLs (JSON2Video can't access localhost)
        if (!$isLocalUpload) {
            try {
                $result = $this->json2video->transcribe($sourceUrl);
                $project->update([
                    'transcribe_job_id' => $result['job_id'] ?? null,
                ]);
            } catch (\Exception $e) {
                Log::error('Transcription failed', ['error' => $e->getMessage()]);
                $project->update(['status' => 'failed']);
            }
        }

        return response()->json([
            'project' => $project,
            'message' => $isLocalUpload ? 'Video yükləndi! AI ilə danışa bilərsiniz.' : 'Layihə yaradıldı, transkripsiya başladı.',
        ], 201);
    }

    /**
     * GET /api/projects/{id} — Get project with clips
     */
    public function show(Project $project): JsonResponse
    {
        $project->load('clips');
        return response()->json($project);
    }

    /**
     * GET /api/projects/{project}/transcription-status — Poll transcription job
     */
    public function transcriptionStatus(Project $project): JsonResponse
    {
        if ($project->status !== 'transcribing' || empty($project->transcribe_job_id)) {
            return response()->json([
                'status' => $project->status,
                'srt_available' => !empty($project->srt_content),
            ]);
        }

        $jobStatus = $this->json2video->getTranscribeStatus($project->transcribe_job_id);

        // Log full response for debugging
        Log::info('Transcription status poll', [
            'job_id' => $project->transcribe_job_id,
            'response' => $jobStatus,
        ]);

        $status = $jobStatus['status'] ?? 'unknown';

        // Check for completion (handle various status names)
        if (in_array($status, ['done', 'completed', 'finished', 'success'])) {
            // JSON2Video returns srt_url — download the actual SRT content
            $srtUrl = $jobStatus['srt_url'] ?? null;
            $srt = null;

            if ($srtUrl) {
                try {
                    $srt = Http::timeout(30)->get($srtUrl)->body();
                } catch (\Exception $e) {
                    Log::error('Failed to download SRT', ['url' => $srtUrl, 'error' => $e->getMessage()]);
                }
            }

            // Fallback: try inline fields
            if (!$srt) {
                $srt = $jobStatus['srt'] ?? $jobStatus['result'] ?? $jobStatus['output'] ?? null;
            }

            if ($srt) {
                $project->update([
                    'srt_content' => $srt,
                    'status' => 'uploaded',
                ]);
                return response()->json([
                    'status' => 'done',
                    'srt_available' => true,
                    'message' => 'Transkripsiya tamamlandı! ✅',
                ]);
            }
        }

        if (in_array($status, ['failed', 'error'])) {
            $project->update(['status' => 'uploaded']);
            return response()->json([
                'status' => 'failed',
                'srt_available' => false,
                'message' => 'Transkripsiya uğursuz oldu ❌',
                'debug' => $jobStatus,
            ]);
        }

        return response()->json([
            'status' => 'processing',
            'srt_available' => false,
            'progress' => $jobStatus['progress'] ?? null,
            'debug_status' => $status,
        ]);
    }

    /**
     * GET /api/projects/{id}/status — Check transcription/render status
     */
    public function checkStatus(Project $project): JsonResponse
    {
        // Check transcription status if still transcribing
        if ($project->status === 'transcribing' && $project->transcribe_job_id) {
            try {
                $status = $this->json2video->getTranscribeStatus($project->transcribe_job_id);

                if ($status['status'] === 'done') {
                    // Download SRT content
                    $srtContent = '';
                    if (!empty($status['srt_url'])) {
                        $srtContent = Http::get($status['srt_url'])->body();
                    }

                    $project->update([
                        'status' => 'analyzing',
                        'srt_url' => $status['srt_url'] ?? null,
                        'srt_content' => $srtContent,
                    ]);
                } elseif ($status['status'] === 'failed') {
                    $project->update(['status' => 'failed']);
                }
            } catch (\Exception $e) {
                Log::error('Transcription check failed', ['error' => $e->getMessage()]);
            }
        }

        // Check clip render statuses
        if ($project->status === 'rendering') {
            $allDone = true;
            $anyFailed = false;

            foreach ($project->clips()->where('render_status', 'rendering')->get() as $clip) {
                if ($clip->render_job_id) {
                    try {
                        $status = $this->json2video->getMovieStatus($clip->render_job_id);

                        if ($status['status'] === 'done') {
                            $clip->update([
                                'render_status' => 'done',
                                'render_url' => $status['url'] ?? null,
                            ]);
                        } elseif ($status['status'] === 'failed') {
                            $clip->update(['render_status' => 'failed']);
                            $anyFailed = true;
                        } else {
                            $allDone = false;
                        }
                    } catch (\Exception $e) {
                        $allDone = false;
                    }
                }
            }

            if ($allDone && $project->clips()->where('render_status', 'pending')->count() === 0) {
                $project->update(['status' => 'done']);
            }
        }

        $project->load('clips');
        return response()->json($project);
    }

    /**
     * POST /api/projects/{id}/analyze — Analyze SRT & select clips
     */
    public function analyze(Project $project): JsonResponse
    {
        if (!$project->srt_content) {
            return response()->json(['error' => 'SRT content not available yet'], 422);
        }

        $project->update(['status' => 'analyzing']);

        try {
            // AI analyzes SRT and selects clips
            $clips = $this->ai->analyzeAndSelectClips(
                $project->srt_content,
                $project->settings ?? []
            );

            // Create clip records
            foreach ($clips as $index => $clipData) {
                $clip = Clip::create([
                    'project_id' => $project->id,
                    'order' => $index + 1,
                    'title' => $clipData['title'] ?? "Klip " . ($index + 1),
                    'hook_text' => $clipData['hook_text'] ?? null,
                    'interest_score' => $clipData['interest_score'] ?? 50,
                    'trim_start' => $clipData['trim_start'],
                    'trim_end' => $clipData['trim_end'],
                    'duration' => ($clipData['trim_end'] ?? 0) - ($clipData['trim_start'] ?? 0),
                ]);

                // Fetch B-roll suggestions
                if (!empty($clipData['broll_keywords']) && ($project->settings['broll_enabled'] ?? true)) {
                    $orientation = ($project->settings['format'] ?? 'vertical') === 'vertical' ? 'portrait' : 'landscape';
                    $brolls = $this->pexels->getBrollSuggestions($clipData['broll_keywords'], $orientation, 1);
                    $clip->update(['broll_items' => $brolls]);
                }

                // Fetch sound effects
                if (!empty($clipData['sound_fx_type']) && ($project->settings['sound_fx_enabled'] ?? true)) {
                    $hookSound = $this->freesound->getHookSound($clipData['sound_fx_type']);
                    if ($hookSound) {
                        $clip->update([
                            'sound_effects' => [
                                [
                                    'type' => 'hook',
                                    'src' => $hookSound['src'],
                                    'name' => $hookSound['name'],
                                    'duration' => $hookSound['duration'],
                                    'volume' => 0.6,
                                ],
                            ],
                        ]);
                    }
                }

                // Fetch background music (once for the first clip, reuse)
                if ($index === 0 && ($project->settings['background_music'] ?? null)) {
                    $music = $this->freesound->searchBackgroundMusic(
                        $project->settings['background_music'],
                        30,
                        120,
                        1
                    );
                    if (!empty($music)) {
                        // Apply same music to all clips
                        $musicData = [
                            'src' => $music[0]['src'],
                            'name' => $music[0]['name'],
                            'volume' => 0.15,
                        ];
                        Clip::where('project_id', $project->id)->update([
                            'background_music' => json_encode($musicData),
                        ]);
                    }
                }
            }

            $project->update(['status' => 'clips_ready']);
            $project->load('clips');

            return response()->json([
                'project' => $project,
                'message' => count($clips) . " klip tapıldı! 🎬",
            ]);
        } catch (\Exception $e) {
            Log::error('AI analysis failed', ['error' => $e->getMessage()]);
            $project->update(['status' => 'failed']);
            return response()->json(['error' => 'Analiz uğursuz oldu: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/projects/{id}/chat — Send message to AI assistant
     */
    public function chat(Request $request, Project $project): JsonResponse
    {
        $request->validate(['message' => 'required|string']);

        $result = $this->ai->processUserMessage($project, $request->message);

        $actionType = $result['action']['type'] ?? 'none';
        $actionParams = $result['action']['params'] ?? [];
        $actionResult = null;

        if (in_array($actionType, ['transcribe', 'render'], true)
            || ($actionType === 'analyze_video' && empty($project->srt_content))) {
            try { $this->json2video->assertProviderSource($project->source_url ?? ''); }
            catch (\DomainException $e) {
                return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage(),
                    'action' => ['type' => 'none'], 'action_result' => null, 'project' => $project], 422);
            }
        }

        try {
            switch ($actionType) {
                case 'transcribe':
                    $videoUrl = $project->source_url;
                    $transcribeResult = $this->json2video->transcribe($videoUrl);
                    $jobId = $transcribeResult['job_id'] ?? null;

                    if ($jobId) {
                        $project->update([
                            'transcribe_job_id' => $jobId,
                            'status' => 'transcribing',
                        ]);
                        $actionResult = ['job_id' => $jobId, 'status' => 'started'];
                    } else {
                        $actionResult = ['error' => 'Transkripsiya başlaya bilmədi'];
                        $result['message'] .= "\n\n❌ Transkripsiya başlaya bilmədi.";
                    }
                    break;

                case 'analyze_video':
                    // If no SRT, auto-start transcription first
                    if (empty($project->srt_content)) {
                        $videoUrl = $project->source_url;
                        $transcribeResult = $this->json2video->transcribe($videoUrl);
                        $jobId = $transcribeResult['job_id'] ?? null;
                        if ($jobId) {
                            $project->update([
                                'transcribe_job_id' => $jobId,
                                'status' => 'transcribing',
                            ]);
                            // Tell frontend to poll, and after transcription completes, auto-describe
                            $actionResult = ['job_id' => $jobId, 'status' => 'started', 'auto_describe' => true];
                            $result['action']['type'] = 'transcribe'; // Override for frontend polling
                        }
                        break;
                    }

                    // SRT available — use GPT to describe what's in the video (NO splitting!)
                    $srtContent = $project->srt_content;
                    $describeMessages = [
                        ['role' => 'system', 'content' => 'You are a video content analyst. Analyze the SRT transcript and describe what the video contains. Respond in Azerbaijani. Be detailed but concise. Mention key topics, interesting moments, and suggest what the user could do next (e.g., split into clips, add B-roll). Respond as JSON: {"message": "your analysis"}'],
                        ['role' => 'user', 'content' => "Analyze this video transcript:\n\n" . mb_substr($srtContent, 0, 3000)],
                    ];
                    $describeResponse = $this->ai->chat($describeMessages, true);
                    $describeData = json_decode($describeResponse, true);
                    $result['message'] = $describeData['message'] ?? $describeResponse;
                    $actionResult = ['analyzed' => true];
                    break;

                case 'split_clips':
                    // The actual clip splitting
                    if (empty($project->srt_content)) {
                        $result['message'] .= "\n\n⚠️ Transkripsiya hələ mövcud deyil. Əvvəlcə 'analiz et' deyin.";
                        break;
                    }
                    $project->clips()->delete();
                    $clips = $this->ai->analyzeAndSelectClips(
                        $project->srt_content,
                        $project->settings ?? []
                    );
                    foreach ($clips as $index => $clipData) {
                        Clip::create([
                            'project_id' => $project->id,
                            'order' => $index + 1,
                            'title' => $clipData['title'] ?? "Klip " . ($index + 1),
                            'hook_text' => $clipData['hook_text'] ?? null,
                            'interest_score' => $clipData['interest_score'] ?? 50,
                            'trim_start' => $clipData['trim_start'],
                            'trim_end' => $clipData['trim_end'],
                            'duration' => ($clipData['trim_end'] ?? 0) - ($clipData['trim_start'] ?? 0),
                            'broll_keywords' => $clipData['broll_keywords'] ?? [],
                            'sound_fx_type' => $clipData['sound_fx_type'] ?? 'whoosh',
                        ]);
                    }
                    $project->update(['status' => 'clips_ready']);
                    $actionResult = ['clips_created' => count($clips)];
                    break;

                case 'search_broll':
                    // Detect orientation from source_resolution or settings
                    $resolution = $project->source_resolution; // e.g. "1080x1920"
                    if ($resolution && str_contains($resolution, 'x')) {
                        [$w, $h] = explode('x', $resolution);
                        $orientation = (int) $h > (int) $w ? 'portrait' : 'landscape';
                    } else {
                        $orientation = ($project->settings['format'] ?? 'vertical') === 'vertical' ? 'portrait' : 'landscape';
                    }

                    // Ensure at least one clip exists
                    $clips = $project->clips()->get();
                    if ($clips->isEmpty()) {
                        $clip = Clip::create([
                            'project_id' => $project->id,
                            'order' => 1,
                            'title' => $project->title ?? 'Full Video',
                            'trim_start' => 0,
                            'trim_end' => $project->source_duration ?? 37,
                            'duration' => $project->source_duration ?? 37,
                        ]);
                        $clips = collect([$clip]);
                    }

                    $brollCount = intval($actionParams['count'] ?? 3);
                    $videoDuration = $project->source_duration ?? 37;
                    $searchDetails = [];
                    $allBrollItems = [];

                    // Use AI to plan B-roll placements from SRT
                    if (!empty($project->srt_content)) {
                        Log::info('B-roll: Using AI plan from SRT');
                        $brollPlan = $this->ai->planBrollPlacements(
                            mb_substr($project->srt_content, 0, 3000),
                            $brollCount,
                            $videoDuration
                        );
                        Log::info('B-roll AI plan', ['plan' => $brollPlan]);

                        foreach ($brollPlan as $placement) {
                            $keyword = $placement['keyword'] ?? 'stock footage';
                            $start = floatval($placement['start'] ?? 0);
                            $end = floatval($placement['end'] ?? $start + 6);
                            $reason = $placement['reason'] ?? '';

                            try {
                                $videos = $this->pexels->searchVideos($keyword, $orientation, 1);
                                $foundCount = count($videos);

                                if ($foundCount > 0) {
                                    $video = $videos[0];
                                    $video['keyword'] = $keyword;
                                    $video['start'] = $start;
                                    $video['end'] = $end;
                                    $video['reason'] = $reason;
                                    $allBrollItems[] = $video;
                                }

                                $searchDetails[] = [
                                    'keyword' => $keyword,
                                    'time' => round($start) . 's-' . round($end) . 's',
                                    'found' => $foundCount,
                                    'reason' => $reason,
                                ];
                            } catch (\Exception $e) {
                                Log::error('Pexels search failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);
                                $searchDetails[] = [
                                    'keyword' => $keyword,
                                    'time' => round($start) . 's-' . round($end) . 's',
                                    'found' => 0,
                                    'reason' => 'Pexels xətası: ' . $e->getMessage(),
                                ];
                            }
                        }
                    } else {
                        // No SRT — use AI-provided keywords from action params
                        $aiKeywords = $actionParams['keywords'] ?? ['stock footage'];
                        foreach ($aiKeywords as $keyword) {
                            try {
                                $videos = $this->pexels->searchVideos($keyword, $orientation, 1);
                                if (!empty($videos)) {
                                    $video = $videos[0];
                                    $video['keyword'] = $keyword;
                                    $video['start'] = count($allBrollItems) * 8 + 3; // space them out
                                    $video['end'] = $video['start'] + 6;
                                    $allBrollItems[] = $video;
                                }
                                $searchDetails[] = ['keyword' => $keyword, 'found' => count($videos)];
                            } catch (\Exception $e) {
                                Log::error('Pexels search failed', ['keyword' => $keyword, 'error' => $e->getMessage()]);
                            }
                        }
                    }

                    // Store B-roll items on the first (or only) clip
                    if (!empty($allBrollItems)) {
                        $clips->first()->update(['broll_items' => $allBrollItems]);
                    }

                    $project->load('clips');
                    $actionResult = [
                        'brolls_added' => count($allBrollItems),
                        'search_details' => $searchDetails,
                    ];
                    break;

                case 'search_sound_fx':
                    $clips = $project->clips()->get();
                    $sfxCount = 0;
                    foreach ($clips as $clip) {
                        $sfxType = $actionParams['type'] ?? 'whoosh';
                        $hookSound = $this->freesound->getHookSound($sfxType);
                        if ($hookSound) {
                            $clip->update([
                                'sound_effects' => [
                                    [
                                        'type' => 'hook',
                                        'src' => $hookSound['src'],
                                        'name' => $hookSound['name'],
                                        'duration' => $hookSound['duration'],
                                        'volume' => 0.6,
                                    ],
                                ],
                            ]);
                            $sfxCount++;
                        }
                    }
                    $actionResult = ['sfx_added' => $sfxCount];
                    break;

                case 'update_settings':
                    if (!empty($actionParams)) {
                        $settings = $project->settings ?? [];
                        $settings = array_merge($settings, $actionParams);
                        $project->update(['settings' => $settings]);
                        $actionResult = ['settings_updated' => array_keys($actionParams)];
                    }
                    break;

                case 'render':
                    $project->update(['status' => 'rendering']);
                    $actionResult = ['rendering_started' => true];
                    break;
            }
        } catch (\Exception $e) {
            Log::error('Chat action failed', ['action' => $actionType, 'error' => $e->getMessage()]);
            $result['message'] .= "\n\n⚠️ Əməliyyat zamanı xəta baş verdi: " . $e->getMessage();
        }

        // Reload project with clips
        $project->load('clips');

        return response()->json([
            'message' => $result['message'],
            'action' => $result['action'],
            'action_result' => $actionResult,
            'project' => $project,
        ]);
    }

    /**
     * POST /api/projects/{id}/render — Start rendering all clips
     */
    public function render(Project $project): JsonResponse
    {
        // Never publish private uploads or spend on a renderer that cannot access them.
        try { $this->json2video->assertProviderSource($project->source_url ?? ''); }
        catch (\DomainException $e) { return response()->json(['error' => $e->getMessage()], 422); }
        $project->update(['status' => 'rendering']);
        $project->load('clips');
        $results = [];

        foreach ($project->clips as $clip) {
            if ($clip->render_status === 'done')
                continue;

            $payload = $this->json2video->buildClipPayload(
                sourceVideoUrl: $project->source_url,
                trimStart: (float) $clip->trim_start,
                trimEnd: (float) $clip->trim_end,
                settings: $project->settings ?? [],
                srtContent: $clip->srt_content,
                brollItems: $clip->broll_items ?? [],
                soundEffects: $clip->sound_effects ?? [],
                backgroundMusic: $clip->background_music,
                hookText: $clip->hook_text,
            );

            try {
                $result = $this->json2video->createMovie($payload);
                $clip->update([
                    'render_status' => 'rendering',
                    'render_job_id' => $result['job_id'] ?? null,
                    'render_payload' => $payload,
                ]);
                $results[] = [
                    'clip_id' => $clip->id,
                    'job_id' => $result['job_id'] ?? null,
                    'status' => 'rendering',
                ];
            } catch (\Exception $e) {
                $clip->update(['render_status' => 'failed']);
                $results[] = [
                    'clip_id' => $clip->id,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Render başladı! 🚀',
            'renders' => $results,
        ]);
    }

    /**
     * PUT /api/projects/{id}/clips/{clipId} — Update clip details
     */
    public function updateClip(Request $request, Project $project, Clip $clip): JsonResponse
    {
        $clip->update($request->only([
            'title',
            'hook_text',
            'trim_start',
            'trim_end',
            'broll_items',
            'sound_effects',
            'background_music',
        ]));

        return response()->json($clip);
    }

    /**
     * DELETE /api/projects/{id}/clips/{clipId} — Remove a clip
     */
    public function deleteClip(Project $project, Clip $clip): JsonResponse
    {
        $clip->delete();
        return response()->json(['message' => 'Klip silindi']);
    }

    /**
     * POST /api/projects/{id}/clips/{clipId}/broll — Refresh B-roll for a clip
     */
    public function refreshBroll(Request $request, Project $project, Clip $clip): JsonResponse
    {
        $keywords = $request->input('keywords', []);
        if (empty($keywords)) {
            return response()->json(['error' => 'Keywords required'], 422);
        }

        $orientation = ($project->settings['format'] ?? 'vertical') === 'vertical' ? 'portrait' : 'landscape';
        $brolls = $this->pexels->getBrollSuggestions($keywords, $orientation, 3);

        $clip->update(['broll_items' => $brolls]);

        return response()->json([
            'broll_items' => $brolls,
            'message' => count($brolls) . ' B-roll tapıldı',
        ]);
    }

    /**
     * POST /api/projects/{project}/save-timeline — Save timeline segment positions
     */
    public function saveTimeline(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'segments' => 'required|array',
            'segments.*.id' => 'required|string',
            'segments.*.type' => 'required|string',
            'segments.*.start' => 'required|numeric',
            'segments.*.end' => 'required|numeric',
        ]);

        $segments = $request->segments;

        // Update B-roll items timing in clips
        foreach ($project->clips as $clip) {
            if (empty($clip->broll_items))
                continue;

            $brollItems = $clip->broll_items;
            $changed = false;

            foreach ($brollItems as &$broll) {
                // Match by keyword + src to find the right broll
                foreach ($segments as $seg) {
                    if (
                        $seg['type'] === 'broll' &&
                        (($seg['label'] ?? '') === ($broll['keyword'] ?? '') ||
                            ($seg['keyword'] ?? '') === ($broll['keyword'] ?? ''))
                    ) {
                        $broll['start'] = round($seg['start'], 2);
                        $broll['end'] = round($seg['end'], 2);
                        $changed = true;
                        break;
                    }
                }
            }

            if ($changed) {
                $clip->update(['broll_items' => $brollItems]);
            }
        }

        // Save video segment trim info in project settings
        $videoSeg = collect($segments)->firstWhere('type', 'video');
        if ($videoSeg) {
            $settings = $project->settings ?? [];
            $settings['video_trim_start'] = round($videoSeg['start'], 2);
            $settings['video_trim_end'] = round($videoSeg['end'], 2);
            $project->update(['settings' => $settings]);
        }

        return response()->json([
            'message' => 'Timeline yadda saxlanıldı ✅',
            'success' => true,
        ]);
    }
}
