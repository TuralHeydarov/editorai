<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

require __DIR__.'/shared-sso.php';

Route::match(['GET', 'HEAD'], '/api/projects/{project}/media', [\App\Http\Controllers\Api\ProjectMediaController::class, 'show'])
    ->middleware(['media.session', 'project.owner']);

Route::any('/storage/{path?}', fn () => abort(404))->where('path', '.*');
