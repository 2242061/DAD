<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { api } from '@/lib/api'
import { useAuth } from '@/composables/useAuth'
const route = useRoute(),
  auth = useAuth()
const busy = ref(false),
  done = ref(false),
  error = ref('')
const path = typeof route.query.path === 'string' ? route.query.path : ''
const valid = /^\/api\/auth\/email\/verify\/\d+\/[a-f0-9]{40}\?/.test(path)
async function verify() {
  busy.value = true
  error.value = ''
  try {
    await api(path)
    await auth.refreshUser()
    done.value = true
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'O link é inválido ou expirou.'
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div class="recovery-page">
    <section class="surface recovery-card">
      <span class="settings-icon"><AppIcon name="shield" :size="28" /></span>
      <p class="eyebrow">MAIS UM PASSO NO CLUBE</p>
      <h1 class="small-title">{{ done ? 'Email confirmado.' : 'Confirmar o teu email.' }}</h1>
      <p class="muted-description">
        {{
          done
            ? 'A tua conta já tem um endereço verificado.'
            : 'Confirma que este endereço te pertence. Tens de estar na conta que recebeu o link.'
        }}
      </p>
      <div v-if="error || !valid" class="alert error" role="alert">
        {{ error || 'Este link está incompleto. Pede um novo na tua conta.' }}
      </div>
      <button
        v-if="!done && valid"
        class="button-primary form-submit"
        :disabled="busy"
        @click="verify"
      >
        {{ busy ? 'A confirmar…' : 'Confirmar o meu email'
        }}<AppIcon name="check" :size="17" /></button
      ><RouterLink to="/conta" class="guest-link recovery-back"
        >{{ done ? 'Ir para a minha conta' : 'Voltar à conta / pedir novo link'
        }}<AppIcon name="arrow" :size="16"
      /></RouterLink>
    </section>
  </div>
</template>
