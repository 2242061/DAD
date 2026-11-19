<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import FormField from '@/components/FormField.vue'
import { useAuth, type User } from '@/composables/useAuth'
import { api, ApiError, type FieldErrors } from '@/lib/api'
interface Overview {
  stats: {
    total: number
    players: number
    admins: number
    blocked: number
    deleted: number
    waiting_rooms: number
    active_games: number
    finished_games: number
    active_tournaments: number
    finished_tournaments: number
  }
  users: User[]
  pagination: { current_page: number; last_page: number; total: number }
}
type Action = 'edit' | 'block' | 'unblock' | 'delete' | 'restore'
const auth = useAuth(),
  overview = ref<Overview | null>(null),
  busy = ref(false),
  saving = ref(false),
  error = ref(''),
  message = ref('')
const search = ref(''),
  status = ref(''),
  roleFilter = ref('')
const selected = ref<User | null>(null),
  action = ref<Action>('edit'),
  dialog = ref<HTMLDialogElement>()
const name = ref(''),
  email = ref(''),
  role = ref('player'),
  currentPassword = ref(''),
  reason = ref(''),
  modalError = ref(''),
  errors = ref<FieldErrors>({})
const labels: Record<Action, string> = {
  edit: 'Editar utilizador',
  block: 'Bloquear conta',
  unblock: 'Desbloquear conta',
  delete: 'Eliminar conta',
  restore: 'Recuperar conta',
}
const explanations: Record<Action, string> = {
  edit: 'Alterações ao email ou ao tipo de conta terminam as sessões deste utilizador. Um novo email precisa de confirmação.',
  block: 'O utilizador perde o acesso à plataforma e as sessões são terminadas.',
  unblock: 'O utilizador poderá voltar a entrar com as suas credenciais.',
  delete: 'A conta fica desativada. Podes recuperá-la no filtro de contas eliminadas.',
  restore: 'A conta volta a ficar ativa. O utilizador terá de iniciar uma nova sessão.',
}
const date = (value: string) =>
  new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' }).format(new Date(value))
