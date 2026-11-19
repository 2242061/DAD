<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
interface Entry {
  id: number
  action: string
  actor: string
  target: string
  details: {
    reason?: string
    fields?: string[]
    tournament_id?: number
    online_turn_seconds?: number
    tournament_commission_percent?: number
  }
  created_at: string
}
interface History {
  data: Entry[]
  pagination: { current_page: number; last_page: number; total: number }
}
const history = ref<History | null>(null),
  busy = ref(false),
  error = ref('')
const labels: Record<string, string> = {
  'user.admin_created': 'Administrador criado pelo terminal',
  'profile.updated': 'Perfil atualizado',
  'password.changed': 'Palavra-passe alterada',
  'password.reset': 'Palavra-passe recuperada',
  'email.verified': 'Email confirmado',
  'account.deleted': 'Conta eliminada pelo titular',
  'user.updated': 'Utilizador editado',
  'user.blocked': 'Conta bloqueada',
  'user.unblocked': 'Conta desbloqueada',
  'user.deleted': 'Conta eliminada',
  'user.restored': 'Conta recuperada',
  platform_settings_updated: 'Configuração atualizada',
  tournament_started: 'Torneio iniciado pela administração',
  tournament_cancelled: 'Torneio cancelado pela administração',
}
const fields: Record<string, string> = { name: 'nome', email: 'email', role: 'tipo de conta' }
const date = (value: string) =>
  new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' }).format(
    new Date(value),
  )
async function load(page = 1) {
  busy.value = true
  error.value = ''
  try {
    history.value = await api<History>(`/api/admin/audit-logs?page=${page}`)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Não foi possível carregar o histórico.'
  } finally {
    busy.value = false
  }
}
onMounted(() => load())
</script>
<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">RASTO DAS ALTERAÇÕES</p>
        <h1>Histórico de ações<span class="green-dot">.</span></h1>
        <p>Quem fez a alteração, em que conta e quando.</p>
      </div>
      <RouterLink to="/admin" class="button-secondary">Voltar aos membros</RouterLink>
    </div>
    <div v-if="error" class="alert error" role="alert">
      {{ error }} <button class="inline-link" @click="load()">Tentar novamente</button>
    </div>
    <p v-if="busy && !history" class="loading-message" role="status">A carregar o histórico…</p>
    <section v-if="history" class="surface users-section" :aria-busy="busy">
      <div class="card-heading">
        <div>
          <h2>Registos da plataforma</h2>
          <p>{{ history.pagination.total }} ações · Mais recentes primeiro</p>
        </div>
        <button
          class="button-secondary"
          :disabled="busy"
          @click="load(history.pagination.current_page)"
        >
          Atualizar
        </button>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th scope="col">Quando</th>
              <th scope="col">Ação / motivo</th>
              <th scope="col">Autor</th>
              <th scope="col">Conta</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in history.data" :key="entry.id">
              <td>{{ date(entry.created_at) }}</td>
              <td>
                <strong class="table-name">{{ labels[entry.action] ?? entry.action }}</strong
                ><span v-if="entry.details.reason" class="audit-detail">{{
                  entry.details.reason
                }}</span
                ><RouterLink
                  v-else-if="entry.details.tournament_id"
                  class="audit-detail inline-link"
                  :to="`/admin/torneios/${entry.details.tournament_id}`"
                  >Torneio #{{ entry.details.tournament_id }}</RouterLink
                >
                <span v-else-if="entry.action === 'platform_settings_updated'" class="audit-detail"
                  >{{ entry.details.online_turn_seconds }} s por jogada ·
                  {{ entry.details.tournament_commission_percent }}% de comissão predefinida</span
                >
                <span v-else-if="entry.details.fields?.length" class="audit-detail"
                  >Campos:
                  {{ entry.details.fields.map((field) => fields[field] ?? field).join(', ') }}</span
                >
              </td>
              <td>{{ entry.actor }}</td>
              <td>{{ entry.target }}</td>
            </tr>
            <tr v-if="!history.data.length">
              <td colspan="4" class="empty-state">Ainda não existem alterações registadas.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="pagination">
        <span
          >Página {{ history.pagination.current_page }} de {{ history.pagination.last_page }}</span
        >
        <div>
          <button
            class="button-secondary"
            :disabled="busy || history.pagination.current_page === 1"
            @click="load(history.pagination.current_page - 1)"
          >
            Anterior</button
          ><button
            class="button-secondary"
            :disabled="busy || history.pagination.current_page === history.pagination.last_page"
            @click="load(history.pagination.current_page + 1)"
          >
            Seguinte
          </button>
        </div>
      </div>
    </section>
  </div>
</template>
