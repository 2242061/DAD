<?php

namespace App\Services;

use InvalidArgumentException;

/** Portuguese rules, articles 3.1–3.9 and 5; squares 1–32 from White's right. */
class CheckersEngine
{
    public function initial(): array
    {
        $board = array_fill(0, 32, null);
        for ($i = 0; $i < 12; $i++) {
            $board[$i] = 'w';
            $board[31 - $i] = 'b';
        }

        return $this->position($board);
    }

    /** Lowercase = man; uppercase = king. Board index = square minus one. */
    public function position(array $board, string $turn = 'white'): array
    {
        if (count($board) !== 32 || ! array_is_list($board) || ! in_array($turn, ['white', 'black'], true)) {
            throw new InvalidArgumentException('Invalid position.');
        }
        foreach ($board as $piece) {
            if (! in_array($piece, [null, 'w', 'W', 'b', 'B'], true)) {
                throw new InvalidArgumentException('Invalid piece.');
            }
        }
        $state = [
            'board' => $board, 'turn' => $turn, 'status' => 'active',
            'winner' => null, 'reason' => null, 'revision' => 0, 'history' => [],
            'repetitions' => [], 'quiet' => ['white' => 0, 'black' => 0], 'forced' => null,
        ];
        $state['repetitions'][$this->key($state)] = 1;
        $state['forced'] = $this->forcedEnding($board);

        return $state;
    }

    public function legalMoves(array $state): array
    {
        if ($state['status'] !== 'active') {
            return [];
        }
        $captures = [];
        $quiet = [];
        foreach ($state['board'] as $index => $piece) {
            if ($piece === null || $this->color($piece) !== $state['turn']) {
                continue;
            }
            $square = $index + 1;
            $this->capturePaths($state['board'], $square, $piece, [$square], [], $captures);
            [$row, $col] = $this->coordinates($square);
            foreach ($this->directions($piece) as [$dr, $dc]) {
                for ($distance = 1; $distance <= (ctype_upper($piece) ? 7 : 1); $distance++) {
                    $target = $this->square($row + $dr * $distance, $col + $dc * $distance);
                    if ($target === null || $state['board'][$target - 1] !== null) {
                        break;
                    }
                    $quiet[] = ['path' => [$square, $target], 'captures' => [], 'kings' => 0];
                }
            }
        }
        if ($captures === []) {
            return $quiet;
        }
        $quantity = max(array_map(fn (array $move): int => count($move['captures']), $captures));
        $captures = array_filter($captures, fn (array $move): bool => count($move['captures']) === $quantity);
        $quality = max(array_column($captures, 'kings'));

        return array_values(array_filter($captures, fn (array $move): bool => $move['kings'] === $quality));
    }

    public function move(array $state, array $path): array
    {
        $match = null;
        foreach ($this->legalMoves($state) as $move) {
            if ($move['path'] === $path) {
                $match = $move;
                break;
            }
        }
        if ($match === null) {
            throw new InvalidArgumentException('Jogada ilegal. Escolhe um dos destinos assinalados e completa todas as capturas.');
        }
        $moving = $state['turn'];
        $piece = $state['board'][$path[0] - 1];
        [$state['board'], $promoted] = $this->applyMove($state['board'], $match);
        $state['turn'] = $this->opponent($moving);
        $state['revision']++;
        $state['history'][] = [
            'color' => $moving, 'path' => $path, 'captures' => $match['captures'],
            'notation' => implode($match['captures'] === [] ? '-' : 'x', $path), 'promoted' => $promoted,
        ];
        if ($this->legalMoves($state) === []) {
            $hasOpponent = array_any($state['board'], fn (?string $p): bool => $p !== null && $this->color($p) === $state['turn']);

            return $this->finish($state, $moving, $hasOpponent ? 'blocked' : 'capture');
        }
        $key = $this->key($state);
        $state['repetitions'][$key] = ($state['repetitions'][$key] ?? 0) + 1;
        if ($state['repetitions'][$key] >= 3) {
            return $this->finish($state, null, 'threefold');
        }
        $previousForced = $state['forced'];
        $ending = $this->forcedEnding($state['board'], $previousForced !== null);
        if ($ending !== null && $previousForced !== null && $ending['color'] === $previousForced['color']) {
            $ending['moves'] = $previousForced['moves'] + ($moving === $ending['color'] ? 1 : 0);
        }
        $state['forced'] = $ending;
        if ($ending !== null && $ending['moves'] >= 12) {
            return $this->finish($state, null, 'forced_twelve');
        }
        if ($ending === null && $this->smallEnding($state['board']) && ctype_upper($piece) && $match['captures'] === []) {
            $state['quiet'][$moving]++;
        } else {
            $state['quiet'] = ['white' => 0, 'black' => 0];
        }
        if (min($state['quiet']) >= 20) {
            return $this->finish($state, null, 'twenty_moves');
        }

        return $state;
    }

