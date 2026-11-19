<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import CheckersBoard from '@/components/CheckersBoard.vue'
import { useAuth } from '@/composables/useAuth'
import { ApiError, type FieldErrors } from '@/lib/api'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const registering = computed(() => route.name === 'register')
const name = ref('')
const email = ref('')
const password = ref('')
const confirmation = ref('')
const showPassword = ref(false)
const busy = ref(false)
const message = ref('')
const errors = ref<FieldErrors>({})
const form = ref<HTMLFormElement>()
const notices: Record<string, string> = {
  'password-changed':
    'Palavra-passe atualizada. As sessões anteriores terminaram. Entra novamente.',
  'password-reset': 'Palavra-passe reposta com sucesso. Já podes entrar com a nova palavra-passe.',
  'account-deleted': 'A tua conta foi desativada. Um administrador pode recuperá-la.',
  'session-expired': 'A sessão terminou ou a conta ficou indisponível. Entra novamente.',
}
const notice = computed(() =>
  typeof route.query.notice === 'string' ? notices[route.query.notice] : undefined,
)

async function submit() {
  if (busy.value) return
  busy.value = true
  message.value = ''
  errors.value = {}
  try {
    const values: Record<string, string> = { email: email.value, password: password.value }
    if (registering.value) {
      values.name = name.value
      values.password_confirmation = confirmation.value
    }
    await auth.authenticate(registering.value ? 'register' : 'login', values)
    password.value = ''
    confirmation.value = ''
    const redirect =
      typeof route.query.redirect === 'string' &&
      route.query.redirect.startsWith('/') &&
      !route.query.redirect.startsWith('//')
        ? route.query.redirect
        : '/conta'
    await router.push(redirect)
  } catch (cause) {
    if (cause instanceof ApiError) errors.value = cause.errors
    message.value =
      cause instanceof Error ? cause.message : 'Não foi possível entrar. Tenta novamente.'
    busy.value = false
    await nextTick()
    form.value?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus()
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <section class="auth-story">
      <div class="eyebrow"><span class="short-line" />ESTRATÉGIA. TRADIÇÃO. COMUNIDADE.</div>
      <h1>Um clássico.<br />Infinitas <em>jogadas.</em></h1>
      <p class="story-description">
        O teu lugar à mesa das damas portuguesas.<br />Cria a tua conta e faz parte do clube.
      </p>
      <CheckersBoard />
      <div class="story-bottom">
        <span class="mini-piece" />
        <p>Cada grande partida começa<br />com uma primeira jogada.</p>
        <span class="story-number">01 — 32</span>
      </div>
    </section>
    <section class="auth-card" :aria-labelledby="'auth-heading'">
      <div class="form-heading">
        <span class="section-index">{{ registering ? '02' : '01' }} / O TEU ESPAÇO</span>
        <h2 id="auth-heading">
          {{ registering ? 'Entra para o clube.' : 'Bom ter-te de volta.' }}
        </h2>
        <p>
          {{
            registering
              ? 'Cria a tua conta de jogador. É simples e gratuito.'
              : 'Entra na tua conta e prepara a próxima jogada.'
          }}
        </p>
      </div>
      <div class="auth-tabs" aria-label="Acesso à conta">
        <RouterLink to="/entrar">Entrar</RouterLink
        ><RouterLink to="/registar">Criar conta</RouterLink>
      </div>
      <form ref="form" @submit.prevent="submit" :aria-busy="busy">
        <div v-if="notice" class="alert success" role="status">{{ notice }}</div>
        <div v-if="message" class="alert error" role="alert">{{ message }}</div>
        <div v-if="registering" class="field">
          <label for="name">Nome de jogador</label>
          <div class="input-wrap">
            <AppIcon name="user" :size="18" /><input
              id="name"
              v-model="name"
              name="name"
              autocomplete="nickname"
              placeholder="Como te podemos chamar?"
              required
              minlength="2"
              maxlength="80"
              :disabled="busy"
              :aria-invalid="!!errors.name"
              :aria-describedby="errors.name ? 'name-error' : undefined"
            />
          </div>
          <p v-if="errors.name" id="name-error" class="field-error">{{ errors.name[0] }}</p>
        </div>
        <div class="field">
          <label for="email">Email</label>
          <div class="input-wrap">
            <AppIcon name="mail" :size="18" /><input
              id="email"
              v-model="email"
              name="email"
              type="email"
              autocomplete="email"
              inputmode="email"
              placeholder="o.teu@email.pt"
              required
              maxlength="254"
              :disabled="busy"
              :aria-invalid="!!errors.email"
              :aria-describedby="errors.email ? 'email-error' : undefined"
            />
          </div>
          <p v-if="errors.email" id="email-error" class="field-error">{{ errors.email[0] }}</p>
        </div>
        <div class="field">
          <label for="password">Palavra-passe</label>
          <div class="input-wrap">
            <AppIcon name="lock" :size="18" /><input
              id="password"
              v-model="password"
              name="password"
              :type="showPassword ? 'text' : 'password'"
              :autocomplete="registering ? 'new-password' : 'current-password'"
              :placeholder="registering ? 'Pelo menos 8 caracteres' : 'A tua palavra-passe'"
              required
              :minlength="registering ? 8 : undefined"
              maxlength="72"
              :disabled="busy"
              :aria-invalid="!!errors.password"
              :aria-describedby="
                errors.password ? 'password-error' : registering ? 'password-hint' : undefined
              "
            /><button
              type="button"
              class="password-toggle"
              :aria-label="showPassword ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe'"
              :aria-pressed="showPassword"
              @click="showPassword = !showPassword"
            >
              <AppIcon :name="showPassword ? 'eyeOff' : 'eye'" :size="18" />
            </button>
          </div>
          <p v-if="errors.password" id="password-error" class="field-error">
            {{ errors.password[0] }}
          </p>
          <p v-if="registering" id="password-hint" class="field-hint">
            Usa pelo menos 8 caracteres, com letras e números.
          </p>
        </div>
        <div v-if="registering" class="field">
          <label for="confirmation">Confirmar palavra-passe</label>
          <div class="input-wrap">
            <AppIcon name="lock" :size="18" /><input
              id="confirmation"
              v-model="confirmation"
              name="password_confirmation"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="new-password"
              placeholder="Repete a palavra-passe"
              required
              maxlength="72"
              :disabled="busy"
              :aria-invalid="!!errors.password"
            />
          </div>
        </div>
        <button class="button-primary form-submit" :disabled="busy" type="submit">
          <span>{{
            busy ? 'Só um instante…' : registering ? 'Criar a minha conta' : 'Entrar no clube'
          }}</span
          ><span v-if="busy" class="spinner" /><AppIcon v-else name="arrow" :size="19" />
        </button>
      </form>
      <RouterLink v-if="!registering" to="/recuperar-conta" class="forgot-link"
        >Esqueci-me da palavra-passe</RouterLink
      >
      <p class="form-alternative">
        {{ registering ? 'Já tens lugar à mesa?' : 'Ainda não fazes parte do clube?' }}
        <RouterLink :to="registering ? '/entrar' : '/registar'">{{
          registering ? 'Entrar' : 'Criar conta'
        }}</RouterLink>
      </p>
      <div class="form-divider"><span>ou</span></div>
      <RouterLink to="/explorar" class="guest-link"
        >Explorar como visitante<AppIcon name="arrow" :size="16"
      /></RouterLink>
      <div class="secure-note">
        <AppIcon name="shield" :size="15" /><span>{{
          registering ? 'A tua conta começa como jogador.' : 'O teu espaço, com acesso protegido.'
        }}</span>
      </div>
    </section>
  </div>
</template>
