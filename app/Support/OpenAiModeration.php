<?php

namespace App\Support;

use App\Models\Dispatch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OpenAI's free moderation endpoint (omni-moderation-latest): the fast first check on
 * every new dispatch. It scores hate, harassment, threats and more in text, and sexual,
 * violent and self-harm content in photos. Anything over a threshold in config/stoop.php
 * is held for moderators. It can't judge Stoop's own rules (profiling, campaign posts),
 * so posts it passes still go to Claude when that's on.
 */
class OpenAiModeration
{
    public static function enabled(): bool
    {
        return (bool) config('services.openai.key');
    }

    /**
     * @return array{hold: bool, flagged: array<string, float>, scores: array<string, float>}
     *
     * @throws RuntimeException when the check can't be completed
     */
    public static function check(Dispatch $dispatch): array
    {
        $input = [['type' => 'text', 'text' => $dispatch->body !== '' ? $dispatch->body : ' ']];

        foreach ($dispatch->images as $image) {
            $data = Storage::disk('public')->get($image->path);
            if ($data !== null) {
                $input[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/webp;base64,'.base64_encode($data)]];
            }
        }

        $response = Http::withToken(config('services.openai.key'))->timeout(10)->retry(2, 500, throw: false)
            ->post('https://api.openai.com/v1/moderations', ['model' => 'omni-moderation-latest', 'input' => $input]);

        if (! $response->successful() || ! is_array($scores = $response->json('results.0.category_scores'))) {
            throw new RuntimeException('OpenAI moderation failed: HTTP '.$response->status());
        }

        $scores = array_map('floatval', $scores);
        $flagged = [];
        foreach (config('stoop.openai_thresholds') as $category => $threshold) {
            if (($scores[$category] ?? 0) >= $threshold) {
                $flagged[$category] = round($scores[$category], 3);
            }
        }
        arsort($flagged);

        return ['hold' => $flagged !== [], 'flagged' => $flagged, 'scores' => $scores];
    }
}
