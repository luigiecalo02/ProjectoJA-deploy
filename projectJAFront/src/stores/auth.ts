import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authService } from '@/services/authService'
import { TOKEN_KEY } from '@/services/api'
import type { AuthContextOption, AuthUser, LoginPayload } from '@/modules/auth/types'

const USER_KEY = 'projectja_user'

function readStoredUser(): AuthUser | null {
  const raw = localStorage.getItem(USER_KEY)
  if (!raw) return null
  try {
    return JSON.parse(raw) as AuthUser
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const user = ref<AuthUser | null>(readStoredUser())
  const bootstrapped = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))
  const permissions = computed(() => user.value?.permissions ?? [])
  const requiresContext = computed(() => Boolean(user.value?.requires_context))
  const contexto = computed(() => user.value?.contexto ?? null)
  const contextOptions = computed(() => user.value?.context_options ?? [])

  function persistUser(nextUser: AuthUser): void {
    user.value = nextUser
    localStorage.setItem(USER_KEY, JSON.stringify(nextUser))
  }

  function persistSession(nextToken: string, nextUser: AuthUser): void {
    token.value = nextToken
    localStorage.setItem(TOKEN_KEY, nextToken)
    persistUser(nextUser)
  }

  function clearSession(): void {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
  }

  async function login(payload: LoginPayload): Promise<void> {
    const result = await authService.login(payload)
    persistSession(result.token, result.user)
  }

  async function logout(): Promise<void> {
    try {
      if (token.value) {
        await authService.logout()
      }
    } finally {
      clearSession()
    }
  }

  async function fetchMe(): Promise<AuthUser | null> {
    if (!token.value) {
      clearSession()
      return null
    }
    try {
      const me = await authService.me()
      persistUser(me)
      return me
    } catch {
      clearSession()
      return null
    } finally {
      bootstrapped.value = true
    }
  }

  async function bootstrap(): Promise<void> {
    if (bootstrapped.value) return
    if (!token.value) {
      bootstrapped.value = true
      return
    }
    await fetchMe()
  }

  async function acceptToken(nextToken: string): Promise<void> {
    token.value = nextToken
    localStorage.setItem(TOKEN_KEY, nextToken)
    await fetchMe()
    if (!user.value) {
      throw new Error('Sesión OAuth inválida')
    }
  }

  async function selectContext(option: AuthContextOption): Promise<AuthUser> {
    const updated = await authService.setContext({
      organizacion_id: option.organizacion_id,
      rol_id: option.rol_id,
    })
    persistUser(updated)
    return updated
  }

  async function switchContext(): Promise<void> {
    const updated = await authService.clearContext()
    persistUser(updated)
  }

  function hasPermission(permission: string): boolean {
    const platformMode =
      Boolean(user.value?.is_super || (user.value?.roles ?? []).includes('super_admin')) &&
      !user.value?.contexto?.organizacion_id

    if (platformMode && !user.value?.requires_context) {
      return true
    }
    return permissions.value.includes(permission)
  }

  return {
    token,
    user,
    bootstrapped,
    isAuthenticated,
    permissions,
    requiresContext,
    contexto,
    contextOptions,
    login,
    logout,
    fetchMe,
    bootstrap,
    acceptToken,
    selectContext,
    switchContext,
    hasPermission,
    clearSession,
    persistSession,
  }
})
