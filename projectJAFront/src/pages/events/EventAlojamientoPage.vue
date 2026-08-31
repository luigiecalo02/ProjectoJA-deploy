<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import BedGridSelector from '@/components/cabanas/BedGridSelector.vue'
import { cabanasService } from '@/services/cabanasService'
import { getApiErrorMessage } from '@/services/api'
import { useRealtimeChannel } from '@/composables/useRealtimeChannel'
import Select from 'primevue/select'
import type {
  AlojamientoCandidato,
  AlojamientoEvento,
  CabanaBed,
  CabanaFloor,
  CabanaRoom,
  EventoCabana,
} from '@/modules/cabanas/types'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const toast = useToast()
const eventId = computed(() => Number(route.params.id))
const data = ref<AlojamientoEvento | null>(null)
const loading = ref(true)
const saving = ref(false)
const selectedBedId = ref<number | null>(null)
const selectedInfo = ref<{
  bed: CabanaBed
  room: CabanaRoom
  floor: CabanaFloor
  eventCabana: EventoCabana
} | null>(null)
const confirmVisible = ref(false)
const closeVisible = ref(false)
const candidates = ref<AlojamientoCandidato[]>([])
const selectedPersonId = ref<number | null>(null)
const assignedBedId = computed(() =>
  data.value?.asignacion?.cama?.id
  ?? data.value?.asignacion?.evento_cabana_cama_id
  ?? data.value?.asignacion?.evento_cama_id
  ?? null,
)
const cupo = computed(() => data.value?.cupo ?? null)
const hasOpenCupo = computed(() => cupo.value?.estado === 'abierto')
const selectedPerson = computed(() => candidates.value.find((item) => item.id === selectedPersonId.value) ?? null)
const isChange = computed(() => !hasOpenCupo.value && !!data.value?.asignacion && selectedBedId.value !== assignedBedId.value)
const realtimeChannel = computed(() => Number.isFinite(eventId.value) ? `events.${eventId.value}.cabanas` : null)
const occupancyLabel = computed(() => `${data.value?.ocupadas ?? data.value?.ocupacion ?? 0}/${data.value?.capacidad ?? 0}`)
const eligibilityMessage = computed(() => data.value?.elegibilidad_motivo || t('alojamiento.notEligible'))
const selectedCabanaName = computed(() =>
  selectedInfo.value
    ? (selectedInfo.value.eventCabana.nombre || selectedInfo.value.eventCabana.cabana?.nombre || '')
    : '',
)
const canPickBed = computed(() => {
  if (saving.value) return false
  if (hasOpenCupo.value) return (cupo.value?.restantes ?? 0) > 0
  return !!data.value?.puede_seleccionar
})

async function load(options: { quiet?: boolean } = {}): Promise<void> {
  if (!options.quiet) loading.value = true
  try {
    data.value = await cabanasService.getAlojamiento(eventId.value)
    selectedBedId.value = assignedBedId.value
    if (!options.quiet) selectedInfo.value = null
    if (data.value.cupo?.estado === 'abierto') {
      candidates.value = await cabanasService.getAlojamientoCandidatos(eventId.value)
      if (selectedPersonId.value && !candidates.value.some((item) => item.id === selectedPersonId.value)) {
        selectedPersonId.value = null
      }
    } else {
      candidates.value = []
    }
  } catch (error) {
    if (!options.quiet) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    }
  } finally {
    if (!options.quiet) loading.value = false
  }
}

function onSelect(
  bed: CabanaBed,
  room: CabanaRoom,
  floor: CabanaFloor,
  eventCabana: EventoCabana,
): void {
  if (hasOpenCupo.value && !selectedPersonId.value) {
    toast.add({ severity: 'warn', summary: t('common.error'), detail: t('alojamiento.quotaNeedPerson'), life: 3500 })
    selectedBedId.value = assignedBedId.value
    return
  }
  selectedInfo.value = { bed, room, floor, eventCabana }
  confirmVisible.value = true
}

