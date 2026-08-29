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
     * Handles DMs (`message`) and channel posts (`channel_post`), including
     * captions and `/command@BotName` forms used in channels.
     */
    public function handleWebhook(Request $request, TelegramBroadcasterService $telegram): JsonResponse
    {
        $payload = $request->input('message')
            ?? $request->input('edited_message')
            ?? $request->input('channel_post')
            ?? $request->input('edited_channel_post')
            ?? [];

        if (! is_array($payload)) {
            $payload = [];
        }

        $text = trim((string) ($payload['text'] ?? $payload['caption'] ?? ''));
        $chat = is_array($payload['chat'] ?? null) ? $payload['chat'] : [];
        $chatId = $chat['id'] ?? null;

        $command = strtolower((string) (strtok($text, " \n\t") ?: ''));
        $command = explode('@', $command)[0];

        if ($chatId === null || ! in_array($command, ['/test', '/status', '/stats'], true)) {
            return response()->json(['ok' => true], Response::HTTP_OK);
        }

        $total = EvidenceSession::query()->count();
        $high = EvidenceSession::query()->where('risk_level', 'high')->count();
        $medium = EvidenceSession::query()->where('risk_level', 'medium')->count();
        $low = EvidenceSession::query()->where('risk_level', 'low')->count();

        $reply = "🛡️ WitnessVault Sentinel Status\n"
            ."━━━━━━━━━━━━━━━━━━━━\n"
            ."🟢 Bot: Active & Monitoring\n"
            ."📊 Total Incidents: {$total}\n"
            ."🔴 High Risk: {$high}\n"
            ."🟡 Medium Risk: {$medium}\n"
            ."🟢 Low Risk: {$low}\n"
            ."🔒 Storage: Immutable WORM Active\n"
            .'🌐 Portal: https://vault.karanja.online';

        try {
            $delivered = $telegram->sendMessage((string) $chatId, $reply, null);
            if (! $delivered) {
                Log::error('Telegram /test reply was not delivered.', [
                    'chat_id' => $chatId,
                    'command' => $command,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('Telegram /test webhook handler failed.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
