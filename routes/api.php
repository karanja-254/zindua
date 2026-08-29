<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EvidenceChunkController;
use App\Http\Controllers\Api\V1\EvidenceReportController;
use App\Http\Controllers\Api\V1\EvidenceSessionController;
use App\Http\Controllers\Api\V1\MasterKeyAuthController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

Route::post('/v1/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:vault-auth');
Route::post('/v1/auth/unlock', [MasterKeyAuthController::class, 'authenticate'])
    ->middleware('throttle:vault-auth');
Route::post('/v1/auth/register', [MasterKeyAuthController::class, 'register'])
    ->middleware('throttle:vault-register');
Route::post('/v1/telegram/webhook', [TelegramWebhookController::class, 'handleWebhook']);

Route::post('/v1/evidence/emergency-access', [EvidenceSessionController::class, 'redeemEmergencyAccess'])
    ->middleware('throttle:vault-auth');

Route::prefix('v1/evidence')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/', [EvidenceSessionController::class, 'index']);

        Route::post('/mock', [EvidenceSessionController::class, 'createMockSession']);

        Route::post('/session', [EvidenceSessionController::class, 'startSession']);

        Route::post('/{sessionId}/chunk', [EvidenceChunkController::class, 'uploadChunk']);

        Route::post('/{sessionId}/finalize', [EvidenceSessionController::class, 'finalizeSession']);

        Route::post('/{sessionId}/upload-file', [EvidenceChunkController::class, 'uploadDirectFile']);

        Route::post('/{sessionId}/override-risk', [EvidenceSessionController::class, 'overrideRiskLevel']);

        Route::get('/{sessionId}/chunks/{sequence}/media', [EvidenceSessionController::class, 'streamChunk'])
            ->whereNumber('sequence');

        Route::get('/{sessionId}/playback', [EvidenceSessionController::class, 'playbackManifest']);

        Route::get('/{sessionId}/verify', [EvidenceSessionController::class, 'verifyIntegrity']);

        Route::get('/{sessionId}/export-package', [EvidenceSessionController::class, 'exportPackage']);

        Route::get('/{sessionId}/report/pdf', [EvidenceReportController::class, 'downloadPdf']);

        Route::get('/{sessionId}/report', [EvidenceReportController::class, 'downloadPdf']);

        Route::get('/{sessionId}', [EvidenceSessionController::class, 'show']);
    });

    Route::match(['put', 'patch', 'delete'], '/{any}', function (): JsonResponse {
        return response()->json([
            'error' => 'WORM Policy: Evidence records cannot be modified or destroyed.',
        ], Response::HTTP_FORBIDDEN);
    })->where('any', '.*');
});