async function load(page = 1) {
  busy.value = true
  error.value = ''
  try {
    const query = new URLSearchParams({
      page: String(page),
      q: search.value,
      status: status.value,
      role: roleFilter.value,
    })
    const result = await api<Overview>(`/api/admin/overview?${query}`)
    if (page > result.pagination.last_page) {
      await load(result.pagination.last_page)
      return
    }
    overview.value = result
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Não foi possível carregar os membros.'
  } finally {
    busy.value = false
  }
}
async function open(user: User, value: Action) {
  selected.value = user
  action.value = value
  name.value = user.name
  email.value = user.email
  role.value = user.role
  currentPassword.value = ''
  reason.value = ''
  modalError.value = ''
  errors.value = {}
  await nextTick()
  dialog.value?.showModal()
}
function close() {
  if (saving.value) return
  dialog.value?.close()
  selected.value = null
  currentPassword.value = ''
}
async function submit() {
  if (!selected.value || saving.value) return
  saving.value = true
  modalError.value = ''
  errors.value = {}
  message.value = ''
  try {
    const base = `/api/admin/users/${selected.value.id}`
    const editing = action.value === 'edit'
    const url = editing || action.value === 'delete' ? base : `${base}/${action.value}`
    const body = editing
      ? {
          name: name.value,
          email: email.value,
          role: role.value,
          current_password: currentPassword.value,
        }
      : { current_password: currentPassword.value, reason: reason.value }
    await api(url, {
      method: editing ? 'PATCH' : action.value === 'delete' ? 'DELETE' : 'POST',
      body: JSON.stringify(body),
    })
    saving.value = false
    close()
    message.value = 'Alteração guardada e registada no histórico.'
    await load(overview.value?.pagination.current_page ?? 1)
  } catch (cause) {
    if (cause instanceof ApiError) errors.value = cause.errors
    modalError.value =
      cause instanceof Error ? cause.message : 'Não foi possível concluir a alteração.'
  } finally {
    saving.value = false
  }
}
onMounted(() => load())
</script>
<template>
  <div class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">GESTÃO DO CLUBE</p>
        <h1>Os membros do clube<span class="green-dot">.</span></h1>
        <p>Gere contas, protege os acessos e acompanha cada alteração.</p>
      </div>
      <div class="admin-heading-links">
        <RouterLink to="/admin/carteira" class="button-secondary"
          >Auditoria financeira ↗</RouterLink
        >
        <RouterLink to="/admin/torneios" class="button-secondary">Gestão de torneios ↗</RouterLink>
        <RouterLink to="/admin/configuracao" class="button-secondary">Configuração ↗</RouterLink>
        <RouterLink to="/admin/historico" class="button-secondary"
          ><AppIcon name="clock" :size="17" />Histórico de ações</RouterLink
        >
      </div>
    </div>
    <div v-if="!auth.user.value?.email_verified" class="notice-strip">
      <AppIcon name="mail" :size="20" />
      <p>
        Confirma o teu email antes de alterar outras contas.
        <RouterLink to="/conta" class="inline-link">Ir para A minha conta</RouterLink>
      </p>
    </div>
    <div v-if="message" class="alert success" role="status">{{ message }}</div>
    <div v-if="error" class="alert error" role="alert">
      {{ error }} <button class="inline-link" @click="load()">Tentar novamente</button>
    </div>
    <p v-if="busy && !overview" class="loading-message" role="status">
      <span class="spinner" />A carregar o clube…
    </p>
    <template v-if="overview"
      ><div class="stats-grid member-stats">
        <article class="surface stat-card">
          <span>Membros</span><AppIcon name="users" /><strong>{{ overview.stats.total }}</strong>
        </article>
        <article class="surface stat-card">
          <span>Jogadores</span><AppIcon name="user" /><strong>{{ overview.stats.players }}</strong>
        </article>
        <article class="surface stat-card">
          <span>Administradores</span><AppIcon name="shield" /><strong>{{
            overview.stats.admins
          }}</strong>
        </article>
        <article class="surface stat-card">
          <span>Bloqueados</span><AppIcon name="lock" /><strong>{{
            overview.stats.blocked
          }}</strong>
        </article>
      </div>
      <section class="admin-operations" aria-label="Atividade da plataforma">
        <div class="card-heading">
          <div>
            <h2>Atividade da plataforma</h2>
            <p>Salas, partidas e torneios registados.</p>
          </div>
        </div>
        <div class="stats-grid admin-operation-stats">
          <article class="surface stat-card">
            <span>Salas à espera</span><strong>{{ overview.stats.waiting_rooms }}</strong>
          </article>
          <article class="surface stat-card">
            <span>Partidas em curso</span><strong>{{ overview.stats.active_games }}</strong>
          </article>
          <article class="surface stat-card">
            <span>Partidas terminadas</span><strong>{{ overview.stats.finished_games }}</strong>
          </article>
          <article class="surface stat-card">
            <span>Torneios em curso</span><strong>{{ overview.stats.active_tournaments }}</strong>
          </article>
          <article class="surface stat-card">
            <span>Torneios concluídos</span
            ><strong>{{ overview.stats.finished_tournaments }}</strong>
          </article>
        </div>
      </section>
      <section class="surface users-section" :aria-busy="busy">
        <div class="card-heading">
          <div>
            <h2>Utilizadores</h2>
            <p>
              {{ overview.pagination.total }} nesta seleção · {{ overview.stats.deleted }} contas
              eliminadas
            </p>
          </div>
          <button
            class="button-secondary"
            :disabled="busy"
            @click="load(overview.pagination.current_page)"
          >
            Atualizar
          </button>
        </div>
        <form class="filter-bar" @submit.prevent="load()">
          <label class="search-filter"
            >Pesquisar<input
              v-model="search"
              type="search"
              placeholder="Nome ou email"
              maxlength="100"
              :disabled="busy" /></label
          ><label
            >Estado<select v-model="status" :disabled="busy" @change="load()">
              <option value="">Contas existentes</option>
              <option value="active">Ativas</option>
              <option value="blocked">Bloqueadas</option>
              <option value="deleted">Eliminadas</option>
            </select></label
          ><label
            >Tipo<select v-model="roleFilter" :disabled="busy" @change="load()">
              <option value="">Todos</option>
              <option value="player">Jogador</option>
              <option value="admin">Administrador</option>
            </select></label
          ><button class="button-secondary" :disabled="busy">Pesquisar</button>
        </form>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th scope="col">Membro</th>
                <th scope="col">Tipo / estado</th>
                <th scope="col">Registo</th>
                <th scope="col">Ações</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in overview.users" :key="user.id">
                <td>
                  <strong class="table-name">{{ user.name }}</strong
                  ><small v-if="user.id === auth.user.value?.id" class="you-label">Tu</small
                  ><span class="table-email">{{ user.email }}</span
                  ><small class="email-state">{{
                    user.email_verified ? 'Email confirmado' : 'Email por confirmar'
                  }}</small>
                </td>
                <td>
                  <span class="role-badge" :class="{ neutral: user.role === 'player' }">{{
                    user.role === 'admin' ? 'Administrador' : 'Jogador'
                  }}</span
                  ><span
                    class="account-status"
                    :class="{ blocked: user.blocked_at, deleted: user.deleted_at }"
                    >{{
                      user.deleted_at ? 'Eliminada' : user.blocked_at ? 'Bloqueada' : 'Ativa'
                    }}</span
                  >
                </td>
                <td>{{ date(user.created_at) }}</td>
                <td>
                  <div v-if="user.id !== auth.user.value?.id" class="row-actions">
                    <button
                      v-if="user.deleted_at"
                      class="table-action"
                      :disabled="busy"
                      @click="open(user, 'restore')"
                    >
                      Recuperar</button
                    ><template v-else
                      ><button class="table-action" :disabled="busy" @click="open(user, 'edit')">
                        Editar</button
                      ><button
                        class="table-action"
                        :disabled="busy"
                        @click="open(user, user.blocked_at ? 'unblock' : 'block')"
                      >
                        {{ user.blocked_at ? 'Desbloquear' : 'Bloquear' }}</button
                      ><button
                        class="table-action destructive"
                        :disabled="busy"
                        @click="open(user, 'delete')"
                      >
                        Eliminar
                      </button></template
                    >
                  </div>
                  <RouterLink v-else to="/conta" class="table-action">A minha conta ↗</RouterLink>
                  <RouterLink
                    v-if="!user.deleted_at"
                    :to="{ path: '/admin/carteira', query: { user: user.id } }"
                    class="table-action"
                    >Saldo ↗</RouterLink
                  >
                </td>
              </tr>
              <tr v-if="!overview.users.length">
                <td colspan="4" class="empty-state">Não encontrámos membros com estes filtros.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pagination">
          <span
            >Página {{ overview.pagination.current_page }} de
            {{ overview.pagination.last_page }}</span
          >
          <div>
            <button
              class="button-secondary"
              :disabled="busy || overview.pagination.current_page === 1"
              @click="load(overview.pagination.current_page - 1)"
            >
              Anterior</button
            ><button
              class="button-secondary"
              :disabled="busy || overview.pagination.current_page === overview.pagination.last_page"
              @click="load(overview.pagination.current_page + 1)"
            >
              Seguinte
            </button>
          </div>
        </div>
      </section></template
    >
    <div class="notice-strip">
      <AppIcon name="shield" :size="20" />
      <p>
        As alterações exigem a tua palavra-passe e ficam registadas. As contas eliminadas podem ser
        recuperadas. Para alterares o teu próprio perfil, usa A minha conta.
      </p>
    </div>
    <dialog
      ref="dialog"
      class="account-dialog"
      aria-labelledby="modal-title"
      @cancel.prevent="close"
    >
      <template v-if="selected"
        ><div class="dialog-header">
          <span class="eyebrow">GESTÃO DE CONTA</span
          ><button
            class="dialog-close"
            aria-label="Fechar janela"
            :disabled="saving"
            @click="close"
          >
            ×
          </button>
        </div>
        <h2 id="modal-title">{{ labels[action] }}</h2>
        <p class="dialog-target">{{ selected.name }} · {{ selected.email }}</p>
        <p class="muted-description">{{ explanations[action] }}</p>
        <div v-if="modalError" class="alert error" role="alert">{{ modalError }}</div>
        <form @submit.prevent="submit" :aria-busy="saving">
          <template v-if="action === 'edit'"
            ><FormField
              v-model="name"
              label="Nome de jogador"
              required
              minlength="2"
              maxlength="80"
              :disabled="saving"
              :error="errors.name?.[0]"
            /><FormField
              v-model="email"
              label="Email"
              type="email"
              required
              maxlength="254"
              :disabled="saving"
              :error="errors.email?.[0]"
            />
            <div class="field account-field">
              <label for="member-role">Tipo de conta</label
              ><select id="member-role" v-model="role" :disabled="saving">
                <option value="player">Jogador</option>
                <option value="admin">Administrador</option>
              </select>
              <p v-if="errors.role" class="field-error">{{ errors.role[0] }}</p>
            </div></template
          ><FormField
            v-else
            v-model="reason"
            label="Motivo da alteração"
            required
            minlength="5"
            maxlength="250"
            :disabled="saving"
            :error="errors.reason?.[0]"
            hint="Uma explicação breve para o histórico, sem dados sensíveis."
          /><FormField
            v-model="currentPassword"
            label="A tua palavra-passe de administrador"
            type="password"
            autocomplete="current-password"
            required
            :disabled="saving"
            :error="errors.current_password?.[0]"
          />
          <div class="dialog-actions">
            <button type="button" class="button-secondary" :disabled="saving" @click="close">
              Cancelar</button
            ><button
              :class="
                action === 'delete' || action === 'block' ? 'button-danger' : 'button-primary'
              "
              :disabled="saving"
            >
              {{
                saving ? 'A guardar…' : action === 'edit' ? 'Guardar alterações' : labels[action]
              }}
            </button>
          </div>
        </form></template
      >
    </dialog>
  </div>
</template>
