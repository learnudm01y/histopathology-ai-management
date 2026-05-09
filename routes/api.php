<?php

use App\Http\Controllers\Api\V1\FeatureExtractionApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| All routes are prefixed automatically with /api by the framework.
| External GPU / processing servers (RunPod) authenticate using a Bearer
| token whose value matches `servers_names.api_key`.
|
| Public endpoints
|--------------------------------------------------------------------------
|   GET  /api/health                                – open ping
|
| Authenticated endpoints (server.api_key)
|--------------------------------------------------------------------------
|   GET  /api/v1/health                             – auth ping
|   POST /api/v1/feature-extraction/report          – status report (unified)
|   GET  /api/v1/feature-extraction/jobs/{sample}   – read current status
*/

// Public health-check (no auth) — useful for connectivity tests.
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'service' => 'histopathology-management-api',
        'time'    => now()->toIso8601String(),
    ]);
});

Route::prefix('v1')
    ->middleware('verify.server.api_key')
    ->group(function () {
        Route::get('/health', [FeatureExtractionApiController::class, 'health']);

        Route::prefix('feature-extraction')->group(function () {
            Route::post('/report', [FeatureExtractionApiController::class, 'report'])
                ->name('api.feature-extraction.report');

            Route::get('/jobs/{sample}', [FeatureExtractionApiController::class, 'show'])
                ->name('api.feature-extraction.show');
        });
    });
