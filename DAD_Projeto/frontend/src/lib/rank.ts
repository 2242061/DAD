export type Tier = { level: number; name: string; minimum: number }
export type CurrentTier = Tier & { next: Tier | null; points_to_next: number; progress: number }
export type Ranking = {
  tournament_points: number
  tournament_podiums: number
  points: number
  tier: CurrentTier
  games: number
  wins: number
  losses: number
  draws: number
  win_rate: number
  win_loss_ratio: number | null
  rules: { win: number; loss: number; draw: number; minimum: number }
}
