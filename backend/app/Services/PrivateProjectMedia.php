<?php
namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Storage;

class PrivateProjectMedia
{
    public function path(Project $project): string
    {
        $url = $project->getRawOriginal('source_url');
        abort_unless(is_string($url) && str_starts_with($url, '/storage/videos/'), 404);
        $name = substr($url, strlen('/storage/videos/'));
        abort_unless($name !== '' && strlen($name) <= 255 && basename($name) === $name
            && !preg_match('/[\\\\\x00-\x1f\x7f%?#]/', $name) && !in_array($name, ['.', '..'], true), 404);
        // A shared physical file is not proof that an orphan or another owner granted access.
        abort_if(Project::where('source_url', $url)->where('id', '!=', $project->id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $project->user_id))->exists(), 404);
        abort_if(is_link(Storage::disk('public')->path('videos')), 404);
        $root = realpath(Storage::disk('public')->path('videos'));
        $path = realpath(Storage::disk('public')->path('videos/'.$name));
        abort_unless($root !== false && $path !== false && is_file($path)
            && str_starts_with($path, $root.DIRECTORY_SEPARATOR), 404);
        return $path;
    }
}
