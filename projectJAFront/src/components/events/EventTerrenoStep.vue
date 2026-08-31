<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Dialog from 'primevue/dialog'
import PageLoader from '@/components/PageLoader.vue'
import { terrenosService } from '@/services/terrenosService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { ConfiguracionTerreno, EventoLote, EventoTerreno, Terreno } from '@/modules/terrenos/types'

const props = defineProps<{
  eventId: number | null
  eventName?: string
  lugarId?: number | null
}>()

const emit = defineEmits<{
  summary: [payload: { terrenoNombre: string | null; lotes: number; capacidad: number }]
  applyCupo: [capacidad: number]
}>()

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can, canCatalog } = usePermission()

const loading = ref(false)
const attaching = ref(false)
const terrenos = ref<Terreno[]>([])
const configs = ref<ConfiguracionTerreno[]>([])
const selectedTerrenoId = ref<number | null>(null)
const selectedConfigId = ref<number | null>(null)
const distribucion = ref<EventoTerreno | null>(null)
const detachVisible = ref(false)
const configNombreResumen = ref<string | null>(null)

const canManage = computed(() => can('terrenos.assign') || canCatalog('terrenos', 'update'))
const canView = computed(() => canCatalog('terrenos', 'view') || canManage.value)

const allLotes = computed(() => {
  const rows: Array<{ zonaNombre: string; lote: EventoLote }> = []
  for (const zona of distribucion.value?.zonas || []) {
    for (const lote of zona.lotes || []) {
      rows.push({ zonaNombre: zona.nombre, lote })
    }
  }
  for (const lote of distribucion.value?.lotes || []) {
    rows.push({ zonaNombre: t('terrenos.lotesDirectos'), lote })
  }
  return rows
})

const lotesDisponiblesCount = computed(
  () => allLotes.value.filter((r) => r.lote.estado === 'disponible').length,
)

const capacidadEstimada = computed(() =>
  allLotes.value.reduce((sum, r) => sum + (r.lote.capacidad_maxima ?? r.lote.capacidad_calculada ?? 0), 0),
)

const capacidadDisponible = computed(() =>
  allLotes.value
    .filter((r) => r.lote.estado === 'disponible')
    .reduce((sum, r) => sum + (r.lote.capacidad_maxima ?? r.lote.capacidad_calculada ?? 0), 0),
)

function emitSummary(): void {
  emit('summary', {
    terrenoNombre: distribucion.value?.terreno?.nombre ?? null,
    lotes: allLotes.value.length,
    capacidad: capacidadEstimada.value,
  })
}

async function loadTerrenos(): Promise<void> {
  if (!canView.value) return
  try {
    const result = await terrenosService.list({
      per_page: 100,
      estado: 'activo',
      lugar_id: props.lugarId ?? undefined,
    })
    terrenos.value = result.items
  } catch {
    terrenos.value = []
  }
}

async function loadConfigs(terrenoId: number | null): Promise<void> {
  selectedConfigId.value = null
  configs.value = []
  if (!terrenoId) return
  try {
    configs.value = await terrenosService.listConfigs(terrenoId)
    const defaultCfg = configs.value.find((c) => c.es_default) || configs.value[0]
    if (defaultCfg) selectedConfigId.value = defaultCfg.id
  } catch {
    configs.value = []
  }
}

async function resolveConfigNombre(dist: EventoTerreno | null): Promise<void> {
  if (!dist) {
    configNombreResumen.value = null
    return
  }
  if (dist.configuracion?.nombre) {
    configNombreResumen.value = dist.configuracion.nombre
    return
  }
  if (!dist.configuracion_terreno_id) {
    configNombreResumen.value = null
    return
  }
  try {
    const list = await terrenosService.listConfigs(dist.terreno_id)
    configNombreResumen.value =
      list.find((c) => c.id === dist.configuracion_terreno_id)?.nombre ?? null
  } catch {
    configNombreResumen.value = null
  }
}