async function confirmAssignment(): Promise<void> {
  if (!selectedBedId.value) return
  saving.value = true
  try {
    if (hasOpenCupo.value && cupo.value && selectedPersonId.value) {
      await cabanasService.assignFromCupo(eventId.value, cupo.value.id, {
        inscripcion_persona_id: selectedPersonId.value,
        evento_cabana_cama_id: selectedBedId.value,
      })
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('alojamiento.quotaAssignSuccess'), life: 3000 })
    } else {
      await cabanasService.autoAssign(selectedBedId.value)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: isChange.value ? t('alojamiento.changeSuccess') : t('alojamiento.assignSuccess'),
        life: 3000,
      })
    }
    confirmVisible.value = false
    await load({ quiet: true })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4500 })
    await load({ quiet: true })
  } finally {
    saving.value = false
  }
}

async function closeCupo(): Promise<void> {
  if (!cupo.value) return
  saving.value = true
  try {
    await cabanasService.closeAlojamientoCupo(eventId.value, cupo.value.id)
    closeVisible.value = false
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('alojamiento.quotaCloseSuccess'), life: 3000 })
    await load({ quiet: true })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4500 })
  } finally {
    saving.value = false
  }
}

useRealtimeChannel(
  realtimeChannel,
  {
    '.cabanas.occupancy.changed': () => void load({ quiet: true }),
  },
  computed(() => !!data.value),
)

onMounted(() => void load())
</script>

