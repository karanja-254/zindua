<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebhookCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'telegram:set-webhook {url? : Public HTTPS webhook URL}';

    /**
     * @var string
     */
    protected $description = 'Register the Telegram bot webhook with the Bot API';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $url = (string) ($this->argument('url') ?: 'https://vault.karanja.online/api/v1/telegram/webhook');

        try {
            $response = Http::asJson()
                ->timeout(20)
                ->post(sprintf('https://api.telegram.org/bot%s/setWebhook', $token), [
                    'url' => $url,
                    'allowed_updates' => ['message', 'edited_message', 'channel_post', 'edited_channel_post'],
                ]);
        } catch (\Throwable $exception) {
            $this->error('Webhook registration failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($response->failed() || $response->json('ok') !== true) {
            $this->error('Telegram rejected setWebhook: '.$response->body());

            return self::FAILURE;
        }

        $this->info('Telegram webhook registered: '.$url);

        return self::SUCCESS;
    }
}
