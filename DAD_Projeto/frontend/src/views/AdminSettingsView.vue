<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import FormField from '@/components/FormField.vue'
import { api, ApiError } from '@/lib/api'

type Settings = { online_turn_seconds: number; tournament_commission_percent: number }
const settings = ref<Settings>()
const password = ref('')
const busy = ref(false)
const error = ref('')
const saved = ref(false)

async function load() {
  error.value = ''
  busy.value = true
  try {
    settings.value = (await api<{ data: Settings }>('/api/admin/settings')).data
  } catch (cause) {
    error.value =
      cause instanceof Error ? cause.message : 'Não foi possível carregar a configuração.'
  } finally {
    busy.value = false
  }
}

async function save() {
  if (busy.value || !settings.value) return
  busy.value = true
  saved.value = false
  error.value = ''
  try {
    const response = await api<{ data: Settings }>('/api/admin/settings', {
      method: 'PATCH',
      body: JSON.stringify({ ...settings.value, current_password: password.value }),
    })
    settings.value = response.data
    password.value = ''
    saved.value = true
  } catch (cause) {
    error.value =
      cause instanceof ApiError && Object.keys(cause.errors).length
        ? Object.values(cause.errors).flat().join(' ')
        : cause instanceof Error
          ? cause.message
          : 'Não foi possível guardar a configuração.'
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">ADMINISTRAÇÃO</p>
        <h1>Configuração<span class="green-dot">.</span></h1>
        <p>Define os valores de partida para novas salas e torneios.</p>
      </div>
      <RouterLink to="/admin" class="button-secondary">Voltar ao painel</RouterLink>
    </div>
    <div v-if="error" class="alert error" role="alert">
      {{ error }}
      <button v-if="!settings" class="inline-link" @click="load">Tentar novamente</button>
    </div>
    <div v-if="saved" class="alert success" role="status">Configuração guardada.</div>
    <p v-if="busy && !settings" class="loading-message" role="status">A carregar…</p>
    <form v-if="settings" class="surface users-section admin-settings-form" @submit.prevent="save">
      <div class="card-heading">
        <div>
          <h2>Jogo e torneios</h2>
          <p>As alterações ficam registadas no histórico de ações.</p>
        </div>
      </div>
      <label class="admin-setting">
        <span>Tempo por jogada</span>
        <input
          v-model.number="settings.online_turn_seconds"
          type="number"
          min="15"
          max="180"
          required
        />
        <small>Em segundos. Aplica-se às novas salas casuais e competitivas.</small>
      </label>
      <label class="admin-setting">
        <span>Comissão predefinida do torneio (%)</span>
        <input
          v-model.number="settings.tournament_commission_percent"
          type="number"
          min="0"
          max="30"
          required
        />
        <small>Valor sugerido ao criar um torneio. O organizador pode ajustá-lo.</small>
      </label>
      <FormField
        v-model="password"
        label="Palavra-passe de administrador"
        name="current_password"
        type="password"
        autocomplete="current-password"
        required
      />
      <button class="button-primary" :disabled="busy || !password">Guardar configuração</button>
    </form>
  </section>
</template>
