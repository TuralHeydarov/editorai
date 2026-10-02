<?php

namespace App\Http\Middleware;

use App\Models\Clip;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        $clip = $request->route('clip');

        if ($project instanceof Project) {
            // An orphaned legacy project belongs to nobody until explicitly mapped.
            abort_unless(
                $request->user() !== null
                    && $project->user_id !== null
                    && (int) $project->user_id === (int) $request->user()->id,
                404
            );
        } elseif ($project !== null) {
            // Fail closed if a route omits implicit model binding.
            abort(404);
        }

        if ($clip !== null) {
            abort_unless(
                $project instanceof Project
                    && $clip instanceof Clip
                    && (int) $clip->project_id === (int) $project->id,
                404
            );
        }

        return $next($request);
    }
}
