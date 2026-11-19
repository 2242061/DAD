<?php

namespace Tests\Unit;

use App\Services\CheckersEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CheckersEngineTest extends TestCase
{
    private CheckersEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new CheckersEngine;
    }

    private function position(array $pieces, string $turn = 'white'): array
    {
        $board = array_fill(0, 32, null);
        foreach ($pieces as $square => $piece) {
            $board[$square - 1] = $piece;
        }

        return $this->engine->position($board, $turn);
    }

    private function paths(array $state): array
    {
        return array_column($this->engine->legalMoves($state), 'path');
    }

    public function test_initial_position_has_twelve_each_and_white_starts_with_seven_moves(): void
    {
        $state = $this->engine->initial();
        $this->assertSame('white', $state['turn']);
        $this->assertSame(12, count(array_filter($state['board'], fn ($p) => $p === 'w')));
        $this->assertSame(12, count(array_filter($state['board'], fn ($p) => $p === 'b')));
        $this->assertCount(7, $this->paths($state));
        $this->assertSame([7, 7], $this->engine->coordinates(1));
        $this->assertSame([0, 0], $this->engine->coordinates(32));
    }

    public function test_pawns_move_and_capture_only_forward_for_both_colors(): void
    {
        $this->assertEqualsCanonicalizing([[10, 13], [10, 14]], $this->paths($this->position([10 => 'w', 6 => 'b'])));
        $this->assertEqualsCanonicalizing([[23, 19], [23, 20]], $this->paths($this->position([23 => 'b', 27 => 'w'], 'black')));
        $this->assertSame([[10, 19]], $this->paths($this->position([10 => 'w', 14 => 'b'])));
        $this->assertSame([[23, 14]], $this->paths($this->position([23 => 'b', 19 => 'w'], 'black')));
    }

    public function test_capture_is_compulsory_across_all_pieces_and_must_finish_the_sequence(): void
    {
        $state = $this->position([1 => 'w', 10 => 'w', 14 => 'b', 23 => 'b']);
        $this->assertSame([[10, 19, 28]], $this->paths($state));
        $this->expectException(InvalidArgumentException::class);
        $this->engine->move($state, [10, 19]);
    }

    public function test_quantity_takes_precedence_over_captured_quality(): void
    {
        $state = $this->position([10 => 'w', 14 => 'b', 23 => 'b', 13 => 'B']);
        $this->assertSame([[10, 19, 28]], $this->paths($state));
    }

    public function test_quality_breaks_equal_quantity_ties(): void
    {
        $state = $this->position([10 => 'w', 14 => 'b', 13 => 'B']);
        $this->assertSame([[10, 17]], $this->paths($state));
    }

    public function test_king_and_pawn_may_both_capture_when_victims_have_equal_quality(): void
    {
        $paths = $this->paths($this->position([1 => 'W', 5 => 'b', 12 => 'w', 15 => 'b']));
        $this->assertContains([1, 10], $paths);
        $this->assertContains([12, 19], $paths);
    }

    public function test_flying_king_can_capture_distant_piece_and_choose_landing(): void
    {
        $paths = $this->paths($this->position([1 => 'W', 14 => 'b']));
        $this->assertEqualsCanonicalizing([[1, 19], [1, 23], [1, 28], [1, 32]], $paths);
        $this->assertSame([], array_filter($this->paths($this->position([1 => 'W', 5 => 'w'])), fn ($p) => $p[0] === 1));
    }

    public function test_contiguous_enemy_pieces_cannot_be_jumped_together(): void
    {
        $moves = $this->engine->legalMoves($this->position([1 => 'W', 10 => 'b', 14 => 'b']));
        $this->assertNotEmpty($moves);
        foreach ($moves as $move) {
            $this->assertSame([], $move['captures']);
        }
    }

    public function test_captured_pieces_remain_obstacles_and_cannot_be_recaptured(): void
    {
        $moves = $this->engine->legalMoves($this->position([14 => 'W', 10 => 'b', 19 => 'b']));
        foreach ($moves as $move) {
            $this->assertCount(1, $move['captures']);
            $this->assertCount(2, $move['path']);
        }
    }

    public function test_promotion_ends_turn_without_continuing_as_a_king(): void
    {
        $state = $this->position([21 => 'w', 26 => 'b', 27 => 'b']);
        $this->assertSame([[21, 30]], $this->paths($state));
        $state = $this->engine->move($state, [21, 30]);
        $this->assertSame('W', $state['board'][29]);
        $this->assertNull($state['board'][25]);
        $this->assertSame('b', $state['board'][26]);
        $this->assertSame('black', $state['turn']);
        $this->assertTrue($state['history'][0]['promoted']);
    }

    public function test_black_promotes_at_white_base(): void
    {
        $state = $this->engine->move($this->position([10 => 'b', 6 => 'w', 20 => 'w'], 'black'), [10, 3]);
        $this->assertSame('B', $state['board'][2]);
    }

    public function test_capturing_the_last_opponent_wins(): void
    {
        $state = $this->engine->move($this->position([10 => 'w', 14 => 'b']), [10, 19]);
        $this->assertSame('finished', $state['status']);
        $this->assertSame('white', $state['winner']);
        $this->assertSame('capture', $state['reason']);
        $this->assertSame([], $this->paths($state));
    }

    public function test_immobilizing_opponent_wins(): void
    {
        $state = $this->engine->move($this->position([1 => 'b', 20 => 'w']), [20, 24]);
        $this->assertSame('white', $state['winner']);
        $this->assertSame('blocked', $state['reason']);
    }

    public function test_threefold_repetition_includes_the_turn(): void
    {
        $state = $this->position([1 => 'W', 4 => 'B']);
        for ($i = 0; $i < 2; $i++) {
            foreach ([[1, 5], [4, 8], [5, 1], [8, 4]] as $path) {
                $state = $this->engine->move($state, $path);
            }
        }
        $this->assertSame('threefold', $state['reason']);
        $this->assertNull($state['winner']);
    }

    public function test_twenty_moves_requires_both_colors_and_resets_after_pawn_move(): void
    {
        $state = $this->position([1 => 'W', 2 => 'B', 9 => 'w']);
        $state['quiet'] = ['white' => 19, 'black' => 19];
        $state = $this->engine->move($state, [1, 5]);
        $this->assertSame('active', $state['status']);
        $state = $this->engine->move($state, [2, 6]);
        $this->assertSame('twenty_moves', $state['reason']);
        $state = $this->position([1 => 'W', 2 => 'B', 9 => 'w']);
        $state['quiet'] = ['white' => 19, 'black' => 19];
        $state = $this->engine->move($state, [9, 13]);
        $this->assertSame(['white' => 0, 'black' => 0], $state['quiet']);
    }

    public function test_twenty_move_rule_does_not_apply_with_five_pieces_on_one_side(): void
    {
        $state = $this->position([1 => 'W', 2 => 'B', 9 => 'w', 10 => 'w', 11 => 'w', 12 => 'w']);
        $state['quiet'] = ['white' => 19, 'black' => 20];
        $state = $this->engine->move($state, [1, 5]);
        $this->assertSame('active', $state['status']);
        $this->assertSame(['white' => 0, 'black' => 0], $state['quiet']);
    }

    public function test_forced_ending_counts_strong_side_moves_and_capture_on_twelfth_wins(): void
    {
        $state = $this->position([1 => 'W', 3 => 'W', 4 => 'W', 2 => 'B']);
        $state['forced']['moves'] = 11;
        $state = $this->engine->move($state, [1, 5]);
        $this->assertSame('forced_twelve', $state['reason']);
        $state = $this->position([1 => 'W', 3 => 'W', 4 => 'W', 14 => 'B']);
        $state['forced']['moves'] = 11;
        $state = $this->engine->move($state, [1, 19]);
        $this->assertSame('capture', $state['reason']);
        $this->assertSame('white', $state['winner']);
    }

    public function test_king_can_return_to_start_during_a_multiple_capture(): void
    {
        $state = $this->position([14 => 'W', 18 => 'b', 26 => 'b', 27 => 'b', 19 => 'b']);
        $this->assertContains([14, 21, 30, 23, 14], $this->paths($state));
        $state = $this->engine->move($state, [14, 21, 30, 23, 14]);
        $this->assertSame('W', $state['board'][13]);
        $this->assertSame(1, count(array_filter($state['board'])));
    }

    public function test_forced_counter_starts_after_occupying_rio_and_cannot_be_reset_by_leaving_it(): void
    {
        $state = $this->position([9 => 'W', 3 => 'W', 4 => 'W', 2 => 'B']);
        $this->assertNull($state['forced']);
        $state = $this->engine->move($state, [9, 5]);
        $this->assertSame(0, $state['forced']['moves']);
        $state = $this->position([5 => 'W', 3 => 'W', 4 => 'W', 2 => 'B']);
        $state['forced']['moves'] = 11;
        $state = $this->engine->move($state, [5, 9]);
        $this->assertSame('forced_twelve', $state['reason']);
    }

    public function test_resignation_awards_opponent_and_prevents_further_moves(): void
    {
        $state = $this->engine->resign($this->engine->initial());
        $this->assertSame('black', $state['winner']);
        $this->expectException(InvalidArgumentException::class);
        $this->engine->move($state, [9, 13]);
    }

    public function test_complete_deterministic_games_preserve_material_and_turn_invariants(): void
    {
        for ($seed = 1; $seed <= 4; $seed++) {
            $state = $this->engine->initial();
            for ($ply = 0; $ply < 200 && $state['status'] === 'active'; $ply++) {
                $moves = $this->engine->legalMoves($state);
                $move = $moves[($seed * 31 + $ply * 17) % count($moves)];
                $before = count(array_filter($state['board']));
                $turn = $state['turn'];
                $state = $this->engine->move($state, $move['path']);
                $this->assertSame($before - count($move['captures']), count(array_filter($state['board'])));
                $this->assertNotSame($turn, $state['turn']);
                $this->assertCount(32, $state['board']);
            }
        }
    }
}
