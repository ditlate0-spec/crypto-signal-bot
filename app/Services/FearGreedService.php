<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class FearGreedService
{
    private const CACHE_KEY = 'fear_greed_index';
    private const CACHE_TTL = 3600; // 1 час

    /**
     * Получить индекс страха и жадности.
     *
     * @return array{value:int, value_classification:string, timestamp:int}|array{error:string}
     */
    public function get(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        try {
            $response = Http::timeout(10)->get('https://api.alternative.me/fng/?limit=1');

            if (!$response->successful()) {
                return ['error' => 'Не удалось получить индекс'];
            }

            $data = $response->json();
            if (!$data || !isset($data['data'][0])) {
                return ['error' => 'Ошибка парсинга индекса'];
            }

            $item = $data['data'][0];
            $result = [
                'value'                => (int)$item['value'],
                'value_classification' => $item['value_classification'],
                'timestamp'            => (int)$item['timestamp'],
            ];

            Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);

            return $result;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Текстовое описание + цвет для отображения
     */
    public function describe(int $value): array
    {
        if ($value <= 25) {
            return ['color' => '#3fb950', 'text' => 'ЦЕНА ПАДАЕТ', 'hint' => 'Экстремальный страх — возможно дно'];
        }
        if ($value <= 45) {
            return ['color' => '#58a6ff', 'text' => 'ВОЗМОЖНО ПАДАЕТ', 'hint' => 'Страх на рынке'];
        }
        if ($value <= 55) {
            return ['color' => '#8b949e', 'text' => 'НЕОПРЕДЕЛЁННОСТЬ', 'hint' => 'Рынок в боковике'];
        }
        if ($value <= 75) {
            return ['color' => '#f0883e', 'text' => 'ВОЗМОЖНО РАСТЁТ', 'hint' => 'Жадность на рынке'];
        }
        return ['color' => '#ff6b6b', 'text' => 'ЦЕНА РАСТЁТ', 'hint' => 'Экстремальная жадность — возможен разворот'];
    }
}