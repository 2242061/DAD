<script setup lang="ts">
const cells = Array.from({ length: 64 }, (_, index) => {
  const row = Math.floor(index / 8)
  const col = index % 8
  const dark = (row + col) % 2 === 0
  return { dark, piece: dark && row < 3 ? 'black' : dark && row > 4 ? 'white' : null }
})
</script>
<template>
  <div
    class="board-scene"
    role="img"
    aria-label="Ilustração da posição inicial de um tabuleiro de damas portuguesas"
  >
    <div class="board-frame">
      <div class="checkers-board">
        <div
          v-for="(cell, index) in cells"
          :key="index"
          class="square"
          :class="{ dark: cell.dark }"
        >
          <span v-if="cell.piece" class="piece" :class="cell.piece" />
        </div>
      </div>
    </div>
  </div>
</template>
