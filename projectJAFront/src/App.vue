<script setup lang="ts">
import { ref } from 'vue'
import Toast from 'primevue/toast'
import PageLoader from '@/components/PageLoader.vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

const { t } = useI18n()
const router = useRouter()
const navigating = ref(false)

router.beforeEach((_to, _from, next) => {
  navigating.value = true
  next()
})

router.afterEach(() => {
  window.setTimeout(() => {
    navigating.value = false
  }, 280)
})

router.onError(() => {
  navigating.value = false
})
</script>

<template>
  <PageLoader
    :show="navigating"
    fullscreen
    :label="t('common.loading')"
  />
  <RouterView />
  <Toast position="top-right" />
</template>
