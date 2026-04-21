<?php

use App\Http\Controllers\FormsController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SurveyUploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/forms/active', [FormsController::class, 'active']);
    Route::get('/forms/{sid}/versions/{version}', [FormsController::class, 'version']);
    Route::post('/sync/responses/batch', [SyncController::class, 'batch']);
    Route::post('/uploads/survey-file', [SurveyUploadController::class, 'store']);
});
