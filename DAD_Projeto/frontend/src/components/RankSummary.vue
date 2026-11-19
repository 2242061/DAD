<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { Ranking } from '@/lib/rank'
defineProps<{ ranking: Ranking }>()
</script>
<template>
  <section class="rank-summary surface" aria-label="O teu rank competitivo">
    <div class="rank-medal" :class="`rank-level-${ranking.tier.level}`" aria-hidden="true">
      <svg viewBox="0 0 80 90">
        <path d="M40 3 72 20v39L40 86 8 59V20Z" />
        <path d="m25 40 8 8 22-23" /></svg
      ><span>{{ ranking.tier.level }}</span>
    </div>
    <div class="rank-summary-main">
      <p class="eyebrow">O TEU RANK COMPETITIVO</p>
      <h2>
        {{ ranking.tier.name }} <span>{{ ranking.points }} pts</span>
      </h2>
      <p v-if="ranking.tier.next">
        Faltam <strong>{{ ranking.tier.points_to_next }} pontos</strong> para
        {{ ranking.tier.next.name }}.
      </p>
      <p v-if="ranking.tournament_podiums">
        {{ ranking.tournament_podiums }} pódios · +{{ ranking.tournament_points }} pontos de
        torneios
      </p>
      <p v-if="!ranking.tier.next">Chegaste ao escalão máximo. Continua a somar pontos.</p>
      <div
        class="rank-progress"
        role="progressbar"
        :aria-valuenow="ranking.tier.progress"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="Progresso para o próximo escalão"
      >
        <span :style="{ width: `${ranking.tier.progress}%` }" />
      </div>
      <small
        >Vitória +{{ ranking.rules.win }} · Derrota {{ ranking.rules.loss }} · Empate
        {{ ranking.rules.draw }} · mínimo 0</small
      >
    </div>
    <div class="rank-summary-stats">
      <strong
        >{{ ranking.wins }} V <span>·</span> {{ ranking.draws }} E <span>·</span>
        {{ ranking.losses }} D</strong
      ><span>{{ ranking.games }} jogos competitivos · {{ ranking.win_rate }}% vitórias</span
      ><small
        >V/D:
        {{ ranking.losses ? ranking.win_loss_ratio : ranking.wins ? 'sem derrotas' : '—' }}</small
      ><RouterLink to="/ranking" class="inline-link">Ver classificação e escalões ↗</RouterLink>
    </div>
  </section>
</template>
