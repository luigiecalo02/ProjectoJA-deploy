import { onUnmounted, reactive, toValue, watch, type MaybeRefOrGetter } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

export type PageChromeAction = {
  key: string
  label: string
  icon?: string
  severity?: 'secondary' | 'success' | 'info' | 'warn' | 'danger' | 'contrast' | 'help'
  outlined?: boolean
  text?: boolean
  loading?: boolean
  disabled?: boolean
  overflow?: boolean
  onClick: () => void
}

export type PageChromeConfig = {
  title?: string
  subtitle?: string
  backTo?: RouteLocationRaw | null
  actions?: PageChromeAction[]
}

const chrome = reactive({
  title: '',
  subtitle: '',
  backTo: null as RouteLocationRaw | null,
  actions: [] as PageChromeAction[],
})

let chromeOwner = 0
let activeChromeOwner = 0

function apply(config: PageChromeConfig): void {
  chrome.title = config.title?.trim() || ''
  chrome.subtitle = config.subtitle?.trim() || ''
  chrome.backTo = config.backTo ?? null
  chrome.actions = config.actions ? [...config.actions] : []
}

function resetPageChrome(): void {
  apply({})
}

export function usePageChrome(config?: MaybeRefOrGetter<PageChromeConfig>) {
  if (config) {
    const owner = ++chromeOwner
    activeChromeOwner = owner
    watch(
      () => toValue(config),
      (next) => {
        if (activeChromeOwner === owner) apply(next)
      },
      { immediate: true, deep: true },
    )
    onUnmounted(() => {
      if (activeChromeOwner === owner) resetPageChrome()
    })
  }

  return chrome
}

export function getPageChrome() {
  return chrome
}

export { resetPageChrome }
