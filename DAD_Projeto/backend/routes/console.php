<?php

use App\Models\OnlineGame;
use App\Models\Tournament;
use App\Services\GameClock;
use App\Services\TournamentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Tournament::where('status', 'active')->pluck('id')->each(fn (int $id) => app(TournamentService::class)->synchronize($id));
})->name('tournaments:advance')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    $clock = app(GameClock::class);
    OnlineGame::whereNull('tournament_id')->where('phase', 'active')
        ->where(function ($query): void {
            $now = now();
            $stale = $now->copy()->subSeconds(GameClock::ABANDON_SECONDS)->format('Y-m-d H:i:s.v');
            $query->where('turn_deadline_at', '<=', $now->format('Y-m-d H:i:s.v'))
                ->orWhere('white_seen_at', '<=', $stale)->orWhere('black_seen_at', '<=', $stale);
        })
        ->eachById(fn (OnlineGame $game) => $clock->synchronize($game));
})->name('games:expire-online')->everyMinute()->withoutOverlapping();
