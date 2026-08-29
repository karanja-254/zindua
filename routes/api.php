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

Route::post('/v1/auth/login', [AuthController::class, 'login']);
Route::post('/v1/auth/unlock', [MasterKeyAuthController::class, 'authenticate']);
Route::post('/v1/auth/register', [MasterKeyAuthController::class, 'register']);
Route::post('/v1/telegram/webhook', [TelegramWebhookController::class, 'handleWebhook']);

Route::prefix('v1/evidence')->group(function (): void {
    // Public ingestion endpoints — live evidence stream capture.
    Route::post('/session/start', [EvidenceSessionController::class, 'startSession'])
        ->middleware('throttle:emergency-ingest');

    Route::post('/{sessionId}/chunk', [EvidenceChunkController::class, 'uploadChunk']);

    Route::post('/{sessionId}/finalize', [EvidenceSessionController::class, 'finalizeSession']);

    // Authenticated read endpoints — signed links & forensic reports.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/', [EvidenceSessionController::class, 'index']);

        Route::post('/mock', [EvidenceSessionController::class, 'createMockSession']);

        Route::post('/session', [EvidenceSessionController::class, 'startSession']);

        Route::post('/{sessionId}/upload-file', [EvidenceChunkController::class, 'uploadDirectFile']);

        Route::post('/{sessionId}/override-risk', [EvidenceSessionController::class, 'overrideRisk']);

        Route::get('/{sessionId}/chunks/{sequence}/media', [EvidenceSessionController::class, 'streamChunk'])
            ->whereNumber('sequence');

        Route::get('/{sessionId}/playback', [EvidenceSessionController::class, 'playbackManifest']);

        Route::get('/{sessionId}/verify', [EvidenceSessionController::class, 'verifyIntegrity']);

        Route::get('/{sessionId}/export-package', [EvidenceSessionController::class, 'exportPackage']);

        Route::get('/{sessionId}/report', [EvidenceReportController::class, 'downloadPdf']);

        Route::get('/{sessionId}', [EvidenceSessionController::class, 'show']);
    });

    Route::match(['put', 'patch', 'delete'], '/{any}', function (): JsonResponse {
        return response()->json([
            'error' => 'WORM Policy: Evidence records cannot be modified or destroyed.',
        ], Response::HTTP_FORBIDDEN);
    })->where('any', '.*');
});
