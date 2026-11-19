<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\OnlineGameController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PlayerHistoryController;
use App\Http\Controllers\PracticeGameController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\SpectatorController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\EnsureFrontendSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureFrontendSession::class)->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:registration');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/forgot-password', [PasswordRecoveryController::class, 'send'])->middleware('throttle:recovery');
    Route::post('/auth/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:recovery');
});

Route::middleware([EnsureFrontendSession::class, 'throttle:120,1'])->prefix('practice')->group(function () {
    Route::get('/', [PracticeGameController::class, 'show'])->block(10, 10);
    Route::post('/', [PracticeGameController::class, 'store'])->block(10, 10);
    Route::patch('/', [PracticeGameController::class, 'update'])->block(10, 10);
});

Route::prefix('spectate')->middleware('throttle:120,1')->group(function () {
    Route::get('/', [SpectatorController::class, 'index']);
    Route::get('/{code}', [SpectatorController::class, 'show'])->where('code', '[A-Z0-9]{10}');
    Route::get('/{code}/ticket', [SpectatorController::class, 'ticket'])->where('code', '[A-Z0-9]{10}');
});

Route::middleware([EnsureFrontendSession::class, 'active', 'throttle:online'])->prefix('games')->group(function () {
    Route::post('/', [OnlineGameController::class, 'store'])->middleware('throttle:online-create')->block(10, 10);
    Route::get('/open', [OnlineGameController::class, 'openRooms'])->block(10, 10);
    Route::get('/{code}/invitation', [OnlineGameController::class, 'invitation']);
    Route::get('/{code}', [OnlineGameController::class, 'show'])->block(10, 10);
    Route::post('/{code}/join', [OnlineGameController::class, 'join'])->middleware('throttle:online-join')->block(10, 10);
    Route::get('/{code}/ticket', [OnlineGameController::class, 'ticket'])->block(10, 10);
    Route::patch('/{code}', [OnlineGameController::class, 'update'])->block(10, 10);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/wallet', [WalletController::class, 'index']);
    Route::get('/wallet/requests/{key}', [WalletController::class, 'requestStatus'])->whereUuid('key');
    Route::middleware([EnsureFrontendSession::class, 'throttle:wallet-operations'])->group(function () {
        Route::post('/wallet/deposits', [WalletController::class, 'deposit'])->name('wallet.deposit');
        Route::post('/wallet/withdrawals', [WalletController::class, 'withdraw'])->name('wallet.withdraw');
    });
    Route::get('/account/tournaments', [TournamentController::class, 'profile']);
    Route::get('/tournaments', [TournamentController::class, 'index']);
    Route::get('/tournaments/{tournament}', [TournamentController::class, 'show']);
    Route::middleware([EnsureFrontendSession::class, 'throttle:30,1'])->prefix('tournaments')->group(function () {
        Route::post('/', [TournamentController::class, 'store']);
        Route::post('/{tournament}/join', [TournamentController::class, 'join']);
        Route::post('/{tournament}/leave', [TournamentController::class, 'leave']);
        Route::post('/{tournament}/start', [TournamentController::class, 'start']);
        Route::post('/{tournament}/cancel', [TournamentController::class, 'cancel']);
    });
    Route::get('/account/games', [PlayerHistoryController::class, 'index']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/email/resend', [EmailVerificationController::class, 'send'])->middleware('throttle:verification');
    Route::get('/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed:relative')->name('verification.verify');
    Route::middleware([EnsureFrontendSession::class, 'throttle:account-changes'])->group(function () {
        Route::patch('/account/profile', [AccountController::class, 'update']);
        Route::put('/account/password', [AccountController::class, 'password']);
        Route::delete('/account', [AccountController::class, 'destroy']);
    });
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/wallet/users/{user}', [WalletController::class, 'userBalance']);
        Route::patch('/wallet/users/{user}', [WalletController::class, 'adjust'])->middleware([EnsureFrontendSession::class, 'verified', 'throttle:account-changes']);
        Route::get('/wallet/transactions', [WalletController::class, 'audit']);
        Route::post('/wallet/transactions/{transaction}/resolve', [WalletController::class, 'resolve'])->middleware([EnsureFrontendSession::class, 'verified', 'throttle:account-changes']);
        Route::get('/tournaments', [TournamentController::class, 'index']);
        Route::get('/overview', AdminOverviewController::class);
        Route::get('/settings', [AdminSettingsController::class, 'show']);
        Route::get('/audit-logs', AuditLogController::class);
        Route::middleware([EnsureFrontendSession::class, 'verified', 'throttle:account-changes'])->group(function () {
            Route::patch('/settings', [AdminSettingsController::class, 'update']);
            Route::post('/tournaments/{tournament}/start', [TournamentController::class, 'start'])->name('admin.tournaments.start');
            Route::post('/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('admin.tournaments.cancel');
            Route::patch('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/block', [AdminUserController::class, 'block']);
            Route::post('/users/{user}/unblock', [AdminUserController::class, 'unblock']);
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
            Route::post('/users/{user}/restore', [AdminUserController::class, 'restore']);
        });
    });
});

Route::get('/ranking', [RankingController::class, 'index'])->middleware('throttle:online');

Route::get('/health', function () {
    DB::select('SELECT 1');

    return response()->json([
        'status' => 'ok',
        'service' => 'api',
        'database' => 'connected',
    ]);
});