async function loadDistribucion(): Promise<void> {
  if (!props.eventId || !canView.value) {
    distribucion.value = null
    configNombreResumen.value = null
    emitSummary()
    return
  }
  loading.value = true
  try {
    distribucion.value = await terrenosService.getDistribucion(props.eventId)
    if (distribucion.value) {
      selectedTerrenoId.value = distribucion.value.terreno_id
      selectedConfigId.value = distribucion.value.configuracion_terreno_id ?? null
      await resolveConfigNombre(distribucion.value)
    }
    emitSummary()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

async function attach(): Promise<void> {
  if (!props.eventId || !selectedTerrenoId.value || !selectedConfigId.value || !canManage.value) return
  attaching.value = true
  try {
    distribucion.value = await terrenosService.attachTerreno(
      props.eventId,
      selectedTerrenoId.value,
      selectedConfigId.value,
    )
    configNombreResumen.value =
      configs.value.find((c) => c.id === selectedConfigId.value)?.nombre ?? null
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('terrenos.attachSuccess'),
      life: 2500,
    })
    emitSummary()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    attaching.value = false
  }
}

async function detach(): Promise<void> {
  if (!props.eventId || !canManage.value) return
  attaching.value = true
  try {
    await terrenosService.detachTerreno(props.eventId)
    distribucion.value = null
    selectedTerrenoId.value = null
    selectedConfigId.value = null
    configs.value = []
    configNombreResumen.value = null
    detachVisible.value = false
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('terrenos.detachSuccess'),
      life: 2500,
    })
    emitSummary()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    attaching.value = false
  }
}

function openDistribucion(): void {
  if (!props.eventId) return
  void router.push({ name: 'events.distribucion', params: { id: props.eventId } })
}

function estadoSeverity(estado: string): 'success' | 'info' | 'warn' | 'danger' | 'secondary' {
  if (estado === 'disponible') return 'success'
  if (estado === 'asignado') return 'info'
  if (estado === 'reservado') return 'warn'
  if (estado === 'no_disponible') return 'danger'
  return 'secondary'
}

watch(
  () => props.eventId,
  () => {
    void loadDistribucion()
  },
)

watch(
  () => props.lugarId,
  () => {
    void loadTerrenos()
  },
)

watch(selectedTerrenoId, (id) => {
  if (distribucion.value) return
  void loadConfigs(id)
})

onMounted(async () => {
  await loadTerrenos()
  await loadDistribucion()
})
</script>

