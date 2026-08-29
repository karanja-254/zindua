<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        $text = trim((string) (
            $request->input('message.text')
            ?? $request->input('edited_message.text')
            ?? $request->input('channel_post.text')
            ?? ''
        ));

        $chatId = $request->input('message.chat.id')
            ?? $request->input('edited_message.chat.id')
            ?? $request->input('channel_post.chat.id');

        $command = strtolower(strtok($text, ' ') ?: '');
        $command = explode('@', $command)[0];

        if ($chatId === null || ! in_array($command, ['/test', '/status'], true)) {
            return response()->json(['ok' => true], Response::HTTP_OK);
        }

        try {
            $telegram->replyOperationalStatus((string) $chatId);
        } catch (\Throwable $exception) {
            Log::error('Telegram /test webhook handler failed.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
