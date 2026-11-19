<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import PlayerHistory from '@/components/PlayerHistory.vue'
import TournamentHistory from '@/components/TournamentHistory.vue'
import FormField from '@/components/FormField.vue'
import { useAuth, type User } from '@/composables/useAuth'
import { api, ApiError, type FieldErrors } from '@/lib/api'
const auth = useAuth()
const router = useRouter()
const tab = ref('profile')
const name = ref(auth.user.value?.name ?? '')
const email = ref(auth.user.value?.email ?? '')
const profilePassword = ref('')
const currentPassword = ref('')
const password = ref('')
const confirmation = ref('')
const deletePassword = ref('')
const deleteConfirmation = ref('')
const busy = ref('')
const message = ref('')
const error = ref('')
const errors = ref<FieldErrors>({})
const emailChanged = computed(() => email.value.trim().toLowerCase() !== auth.user.value?.email)
const firstName = computed(() => auth.user.value?.name.split(' ')[0])
const joined = computed(() =>
  auth.user.value
    ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' }).format(
        new Date(auth.user.value.created_at),
      )
    : '',
)
function start(action: string) {
  busy.value = action
  message.value = ''
  error.value = ''
  errors.value = {}
}
function fail(cause: unknown) {
  if (cause instanceof ApiError) errors.value = cause.errors
  error.value = cause instanceof Error ? cause.message : 'Não foi possível guardar a alteração.'
}
function switchTab(value: string) {
  currentPassword.value = ''
  password.value = ''
  confirmation.value = ''
  deletePassword.value = ''
  profilePassword.value = ''
  tab.value = value
  error.value = ''
  message.value = ''
  errors.value = {}
}
async function saveProfile() {
  if (busy.value) return
  start('profile')
  try {
    const result = await api<{ data: User }>('/api/account/profile', {
      method: 'PATCH',
      body: JSON.stringify({
        name: name.value,
        email: email.value,
        ...(emailChanged.value ? { current_password: profilePassword.value } : {}),
      }),
    })
    auth.setUser(result.data)
    name.value = result.data.name
    email.value = result.data.email
    profilePassword.value = ''
    message.value = 'Perfil atualizado com sucesso.'
  } catch (cause) {
    fail(cause)
  } finally {
    busy.value = ''
  }
}
async function changePassword() {
  if (busy.value) return
  start('password')
  try {
    await api('/api/account/password', {
      method: 'PUT',
      body: JSON.stringify({
        current_password: currentPassword.value,
        password: password.value,
        password_confirmation: confirmation.value,
      }),
    })
    currentPassword.value = ''
    password.value = ''
    confirmation.value = ''
    auth.clearSession()
    await router.push({ name: 'login', query: { notice: 'password-changed' } })
  } catch (cause) {
    fail(cause)
  } finally {
    busy.value = ''
  }
}
async function resend() {
  if (busy.value) return
  start('email')
  try {
    const result = await api<{ message: string }>('/api/auth/email/resend', { method: 'POST' })
    message.value = result.message
  } catch (cause) {
    fail(cause)
  } finally {
    busy.value = ''
  }
}
async function deleteAccount() {
  if (busy.value) return
  start('delete')
  try {
    await api('/api/account', {
      method: 'DELETE',
      body: JSON.stringify({
        current_password: deletePassword.value,
        confirmation: deleteConfirmation.value,
      }),
    })
    deletePassword.value = ''
    auth.clearSession()
    await router.push({ name: 'login', query: { notice: 'account-deleted' } })
  } catch (cause) {
    fail(cause)
  } finally {
    busy.value = ''
  }
}
</script>
<template>
  <div v-if="auth.user.value" class="page-content">
    <div class="page-heading">
      <div>
        <p class="eyebrow">O TEU LUGAR NO CLUBE</p>
        <h1>Olá, {{ firstName }}<span class="green-dot">.</span></h1>
        <p>O teu perfil, os teus acessos e a segurança da tua conta.</p>
      </div>
      <span class="role-badge"
        ><AppIcon :name="auth.isAdmin.value ? 'shield' : 'user'" :size="15" />{{
          auth.isAdmin.value ? 'Administrador' : 'Jogador'
        }}</span
      >
    </div>
    <div class="page-tabs" aria-label="Definições da conta">
      <button
        :class="{ active: tab === 'profile' }"
        :aria-pressed="tab === 'profile'"
        :disabled="!!busy"
        @click="switchTab('profile')"
      >
        <AppIcon name="user" :size="17" />O meu perfil</button
      ><button
        :class="{ active: tab === 'security' }"
        :aria-pressed="tab === 'security'"
        :disabled="!!busy"
        @click="switchTab('security')"
      >
        <AppIcon name="shield" :size="17" />Segurança
      </button>
      <button
        :class="{ active: tab === 'history' }"
        :aria-pressed="tab === 'history'"
        :disabled="!!busy"
        @click="switchTab('history')"
      >
        <AppIcon name="clock" :size="17" />Os meus jogos
      </button>
      <button
        :class="{ active: tab === 'tournaments' }"
        :aria-pressed="tab === 'tournaments'"
        :disabled="!!busy"
        @click="switchTab('tournaments')"
      >
        <AppIcon name="trophy" :size="17" />Os meus torneios
      </button>
    </div>
    <TournamentHistory v-if="tab === 'tournaments'" />
    <PlayerHistory v-if="tab === 'profile' || tab === 'history'" :detailed="tab === 'history'" />
    <div v-if="message" class="alert success" role="status">{{ message }}</div>
    <div v-if="error" class="alert error" role="alert">{{ error }}</div>
    <div v-if="tab === 'profile'" class="settings-grid">
      <section class="surface settings-card">
        <div class="card-heading">
          <div>
            <h2>Dados do perfil</h2>
            <p>Como te apresentas no clube.</p>
          </div>
          <AppIcon name="user" />
        </div>
        <form @submit.prevent="saveProfile" :aria-busy="busy === 'profile'">
          <FormField
            v-model="name"
            label="Nome de jogador"
            autocomplete="nickname"
            required
            minlength="2"
            maxlength="80"
            :disabled="!!busy"
            :error="errors.name?.[0]"
          /><FormField
            v-model="email"
            label="Email"
            type="email"
            autocomplete="email"
            required
            maxlength="254"
            :disabled="!!busy"
            :error="errors.email?.[0]"
          /><FormField
            v-if="emailChanged"
            v-model="profilePassword"
            label="Palavra-passe atual"
            type="password"
            autocomplete="current-password"
            required
            :disabled="!!busy"
            :error="errors.current_password?.[0]"
            hint="Confirma a tua identidade para alterar o email. O novo endereço terá de ser verificado."
          />
          <div class="form-actions">
            <span class="field-hint">Membro desde {{ joined }}</span
            ><button class="button-primary" :disabled="!!busy">
              {{ busy === 'profile' ? 'A guardar…' : 'Guardar alterações'
              }}<AppIcon name="check" :size="17" />
            </button>
          </div>
        </form>
      </section>
      <aside class="surface verification-card">
        <span class="settings-icon"
          ><AppIcon :name="auth.user.value.email_verified ? 'shield' : 'mail'" :size="26" /></span
        ><span class="role-badge" :class="{ neutral: !auth.user.value.email_verified }">{{
          auth.user.value.email_verified ? 'Email confirmado' : 'Confirmação pendente'
        }}</span>
        <h2>
          {{
            auth.user.value.email_verified ? 'Tudo certo com o teu email.' : 'Confirma o teu email.'
          }}
        </h2>
        <p>
          {{
            auth.user.value.email_verified
              ? 'O teu endereço está verificado e associado à tua conta.'
              : 'Abre o link da mensagem de confirmação para verificar que este endereço te pertence.'
          }}
        </p>
        <strong class="verification-email">{{ auth.user.value.email }}</strong
        ><button
          v-if="!auth.user.value.email_verified"
          class="button-secondary"
          :disabled="!!busy"
          @click="resend"
        >
          {{ busy === 'email' ? 'A enviar…' : 'Reenviar confirmação'
          }}<AppIcon name="arrow" :size="16" />
        </button>
      </aside>
    </div>
    <div v-else-if="tab === 'security'" class="settings-grid">
      <section class="surface settings-card">
        <div class="card-heading">
          <div>
            <h2>Alterar palavra-passe</h2>
            <p>Escolhe uma palavra-passe exclusiva para esta conta.</p>
          </div>
          <AppIcon name="lock" />
        </div>
        <form @submit.prevent="changePassword" :aria-busy="busy === 'password'">
          <FormField
            v-model="currentPassword"
            label="Palavra-passe atual"
            type="password"
            autocomplete="current-password"
            required
            :disabled="!!busy"
            :error="errors.current_password?.[0]"
          /><FormField
            v-model="password"
            label="Nova palavra-passe"
            type="password"
            autocomplete="new-password"
            required
            minlength="8"
            maxlength="72"
            :disabled="!!busy"
            :error="errors.password?.[0]"
            hint="Pelo menos 8 caracteres, com letras e números."
          /><FormField
            v-model="confirmation"
            label="Confirmar nova palavra-passe"
            type="password"
            autocomplete="new-password"
            required
            maxlength="72"
            :disabled="!!busy"
          />
          <div class="notice-strip compact">
            <AppIcon name="info" :size="18" />
            <p>Ao alterar a palavra-passe, todas as sessões terminam. Terás de entrar novamente.</p>
          </div>
          <button class="button-primary" :disabled="!!busy">
            {{ busy === 'password' ? 'A atualizar…' : 'Atualizar palavra-passe'
            }}<AppIcon name="arrow" :size="17" />
          </button>
        </form>
      </section>
      <aside class="account-feature security-aside">
        <span class="section-index">UM ACESSO SÓ TEU</span><AppIcon name="shield" :size="40" />
        <h2>A tua conta,<br />bem cuidada.</h2>
        <p>
          Não partilhes a palavra-passe. Se a esqueceres, podes pedir um link de recuperação no ecrã
          de entrada.
        </p>
        <span class="pill-outline">Sessão protegida</span>
      </aside>
    </div>
    <RouterLink v-if="auth.isAdmin.value" to="/admin" class="admin-callout"
      ><span class="callout-icon"><AppIcon name="shield" :size="24" /></span
      ><span
        ><strong>Administração do clube</strong
        ><small>Gere os membros e consulta o histórico de ações.</small></span
      ><AppIcon name="arrow"
    /></RouterLink>
    <details v-if="!auth.isAdmin.value && tab === 'security'" class="surface danger-zone">
      <summary>Eliminar a minha conta</summary>
      <p>
        A conta fica desativada e deixas de poder entrar. Um administrador pode recuperá-la; os
        registos necessários ao funcionamento do clube são preservados.
      </p>
      <form @submit.prevent="deleteAccount">
        <FormField
          v-model="deletePassword"
          label="Palavra-passe atual para eliminar a conta"
          type="password"
          autocomplete="current-password"
          required
          :disabled="!!busy"
          :error="errors.current_password?.[0]"
        /><FormField
          v-model="deleteConfirmation"
          label="Escreve ELIMINAR para confirmar"
          required
          :disabled="!!busy"
          :error="errors.confirmation?.[0]"
        /><button class="button-danger" :disabled="!!busy || deleteConfirmation !== 'ELIMINAR'">
          {{ busy === 'delete' ? 'A eliminar…' : 'Eliminar a minha conta' }}
        </button>
      </form>
    </details>
  </div>
</template>
