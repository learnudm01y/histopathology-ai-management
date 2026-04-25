<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
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
        Route::get('workflow', [DashboardController::class, 'workflow'])->name('workflow');
        Route::get('output', [DashboardController::class, 'output'])->name('output');

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
