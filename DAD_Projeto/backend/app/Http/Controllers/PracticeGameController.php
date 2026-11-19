<?php

namespace App\Http\Controllers;

use App\Services\CheckersEngine;
use App\Services\GameClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PracticeGameController extends Controller
{
    public function __construct(private CheckersEngine $engine, private GameClock $clock) {}

    public function show(Request $request): JsonResponse
    {
        return $this->response($this->read($request));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['board' => 'prohibited', 'state' => 'prohibited']);
        $state = $this->clock->practice($this->engine->initial());
        $state['id'] = (string) Str::uuid();
        $request->session()->put($this->sessionKey($request), $state);

        return $this->response($state, 201);
    }

    public function update(Request $request): JsonResponse
    {
        $input = $request->validate([
            'id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0'],
            'action' => ['required', 'in:move,resign,claim_draw'],
            'path' => ['required_if:action,move', 'array', 'min:2', 'max:13'],
            'path.*' => ['required', 'integer', 'between:1,32'],
            'board' => 'prohibited', 'state' => 'prohibited', 'turn' => 'prohibited',
        ]);
        $state = $this->read($request);
        abort_if($state === null, 404, 'Começa uma partida local.');
        abort_if($state['id'] !== $input['id'] || $state['revision'] !== (int) $input['revision'], 409, 'A partida mudou noutra janela. O tabuleiro foi atualizado; escolhe novamente a jogada.');
        try {
            if ($input['action'] === 'claim_draw') {
                $draw = $this->engine->technicalDraw($state);
                if ($draw === null) {
                    throw ValidationException::withMessages(['action' => 'O motor não conseguiu comprovar o empate técnico nesta posição.']);
                }
                $state = $draw;
                $state['revision']++;
            } else {
                $state = $input['action'] === 'resign' ? $this->engine->resign($state)
                    : $this->engine->move($state, array_map(intval(...), $input['path']));
                $state = $this->engine->adjudicateEndgame($state);
                if ($this->isTraining($request) && $state['status'] === 'active') {
                    $state = $this->engine->adjudicateEndgame($this->botMove($state));
                }
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }
        $state['clock']['deadline_at'] = $state['status'] === 'active' ? now()->getTimestampMs() + 60000 : null;
        $state['clock']['finished_at'] = $state['status'] === 'finished' ? now()->getTimestampMs() : null;
        $state['clock']['server_now'] = now()->getTimestampMs();
        $request->session()->put($this->sessionKey($request), $state);

        return $this->response($state);
    }

    private function isTraining(Request $request): bool
    {
        return $request->query('mode') === 'bot';
    }

    private function sessionKey(Request $request): string
    {
        return $this->isTraining($request) ? 'training' : 'practice';
    }

    private function botMove(array $state): array
    {
        $best = null;
        $bestScore = -INF;
        foreach ($this->engine->legalMoves($state) as $move) {
            $candidate = $this->engine->move($state, $move['path']);
            $score = $this->score($candidate);
            if ($candidate['status'] === 'active') {
                $score = min(array_map(
                    fn (array $reply): int => $this->score($this->engine->move($candidate, $reply['path'])),
                    $this->engine->legalMoves($candidate)
                ));
            }
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function score(array $state): int
    {
        if ($state['status'] === 'finished') {
            return match ($state['winner']) {
                'black' => 1000,
                'white' => -1000,
                default => 0,
            };
        }

        $pieces = array_count_values(array_filter($state['board']));

        return 3 * (($pieces['B'] ?? 0) - ($pieces['W'] ?? 0)) + ($pieces['b'] ?? 0) - ($pieces['w'] ?? 0);
    }

    private function read(Request $request): ?array
    {
        $state = $request->session()->get($this->sessionKey($request));
        if ($state !== null) {
            $state = $this->clock->practice($state);
            $request->session()->put($this->sessionKey($request), $state);
        }

        return $state;
    }

    private function response(?array $state, int $status = 200): JsonResponse
    {
        if ($state !== null) {
            $state = array_intersect_key($state, array_flip(['id', 'board', 'turn', 'status', 'winner', 'reason', 'revision', 'history', 'quiet', 'forced', 'clock']));
            $state['legal_moves'] = $this->engine->legalMoves($state);
        }

        return response()->json(['data' => $state], $status)->header('Cache-Control', 'private, no-store');
    }
}
