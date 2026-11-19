<script setup lang="ts">
withDefaults(defineProps<{ name: string; size?: number }>(), { size: 20 })
const paths: Record<string, string> = {
  grid: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
  user: 'M20 21v-2a7 7 0 0 0-14 0v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
  users:
    'M16 21v-2a5 5 0 0 0-10 0v2 M11 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M20 21v-2a5 5 0 0 0-3-4.6 M17 3.2a4 4 0 0 1 0 7.6',
  shield: 'M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6z M8.5 12l2.5 2.5 4.5-5',
  arrow: 'M5 12h14 M13 6l6 6-6 6',
  logout: 'M9 4H4v16h5 M9 12h12 M16 7l5 5-5 5',
  mail: 'M3 5h18v14H3z M3 6l9 7 9-7',
  lock: 'M5 10h14v11H5z M8 10V7a4 4 0 0 1 8 0v3 M12 15v2',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12 M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6',
  eyeOff:
    'M3 3l18 18 M10.6 5.1A12 12 0 0 1 12 5c6 0 10 7 10 7a18 18 0 0 1-3 3.7 M6 6.7A19 19 0 0 0 2 12s4 7 10 7a12 12 0 0 0 5-1.2',
  trophy: 'M8 3h8v7a4 4 0 0 1-8 0z M8 5H4v3a4 4 0 0 0 4 4 M16 5h4v3a4 4 0 0 1-4 4 M12 14v6 M8 21h8',
  wallet: 'M4 5h15v4 M4 5a2 2 0 0 0 0 4h17v12H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2 M21 13h-5v4h5',
  clock: 'M12 8v5l3 2 M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20',
  check: 'M5 12l4 4L19 6',
  info: 'M12 11v6 M12 7h.01 M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20',
  chess: 'M6 21h12 M7 17h10l-2-6 2-5-5 2-5-2 2 5z M12 2v3',
  code: 'M8 5l-7 7 7 7 M16 5l7 7-7 7 M14 3l-4 18',
  calendar: 'M4 5h16v16H4z M8 3v4 M16 3v4 M4 11h16',
}
</script>
<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="paths[name] ?? paths.grid" />
  </svg>
</template>
