import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authService } from '@/services/authService'
import { TOKEN_KEY, isNetworkError, isUnauthorizedError } from '@/services/api'
import { clearAllFieldData, clearFieldDataForUser } from '@/modules/fieldMode/db'
import type { AuthContextOption, AuthUser, LoginPayload } from '@/modules/auth/types'
import { clubLoaderKeyFromContext, persistClubLoader } from '@/modules/auth/clubLogin'

const USER_KEY = 'projectja_user'
const IMPERSONATOR_TOKEN_KEY = 'projectja_impersonator_token'
const IMPERSONATOR_USER_KEY = 'projectja_impersonator_user'

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
  const isImpersonating = computed(() => Boolean(user.value?.impersonated || user.value?.impersonator))
  const impersonator = computed(() => user.value?.impersonator ?? null)
  const canImpersonate = computed(() => {
    if (isImpersonating.value) return false
    return (
      Boolean(user.value?.is_super || user.value?.is_admin) ||
      (user.value?.roles ?? []).includes('super_admin') ||
      (user.value?.roles ?? []).includes('admin')
    )
  })

  function persistUser(nextUser: AuthUser): void {
    user.value = nextUser
    localStorage.setItem(USER_KEY, JSON.stringify(nextUser))
    persistClubLoader(clubLoaderKeyFromContext(nextUser.contexto ?? null))
  }

  function persistSession(nextToken: string, nextUser: AuthUser): void {
    token.value = nextToken
    localStorage.setItem(TOKEN_KEY, nextToken)
    persistUser(nextUser)
  }

  function clearImpersonatorBackup(): void {
    localStorage.removeItem(IMPERSONATOR_TOKEN_KEY)
    localStorage.removeItem(IMPERSONATOR_USER_KEY)
  }

  function clearSession(): void {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
    clearImpersonatorBackup()
  }

  function backupCurrentSession(): void {
    if (!token.value || !user.value || isImpersonating.value) return
    localStorage.setItem(IMPERSONATOR_TOKEN_KEY, token.value)
    localStorage.setItem(IMPERSONATOR_USER_KEY, JSON.stringify(user.value))
  }

  async function login(payload: LoginPayload): Promise<void> {
    const result = await authService.login(payload)
    persistSession(result.token, result.user)
  }

  async function logout(): Promise<void> {
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      return
    }
    if (isImpersonating.value) {
      await stopImpersonation()
      return
    }
    const userId = user.value?.id
    try {
      if (token.value) {
        await authService.logout()
      }
    } catch {
      // Sin red el token local igual debe limpiarse.
    } finally {
      if (userId) {
        await clearFieldDataForUser(userId).catch(() => undefined)
      } else {
        await clearAllFieldData().catch(() => undefined)
      }
      clearSession()
    }
  }

  async function impersonate(userId: number): Promise<void> {
    backupCurrentSession()
    const result = await authService.impersonate(userId)
    persistSession(result.token, result.user)
  }

  async function stopImpersonation(): Promise<void> {
    const result = await authService.stopImpersonation()
    persistSession(result.token, result.user)
    clearImpersonatorBackup()
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
    } catch (error) {
      if (isUnauthorizedError(error)) {
        await clearAllFieldData().catch(() => undefined)
        clearSession()
        return null
      }
      if (isNetworkError(error) || user.value) {
        return user.value
      }
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
    impersonate,
    stopImpersonation,
    fetchMe,
    bootstrap,
    acceptToken,
    selectContext,
    switchContext,
    hasPermission,
    clearSession,
    persistSession,
    persistUser,
    isImpersonating,
    impersonator,
    canImpersonate,
  }
})
