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
     * captions, `/command@BotName`, and forum-topic thread ids.
     */
    public function handleWebhook(Request $request, TelegramBroadcasterService $telegram): JsonResponse
    {
        $payload = $this->extractTelegramMessage($request);

        $text = trim((string) ($payload['text'] ?? $payload['caption'] ?? ''));
        $text = preg_replace('/^[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]+/u', '', $text) ?? $text;

        $chat = is_array($payload['chat'] ?? null) ? $payload['chat'] : [];
        $chatId = $chat['id'] ?? null;

        $command = $this->extractCommand($text, $payload);

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

        $threadId = isset($payload['message_thread_id']) ? (int) $payload['message_thread_id'] : null;

        try {
            $delivered = $telegram->sendMessage($chatId, $reply, null, $threadId > 0 ? $threadId : null);
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

    /**
     * @return array<string, mixed>
     */
    private function extractTelegramMessage(Request $request): array
    {
        $data = $request->all();

        if (
            ! isset($data['message'])
            && ! isset($data['edited_message'])
            && ! isset($data['channel_post'])
            && ! isset($data['edited_channel_post'])
        ) {
            $decoded = json_decode((string) $request->getContent(), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $payload = $data['message']
            ?? $data['edited_message']
            ?? $data['channel_post']
            ?? $data['edited_channel_post']
            ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractCommand(string $text, array $payload): string
    {
        $candidate = $text;

        $entities = $payload['entities'] ?? $payload['caption_entities'] ?? [];
        if (is_array($entities)) {
            foreach ($entities as $entity) {
                if (! is_array($entity) || ($entity['type'] ?? '') !== 'bot_command') {
                    continue;
                }

                $offset = (int) ($entity['offset'] ?? 0);
                $length = (int) ($entity['length'] ?? 0);
                if ($length > 0) {
                    $slice = mb_substr($text, $offset, $length);
                    if ($slice !== '') {
                        $candidate = $slice;
                    }
                }

                break;
            }
        }

        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
        $command = strtolower((string) ($parts[0] ?? ''));
        $command = explode('@', $command)[0];

        return $command;
    }
}
