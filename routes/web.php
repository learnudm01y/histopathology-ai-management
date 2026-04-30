<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CasesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImportsController;
use App\Http\Controllers\Admin\WsiPreviewController;
use App\Http\Controllers\Admin\Settings\CategoriesController;
use App\Http\Controllers\Admin\Settings\DataSourcesController;
use App\Http\Controllers\Admin\Settings\DiseaseSubtypesController;
use App\Http\Controllers\Admin\Settings\OrgansController;
use App\Http\Controllers\Admin\Settings\StainsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::prefix('admin')->name('admin.')->group(function () {
    // Guest (login)
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.submit');
    });

    // Authenticated
    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('samples', [DashboardController::class, 'samples'])->name('samples');
        Route::post('samples', [DashboardController::class, 'storeSample'])->name('samples.store');
        Route::get('samples/{sample}', [DashboardController::class, 'showSample'])->name('samples.show');
        Route::get('samples/{sample}/edit', [DashboardController::class, 'editSample'])->name('samples.edit');
        Route::put('samples/{sample}', [DashboardController::class, 'updateSample'])->name('samples.update');
        Route::delete('samples/{sample}', [DashboardController::class, 'destroySample'])->name('samples.destroy');
        Route::post('samples/{sample}/retry', [DashboardController::class, 'retrySample'])->name('samples.retry');
        Route::post('samples/{sample}/verify', [DashboardController::class, 'verifySample'])->name('samples.verify');
        Route::patch('samples/{sample}/verification', [DashboardController::class, 'updateVerification'])->name('samples.verification.update');

        // WSI on-demand preview (download from Drive → OpenSlide inspection → thumbnail)
        Route::post('samples/{sample}/wsi-preview/start',     [WsiPreviewController::class, 'start'])->name('samples.wsi-preview.start');
        Route::get('samples/{sample}/wsi-preview/status',     [WsiPreviewController::class, 'status'])->name('samples.wsi-preview.status');
        Route::get('samples/{sample}/wsi-preview/thumbnail',  [WsiPreviewController::class, 'thumbnail'])->name('samples.wsi-preview.thumbnail');
        Route::post('samples/{sample}/wsi-preview/cleanup',   [WsiPreviewController::class, 'cleanup'])->name('samples.wsi-preview.cleanup');

        // DeepZoom (OpenSeadragon) tile pyramid — full-resolution viewer
        // Standard DZI URL scheme (used by OSD's native parser):
        //   descriptor: /admin/samples/{id}/wsi-preview/slide.dzi
        //   tiles:      /admin/samples/{id}/wsi-preview/slide_files/{level}/{x}_{y}.jpeg
        Route::get('samples/{sample}/wsi-preview/dzi',                                  [WsiPreviewController::class, 'dzi'])->name('samples.wsi-preview.dzi');
        Route::get('samples/{sample}/wsi-preview/slide.dzi',                            [WsiPreviewController::class, 'dzi'])->name('samples.wsi-preview.dzi-standard');
        Route::get('samples/{sample}/wsi-preview/slide_files/{level}/{tileFile}',        [WsiPreviewController::class, 'dziTileStandard'])
            ->where(['level' => '[0-9]+', 'tileFile' => '.+'])
            ->name('samples.wsi-preview.dzi-tile-standard');
        Route::get('samples/{sample}/wsi-preview/dzi-tile/{level}/{col}_{row}.{ext}',   [WsiPreviewController::class, 'dziTile'])
            ->where(['level' => '[0-9]+', 'col' => '[0-9]+', 'row' => '[0-9]+', 'ext' => 'jpe?g|png'])
            ->name('samples.wsi-preview.dzi-tile');

        Route::get('workflow', [DashboardController::class, 'workflow'])->name('workflow');
        Route::get('output', [DashboardController::class, 'output'])->name('output');

        // Cases (patients) — clinical case browser
        Route::get('cases',          [CasesController::class, 'index'])->name('cases.index');
        Route::get('cases/{case}',   [CasesController::class, 'show'])->name('cases.show');

        // Imports — manifest / metadata / clinical JSON files
        Route::post('imports', [ImportsController::class, 'store'])->name('imports.store');

        // Settings
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::resource('categories', CategoriesController::class)->except(['show']);
            Route::resource('categories/{category}/subtypes', DiseaseSubtypesController::class)
                ->except(['index', 'show'])
                ->parameters(['subtypes' => 'subtype']);
            Route::resource('organs', OrgansController::class)->except(['show']);
            Route::resource('data-sources', DataSourcesController::class)->except(['show']);
            Route::resource('stains', StainsController::class)->except(['show']);
        });
    });
});
