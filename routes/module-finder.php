<?php

use Illuminate\Support\Facades\Route;
use TornadoEngine\ModuleFinder\Http\ModulesController;

Route::middleware(['web', 'auth', 'can:modules.manage'])
    ->prefix('modules')
    ->name('modules.')
    ->group(function () {
        Route::get('/', [ModulesController::class, 'index'])->name('index');
        Route::post('/check', [ModulesController::class, 'check'])->name('check');
        Route::post('/auto-update', [ModulesController::class, 'autoUpdate'])->name('auto-update');
        Route::post('/install', [ModulesController::class, 'install'])->name('install');
        Route::post('/uninstall', [ModulesController::class, 'uninstall'])->name('uninstall');
    });
