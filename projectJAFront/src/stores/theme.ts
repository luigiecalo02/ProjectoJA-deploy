import { computed, watch } from 'vue'
import { defineStore } from 'pinia'
import { useStorage } from '@vueuse/core'

const DARK_CLASS = 'dark'

export const useThemeStore = defineStore('theme', () => {
  const isDark = useStorage('projectja_theme_dark', false)

  const modeLabel = computed(() => (isDark.value ? 'dark' : 'light'))

  function applyTheme(dark: boolean): void {
    document.documentElement.classList.toggle(DARK_CLASS, dark)
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
  }

  function toggle(): void {
    isDark.value = !isDark.value
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
