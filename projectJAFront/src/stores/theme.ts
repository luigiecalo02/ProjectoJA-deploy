import { computed, watch } from 'vue'
import { defineStore } from 'pinia'
import { useStorage } from '@vueuse/core'

const DARK_CLASS = 'dark'
const STORAGE_KEY = 'projectja_theme_dark'

function toBool(value: unknown): boolean {
  return value === true || value === 'true' || value === 1 || value === '1'
}

export const useThemeStore = defineStore('theme', () => {
  const isDark = useStorage(STORAGE_KEY, false, undefined, {
    serializer: {
      read: (raw) => raw === 'true' || raw === '1',
      write: (value) => (toBool(value) ? 'true' : 'false'),
    },
  })

  const modeLabel = computed(() => (toBool(isDark.value) ? 'dark' : 'light'))

  function applyTheme(dark: unknown): void {
    const enabled = toBool(dark)
    const root = document.documentElement
    root.classList.toggle(DARK_CLASS, enabled)
    root.style.colorScheme = enabled ? 'dark' : 'light'
    document.body?.classList.toggle(DARK_CLASS, enabled)

    const themeColor = document.querySelector('meta[name="theme-color"]')
    if (themeColor) {
      themeColor.setAttribute('content', enabled ? '#07131f' : '#0A1B3D')
    }
  }

  function toggle(): void {
    isDark.value = !toBool(isDark.value)
  }

  function init(): void {
    applyTheme(isDark.value)
  }

  watch(isDark, (dark) => applyTheme(dark), { immediate: true })

  return {
    isDark,
    modeLabel,
    toggle,
    init,
  }
})