    public function adjudicateEndgame(array $state): array
    {
        if (count(array_filter($state['board'])) === 2) {
            return $this->technicalDraw($state) ?? $state;
        }

        return $state;
    }

    public function technicalDraw(array $state): ?array
    {
        if ($state['status'] !== 'active' || ! $this->isDrawnEnding($state)) {
            return null;
        }

        unset($state['draw_offer']);

        return $this->finish($state, null, 'dead_position');
    }

    private function isDrawnEnding(array $state): bool
    {
        $root = $this->key($state);
        $queue = [[$root, array_intersect_key($state, array_flip(['board', 'turn', 'status']))]];
        $seen = [$root => true];
        $parents = $remaining = $outcomes = $frontier = [];
        for ($i = 0; $i < count($queue); $i++) {
            [$key, $position] = $queue[$i];
            $moves = $this->legalMoves($position);
            if ($moves === []) {
                $outcomes[$key] = false;

                continue;
            }

            $remaining[$key] = count($moves);
            foreach ($moves as $move) {
                $next = $position;
                [$next['board']] = $this->applyMove($position['board'], $move);
                $next['turn'] = $this->opponent($position['turn']);
                $nextKey = $this->key($next);
                if (! isset($seen[$nextKey])) {
                    if (count($queue) < 6000) {
                        $seen[$nextKey] = true;
                        $queue[] = [$nextKey, $next];
                    } else {
                        $nextKey = 'unexplored:'.$next['turn'];
                        $frontier[$nextKey] = $next['turn'];
                    }
                }
                $parents[$nextKey][] = $key;
            }
        }

        // Prove a strategy to avoid defeat for each side, even if every unexplored
        // continuation is assumed to favor its opponent.
        foreach (['white', 'black'] as $defender) {
            $pessimistic = $outcomes;
            foreach ($frontier as $key => $turn) {
                $pessimistic[$key] = $turn !== $defender;
            }
            $rootWin = $this->solveEndgame($root, $parents, $remaining, $pessimistic);
            if ($rootWin !== null && $rootWin !== ($state['turn'] === $defender)) {
                return false;
            }
        }

        return true;
    }

    private function solveEndgame(string $root, array $parents, array $remaining, array $outcomes): ?bool
    {
        $resolved = array_keys($outcomes);
        for ($i = 0; $i < count($resolved); $i++) {
            $child = $resolved[$i];
            foreach ($parents[$child] ?? [] as $parent) {
                if (isset($outcomes[$parent])) {
                    continue;
                }
                if (! $outcomes[$child] || --$remaining[$parent] === 0) {
                    $outcomes[$parent] = ! $outcomes[$child];
                    $resolved[] = $parent;
                }
            }
        }

        return $outcomes[$root] ?? null;
    }

    public function resign(array $state): array
    {
        if ($state['status'] !== 'active') {
            throw new InvalidArgumentException('Esta partida já terminou.');
        }
        $state['revision']++;

        return $this->finish($state, $this->opponent($state['turn']), 'resignation');
    }

    private function applyMove(array $board, array $move): array
    {
        $from = $move['path'][0];
        $target = $move['path'][count($move['path']) - 1];
        $piece = $board[$from - 1];
        $board[$from - 1] = null;
        foreach ($move['captures'] as $square) {
            $board[$square - 1] = null;
        }
        $promoted = $this->promotion($piece, $target);
        $board[$target - 1] = $promoted ? strtoupper($piece) : $piece;

        return [$board, $promoted];
    }

