<?php

use App\Http\Controllers\Admin\AdminCampaignController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFinanceController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminReviewController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::redirect('admin/login', 'login')->name('admin.login');

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');

        Route::get('campaigns', [AdminCampaignController::class, 'index'])->name('campaigns.index');
        Route::patch('campaigns/task-type-pricing/defaults', [AdminCampaignController::class, 'updateTaskTypePricing'])->name('campaigns.task-type-pricing.update');
        Route::patch('campaigns/{campaign}', [AdminCampaignController::class, 'update'])->name('campaigns.update');

        Route::get('reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{submission}/approve', [AdminReviewController::class, 'approve'])->name('reviews.approve');
        Route::post('reviews/{submission}/reject', [AdminReviewController::class, 'reject'])->name('reviews.reject');

        Route::get('finance', [AdminFinanceController::class, 'index'])->name('finance.index');
        Route::patch('finance/payment-setting', [AdminFinanceController::class, 'updatePaymentSetting'])->name('finance.payment-setting.update');
        Route::patch('finance/withdrawals/{withdrawal}', [AdminFinanceController::class, 'updateWithdrawal'])->name('finance.withdrawals.update');
        Route::patch('finance/funding-requests/{fundingRequest}', [AdminFinanceController::class, 'updateFundingRequest'])->name('finance.funding-requests.update');

        Route::get('notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/send', [AdminNotificationController::class, 'send'])->name('notifications.send');
    });
});

require __DIR__.'/settings.php';
