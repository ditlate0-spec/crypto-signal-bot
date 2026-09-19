<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NewsService
{
    private const CACHE_TTL = 1800; // 30 минут

    private const RSS_FEEDS = [
        'CoinDesk'         => 'https://www.coindesk.com/arc/outboundfeeds/rss/',
        'Cointelegraph'    => 'https://cointelegraph.com/rss',
        'Bitcoin Magazine' => 'https://bitcoinmagazine.com/feed',
        'CryptoSlate'      => 'https://cryptoslate.com/feed/',
        'Decrypt'          => 'https://decrypt.co/feed',
    ];

    private const POSITIVE_WORDS = [
        'surge', 'soar', 'rally', 'bull', 'gain', 'rise', 'high', 'up',
        'growth', 'adopt', 'boost', 'jump', 'break', 'record', 'approve',
        'positive', 'launch', 'partnership', 'support', 'buy', 'etf',
        'inflow', 'accumulate', 'рост', 'прибыль', 'бычий',
        'unveils', 'launches', 'adoption', 'integration', 'milestone',
        'breakthrough', 'approval', 'green light', 'snaps up', 'purchase',
        'acquires', 'buys', 'expands', 'crossed', 'record high', 'new high',
    ];

    private const NEGATIVE_WORDS = [
        'crash', 'drop', 'fall', 'bear', 'loss', 'decline', 'low', 'down',
        'hack', 'fear', 'plunge', 'dump', 'negative', 'risk',
        'scam', 'lawsuit', 'warning', 'sell', 'outflow', 'liquidat',
        'падение', 'убыток', 'медвежий',
        'black market', 'crackdown', 'investigation', 'penalty',
        'seize', 'arrest', 'fraud', 'theft', 'exploit', 'falls',
        'overshoot', 'hits new high', 'yield high',
    ];

    /**
     * Получить новости с анализом тональности.
     * Возвращает ['headlines' => [...], 'positive_count' => int, 'negative_count' => int, 'neutral_count' => int, 'error' => ?string]
     */
    public function getSentiment(string $asset = 'BTC'): array
    {
        $cacheKey = 'news_' . $asset;

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $result = [
            'headlines'      => [],
            'positive_count' => 0,
            'negative_count' => 0,
            'neutral_count'  => 0,
            'error'          => null,
        ];

        $allItems = [];

        foreach (self::RSS_FEEDS as $sourceName => $feedUrl) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (CryptoBot)'])
                    ->get($feedUrl);

                if (!$response->successful()) {
                    continue;
                }

                $xml = @simplexml_load_string($response->body());
                if ($xml === false) {
                    continue;
                }

                $count = 0;
                foreach ($xml->channel->item as $item) {
                    if ($count >= 2) break;

                    $title = trim((string)$item->title);
                    if ($title === '') continue;

                    $allItems[] = [
                        'title'     => $title,
                        'source'    => $sourceName,
                        'sentiment' => $this->analyzeSentiment($title),
                        'date'      => (string)$item->pubDate,
                    ];
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::warning("[NewsService] {$sourceName} failed: " . $e->getMessage());
                continue;
            }
        }

        if (empty($allItems)) {
            $result['error'] = 'Не удалось получить новости из RSS';
            return $result;
        }

        usort($allItems, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        $result['headlines'] = array_slice($allItems, 0, 5);

        foreach ($result['headlines'] as $h) {
            if ($h['sentiment'] === 'positive')      $result['positive_count']++;
            elseif ($h['sentiment'] === 'negative')  $result['negative_count']++;
            else                                     $result['neutral_count']++;
        }

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Анализ тональности по ключевым словам.
     */
    private function analyzeSentiment(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $pos = 0;
        $neg = 0;

        foreach (self::POSITIVE_WORDS as $word) {
            if (mb_strpos($text, $word) !== false) $pos++;
        }
        foreach (self::NEGATIVE_WORDS as $word) {
            if (mb_strpos($text, $word) !== false) $neg++;
        }

        if ($pos > $neg) return 'positive';
        if ($neg > $pos) return 'negative';
        return 'neutral';
    }
}