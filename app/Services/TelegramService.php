<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramService
{
    private string $token;
    private string $chatId;

    public function __construct()
    {
        $this->token  = config('services.telegram.token');
        $this->chatId = (string)config('services.telegram.chat_id');
    }

    /**
     * Отправить текстовое сообщение в Telegram
     */
    public function send(string $text, ?string $chatId = null): bool
    {
        $chatId = $chatId ?? $this->chatId;

        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->token}/sendMessage",
                [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ]
            );

            return $response->successful();
        } catch (\Throwable $e) {
            \Log::error('[TelegramService] ' . $e->getMessage());
            return false;
        }
    }
}