    /** Captured pieces remain obstacles until the entire turn finishes (article 3.8). */
    private function capturePaths(array $board, int $from, string $piece, array $path, array $captured, array &$results): void
    {
        $next = [];
        if (! $this->promotion($piece, $from)) {
            [$row, $col] = $this->coordinates($from);
            foreach ($this->directions($piece) as [$dr, $dc]) {
                $victim = null;
                for ($distance = 1; $distance <= (ctype_upper($piece) ? 7 : 2); $distance++) {
                    $target = $this->square($row + $dr * $distance, $col + $dc * $distance);
                    if ($target === null) {
                        break;
                    }
                    $occupant = $board[$target - 1];
                    if ($occupant !== null) {
                        if ($victim !== null || $this->color($occupant) === $this->color($piece) || in_array($target, $captured, true)) {
                            break;
                        }
                        $victim = $target;

                        continue;
                    }
                    if ($victim !== null) {
                        $next[] = [$target, $victim];
                    } elseif (! ctype_upper($piece)) {
                        break;
                    }
                }
            }
        }
        if ($next === [] && $captured !== []) {
            $results[] = [
                'path' => $path, 'captures' => $captured,
                'kings' => count(array_filter($captured, fn (int $s): bool => ctype_upper($board[$s - 1]))),
            ];
        }
        foreach ($next as [$target, $victim]) {
            $copy = $board;
            $copy[$from - 1] = null;
            $copy[$target - 1] = $piece;
            $this->capturePaths($copy, $target, $piece, [...$path, $target], [...$captured, $victim], $results);
        }
    }

    private function directions(string $piece): array
    {
        return ctype_upper($piece) ? [[-1, -1], [-1, 1], [1, -1], [1, 1]]
            : ($piece === 'w' ? [[-1, -1], [-1, 1]] : [[1, -1], [1, 1]]);
    }

    public function coordinates(int $square): array
    {
        $row = 7 - intdiv($square - 1, 4);

        return [$row, ($row % 2 === 1 ? 7 : 6) - 2 * (($square - 1) % 4)];
    }

    private function square(int $row, int $col): ?int
    {
        if ($row < 0 || $row > 7 || $col < 0 || $col > 7 || ($row + $col) % 2 !== 0) {
            return null;
        }

        return (7 - $row) * 4 + intdiv(7 - $col, 2) + 1;
    }

    private function promotion(string $piece, int $square): bool
    {
        return ($piece === 'w' && $square >= 29) || ($piece === 'b' && $square <= 4);
    }

    private function color(string $piece): string
    {
        return strtolower($piece) === 'w' ? 'white' : 'black';
    }

    private function opponent(string $color): string
    {
        return $color === 'white' ? 'black' : 'white';
    }

    private function key(array $state): string
    {
        return $state['turn'].':'.implode('', array_map(fn (?string $p): string => $p ?? '.', $state['board']));
    }

    private function smallEnding(array $board): bool
    {
        foreach (['w', 'b'] as $color) {
            $pieces = array_filter($board, fn (?string $p): bool => $p !== null && strtolower($p) === $color);
            if (count($pieces) > 4 || ! in_array(strtoupper($color), $pieces, true)) {
                return false;
            }
        }

        return true;
    }

    private function forcedEnding(array $board, bool $alreadyCounting = false): ?array
    {
        $pieces = array_filter($board, fn (?string $p): bool => $p !== null);
        if (count($pieces) !== 4 || array_any($pieces, fn (string $p): bool => ctype_lower($p))) {
            return null;
        }
        foreach (['W' => 'white', 'B' => 'black'] as $piece => $color) {
            if (count(array_filter($pieces, fn (string $p): bool => $p === $piece)) !== 3) {
                continue;
            }
            if ($alreadyCounting) {
                return ['color' => $color, 'moves' => 0];
            }
            foreach ($board as $index => $occupant) {
                [$row, $col] = $this->coordinates($index + 1);
                if ($occupant === $piece && $row === $col) {
                    return ['color' => $color, 'moves' => 0];
                }
            }
        }

        return null;
    }

    private function finish(array $state, ?string $winner, string $reason): array
    {
        $state['status'] = 'finished';
        $state['winner'] = $winner;
        $state['reason'] = $reason;

        return $state;
    }
}
