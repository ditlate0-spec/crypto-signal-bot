<?php
// ============================================
// news.php — НОВОСТИ ИЗ RSS-ЛЕНТ (бесплатно)
// ============================================

function getCryptoNewsSentiment($asset = 'BTC') {
    $cacheFile = __DIR__ . '/../news_cache_' . $asset . '.json';
    $cacheTime = 1800;
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }
    
    $result = [
        'headlines' => [],
        'positive_count' => 0,
        'negative_count' => 0,
        'neutral_count' => 0,
        'error' => null
    ];
    
    $rssFeeds = [
        'CoinDesk' => 'https://www.coindesk.com/arc/outboundfeeds/rss/',
        'Cointelegraph' => 'https://cointelegraph.com/rss',
        'Bitcoin Magazine' => 'https://bitcoinmagazine.com/feed',
        'CryptoSlate' => 'https://cryptoslate.com/feed/',
        'Decrypt' => 'https://decrypt.co/feed'
    ];
    
    $allTitles = [];
    
    foreach ($rssFeeds as $sourceName => $feedUrl) {
        $rss = @simplexml_load_file($feedUrl);
        if ($rss === false) continue;
        
        $items = $rss->channel->item ?? [];
        $count = 0;
        
        foreach ($items as $item) {
            if ($count >= 2) break;
            
            $title = (string)$item->title;
            if (empty($title)) continue;
            
            $sentiment = analyzeSentiment($title);
            
            $allTitles[] = [
                'title' => $title,
                'source' => $sourceName,
                'sentiment' => $sentiment,
                'date' => (string)$item->pubDate
            ];
            
            $count++;
        }
    }
    
    if (empty($allTitles)) {
        $result['error'] = 'Не удалось получить новости из RSS';
        return $result;
    }
    
    usort($allTitles, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    $result['headlines'] = array_slice($allTitles, 0, 5);
    
    // Считаем счётчики по топ-5
    foreach ($result['headlines'] as $h) {
        if ($h['sentiment'] == 'positive') $result['positive_count']++;
        elseif ($h['sentiment'] == 'negative') $result['negative_count']++;
        else $result['neutral_count']++;
    }
    
    file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    
    return $result;
}

// ============================================
// АНАЛИЗ ТОНАЛЬНОСТИ ПО СЛОВАМ
// ============================================
function analyzeSentiment($text) {
    $text = mb_strtolower($text, 'UTF-8');
    
    $positiveWords = [
        'surge', 'soar', 'rally', 'bull', 'gain', 'rise', 'high', 'up',
        'growth', 'adopt', 'boost', 'jump', 'break', 'record', 'approve',
        'positive', 'launch', 'partnership', 'support', 'buy', 'etf',
        'inflow', 'accumulate', 'рост', 'прибыль', 'бычий',
        'unveils', 'launches', 'adoption', 'integration', 'milestone',
        'breakthrough', 'approval', 'green light', 'snaps up', 'purchase', 'acquires', 'buys', 'expands',
'crossed', 'milestone', 'record high', 'new high'
    ];
    
    $negativeWords = [
        'crash', 'drop', 'fall', 'bear', 'loss', 'decline', 'low', 'down',
'hack', 'fear', 'plunge', 'dump', 'negative', 'risk',
'scam', 'lawsuit', 'warning', 'sell', 'outflow', 'liquidat',
'падение', 'убыток', 'медвежий',
'black market', 'crackdown', 'investigation', 'penalty',
'seize', 'arrest', 'fraud', 'theft', 'exploit', 'falls', 'overshoot', 'hits new high', 'yield high'
    ];
    
    $pos = 0;
    $neg = 0;
    
    foreach ($positiveWords as $word) {
        if (mb_strpos($text, $word) !== false) $pos++;
    }
    foreach ($negativeWords as $word) {
        if (mb_strpos($text, $word) !== false) $neg++;
    }
    
    if ($pos > $neg) return 'positive';
    if ($neg > $pos) return 'negative';
    return 'neutral';
}