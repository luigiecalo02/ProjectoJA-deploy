<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import PageLoader from '@/components/PageLoader.vue'
import CabanaLayoutEditor from '@/components/cabanas/CabanaLayoutEditor.vue'
import { cabanasService } from '@/services/cabanasService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { Cabana, CabanaLayoutPayload } from '@/modules/cabanas/types'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()
const cabanaId = computed(() => Number(route.params.id))
const cabana = ref<Cabana | null>(null)
const loading = ref(true)
const saving = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    cabana.value = await cabanasService.get(cabanaId.value)
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    await router.push({ name: 'cabanas' })
  } finally {
    loading.value = false
  }
}

async function save(payload: CabanaLayoutPayload): Promise<void> {
  saving.value = true
  try {
    cabana.value = await cabanasService.saveLayout(cabanaId.value, payload)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.layoutSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="pj-page layout-page">
    <header class="pj-page__header">
      <div class="heading">
        <Button icon="pi pi-arrow-left" text rounded @click="router.push({ name: 'cabanas' })" />
        <div>
          <h1 class="pj-display">{{ cabana?.nombre || t('cabanas.layout') }}</h1>
          <p>{{ t('cabanas.layoutSubtitle') }}</p>
        </div>
      </div>
    </header>
    <PageLoader v-if="loading" />
    <div v-else-if="cabana" class="pj-panel editor-panel">
      <CabanaLayoutEditor
        :cabana="cabana"
        :saving="saving"
        :readonly="!can('cabanas.update')"
        @save="save"
      />
    </div>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: center; gap: .45rem; }
.heading h1, .heading p { margin: 0; }
.editor-panel { padding: 1rem; min-width: 0; }
</style>
