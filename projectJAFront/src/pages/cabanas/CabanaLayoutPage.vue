<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import PageLoader from '@/components/PageLoader.vue'
import CabanaLayoutEditor from '@/components/cabanas/CabanaLayoutEditor.vue'
import { cabanasService } from '@/services/cabanasService'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { Cabana, CabanaLayoutPayload } from '@/modules/cabanas/types'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const toast = useToast()
const { canCatalog } = usePermission()
const cabanaId = computed(() => Number(route.params.id))
const cabana = ref<Cabana | null>(null)
const catalog = ref<Cabana[]>([])
const loading = ref(true)
const saving = ref(false)

async function load(): Promise<void> {
  loading.value = true
  try {
    const current = await cabanasService.get(cabanaId.value)
    const lugarId = current.lugar_id ?? current.lugar?.id ?? null
    const list = lugarId
      ? await cabanasService.list({ per_page: 200, lugar_id: lugarId })
      : { items: [] as Cabana[] }
    cabana.value = current
    const others = list.items.filter((item) => item.id !== current.id)
    catalog.value = [current, ...others]
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    await router.push({ name: 'lugares' })
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

watch(cabanaId, () => void load(), { immediate: true })
</script>

<template>
  <section class="pj-page layout-page">
    <header class="pj-page__header">
      <div class="heading">
        <Button
          icon="pi pi-arrow-left"
          text
          rounded
          @click="router.push(cabana?.lugar_id ? { name: 'lugares.edit', params: { id: cabana.lugar_id } } : { name: 'lugares' })"
        />
        <span v-if="resolveFileUrl(cabana?.image_url)" class="layout-thumb">
          <img :src="resolveFileUrl(cabana?.image_url)!" :alt="cabana.nombre" />
        </span>
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
        :catalog="catalog"
        :saving="saving"
        :readonly="!canCatalog('cabanas', 'update')"
        @save="save"
        @open-cabana="(id) => router.push({ name: 'cabanas.layout', params: { id } })"
      />
    </div>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: center; gap: .45rem; }
.heading h1, .heading p { margin: 0; }
.layout-thumb {
  display: block;
  width: 3.2rem;
  height: 2.3rem;
  overflow: hidden;
  border-radius: 8px;
  flex-shrink: 0;
}
.layout-thumb img { width: 100%; height: 100%; object-fit: cover; }
.layout-page {
  display: flex;
  flex-direction: column;
  min-height: calc(100dvh - var(--pj-topbar-height) - 2.5rem);
}
.editor-panel {
  flex: 1;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 0.75rem;
}
.editor-panel :deep(.studio) {
  flex: 1;
  min-height: 0;
}
@media (min-width: 900px) {
  .layout-page {
    height: calc(100dvh - var(--pj-topbar-height) - 7rem);
    max-height: calc(100dvh - var(--pj-topbar-height) - 7rem);
    min-height: 0;
    overflow: hidden;
  }
}
</style>
