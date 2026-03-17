<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientCampaignController;
use App\Http\Controllers\Api\ClientFinanceController;
use App\Http\Controllers\Api\ClientNotificationController;
use App\Http\Controllers\Api\ClientReviewController;
use App\Http\Controllers\Api\FreelancerAssignmentController;
use App\Http\Controllers\Api\FreelancerFinanceController;
use App\Http\Controllers\Api\FreelancerNotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('freelancer')->group(function (): void {
    Route::get('dashboard', [FreelancerAssignmentController::class, 'dashboard']);
    Route::get('tasks', [FreelancerAssignmentController::class, 'index']);
    Route::get('tasks/{assignment}', [FreelancerAssignmentController::class, 'show']);
    Route::post('tasks/{assignment}/start', [FreelancerAssignmentController::class, 'start']);
    Route::post('tasks/{assignment}/submit', [FreelancerAssignmentController::class, 'submit']);

    Route::get('wallet', [FreelancerFinanceController::class, 'wallet']);
    Route::get('history', [FreelancerFinanceController::class, 'history']);
    Route::post('withdrawals', [FreelancerFinanceController::class, 'requestWithdrawal']);
    Route::patch('profile/banking', [FreelancerFinanceController::class, 'updateBanking']);

    Route::get('notifications', [FreelancerNotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [FreelancerNotificationController::class, 'markAsRead']);
    Route::post('push-tokens', [FreelancerNotificationController::class, 'registerPushToken']);
});

Route::middleware('auth:sanctum')->prefix('client')->group(function (): void {
    Route::get('dashboard', [ClientCampaignController::class, 'dashboard']);
    Route::get('campaigns', [ClientCampaignController::class, 'index']);
    Route::post('campaigns', [ClientCampaignController::class, 'store']);
    Route::get('campaigns/{campaign}', [ClientCampaignController::class, 'show']);

    Route::get('reviews', [ClientReviewController::class, 'index']);
    Route::post('reviews/{submission}/approve', [ClientReviewController::class, 'approve']);
    Route::post('reviews/{submission}/reject', [ClientReviewController::class, 'reject']);

    Route::get('wallet', [ClientFinanceController::class, 'wallet']);
    Route::get('history', [ClientFinanceController::class, 'history']);
    Route::post('funding-requests', [ClientFinanceController::class, 'requestFunding']);

    Route::get('notifications', [ClientNotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [ClientNotificationController::class, 'markAsRead']);
    Route::post('push-tokens', [ClientNotificationController::class, 'registerPushToken']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
