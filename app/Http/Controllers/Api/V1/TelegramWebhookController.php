<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EvidenceSession;
use App\Services\TelegramBroadcasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramWebhookController extends Controller
{
    /**
     * Process inbound Telegram Bot API updates (public webhook).
     *
     * Supports /test, /status, and /stats commands — all return the same
     * live vault operational statistics.
     */
    public function handleWebhook(Request $request, TelegramBroadcasterService $telegram): JsonResponse
    {
        $payload = $request->input('message') ?? $request->input('channel_post') ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        $text = trim((string) ($payload['text'] ?? $payload['caption'] ?? ''));
        $chatId = $payload['chat']['id'] ?? null;

        $isStatusCommand = str_starts_with($text, '/test')
            || str_starts_with($text, '/status')
            || str_starts_with($text, '/stats');

        if ($chatId !== null && $isStatusCommand) {
            $total  = EvidenceSession::query()->count();
            $high   = EvidenceSession::query()->where('risk_level', 'high')->count();
            $medium = EvidenceSession::query()->where('risk_level', 'medium')->count();
            $low    = EvidenceSession::query()->where('risk_level', 'low')->count();

            $text = "\u{1F6E1}\u{FE0F} *WitnessVault Sentinel Status*\n"
                . "\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\u{2501}\n"
                . "\u{1F7E2} *Bot:* Active & Monitoring\n"
                . "\u{1F4CA} *Total Incidents:* {$total}\n"
                . "\u{1F534} *High Risk:* {$high}\n"
                . "\u{1F7E1} *Medium Risk:* {$medium}\n"
                . "\u{1F7E2} *Low Risk:* {$low}\n"
                . "\u{1F512} *Storage:* Immutable WORM Active\n"
                . '\u{1F310} *Portal:* [vault.karanja.online](https://vault.karanja.online)';

            try {
                // Use plain 'Markdown' parse mode — the status text uses *bold*
                // markers only (no MarkdownV2 special chars needing escaping).
                $telegram->sendMessage($chatId, $text, 'Markdown');
            } catch (\Throwable $exception) {
                Log::error('Telegram /test webhook handler failed.', [
                    'chat_id' => $chatId,
                    'error'   => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
