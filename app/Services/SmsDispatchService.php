<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsDispatchService
{
    /**
     * How long an emergency access token remains valid.
     */
    private const TOKEN_TTL_MINUTES = 60;

    /**
     * Send critical incident coordinates plus a one-time emergency access token
     * to every configured responder number via the active SMS gateway driver.
     *
     * @return array<string, bool>  Map of recipient => delivery success.
     */
    public function dispatchEmergencyAlert(EvidenceSession $session, EvidenceChunk $chunk): array
    {
        $recipients = (array) config('services.sms.emergency_recipients', []);

        if ($recipients === []) {
            Log::warning('SMS emergency alert skipped: no recipients configured.', [
                'session_id' => $session->id,
            ]);

            return [];
        }

        $token = $this->mintOneTimeToken($session);
        $body = $this->buildMessage($session, $chunk, $token);

        $driver = (string) config('services.sms.driver', 'africastalking');

        $results = [];

        foreach ($recipients as $recipient) {
            $recipient = (string) $recipient;

            $results[$recipient] = match ($driver) {
                'twilio' => $this->sendViaTwilio($recipient, $body),
                default => $this->sendViaAfricasTalking($recipient, $body),
            };
        }

        return $results;
    }

    /**
     * Generate and cache a single-use emergency access token for the session.
     */
    private function mintOneTimeToken(EvidenceSession $session): string
    {
        $token = Str::upper(Str::random(8));

        Cache::put(
            $this->tokenCacheKey($token),
            ['session_id' => $session->id, 'used' => false],
            now()->addMinutes(self::TOKEN_TTL_MINUTES),
        );

        return $token;
    }

    /**
     * @return array{session_id: string}|null
     */
    public function redeemToken(string $token): ?array
    {
        $key = $this->tokenCacheKey(Str::upper(trim($token)));
        $payload = Cache::get($key);

        if (! is_array($payload) || ($payload['used'] ?? false) === true || empty($payload['session_id'])) {
            return null;
        }

        Cache::put($key, [...$payload, 'used' => true], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return ['session_id' => (string) $payload['session_id']];
    }

    private function tokenCacheKey(string $token): string
    {
        return 'evidence:emergency-token:'.$token;
    }

    private function buildMessage(EvidenceSession $session, EvidenceChunk $chunk, string $token): string
    {
        $location = ($chunk->latitude !== null && $chunk->longitude !== null)
            ? sprintf('%s,%s', $chunk->latitude, $chunk->longitude)
            : 'unavailable';

        return sprintf(
            "ProofVault ALERT [%s]\nSession: %s\nGPS: %s\nMap: https://maps.google.com/?q=%s\nAccess code: %s (valid %dm)",
            strtoupper((string) $session->risk_level),
            substr((string) $session->id, 0, 8),
            $location,
            $location,
            $token,
            self::TOKEN_TTL_MINUTES,
        );
    }

    private function sendViaAfricasTalking(string $recipient, string $body): bool
    {
        $username = config('services.sms.africastalking.username');
        $apiKey = config('services.sms.africastalking.api_key');
        $from = config('services.sms.africastalking.sender_id');

        if (empty($username) || empty($apiKey)) {
            Log::error('Africa\'s Talking credentials missing; SMS not sent.', ['recipient' => $recipient]);

            return false;
        }

        $payload = [
            'username' => $username,
            'to' => $recipient,
            'message' => $body,
        ];

        if (! empty($from)) {
            $payload['from'] = $from;
        }

        $endpoint = strtolower((string) $username) === 'sandbox'
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'apiKey' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->timeout(15)
                ->post($endpoint, $payload);
        } catch (\Throwable $exception) {
            Log::error('Africa\'s Talking SMS delivery failed.', [
                'recipient' => $recipient,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::error('Africa\'s Talking SMS delivery failed.', [
                'recipient' => $recipient,
                'status' => $response->status(),
            ]);
        }

        return $response->successful();
    }

    private function sendViaTwilio(string $recipient, string $body): bool
    {
        $sid = config('services.sms.twilio.account_sid');
        $authToken = config('services.sms.twilio.auth_token');
        $from = config('services.sms.twilio.from');

        if (empty($sid) || empty($authToken) || empty($from)) {
            Log::error('Twilio credentials missing; SMS not sent.', ['recipient' => $recipient]);

            return false;
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $authToken)
            ->timeout(15)
            ->post(
                sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $sid),
                [
                    'To' => $recipient,
                    'From' => $from,
                    'Body' => $body,
                ],
            );

        if ($response->failed()) {
            Log::error('Twilio SMS delivery failed.', [
                'recipient' => $recipient,
                'status' => $response->status(),
            ]);
        }

        return $response->successful();
    }
}