<template>
  <section class="pj-page alojamiento-page">
    <header class="pj-page__header">
      <div class="heading">
        <Button icon="pi pi-arrow-left" text rounded @click="router.push({ name: 'events' })" />
        <div>
          <h1 class="pj-display">{{ data?.evento.name || t('alojamiento.title') }}</h1>
          <p>{{ t('alojamiento.subtitle') }}</p>
        </div>
      </div>
      <span v-if="data" class="capacity-pill">
        <i class="pi pi-users" />
        {{ occupancyLabel }}
      </span>
    </header>

    <PageLoader v-if="loading" />
    <template v-else-if="data">
      <Message v-if="hasOpenCupo && cupo" severity="info" :closable="false">
        {{ t('alojamiento.quotaSummary', { total: cupo.cupos, used: cupo.usados, remaining: cupo.restantes }) }}
        <span class="change-hint">{{ t('alojamiento.quotaCloseHint') }}</span>
      </Message>
      <Message v-else-if="cupo?.estado === 'cerrado'" severity="secondary" :closable="false">
        {{ t('alojamiento.quotaClosed') }}
      </Message>
      <Message v-if="data.asignacion && !hasOpenCupo" severity="success" :closable="false">
        <strong>{{ t('alojamiento.currentAssignment') }}:</strong>
        {{ data.asignacion.cabana?.nombre }} · {{ data.asignacion.piso?.nombre }} ·
        {{ data.asignacion.cuarto?.nombre }} · {{ data.asignacion.cama?.codigo }}
        <span class="change-hint">{{ t('alojamiento.changeHint') }}</span>
      </Message>
      <Message v-if="!data.puede_seleccionar && !hasOpenCupo" severity="warn" :closable="false">
        {{ eligibilityMessage }}
      </Message>
      <div v-if="hasOpenCupo" class="pj-panel quota-tools">
        <label>
          {{ t('alojamiento.quotaPickPerson') }}
          <Select
            v-model="selectedPersonId"
            :options="candidates"
            option-label="nombre"
            option-value="id"
            filter
            :placeholder="t('alojamiento.quotaPickPersonHint')"
            :disabled="saving || (cupo?.restantes ?? 0) <= 0"
          />
        </label>
        <Button
          :label="t('alojamiento.quotaClose')"
          icon="pi pi-lock"
          severity="warning"
          outlined
          :disabled="saving"
          @click="closeVisible = true"
        />
        <div v-if="cupo?.asignaciones?.length" class="quota-people">
          <strong>{{ t('alojamiento.quotaAssignedPeople') }}</strong>
          <ul>
            <li v-for="item in cupo.asignaciones" :key="item.id">
              <strong>{{ item.nombre }}</strong>
              <span>{{ item.cama?.codigo }}</span>
            </li>
          </ul>
        </div>
      </div>
      <div v-if="data.cabanas.length" class="pj-panel selector-panel">
        <BedGridSelector
          v-model="selectedBedId"
          :cabanas="data.cabanas"
          :assigned-bed-id="assignedBedId"
          :disabled="!canPickBed"
          @select="onSelect"
        />
      </div>
      <div v-else class="empty-state">
        <i class="pi pi-building" />
        <h2>{{ t('alojamiento.emptyTitle') }}</h2>
        <p>{{ t('alojamiento.emptyHint') }}</p>
      </div>
    </template>

    <Dialog
      v-model:visible="confirmVisible"
      modal
      :header="isChange ? t('alojamiento.confirmChange') : t('alojamiento.confirmTitle')"
      :style="{ width: 'min(440px, 95vw)' }"
      :closable="!saving"
    >
      <div v-if="selectedInfo" class="selection-summary">
        <i class="pi pi-building" />
        <div>
          <strong>{{ selectedCabanaName }}</strong>
          <span>{{ selectedInfo.floor.nombre }} · {{ selectedInfo.room.nombre }}</span>
          <span>{{ t('alojamiento.bed') }} {{ selectedInfo.bed.codigo }}</span>
        </div>
      </div>
      <p>
        <template v-if="hasOpenCupo && selectedPerson">
          {{ selectedPerson.nombre }} · {{ t('alojamiento.bed') }} {{ selectedInfo?.bed.codigo }}
        </template>
        <template v-else>
          {{ isChange ? t('alojamiento.confirmChangeText') : t('alojamiento.confirmText') }}
        </template>
      </p>
      <template #footer>
        <Button :label="t('common.cancel')" text :disabled="saving" @click="confirmVisible = false; selectedBedId = assignedBedId" />
        <Button
          :label="hasOpenCupo ? t('alojamiento.confirm') : (isChange ? t('alojamiento.change') : t('alojamiento.confirm'))"
          icon="pi pi-check"
          :loading="saving"
          @click="confirmAssignment"
        />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="closeVisible"
      modal
      :header="t('alojamiento.quotaCloseConfirm')"
      :style="{ width: 'min(440px, 95vw)' }"
      :closable="!saving"
    >
      <p>{{ t('alojamiento.quotaCloseText', { count: cupo?.restantes ?? 0 }) }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text :disabled="saving" @click="closeVisible = false" />
        <Button
          :label="t('alojamiento.quotaClose')"
          icon="pi pi-lock"
          severity="warning"
          :loading="saving"
          @click="closeCupo"
        />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.alojamiento-page { gap: 1rem; }
.heading { display: flex; align-items: center; gap: .45rem; }
.heading h1, .heading p { margin: 0; }
.capacity-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .75rem; border-radius: 999px; background: var(--pj-primary-soft); color: var(--pj-navy); font-weight: 700; }
.selector-panel { padding: 1rem; min-width: 0; }
.quota-tools { display: grid; gap: .75rem; padding: 1rem; }
.quota-tools label { display: grid; gap: .35rem; font-weight: 700; }
.quota-people { display: grid; gap: .35rem; }
.quota-people ul { display: grid; gap: .35rem; margin: 0; padding: 0; list-style: none; }
.quota-people li { display: flex; justify-content: space-between; gap: .75rem; padding: .35rem 0; border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent); }
.change-hint { display: block; margin-top: .25rem; font-size: .82rem; }
.selection-summary { display: flex; align-items: center; gap: .75rem; padding: .8rem; border-radius: 10px; background: var(--pj-primary-soft); }
.selection-summary > i { font-size: 1.5rem; color: var(--p-primary-color); }
.selection-summary strong, .selection-summary span { display: block; }
.selection-summary span { margin-top: .15rem; color: var(--pj-text-muted); }
.empty-state { display: grid; justify-items: center; padding: 3rem 1rem; text-align: center; color: var(--pj-text-muted); }
.empty-state > i { font-size: 3rem; }
.empty-state h2 { margin-bottom: 0; color: var(--pj-text); }
@media (max-width: 600px) {
  .capacity-pill { width: 100%; justify-content: center; }
}
</style>
