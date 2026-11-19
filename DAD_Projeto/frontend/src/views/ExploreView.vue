<script setup lang="ts">
import { RouterLink } from 'vue-router'
import CheckersBoard from '@/components/CheckersBoard.vue'
import AppIcon from '@/components/AppIcon.vue'
</script>
<template>
  <div class="page-content">
    <section class="explore-hero">
      <div>
        <p class="eyebrow">BEM-VINDO AO DAMA</p>
        <h1>Há sempre<br />uma próxima <em>jogada.</em></h1>
        <p>
          Um clube online dedicado às damas portuguesas.<br />O clássico de sempre, com um novo
          lugar à mesa.
        </p>
        <RouterLink to="/jogar" class="button-primary"
          >Jogar uma partida local<AppIcon name="arrow" :size="18"
        /></RouterLink>
      </div>
      <CheckersBoard />
    </section>
    <div class="notice-strip">
      <AppIcon name="info" :size="20" />
      <p>
        <strong>O tabuleiro já está pronto.</strong> Joga com outra pessoa no mesmo dispositivo. As
        partidas online e as competições chegam nas próximas etapas.
      </p>
    </div>
    <div class="feature-grid">
      <article
        v-for="item in [
          {
            n: '01',
            title: 'Joga à portuguesa',
            text: 'Tabuleiro de 64 casas. Doze peças por lado. Uma tradição inteira em cada movimento.',
          },
          {
            n: '02',
            title: 'Encontra o teu desafio',
            text: 'Partidas casuais, treino com bot e competições estão no roteiro do clube.',
          },
          {
            n: '03',
            title: 'Partilha a estratégia',
            text: 'Acompanha partidas públicas e faz parte de uma comunidade de jogadores.',
          },
        ]"
        :key="item.n"
        class="surface feature-card"
      >
        <span class="section-index">{{ item.n }}</span>
        <h2>{{ item.title }}</h2>
        <p>{{ item.text }}</p>
      </article>
    </div>
  </div>
</template>
