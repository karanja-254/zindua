<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramBroadcasterService
{
    private const API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly ?string $botToken = null,
        /** @var list<string> */
        private readonly array $channels = [],
    ) {
    }

    /**
     * Post a MarkdownV2 incident alert with a GPS pin and hash verification card
     * to every configured monitoring/community channel.
     *
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $aiIndicators
     * @return array<string, bool>  Map of channel id => delivery success.
     */
    public function broadcastThreatAlert(EvidenceSession $session, ?EvidenceChunk $chunk, array $aiIndicators): array
    {
        $channels = $this->resolveChannels();

        if ($channels === []) {
            Log::info('Telegram broadcast skipped: no channel_id or TELEGRAM_ALERT_CHANNELS configured.', [
                'session_id' => $session->id,
            ]);

            return [];
        }

        $token = $this->resolveBotToken();

        if ($token === null) {
            Log::info('Telegram broadcast skipped: TELEGRAM_BOT_TOKEN is empty or unset.', [
                'session_id' => $session->id,
            ]);

            return [];
        }

        $message = $this->buildMessage($session, $chunk, $aiIndicators);
        $results = [];

        foreach ($channels as $channel) {
            try {
                $response = Http::asJson()
                    ->timeout(15)
                    ->post(sprintf('%s/bot%s/sendMessage', self::API_BASE, $token), [
                        'chat_id' => $channel,
                        'text' => $message,
                        'parse_mode' => 'MarkdownV2',
                        'disable_web_page_preview' => false,
                    ]);
            } catch (\Throwable $exception) {
                Log::error('Telegram threat alert delivery failed.', [
                    'session_id' => $session->id,
                    'channel' => $channel,
                    'error' => $exception->getMessage(),
                ]);
                $results[$channel] = false;

                continue;
            }

            $results[$channel] = $response->successful();

            if ($response->failed()) {
                Log::error('Telegram threat alert delivery failed.', [
                    'session_id' => $session->id,
                    'channel' => $channel,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Send a synthesized emergency voice briefing (MP3) to all configured channels
     * as a Telegram voice note.
     *
     * @param  string  $audioStoragePath  Path on the S3 disk to the MP3 briefing.
     * @return array<string, bool>  Map of channel id => delivery success.
     */
    public function sendVoice(EvidenceSession $session, string $audioStoragePath, ?string $caption = null): array
    {
        $channels = $this->resolveChannels();

        if ($channels === []) {
            Log::info('Telegram voice note skipped: TELEGRAM_ALERT_CHANNELS is empty or unset.', [
                'session_id' => $session->id,
            ]);

            return [];
        }

        $token = $this->resolveBotToken();

        if ($token === null) {
            Log::info('Telegram voice note skipped: TELEGRAM_BOT_TOKEN is empty or unset.', [
                'session_id' => $session->id,
            ]);

            return [];
        }

        try {
            if (! Storage::disk('s3')->exists($audioStoragePath)) {
                Log::error('Telegram voice note skipped: briefing audio not found.', [
                    'session_id' => $session->id,
                    'path' => $audioStoragePath,
                ]);

                return [];
            }

            $audio = Storage::disk('s3')->get($audioStoragePath);
        } catch (\Throwable $exception) {
            Log::error('Telegram voice note skipped: briefing audio could not be read.', [
                'session_id' => $session->id,
                'path' => $audioStoragePath,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $filename = basename($audioStoragePath);
        $results = [];

        foreach ($channels as $channel) {
            try {
                $request = Http::timeout(45)
                    ->attach('voice', $audio, $filename);

                $payload = ['chat_id' => $channel];

                if ($caption !== null) {
                    $payload['caption'] = $this->escape($caption);
                    $payload['parse_mode'] = 'MarkdownV2';
                }

                $response = $request->post(sprintf('%s/bot%s/sendVoice', self::API_BASE, $token), $payload);
            } catch (\Throwable $exception) {
                Log::error('Telegram voice note delivery failed.', [
                    'session_id' => $session->id,
                    'channel' => $channel,
                    'error' => $exception->getMessage(),
                ]);
                $results[$channel] = false;

                continue;
            }

            $results[$channel] = $response->successful();

            if ($response->failed()) {
                Log::error('Telegram voice note delivery failed.', [
                    'session_id' => $session->id,
                    'channel' => $channel,
                    'status' => $response->status(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Send an arbitrary Telegram message without failing the caller on API errors.
     */
    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = 'Markdown'): bool
    {
        $token = $this->resolveBotToken();

        if ($token === null) {
            Log::info('Telegram sendMessage skipped: TELEGRAM_BOT_TOKEN is empty or unset.', [
                'chat_id' => $chatId,
            ]);

            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => false,
        ];

        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post(sprintf('%s/bot%s/sendMessage', self::API_BASE, $token), $payload);
        } catch (\Throwable $exception) {
            Log::error('Telegram sendMessage failed.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Telegram sendMessage failed.', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Reply to /test or /status with live vault operational counts.
     */
    public function replyOperationalStatus(string $chatId): bool
    {
        $token = $this->resolveBotToken();

        if ($token === null) {
            Log::info('Telegram /test skipped: TELEGRAM_BOT_TOKEN is empty or unset.');

            return false;
        }

        $totalCount = EvidenceSession::query()->count();
        $highCount = EvidenceSession::query()->where('risk_level', 'high')->count();
        $medCount = EvidenceSession::query()->where('risk_level', 'medium')->count();
        $lowCount = EvidenceSession::query()->where('risk_level', 'low')->count();

        $text = implode("\n", [
            '🛡️ ProofVault Operational Status',
            '━━━━━━━━━━━━━━━━━━',
            '🟢 Bot Status: ACTIVE & MONITORING',
            '📊 Total Incidents: '.$totalCount,
            '🔴 High Risk: '.$highCount,
            '🟡 Medium Risk: '.$medCount,
            '🟢 Low Risk: '.$lowCount,
            '🔒 Storage Mode: Append-Only WORM Active',
        ]);

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post(sprintf('%s/bot%s/sendMessage', self::API_BASE, $token), [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $exception) {
            Log::error('Telegram /test status reply failed.', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Telegram /test status reply failed.', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveChannels(): array
    {
        $configured = $this->channels !== []
            ? $this->channels
            : (array) config('services.telegram.channels', []);

        $channelId = config('services.telegram.channel_id');

        if (is_string($channelId) && trim($channelId) !== '') {
            $configured = array_merge($configured, explode(',', $channelId));
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $channel): string => trim((string) $channel), $configured),
            static fn (string $channel): bool => $channel !== '',
        )));
    }

    private function resolveBotToken(): ?string
    {
        $token = $this->botToken ?? config('services.telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return $token;
    }

    /**
     * Compose the MarkdownV2 high-priority incident card.
     *
     * @param  array{weapon?: float, violence?: float, acoustic_distress?: float, reason?: string, risk_level?: string}  $aiIndicators
     */
    private function buildMessage(EvidenceSession $session, ?EvidenceChunk $chunk, array $aiIndicators): string
    {
        $evidenceId = $session->evidenceId();
        $portal = 'https://vault.karanja.online';
        $weapon = $this->asPercent((float) ($aiIndicators['weapon'] ?? 0));
        $violence = $this->asPercent((float) ($aiIndicators['violence'] ?? 0));
        $distress = $this->asPercent((float) ($aiIndicators['acoustic_distress'] ?? 0));
        $reason = (string) ($aiIndicators['reason'] ?? 'High-risk multi-modal indicators detected.');
        $capturedAt = ($chunk?->captured_at !== null)
            ? $chunk->captured_at->timezone('Africa/Nairobi')->format('Y-m-d H:i:s').' EAT'
            : now()->timezone('Africa/Nairobi')->format('Y-m-d H:i:s').' EAT';

        $session->loadMissing('user');
        $userName = $session->user?->name ?: 'Unknown investigator';

        $locationLine = '📍 *Location:* Location unavailable';
        if ($chunk !== null) {
            $pin = $this->buildGpsPin($chunk);
            $lat = $chunk->latitude;
            $lng = $chunk->longitude;
            $locationLabel = ($lat !== null && $lng !== null)
                ? sprintf('Nairobi (%s, %s)', $lat, $lng)
                : 'Location unavailable';
            $locationLine = $pin !== null
                ? '📍 *Location:* ['.$this->escape($locationLabel).']('.$pin.')'
                : '📍 *Location:* '.$this->escape($locationLabel);
        }

        $lines = [
            '🔴 *HIGH RISK THREAT DETECTED*',
            '',
            '*Evidence ID:* `'.$this->escape($evidenceId).'`',
            '*Session:* `'.$this->escape((string) $session->id).'`',
            '🕒 *Timestamp:* '.$this->escape($capturedAt),
            '👤 *Preserved by:* '.$this->escape($userName),
            $locationLine,
            '',
            '*Detected threat factors:*',
            $this->escape(sprintf('Weapon: %s, Violence: %s, Distress: %s', $weapon, $violence, $distress)),
            $this->escape($reason),
            '',
            '🔐 *Chain Hash:* `'.$this->escape((string) ($chunk?->cumulative_hash ?? $session->chain_hash ?? '')).'`',
            '',
            '['.$this->escape('Open secure portal').']('.$portal.')',
        ];

        return implode("\n", $lines);
    }

    private function buildGpsPin(EvidenceChunk $chunk): ?string
    {
        if ($chunk->latitude === null || $chunk->longitude === null) {
            return null;
        }

        return sprintf('https://www.google.com/maps?q=%s,%s', $chunk->latitude, $chunk->longitude);
    }

    private function asPercent(float $score): string
    {
        return number_format($score * 100, 1).'%';
    }

    /**
     * Escape reserved MarkdownV2 characters per the Telegram Bot API spec.
     */
    private function escape(string $value): string
    {
        $reserved = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        return str_replace($reserved, array_map(static fn (string $c): string => '\\'.$c, $reserved), $value);
    }
}