<template>
  <div class="event-terreno-step">
    <div class="step-section-title">
      <i class="pi pi-map" />
      <h2>{{ t('events.wizard.stepTerreno') }}</h2>
    </div>
    <p class="step-lead">{{ t('events.wizard.terrenoLead') }}</p>

    <Message v-if="!lugarId" severity="info" :closable="false">
      {{ t('events.wizard.pickPlaceFirst') }}
    </Message>
    <Message v-else-if="!eventId" severity="info" :closable="false">
      {{ t('events.wizard.terrenoNeedDraft') }}
    </Message>

    <Message v-else-if="!canView" severity="warn" :closable="false">
      {{ t('events.wizard.terrenoNoPermission') }}
    </Message>

    <PageLoader v-else-if="loading" />

    <template v-else>
      <div v-if="!distribucion" class="attach-panel">
        <label class="field">
          <span>{{ t('terrenos.selectTerreno') }}</span>
          <Select
            v-model="selectedTerrenoId"
            :options="terrenos"
            option-label="nombre"
            option-value="id"
            filter
            :placeholder="t('terrenos.selectTerreno')"
            class="w-full"
            :disabled="!canManage"
          />
        </label>
        <label class="field">
          <span>{{ t('terrenos.elegirConfig') }}</span>
          <Select
            v-model="selectedConfigId"
            :options="configs"
            option-label="nombre"
            option-value="id"
            filter
            :placeholder="t('terrenos.selectConfig')"
            class="w-full"
            :disabled="!canManage || !selectedTerrenoId || !configs.length"
          />
        </label>
        <p v-if="selectedTerrenoId && !configs.length" class="pj-muted">{{ t('terrenos.noConfigs') }}</p>
        <div class="attach-actions">
          <Button
            v-if="canManage"
            :label="t('terrenos.attach')"
            icon="pi pi-link"
            :loading="attaching"
            :disabled="!selectedTerrenoId || !selectedConfigId"
            @click="attach"
          />
          <Button
            v-if="canCatalog('terrenos', 'view')"
            :label="t('events.wizard.terrenoManageTerrenos')"
            icon="pi pi-external-link"
            text
            @click="router.push({ name: 'lugares' })"
          />
        </div>
        <p v-if="!terrenos.length" class="pj-muted">{{ t('events.wizard.terrenoEmptyCatalog') }}</p>
      </div>

      <template v-else>
        <div class="summary-cards">
          <article class="stat-card">
            <span class="stat-card__label">{{ t('terrenos.terreno') }}</span>
            <strong>{{ distribucion.terreno?.nombre || '—' }}</strong>
          </article>
          <article class="stat-card">
            <span class="stat-card__label">{{ t('terrenos.configuracion') }}</span>
            <strong>{{ configNombreResumen || '—' }}</strong>
          </article>
          <article class="stat-card">
            <span class="stat-card__label">{{ t('events.wizard.terrenoLotesTotal') }}</span>
            <strong>{{ allLotes.length }}</strong>
          </article>
          <article class="stat-card accent">
            <span class="stat-card__label">{{ t('events.wizard.terrenoCapacidadEstimada') }}</span>
            <strong>{{ capacidadEstimada }}</strong>
            <small>{{ t('events.wizard.terrenoAcampantes') }}</small>
          </article>
          <article class="stat-card">
            <span class="stat-card__label">{{ t('events.wizard.terrenoLotesDisponibles') }}</span>
            <strong>{{ lotesDisponiblesCount }}</strong>
            <small>{{ capacidadDisponible }} {{ t('events.wizard.terrenoAcampantes') }}</small>
          </article>
        </div>

        <Message severity="info" :closable="false" class="hint">
          {{ t('events.wizard.terrenoCapacidadHint', { n: capacidadEstimada }) }}
        </Message>

        <div class="toolbar">
          <Button
            v-if="canManage && capacidadEstimada > 0"
            :label="t('events.wizard.terrenoApplyCupo')"
            icon="pi pi-users"
            outlined
            size="small"
            @click="emit('applyCupo', capacidadEstimada)"
          />
          <Button
            :label="t('terrenos.distribucion')"
            icon="pi pi-map"
            size="small"
            @click="openDistribucion"
          />
          <Button
            v-if="canManage"
            :label="t('terrenos.detach')"
            severity="danger"
            text
            size="small"
            @click="detachVisible = true"
          />
        </div>

        <div class="lotes-table">
          <h3>{{ t('events.wizard.terrenoLotesList') }}</h3>
          <div v-if="!allLotes.length" class="pj-muted">{{ t('events.wizard.terrenoNoLotes') }}</div>
          <div v-else class="lotes-grid">
            <article v-for="row in allLotes" :key="row.lote.id" class="lote-row">
              <div>
                <strong>{{ row.lote.codigo }}</strong>
                <span class="pj-muted"> · {{ row.zonaNombre }}</span>
              </div>
              <Tag :value="row.lote.estado" :severity="estadoSeverity(row.lote.estado)" />
              <span>
                {{ row.lote.capacidad_maxima ?? row.lote.capacidad_calculada ?? '—' }}
                {{ t('events.wizard.terrenoAcampantes') }}
              </span>
            </article>
          </div>
        </div>
      </template>
    </template>

    <Dialog v-model:visible="detachVisible" modal :header="t('common.confirm')" :style="{ width: 'min(400px, 95vw)' }">
      <p>{{ t('terrenos.detachConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="detachVisible = false" />
        <Button :label="t('terrenos.detach')" severity="danger" :loading="attaching" @click="detach" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.event-terreno-step {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.step-section-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.step-section-title h2 {
  margin: 0;
  font-size: 1.25rem;
}

.step-lead {
  margin: 0;
  color: var(--pj-muted, #667);
}

.attach-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 480px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.attach-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
}

.stat-card {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.85rem 1rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
  background: var(--pj-surface, #fff);
}

.stat-card.accent {
  border-color: color-mix(in srgb, var(--pj-accent, #1565c0) 40%, transparent);
  background: color-mix(in srgb, var(--pj-accent, #1565c0) 8%, transparent);
}

.stat-card__label {
  font-size: 0.8rem;
  color: var(--pj-muted, #667);
}

.stat-card strong {
  font-size: 1.35rem;
}

.stat-card small {
  font-size: 0.75rem;
  color: var(--pj-muted, #667);
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.lotes-table h3 {
  margin: 0 0 0.5rem;
  font-size: 1rem;
}

.lotes-grid {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  max-height: 320px;
  overflow: auto;
}

.lote-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.55rem 0.7rem;
  border-radius: 8px;
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 75%, transparent);
  font-size: 0.9rem;
}

.hint {
  margin: 0;
}

.pj-muted {
  color: var(--pj-muted, #667);
}

.w-full {
  width: 100%;
}

@media (max-width: 640px) {
  .lote-row {
    grid-template-columns: 1fr auto;
  }
}
</style>
