<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import AppSearchField from '@/components/AppSearchField.vue'
import PageLoader from '@/components/PageLoader.vue'
import { cabanasService } from '@/services/cabanasService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { Cabana } from '@/modules/cabanas/types'

const props = defineProps<{ eventId: number | null }>()
const emit = defineEmits<{ summary: [payload: { cabanas: number; capacidad: number }] }>()
const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()
const catalog = ref<Cabana[]>([])
const selectedIds = ref<number[]>([])
const search = ref('')
const loading = ref(false)
const saving = ref(false)
const canManage = computed(() => can('events.update') || can('cabanas.assign'))
const filtered = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('es')
  return term ? catalog.value.filter((item) => item.nombre.toLocaleLowerCase('es').includes(term)) : catalog.value
})
const selectedCabanas = computed(() =>
  selectedIds.value.map((id) => catalog.value.find((item) => item.id === id)).filter((item): item is Cabana => !!item),
)
const totalCapacity = computed(() => selectedCabanas.value.reduce((sum, item) => sum + Number(item.capacidad_total ?? 0), 0))

function emitSummary(): void {
  emit('summary', { cabanas: selectedIds.value.length, capacidad: totalCapacity.value })
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [available, configured] = await Promise.all([
      cabanasService.list({ per_page: 200, estado: 'activa' }),
      props.eventId ? cabanasService.getEventCabanas(props.eventId) : Promise.resolve([]),
    ])
    catalog.value = available.items
    selectedIds.value = configured
      .sort((a, b) => a.orden - b.orden)
      .map((item) => item.cabana_id)
    emitSummary()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

function toggle(id: number): void {
  if (!canManage.value) return
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((value) => value !== id)
    : [...selectedIds.value, id]
  emitSummary()
}

function move(id: number, direction: -1 | 1): void {
  const index = selectedIds.value.indexOf(id)
  const target = index + direction
  if (index < 0 || target < 0 || target >= selectedIds.value.length) return
  const next = [...selectedIds.value]
  ;[next[index], next[target]] = [next[target], next[index]]
  selectedIds.value = next
}

async function save(): Promise<void> {
  if (!props.eventId || !canManage.value) return
  saving.value = true
  try {
    await cabanasService.syncEventCabanas(
      props.eventId,
      selectedIds.value.map((cabanaId, index) => ({ cabana_id: cabanaId, orden: index + 1 })),
    )
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.eventSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

watch(() => props.eventId, () => void load())
onMounted(() => void load())
</script>

<template>
  <section class="event-cabanas-step">
    <div class="step-heading">
      <div>
        <h2>{{ t('cabanas.eventTitle') }}</h2>
        <p>{{ t('cabanas.eventHint') }}</p>
      </div>
      <Tag :value="t('cabanas.selectedCount', { count: selectedIds.length })" severity="info" />
    </div>
    <Message v-if="!eventId" severity="info" :closable="false">{{ t('cabanas.saveEventFirst') }}</Message>
    <template v-else>
      <div class="summary">
        <span><i class="pi pi-building" /> {{ selectedIds.length }} {{ t('cabanas.cabins') }}</span>
        <span><i class="pi pi-users" /> {{ totalCapacity }} {{ t('cabanas.spaces') }}</span>
      </div>
      <AppSearchField v-model="search" :placeholder="t('cabanas.search')" />
      <PageLoader v-if="loading" />
      <div v-else class="cabana-cards">
        <article
          v-for="item in filtered"
          :key="item.id"
          class="cabana-card"
          :class="{ selected: selectedIds.includes(item.id) }"
          @click="toggle(item.id)"
        >
          <div class="cabana-icon" :class="{ 'has-photo': !!item.image_url }">
            <img v-if="item.image_url" :src="item.image_url" :alt="item.nombre" />
            <i v-else class="pi pi-building" />
          </div>
          <div>
            <strong>{{ item.nombre }}</strong>
            <small>{{ item.pisos_count ?? item.pisos?.length ?? 0 }} {{ t('cabanas.floors').toLowerCase() }}</small>
          </div>
          <span class="capacity">{{ item.capacidad_total ?? 0 }} <i class="pi pi-user" /></span>
          <i :class="selectedIds.includes(item.id) ? 'pi pi-check-circle' : 'pi pi-circle'" />
        </article>
      </div>
      <div v-if="selectedCabanas.length" class="priority-list">
        <strong>{{ t('cabanas.priority') }}</strong>
        <div v-for="(item, index) in selectedCabanas" :key="item.id">
          <span>{{ index + 1 }}. {{ item.nombre }}</span>
          <span>
            <Button icon="pi pi-chevron-up" text rounded size="small" :disabled="index === 0" @click="move(item.id, -1)" />
            <Button icon="pi pi-chevron-down" text rounded size="small" :disabled="index === selectedCabanas.length - 1" @click="move(item.id, 1)" />
          </span>
        </div>
      </div>
      <div v-if="canManage" class="actions">
        <Button :label="t('common.save')" icon="pi pi-save" :loading="saving" @click="save" />
      </div>
    </template>
  </section>
</template>

<style scoped>
.event-cabanas-step { display: grid; gap: 1rem; }
.step-heading { display: flex; justify-content: space-between; gap: 1rem; align-items: start; }
.step-heading h2, .step-heading p { margin: 0; }
.step-heading p { margin-top: .25rem; color: var(--pj-text-muted); }
.summary { display: flex; flex-wrap: wrap; gap: 1rem; padding: .75rem; border-radius: 10px; background: var(--pj-primary-soft); }
.summary span { display: inline-flex; align-items: center; gap: .4rem; font-weight: 600; }
.cabana-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: .65rem; }
.cabana-card { display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: .65rem; padding: .8rem; border: 1px solid var(--pj-border); border-radius: 12px; background: var(--pj-bg-elevated); cursor: pointer; }
.cabana-card.selected { border-color: var(--p-primary-color); background: var(--pj-primary-soft); }
.cabana-card strong, .cabana-card small { display: block; }
.cabana-card small { margin-top: .18rem; color: var(--pj-text-muted); }
.cabana-icon { display: grid; place-items: center; width: 2.6rem; height: 2.6rem; overflow: hidden; border-radius: 9px; background: color-mix(in srgb, var(--p-primary-color) 12%, white); color: var(--p-primary-color); }
.cabana-icon img { width: 100%; height: 100%; object-fit: cover; }
.cabana-icon.has-photo { background: #e2e8f0; }
.capacity { white-space: nowrap; font-weight: 700; }
.priority-list { display: grid; gap: .35rem; padding: .75rem; border: 1px solid var(--pj-border); border-radius: 10px; }
.priority-list > div { display: flex; justify-content: space-between; align-items: center; min-height: 2.2rem; border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent); }
.actions { display: flex; justify-content: flex-end; }
</style>
