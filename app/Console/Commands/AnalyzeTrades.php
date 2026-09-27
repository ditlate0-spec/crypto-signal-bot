<?php

namespace App\Console\Commands;

use App\Models\AiTradeJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalyzeTrades extends Command
{
    protected $signature = 'trades:analyze
                            {--limit=50 : Сколько сделок брать за раз}
                            {--hours=24 : За сколько часов}';

    protected $description = 'Отправить закрытые сделки AI-агенту для анализа';

    public function handle(): int
    {
        $limit = (int)$this->option('limit');
        $hours = (int)$this->option('hours');

        // Берём закрытые, но не проанализированные
        $trades = AiTradeJournal::whereNotNull('exit_time')
            ->where('exit_time', '>', now()->subHours($hours))
            ->orderBy('exit_time')
            ->limit($limit)
            ->get();

        if ($trades->isEmpty()) {
            $this->info('Нет закрытых сделок за последние ' . $hours . ' часов.');
            return self::SUCCESS;
        }

        $this->info("Найдено {$trades->count()} закрытых сделок. Отправляю агенту...");

        $url = config('services.analyst.url') . '/analyze';

        try {
            $response = Http::timeout(120)->post($url, [
                'trades' => $trades->map(fn($t) => [
                    'id'               => $t->id,
                    'symbol'           => $t->symbol,
                    'side'             => $t->side,
                    'entry_price'      => $t->entry_price,
                    'exit_price'       => $t->exit_price,
                    'quantity'         => $t->quantity,
                    'leverage'         => $t->leverage,
                    'ml_probability'   => $t->ml_probability,
                    'news_positive'    => $t->news_positive,
                    'news_negative'    => $t->news_negative,
                    'news_neutral'     => $t->news_neutral,
                    'news_sentiment'   => $t->news_sentiment,
                    'fear_greed_index' => $t->fear_greed_index,
                    'fear_greed_label' => $t->fear_greed_label,
                    'pnl'              => $t->pnl,
                    'pnl_pct'          => $t->pnl_pct,
                    'fees'             => $t->fees,
                    'exit_reason'      => $t->exit_reason,
                    'duration_seconds' => $t->duration_seconds,
                    'is_win'           => $t->is_win,
                    'entry_time'       => $t->entry_time?->toIso8601String(),
                    'exit_time'        => $t->exit_time?->toIso8601String(),
                ])->toArray(),
                'period' => "{$hours} часов",
            ]);

            if (!$response->successful()) {
                $this->error('Agent error: ' . $response->body());
                return self::FAILURE;
            }

            $data = $response->json();
            $analysis = $data['analysis'] ?? null;

            if (!$analysis) {
                $this->error('Пустой ответ от агента.');
                return self::FAILURE;
            }

            $this->info("✅ Анализ получен для {$trades->count()} сделок.");
            $this->newLine();
            $this->line($analysis);

            // Сохраняем в файл для истории
            $filename = 'analysis_' . now()->format('Y-m-d_H-i-s') . '.txt';
            \Storage::disk('local')->put(
                'analytics/' . $filename,
                "Период: {$hours} часов\n" .
                "Сделок: {$trades->count()}\n" .
                "Дата: " . now()->toDateTimeString() . "\n\n" .
                $analysis
            );

            $this->info("Сохранено: storage/app/analytics/{$filename}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            Log::error('[AnalyzeTrades] ' . $e->getMessage());
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}