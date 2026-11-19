export type Tournament = {
  id: number
  name: string
  status: string
  creator_id: number
  creator: string
  entry_fee: number
  commission_percent: number
  finances: { gross: number; commission: number; pool: number; prizes: number[] }
  capacity: number
  registered: number
  min_rank: number
  max_rank: number
  round_minutes: number
  game_minutes: number
  current_round: number
  round_deadline_at: string | null
  created_at: string
  finished_at: string | null
  prizes: number[]
}
export type Standing = {
  user_id: number
  name: string
  position: number
  score: number
  wins: number
  draws: number
  losses: number
  played: number
  entry_points: number
  coin_prize: number
  prize: number
}
export type TournamentDetail = Tournament & {
  standings: Standing[]
  games: {
    code: string
    round: number
    white: string
    black: string
    phase: string
    winner: string | null
    reason: string | null
    mine: boolean
  }[]
  joined: boolean
  server_now: number
  my_award: number | null
}
export const tournamentStatus: Record<string, string> = {
  registration: 'Inscrições abertas',
  active: 'Em curso',
  finished: 'Terminado',
  cancelled: 'Cancelado',
}
export const tierNames = [
  'Novato',
  'Bronze',
  'Prata',
  'Ouro',
  'Platina',
  'Diamante',
  'Mestre',
  'Grão-Mestre',
  'Lenda',
  'Imortal',
]
