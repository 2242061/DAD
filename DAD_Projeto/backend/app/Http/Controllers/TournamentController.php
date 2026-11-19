<?php

namespace App\Http\Controllers;

use App\Models\OnlineGame;
use App\Models\PlatformSetting;
use App\Models\RankEntry;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\CompetitionWallet;
use App\Services\CompetitiveRank;
use App\Services\TournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    public function __construct(private TournamentService $tournaments, private CompetitiveRank $ranks) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', 'in:registration,active,finished,cancelled']]);
        Tournament::where('status', 'active')->pluck('id')->each(fn (int $id) => $this->tournaments->synchronize($id));
        $rows = Tournament::when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))->latest('id')->paginate(12);
        $data = $rows->getCollection()->map(fn (Tournament $t): array => $this->summary($t));

        return response()->json(['data' => $data, 'tiers' => $this->ranks->tiers(), 'prizes' => TournamentService::PRIZES, 'default_commission_percent' => PlatformSetting::values()['tournament_commission_percent'], 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_fee' => ['sometimes', 'required', 'integer', 'between:1,10000'],
            'commission_percent' => ['sometimes', 'required', 'integer', 'between:0,30'],
            'name' => ['required', 'string', 'min:3', 'max:80'], 'capacity' => ['required', 'integer', 'between:4,16'],
            'min_rank' => ['required', 'integer', 'between:1,10'], 'max_rank' => ['required', 'integer', 'between:1,10', 'gte:min_rank'],
            'round_minutes' => ['required', 'integer', 'between:1,120'], 'game_minutes' => ['required', 'integer', 'min:1', 'lte:round_minutes'],
        ]);
        $t = Tournament::create($data + ['creator_id' => $request->user()->id, 'entry_fee' => CompetitionWallet::TOURNAMENT_FEE, 'commission_percent' => PlatformSetting::values()['tournament_commission_percent']]);

        return response()->json(['data' => $this->summary($t)], 201);
    }

    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        $t = $this->tournaments->synchronize($tournament->id);
        $names = User::withTrashed()->pluck('name', 'id');
        $games = OnlineGame::where('tournament_id', $t->id)->orderBy('tournament_round')->orderBy('id')->get()->map(fn (OnlineGame $g): array => [
            'code' => $g->code, 'round' => $g->tournament_round, 'white' => $names[$g->white_user_id] ?? 'Conta eliminada', 'black' => $names[$g->black_user_id] ?? 'Conta eliminada',
            'phase' => $g->phase, 'winner' => $g->state['winner'], 'reason' => $g->state['reason'],
            'mine' => in_array($request->user()->id, [$g->white_user_id, $g->black_user_id], true),
        ]);

        return response()->json(['data' => $this->summary($t) + ['standings' => $this->tournaments->standings($t), 'games' => $games,
            'joined' => TournamentParticipant::where('tournament_id', $t->id)->where('user_id', $request->user()->id)->exists(), 'server_now' => now()->getTimestampMs(),
            'my_award' => RankEntry::where('tournament_id', $t->id)->where('user_id', $request->user()->id)->value('delta'),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function join(Request $request, Tournament $tournament): JsonResponse
    {
        $this->tournaments->locked($tournament->id, function (Tournament $t) use ($request): void {
            abort_unless($t->status === 'registration', 409, 'As inscrições estão fechadas.');
            if (TournamentParticipant::where('tournament_id', $t->id)->where('user_id', $request->user()->id)->exists()) {
                return;
            }
            abort_if(TournamentParticipant::where('tournament_id', $t->id)->count() >= $t->capacity, 409, 'O torneio está completo.');
            $user = $request->user()->fresh();
            $level = $this->ranks->tier($user->rank_points)['level'];
            abort_unless($level >= $t->min_rank && $level <= $t->max_rank, 403, 'O teu escalão não está no intervalo permitido.');
            app(CompetitionWallet::class)->move($user->id, -(int) $t->entry_fee, 'entry', 'Torneio #'.$t->id);
            TournamentParticipant::create(['tournament_id' => $t->id, 'user_id' => $user->id, 'entry_points' => $user->rank_points]);
        });

        return $this->show($request, $tournament);
    }

    public function leave(Request $request, Tournament $tournament): JsonResponse
    {
        $this->tournaments->locked($tournament->id, function (Tournament $t) use ($request): void {
            abort_unless($t->status === 'registration', 409, 'O torneio já não aceita alterações de inscrições.');
            if (TournamentParticipant::where('tournament_id', $t->id)->where('user_id', $request->user()->id)->delete()) {
                app(CompetitionWallet::class)->move($request->user()->id, (int) $t->entry_fee, 'refund', 'Torneio #'.$t->id);
            }
        });

        return $this->show($request, $tournament);
    }

    public function start(Request $request, Tournament $tournament): JsonResponse
    {
        $moderation = $request->routeIs('admin.tournaments.start');
        if ($moderation) {
            $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        }
        $this->tournaments->locked($tournament->id, function (Tournament $t) use ($request, $moderation): void {
            abort_unless($moderation || $t->creator_id === $request->user()->id, 403);
            abort_unless($t->status === 'registration', 409, 'O torneio já foi iniciado ou cancelado.');
            $players = TournamentParticipant::where('tournament_id', $t->id)->orderByDesc('entry_points')->orderBy('id')->get();
            abort_unless($players->count() === $t->capacity, 409, 'É necessário preencher todas as vagas antes de começar.');
            foreach ($players as $i => $p) {
                $user = User::whereKey($p->user_id)->whereNull('blocked_at')->first();
                abort_unless($user, 409, 'Há uma conta indisponível entre os inscritos. Remove a inscrição ou cancela o torneio.');
                $level = $this->ranks->tier($user->rank_points)['level'];
                abort_unless($level >= $t->min_rank && $level <= $t->max_rank, 409, 'O escalão de um inscrito mudou. A inscrição tem de ser retirada antes de iniciar.');
                $p->forceFill(['seed' => $i + 1])->save();
            }
            $t->forceFill(['status' => 'active', 'current_round' => 1, 'round_started_at' => now(), 'round_deadline_at' => now()->addMinutes($t->round_minutes)])->save();
            $this->tournaments->createRound($t);
            if ($moderation) {
                $actor = $request->user();
                app(AccountSecurity::class)->record($actor, $actor, 'tournament_started', ['tournament_id' => $t->id]);
            }
        });

        return $this->show($request, $tournament);
    }

    public function cancel(Request $request, Tournament $tournament): JsonResponse
    {
        $moderation = $request->routeIs('admin.tournaments.cancel');
        if ($moderation) {
            $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        }
        $this->tournaments->locked($tournament->id, function (Tournament $t) use ($request, $moderation): void {
            abort_unless($moderation || $t->creator_id === $request->user()->id, 403);
            abort_unless($t->status === 'registration', 409, 'Só podes cancelar antes de começar.');
            foreach (TournamentParticipant::where('tournament_id', $t->id)->pluck('user_id') as $id) {
                app(CompetitionWallet::class)->move($id, (int) $t->entry_fee, 'refund', 'Torneio #'.$t->id);
            }
            $t->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            if ($moderation) {
                $actor = $request->user();
                app(AccountSecurity::class)->record($actor, $actor, 'tournament_cancelled', ['tournament_id' => $t->id]);
            }
        });

        return $this->show($request, $tournament);
    }

    public function profile(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $id = $request->user()->id;
        Tournament::where('status', 'active')->where(fn ($q) => $q->where('creator_id', $id)->orWhereIn('id', TournamentParticipant::where('user_id', $id)->select('tournament_id')))->pluck('id')->each(fn (int $tournamentId) => $this->tournaments->synchronize($tournamentId));
        $joined = TournamentParticipant::where('user_id', $id)->pluck('tournament_id');
        $rows = Tournament::where(fn ($q) => $q->where('creator_id', $id)->orWhereIn('id', $joined))->latest('id')->paginate(10);
        $awards = RankEntry::where('user_id', $id)->whereNotNull('tournament_id')->pluck('delta', 'tournament_id');

        return response()->json(['stats' => ['created' => Tournament::where('creator_id', $id)->count(), 'participated' => Tournament::whereIn('id', $joined)->whereIn('status', ['active', 'finished'])->count(), 'registered' => Tournament::whereIn('id', $joined)->where('status', 'registration')->count(), 'podiums' => $awards->count(), 'points' => $awards->sum()],
            'data' => $rows->getCollection()->map(fn (Tournament $t): array => $this->summary($t) + ['created_by_me' => $t->creator_id === $id, 'participated' => $joined->contains($t->id), 'prize' => $awards[$t->id] ?? 0]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function summary(Tournament $t): array
    {
        return ['id' => $t->id, 'entry_fee' => (int) $t->entry_fee, 'commission_percent' => (int) $t->commission_percent, 'finances' => $this->tournaments->finances($t), 'name' => $t->name, 'status' => $t->status, 'creator_id' => $t->creator_id, 'creator' => User::withTrashed()->find($t->creator_id)?->name ?? 'Conta eliminada',
            'capacity' => $t->capacity, 'registered' => TournamentParticipant::where('tournament_id', $t->id)->count(), 'min_rank' => $t->min_rank, 'max_rank' => $t->max_rank,
            'round_minutes' => $t->round_minutes, 'game_minutes' => $t->game_minutes, 'current_round' => $t->current_round, 'round_deadline_at' => $t->round_deadline_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(), 'finished_at' => $t->finished_at?->toIso8601String(), 'prizes' => TournamentService::PRIZES];
    }
}
