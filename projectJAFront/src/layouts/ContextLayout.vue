<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useThemeStore } from '@/stores/theme'
import Button from 'primevue/button'
import { brandConfig } from '@/config/brand'

const { t } = useI18n()
const theme = useThemeStore()
</script>

<template>
  <div
    class="context-shell"
    :style="{ '--pj-pattern': `url(${brandConfig.pattern})` }"
  >
    <Button
      class="context-shell__theme"
      text
      rounded
      :icon="theme.isDark ? 'pi pi-sun' : 'pi pi-moon'"
      :aria-label="theme.isDark ? t('nav.themeLight') : t('nav.themeDark')"
      @click="theme.toggle()"
    />
    <main class="context-shell__main">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.context-shell {
  min-height: 100vh;
  background-color: #f4f6f9;
  background-image: var(--pj-pattern);
  background-repeat: repeat;
  background-size: 420px auto;
  position: relative;
  padding: 1.25rem;
}

.context-shell__theme {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  color: var(--pj-navy) !important;
  z-index: 2;
}

.context-shell__main {
  width: min(1120px, 100%);
  margin: 0 auto;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 1.25rem;
  box-shadow: 0 18px 50px rgba(11, 31, 74, 0.1);
  padding: 1.5rem 1.25rem 1.75rem;
}

@media (min-width: 900px) {
  .context-shell {
    padding: 2rem;
  }

  .context-shell__main {
    padding: 2rem 2rem 2.25rem;
  }
}
</style>
