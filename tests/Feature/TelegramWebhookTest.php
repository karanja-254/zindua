<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EvidenceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', 'test-bot-token');
        Config::set('services.telegram.webhook_secret', 'test-webhook-secret');
        Config::set('services.telegram.channels', []);
        Config::set('services.telegram.channel_id', null);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_channel_post_test_command_sends_status_reply(): void
    {
        EvidenceSession::create([
            'status' => 'finalized',
            'risk_level' => 'high',
            'started_at' => now()->subMinute(),
            'finalized_at' => now(),
        ]);

        $response = $this->webhook([
            'update_id' => 42,
            'channel_post' => [
                'message_id' => 10,
                'date' => time(),
                'chat' => [
                    'id' => -1001234567890,
                    'title' => 'ProofVault Alerts',
                    'type' => 'channel',
                ],
                'text' => '/test@ProofVaultBot',
                'entities' => [[
                    'offset' => 0,
                    'length' => 19,
                    'type' => 'bot_command',
                ]],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && (string) ($data['chat_id'] ?? '') === '-1001234567890'
                && str_contains((string) ($data['text'] ?? ''), 'WitnessVault Sentinel Status')
                && str_contains((string) ($data['text'] ?? ''), 'High Risk: 1')
                && ! isset($data['parse_mode']);
        });
    }

    public function test_direct_message_status_command_sends_status_reply(): void
    {
        $response = $this->webhook([
            'update_id' => 43,
            'message' => [
                'message_id' => 11,
                'date' => time(),
                'chat' => [
                    'id' => 555001,
                    'type' => 'private',
                ],
                'text' => '/status',
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && (string) ($data['chat_id'] ?? '') === '555001';
        });
    }

    public function test_unrelated_channel_post_does_not_call_telegram(): void
    {
        $response = $this->webhook([
            'update_id' => 44,
            'channel_post' => [
                'message_id' => 12,
                'date' => time(),
                'chat' => [
                    'id' => -1001234567890,
                    'type' => 'channel',
                ],
                'text' => 'just a normal channel update',
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        Http::assertNothingSent();
    }

    public function test_webhook_without_secret_is_forbidden(): void
    {
        $response = $this->postJson('/api/v1/telegram/webhook', [
            'update_id' => 45,
            'message' => [
                'message_id' => 13,
                'date' => time(),
                'chat' => ['id' => 555001, 'type' => 'private'],
                'text' => '/status',
            ],
        ]);

        $response->assertForbidden();
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload)
    {
        return $this->postJson('/api/v1/telegram/webhook', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret',
        ]);
    }
}
