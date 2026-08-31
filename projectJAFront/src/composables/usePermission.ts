import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function usePermission() {
  const auth = useAuthStore()

  const permissions = computed(() => auth.permissions)

  function can(permission: string): boolean {
    return auth.hasPermission(permission)
  }

  function canCatalog(module: 'terrenos' | 'cabanas', action: 'view' | 'create' | 'update' | 'delete'): boolean {
    return can(`lugares.${action}`) || can(`${module}.${action}`)
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
    canCatalog,
    canAny,
    canAll,
  }
}
