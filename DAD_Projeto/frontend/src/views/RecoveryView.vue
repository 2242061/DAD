<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import FormField from '@/components/FormField.vue'
import AppIcon from '@/components/AppIcon.vue'
import { api, ApiError, type FieldErrors } from '@/lib/api'
import { useAuth } from '@/composables/useAuth'
const route = useRoute(),
  router = useRouter(),
  auth = useAuth()
const resetting = computed(() => route.name === 'reset-password')
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const password = ref(''),
  confirmation = ref(''),
  busy = ref(false),
  error = ref(''),
  message = ref('')
const errors = ref<FieldErrors>({})
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  message.value = ''
  errors.value = {}
  try {
    await api('/sanctum/csrf-cookie')
    if (resetting.value) {
      await api('/api/auth/reset-password', {
        method: 'POST',
        body: JSON.stringify({
          email: email.value,
          token: token.value,
          password: password.value,
          password_confirmation: confirmation.value,
        }),
      })
      password.value = ''
      confirmation.value = ''
      auth.clearSession()
      await router.push({ name: 'login', query: { notice: 'password-reset' } })
    } else {
      const result = await api<{ message: string }>('/api/auth/forgot-password', {
        method: 'POST',
        body: JSON.stringify({ email: email.value }),
      })
      message.value = result.message
    }
  } catch (cause) {
    if (cause instanceof ApiError) errors.value = cause.errors
    error.value = cause instanceof Error ? cause.message : 'Não foi possível concluir o pedido.'
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div class="recovery-page">
    <div class="recovery-intro">
      <p class="eyebrow">VOLTAR AO CLUBE</p>
      <h1>{{ resetting ? 'Um novo acesso.' : 'Ainda há uma próxima jogada.' }}</h1>
      <p>
        {{
          resetting
            ? 'Escolhe uma nova palavra-passe para a tua conta.'
            : 'Esqueceste-te da palavra-passe? Ajudamos-te a voltar.'
        }}
      </p>
    </div>
    <section class="surface recovery-card">
      <span class="settings-icon"><AppIcon :name="resetting ? 'lock' : 'mail'" :size="26" /></span>
      <h2>{{ resetting ? 'Repor palavra-passe' : 'Recuperar a conta' }}</h2>
      <p class="muted-description">
        {{
          resetting
            ? 'O link só pode ser utilizado uma vez.'
            : 'Indica o email da tua conta para pedir um link de recuperação.'
        }}
      </p>
      <div v-if="message" class="alert success" role="status">{{ message }}</div>
      <div v-if="error" class="alert error" role="alert">{{ error }}</div>
      <div v-if="errors.token || (resetting && !token)" class="alert error" role="alert">
        {{ errors.token?.[0] ?? 'Este link está incompleto. Pede um novo link de recuperação.' }}
        <RouterLink to="/recuperar-conta" class="inline-link">Pedir novo link</RouterLink>
      </div>
      <form @submit.prevent="submit" :aria-busy="busy">
        <FormField
          v-model="email"
          label="Email"
          type="email"
          autocomplete="email"
          required
          maxlength="254"
          :disabled="busy"
          :error="errors.email?.[0]"
        /><template v-if="resetting"
          ><FormField
            v-model="password"
            label="Nova palavra-passe"
            type="password"
            autocomplete="new-password"
            required
            minlength="8"
            maxlength="72"
            :disabled="busy"
            :error="errors.password?.[0]"
            hint="Pelo menos 8 caracteres, com letras e números." /><FormField
            v-model="confirmation"
            label="Confirmar nova palavra-passe"
            type="password"
            autocomplete="new-password"
            required
            maxlength="72"
            :disabled="busy" /></template
        ><button class="button-primary form-submit" :disabled="busy || (resetting && !token)">
          {{
            busy
              ? 'Só um instante…'
              : resetting
                ? 'Guardar nova palavra-passe'
                : 'Enviar link de recuperação'
          }}<AppIcon name="arrow" :size="18" />
        </button>
      </form>
      <RouterLink to="/entrar" class="guest-link recovery-back">Voltar a entrar</RouterLink>
    </section>
  </div>
</template>
