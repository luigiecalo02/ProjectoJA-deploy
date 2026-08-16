import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function usePermission() {
  const auth = useAuthStore()

  const permissions = computed(() => auth.permissions)

  function can(permission: string): boolean {
    return auth.hasPermission(permission)
  }

  function canAny(required: string[]): boolean {
    return required.some((permission) => auth.hasPermission(permission))
  }

  function canAll(required: string[]): boolean {
    return required.every((permission) => auth.hasPermission(permission))
  }

  return {
    permissions,
    can,
    canAny,
    canAll,
  }
}
