<?php

namespace App\Services;

use Carbon\Carbon;

class ContextRankingService
{
    public function rank(array $item): array
    {
        $age = max(0, Carbon::parse($item['updated_at'])->diffInDays(now()));
        $components = [
            'similarity'=>max(0, min(1, (float) ($item['similarity'] ?? 0))),
            'importance'=>(float) ($item['importance'] ?? .5),
            'recency'=>exp(-$age / 90),
            'confidence'=>(float) ($item['confidence'] ?? 1),
            'usage'=>min(1, log(1 + (int) ($item['usage_count'] ?? 0)) / log(101)),
        ];
        $score = 0;
        foreach (config('contextdock.weights') as $key=>$weight) { $score += ($components[$key] ?? 0) * $weight; }
        return $item + ['score'=>round($score, 6), 'components'=>$components];
    }
}
