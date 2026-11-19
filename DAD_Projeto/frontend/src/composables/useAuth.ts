import { computed, readonly, ref } from 'vue'
import { api, ApiError } from '@/lib/api'

export interface User {
  id: number
  name: string
  email: string
  role: 'player' | 'admin'
  created_at: string
  email_verified: boolean
  blocked_at: string | null
  deleted_at: string | null
}

const user = ref<User | null>(null)
const ready = ref(false)
const connectionError = ref('')
let initialRequest: Promise<void> | undefined

async function refreshUser() {
  connectionError.value = ''
  try {
    user.value = (await api<{ data: User }>('/api/auth/me')).data
  } catch (error) {
    if (error instanceof ApiError && [401, 403].includes(error.status)) user.value = null
    else
      connectionError.value =
        error instanceof Error ? error.message : 'Não foi possível verificar a sessão.'
  } finally {
    ready.value = true
  }
}

function initialize() {
  return (initialRequest ??= refreshUser())
}

async function authenticate(mode: 'login' | 'register', values: Record<string, string>) {
  await api('/sanctum/csrf-cookie')
  user.value = (
    await api<{ data: User }>(`/api/auth/${mode}`, {
      method: 'POST',
      body: JSON.stringify(values),
    })
  ).data
  connectionError.value = ''
}

async function logout() {
  try {
    await api('/api/auth/logout', { method: 'POST' })
  } catch (error) {
    if (!(error instanceof ApiError && error.status === 401)) throw error
  }
  user.value = null
}

export function useAuth() {
  return {
    user: readonly(user),
    ready: readonly(ready),
    connectionError: readonly(connectionError),
    isAdmin: computed(() => user.value?.role === 'admin'),
    initialize,
    refreshUser,
    authenticate,
    logout,
    clearSession: () => {
      user.value = null
    },
    setUser: (value: User) => {
      user.value = value
    },
  }
}
