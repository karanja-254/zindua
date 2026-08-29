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
     */
    public function handleWebhook(Request $request, TelegramBroadcasterService $telegram): JsonResponse
    {
        $message = (string) (
            $request->input('message.text')
            ?? $request->input('edited_message.text')
            ?? $request->input('channel_post.text')
            ?? ''
        );
        $chatId = $request->input('message.chat.id')
            ?? $request->input('edited_message.chat.id')
            ?? $request->input('channel_post.chat.id');

        if ($chatId !== null && (str_starts_with($message, '/test') || str_starts_with($message, '/status'))) {
            $total = EvidenceSession::query()->count();
            $high = EvidenceSession::query()->where('risk_level', 'high')->count();
            $medium = EvidenceSession::query()->where('risk_level', 'medium')->count();
            $low = EvidenceSession::query()->where('risk_level', 'low')->count();

            $text = "🛡️ *WitnessVault Sentinel Status*\n"
                ."━━━━━━━━━━━━━━━━━━━━\n"
                ."🟢 *Bot:* Active & Monitoring\n"
                ."📊 *Total Incidents:* {$total}\n"
                ."🔴 *High Risk:* {$high}\n"
                ."🟡 *Medium Risk:* {$medium}\n"
                ."🟢 *Low Risk:* {$low}\n"
                ."🔒 *Storage:* Immutable WORM Active\n"
                .'🌐 *Portal:* [https://vault.karanja.online](https://vault.karanja.online)';

            try {
                $telegram->sendMessage($chatId, $text);
            } catch (\Throwable $exception) {
                Log::error('Telegram /test webhook handler failed.', [
                    'chat_id' => $chatId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
