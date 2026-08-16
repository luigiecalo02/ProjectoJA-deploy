<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { usePermission } from '@/composables/usePermission'
import Button from 'primevue/button'
import { useRouter } from 'vue-router'

const { t } = useI18n()
const auth = useAuthStore()
const { can } = usePermission()
const router = useRouter()

const welcome = computed(() =>
  t('dashboard.welcome', { name: auth.user?.name || '…' }),
)
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('dashboard.title') }}</h1>
        <p class="pj-page__subtitle">{{ welcome }}</p>
      </div>
    </header>

    <div class="pj-panel dashboard-panel">
      <p class="pj-muted">{{ t('dashboard.hint') }}</p>
      <Button
        v-if="can('users.view')"
        :label="t('nav.users')"
        @click="router.push({ name: 'users' })"
      />
    </div>
  </section>
</template>

<style scoped>
.dashboard-panel {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.dashboard-panel p {
  margin: 0;
  max-width: 42ch;
}
</style>
