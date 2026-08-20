import type { RouteLocationRaw } from 'vue-router'
import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guest?: boolean
    permission?: string
    permissionsAny?: string[]
    contextSelection?: boolean
    titleKey?: string
    backTo?: RouteLocationRaw
    wide?: boolean
  }
}

export {}
