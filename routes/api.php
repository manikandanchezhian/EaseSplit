<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettlementController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/google', [AuthController::class, 'google'])->middleware('throttle:10,1');
    Route::post('/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/search', [SearchController::class, 'index']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::apiResource('groups', GroupController::class)->except(['destroy'])->parameters(['groups' => 'group']);
    Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
    Route::post('/groups/join', [GroupController::class, 'join']);
    Route::post('/groups/{group}/members', [GroupController::class, 'addMembers']);
    Route::get('/groups/{group}/ledger', [GroupController::class, 'ledger']);

    Route::apiResource('expenses', ExpenseController::class)->only(['index', 'store', 'show']);
    Route::post('/expenses/{expense}/respond', [ExpenseController::class, 'respond']);
    Route::post('/expenses/{expense}/dispute', [ExpenseController::class, 'dispute']);

    Route::apiResource('settlements', SettlementController::class)->only(['index', 'store', 'show']);
    Route::post('/settlements/{settlement}/verify', [SettlementController::class, 'verify']);

    Route::get('/friends', [FriendController::class, 'index']);
    Route::get('/friends/pending', [FriendController::class, 'pending']);
    Route::post('/friends/request', [FriendController::class, 'request']);
    Route::post('/friends/{friendRequest}/respond', [FriendController::class, 'respond']);
    Route::get('/friends/{friend}/ledger', [FriendController::class, 'ledger']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);
});

Route::get('/push/vapid-public-key', fn () => response()->json(['key' => config('services.webpush.public_key')]));
