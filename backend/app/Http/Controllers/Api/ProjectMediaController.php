<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireMediaSession;
use App\Models\Project;
use App\Services\PrivateProjectMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

class ProjectMediaController extends Controller
{
    public function session(Request $request, Project $project, PrivateProjectMedia $media)
    {
        $media->path($project);
        if (config('shared_sso.enabled')) { return response()->noContent()->header('Cache-Control', 'no-store'); }
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && RequireMediaSession::validToken($token, $user), 404);
        $value = Crypt::encryptString(json_encode(['user' => (int) $user->id, 'token' => (int) $token->id,
            'hash' => $token->token, 'until' => time() + RequireMediaSession::TTL], JSON_THROW_ON_ERROR));
        $cookie = cookie(RequireMediaSession::COOKIE, $value, RequireMediaSession::TTL / 60,
            '/', null, true, true, false, 'lax');
        return response()->noContent()->withCookie($cookie)->header('Cache-Control', 'no-store');
    }

    public function show(Project $project, PrivateProjectMedia $media)
    {
        $path = $media->path($project);
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        abort_unless(in_array($type, ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm',
            'audio/mpeg', 'audio/wav', 'audio/x-wav'], true), 404);
        // Symfony BinaryFileResponse handles HEAD, Range, Content-Length and 416.
        $response = response()->file($path, ['Content-Type' => $type]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
