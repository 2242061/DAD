<?php

namespace App\Services;

use App\Models\OnlineGame;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GameRealtime
{
    public function ticket(OnlineGame $game, string $color): string
    {
        $secret = config('services.realtime.secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'O serviço de tempo real precisa de configuração.');
        $payload = $this->base64url(json_encode([
            'aud' => 'dama-game', 'game' => $game->id, 'color' => $color, 'exp' => now()->timestamp + 90,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->base64url(hash_hmac('sha256', $payload, $secret, true));
    }

    /** The committed database state remains available even when delivery fails. */
    public function publish(OnlineGame $game): void
    {
        try {
            Http::connectTimeout(1)->timeout(2)->withToken(config('services.realtime.secret') ?? '')
                ->post(config('services.realtime.url').'/internal/games', [
                    'game' => $game->id, 'revision' => $game->revision,
                ])->throw();
        } catch (Throwable) {
            Log::warning('Game realtime notification unavailable.', ['game' => $game->id, 'revision' => $game->revision]);
        }
    }

    private function base64url(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
