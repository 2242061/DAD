<?php

namespace App\Http\Controllers;

use App\Models\OnlineGame;
use App\Models\PlatformSetting;
use App\Models\RankEntry;
use App\Models\User;
use App\Services\CheckersEngine;
use App\Services\CompetitionWallet;
use App\Services\CompetitiveRank;
use App\Services\GameClock;
use App\Services\GameRealtime;
use App\Services\TournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OnlineGameController extends Controller
{
    public function __construct(private CheckersEngine $engine, private GameRealtime $realtime, private GameClock $clock, private CompetitiveRank $ranks) {}

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['mode' => ['sometimes', 'in:casual,ranked'], 'discoverable' => ['sometimes', 'boolean'], 'board' => 'prohibited', 'state' => 'prohibited', 'color' => 'prohibited', 'points' => 'prohibited']);
        $mode = $input['mode'] ?? 'casual';
        abort_if($mode === 'ranked' && ! $request->user(), 401, 'Entra na tua conta para jogar no competitivo.');
        $key = $this->playerKey($request);
        $discoverable = (bool) ($input['discoverable'] ?? false);
        $waiting = OnlineGame::where('mode', $mode)->where('phase', 'waiting')->where('discoverable', $discoverable);
        $game = ($mode === 'ranked' ? $waiting->where('white_user_id', $request->user()->id) : $waiting->where('white_key', $key))->first();
        if ($game === null) {
            $game = OnlineGame::create([
                'code' => strtoupper(Str::random(10)), 'white_key' => $key,
                'white_user_id' => $request->user()?->id, 'state' => $this->engine->initial(),
                'phase' => 'waiting', 'revision' => 0, 'mode' => $mode,
                'entry_fee' => $mode === 'ranked' ? CompetitionWallet::RANKED_FEE : 0, 'discoverable' => $discoverable,
                'turn_seconds' => PlatformSetting::values()['online_turn_seconds'],
            ]);
        }

        if ($game->phase === 'waiting' && $game->white_user_id === null && $request->user()) {
            OnlineGame::whereKey($game->id)->where('phase', 'waiting')->whereNull('white_user_id')
                ->update(['white_user_id' => $request->user()->id]);
            $game->refresh();
        }

        return $this->response($game, $request, 201);
    }

    public function openRooms(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $userId = $request->user()?->id;
        $availableUsers = User::whereNull('blocked_at')->select('id');
        $rooms = OnlineGame::where('discoverable', true)->where('phase', 'waiting')->whereNull('black_key')
            ->whereIn('mode', ['casual', 'ranked'])->where('white_key', '!=', $this->playerKey($request))
            ->where(fn ($query) => $query->where(fn ($guest) => $guest->whereNull('white_user_id')->where('mode', 'casual'))
                ->orWhereIn('white_user_id', $availableUsers))
            ->when($userId, fn ($query) => $query->where(fn ($owner) => $owner->whereNull('white_user_id')->orWhere('white_user_id', '!=', $userId)))
            ->latest()->orderBy('id')->simplePaginate(12);
        $names = User::whereIn('id', $rooms->getCollection()->pluck('white_user_id'))->pluck('name', 'id');

        return response()->json([
            'data' => $rooms->getCollection()->map(fn (OnlineGame $game) => [
                'code' => $game->code, 'mode' => $game->mode, 'entry_fee' => (int) $game->entry_fee,
                'host' => $names[$game->white_user_id] ?? 'Visitante',
            ]),
            'ranked_entry_fee' => CompetitionWallet::RANKED_FEE,
            'page' => $rooms->currentPage(),
            'has_more' => $rooms->hasMorePages(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function invitation(Request $request, string $code): JsonResponse
    {
        $game = $this->game($code);

        return response()->json(['data' => ['code' => $game->code, 'mode' => $game->mode, 'phase' => $game->phase, 'entry_fee' => (int) $game->entry_fee]])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $game = $this->game($code);
        $key = $this->playerKey($request);
        $color = $this->color($request, $game, $key);
        abort_unless($color, 403, 'Entra na sala para veres a partida. Usa outro navegador ou uma janela privada para o segundo jogador.');

        return $this->response($this->clock->playerSeen($game, $color), $request);
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $game = $this->game($code);
        $key = $this->playerKey($request);
        if ($this->color($request, $game, $key)) {
            return $this->response($game, $request);
        }
        if ($game->mode === 'ranked') {
            abort_unless(User::whereKey($game->white_user_id)->whereNull('blocked_at')->exists(), 409, 'O criador desta sala já não está disponível.');
        }
        abort_if($request->user() && $game->white_user_id === $request->user()->id, 409, 'Esta conta já joga com as brancas. O adversário deve usar outra conta ou entrar como visitante.');
        abort_unless($game->phase === 'waiting', 409, 'Esta sala já está completa ou terminou.');
        if ($game->mode === 'ranked') {
            $quote = $request->validate(['entry_fee' => ['required', 'integer', 'min:0']]);
            abort_unless((int) $quote['entry_fee'] === (int) $game->entry_fee, 409, 'O valor da entrada mudou. Volta a confirmar o convite.');
        }
        $state = $game->state;
        $state['revision'] = $game->revision + 1;
        $now = now()->format('Y-m-d H:i:s.v');
        DB::transaction(function () use ($game, $request, $key, $state, $now): void {
            $changed = OnlineGame::whereKey($game->id)->where('phase', 'waiting')->whereNull('black_key')->where('revision', $game->revision)
                ->update([
                    'black_key' => $key, 'black_user_id' => $request->user()?->id,
                    'phase' => 'active', 'revision' => $state['revision'], 'state' => json_encode($state),
                    'started_at' => $now, 'turn_deadline_at' => now()->addSeconds($game->turn_seconds)->format('Y-m-d H:i:s.v'),
                    'white_seen_at' => $now, 'black_seen_at' => $now,
                ]);
            abort_unless($changed === 1, 409, 'Outra pessoa acabou de entrar nesta sala.');
            if ($game->mode === 'ranked') {
                $wallet = app(CompetitionWallet::class);
                $wallet->move($game->white_user_id, -(int) $game->entry_fee, 'entry', 'Competitivo '.$game->code, 'O criador da sala não tem Damas suficientes para pagar a entrada.');
                $wallet->move($request->user()->id, -(int) $game->entry_fee, 'entry', 'Competitivo '.$game->code);
            }
        });
        $game->refresh();
        $this->realtime->publish($game);

        return $this->response($game, $request);
    }

    public function ticket(Request $request, string $code): JsonResponse
    {
        $game = $this->game($code);
        $color = $this->color($request, $game, $this->playerKey($request));
        abort_unless($color, 403, 'Entra na sala para receberes as atualizações.');

        return response()->json(['ticket' => $this->realtime->ticket($game, $color)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:0'], 'action' => ['required', 'in:move,resign,offer_draw,accept_draw,decline_draw,claim_draw'],
            'path' => ['required_if:action,move', 'array', 'min:2', 'max:13'],
            'path.*' => ['required', 'integer', 'between:1,32'],
            'board' => 'prohibited', 'state' => 'prohibited', 'turn' => 'prohibited', 'color' => 'prohibited', 'mode' => 'prohibited', 'points' => 'prohibited', 'rank_points' => 'prohibited',
        ]);
        $game = $this->game($code);
        $key = $this->playerKey($request);
        $color = $this->color($request, $game, $key);
        abort_unless($color, 403, 'Não és um dos jogadores desta partida.');
        $game = $this->clock->playerSeen($game, $color);
        abort_if($game->revision !== (int) $input['revision'], 409, 'A partida mudou. O tabuleiro foi atualizado; escolhe novamente a jogada.');
        abort_if($game->phase === 'finished', 409, 'Esta partida já terminou.');
        $state = $game->state;
        $action = $input['action'];
        $drawOffer = $state['draw_offer'] ?? null;
        if ($action === 'resign') {
            unset($state['draw_offer']);
            $state['status'] = 'finished';
            $state['winner'] = $game->phase === 'waiting' ? null : ($color === 'white' ? 'black' : 'white');
            $state['reason'] = $game->phase === 'waiting' ? 'cancelled' : 'resignation';
            $state['revision']++;
        } elseif ($action !== 'move') {
            abort_unless($game->phase === 'active', 409, 'Aguarda a entrada do segundo jogador.');
            if ($action === 'claim_draw') {
                abort_unless($state['turn'] === $color, 403, 'Só podes reclamar um empate técnico na tua vez.');
                $draw = $this->engine->technicalDraw($state);
                if ($draw === null) {
                    throw ValidationException::withMessages(['action' => 'O motor não conseguiu comprovar o empate técnico nesta posição. A partida continua.']);
                }
                $state = $draw;
            } elseif ($action === 'offer_draw') {
                abort_unless(array_any($state['history'], fn (array $entry): bool => $entry['path'] !== []), 409, 'O primeiro lance tem de ser jogado antes de propor empate.');
                abort_if($drawOffer !== null, 409, 'Já existe uma proposta de empate.');
                $state['draw_offer'] = $color;
            } else {
                abort_if($drawOffer === null || $drawOffer === $color, 409, 'O adversário ainda não propôs um empate.');
                unset($state['draw_offer']);
                if ($action === 'accept_draw') {
                    $state['status'] = 'finished';
                    $state['winner'] = null;
                    $state['reason'] = 'agreement';
                }
            }
            $state['revision']++;
        } else {
            abort_unless($game->phase === 'active', 409, 'Aguarda a entrada do segundo jogador.');
            abort_unless($state['turn'] === $color, 403, 'Aguarda a tua vez. Só podes jogar com a tua cor.');
            try {
                $state = $this->engine->move($state, array_map(intval(...), $input['path']));
                $state = $this->engine->adjudicateEndgame($state);
                if ($drawOffer !== $color || $state['status'] === 'finished') {
                    unset($state['draw_offer']);
                }
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['path' => $exception->getMessage()]);
            }
        }
        $deadline = $action === 'move' ? now()->addSeconds($game->turn_seconds) : $game->turn_deadline_at;
        $game = $this->ranks->commit($game, [
            'state' => json_encode($state), 'revision' => $state['revision'],
            'phase' => $state['status'] === 'finished' ? 'finished' : 'active',
            'finished_at' => $state['status'] === 'finished' ? now()->format('Y-m-d H:i:s.v') : null,
            'turn_deadline_at' => $state['status'] === 'finished' ? null : $deadline?->format('Y-m-d H:i:s.v'),
        ]);
        abort_if($game === null, 409, 'Outra ação alterou a partida. Atualiza o tabuleiro.');
        $this->realtime->publish($game);

        return $this->response($game, $request);
    }

    private function game(string $code): OnlineGame
    {
        abort_unless(preg_match('/^[A-Z0-9]{10}$/', $code), 404);

        $game = OnlineGame::where('code', $code)->first();
        abort_if($game === null, 404, 'Sala não encontrada. Confirma o código do convite.');

        return $game;
    }

    private function playerKey(Request $request): string
    {
        if (! $request->session()->has('online_player')) {
            $request->session()->put('online_player', Str::random(64));
        }

        return hash('sha256', $request->session()->get('online_player'));
    }

    private function color(Request $request, OnlineGame $game, string $key): ?string
    {
        if (! in_array($game->mode, ['ranked', 'tournament'], true)) {
            return $game->colorFor($key);
        }
        abort_unless($request->user(), 401, 'Entra na tua conta para jogar no competitivo.');
        $id = $request->user()->id;

        return $game->white_user_id === $id ? 'white' : ($game->black_user_id === $id ? 'black' : null);
    }

    private function response(OnlineGame $game, Request $request, int $status = 200): JsonResponse
    {
        if ($game->tournament_id) {
            app(TournamentService::class)->synchronize($game->tournament_id);
            $game->refresh();
        }
        $game = $this->clock->synchronize($game);
        $this->ranks->settle($game);
        $key = $this->playerKey($request);
        $state = array_intersect_key($game->state, array_flip(['board', 'turn', 'status', 'winner', 'reason', 'revision', 'history', 'quiet', 'forced', 'draw_offer']));
        $state += ['id' => $game->id, 'code' => $game->code, 'phase' => $game->phase, 'my_color' => $this->color($request, $game, $key), 'mode' => $game->mode, 'entry_fee' => (int) $game->entry_fee, 'tournament_id' => $game->tournament_id];
        $entry = $game->mode === 'ranked' && $request->user() ? RankEntry::where('game_id', $game->id)->where('user_id', $request->user()->id)->first() : null;
        $state['rank_change'] = $entry ? ['delta' => $entry->delta, 'before' => $entry->previous_points, 'after' => $entry->resulting_points, 'tier' => $this->ranks->tier($entry->resulting_points)] : null;
        $state['discoverable'] = (bool) $game->discoverable;
        $state['wallet_reward'] = $game->mode === 'ranked' && $game->phase === 'finished' && $game->black_user_id && $game->ranked_scored_at
            ? ($state['winner'] === null ? (int) $game->entry_fee : ($state['winner'] === $state['my_color'] ? 2 * $game->entry_fee : 0)) : 0;
        $state['clock'] = $this->clock->data($game);
        $state['legal_moves'] = $game->phase === 'active' && $state['my_color'] === $state['turn'] ? $this->engine->legalMoves($game->state) : [];

        return response()->json(['data' => $state], $status)->header('Cache-Control', 'private, no-store');
    }
}
