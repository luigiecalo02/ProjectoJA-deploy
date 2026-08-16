<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import { clubsService } from '@/services/clubsService'
import type { Club } from '@/modules/clubs/types'
import type { EventoLote } from '@/modules/terrenos/types'

const props = defineProps<{
  visible: boolean
  lote: EventoLote | null
  canOverride: boolean
  selfAssign?: boolean
  saving?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  submit: [payload: { club_id?: number; cantidad_personas: number; observaciones?: string }]
}>()

const { t } = useI18n()
const clubs = ref<Club[]>([])
const clubId = ref<number | null>(null)
const cantidad = ref(0)
const observaciones = ref('')

const dialogVisible = computed({
  get: () => props.visible,
  set: (v) => emit('update:visible', v),
})

const capacidad = computed(() => props.lote?.capacidad_maxima ?? null)
const overCapacity = computed(() => capacidad.value !== null && cantidad.value > capacidad.value)

watch(
  () => props.visible,
  (v) => {
    if (v && props.lote) {
      clubId.value = props.selfAssign ? null : props.lote.asignacion?.club_id ?? null
      cantidad.value = props.lote.asignacion?.cantidad_personas ?? props.lote.capacidad_maxima ?? 0
      observaciones.value = props.lote.asignacion?.observaciones ?? ''
    }
  },
)

onMounted(async () => {
  if (props.selfAssign) return
  try {
    const result = await clubsService.list({ per_page: 200, is_active: true })
    clubs.value = result.items
  } catch {
    clubs.value = []
  }
})

function submit(): void {
  if (!props.selfAssign && !clubId.value) return
  emit('submit', {
    club_id: clubId.value ?? undefined,
    cantidad_personas: cantidad.value,
    observaciones: observaciones.value || undefined,
  })
}
</script>

<template>
  <Dialog
    v-model:visible="dialogVisible"
    modal
    :header="selfAssign ? t('terrenos.elegirLote') : t('terrenos.asignarClub')"
    class="asignar-dialog"
    :style="{ width: 'min(420px, 95vw)' }"
  >
    <div v-if="lote" class="form-stack">
      <p>
        <strong>{{ lote.codigo }}</strong>
        <span v-if="capacidad !== null"> · {{ t('terrenos.capacidadMaxima') }}: {{ capacidad }}</span>
      </p>
      <p v-if="selfAssign" class="self-assign-hint">
        {{ t('terrenos.selfAssignHint') }}
      </p>
      <label v-else>
        <span>{{ t('terrenos.club') }}</span>
        <Select
          v-model="clubId"
          :options="clubs"
          option-label="nombre"
          option-value="id"
          filter
          :placeholder="t('terrenos.selectClub')"
        />
      </label>
      <label v-if="!selfAssign">
        <span>{{ t('terrenos.cantidadPersonas') }}</span>
        <InputNumber v-model="cantidad" :min="0" />
      </label>
      <small v-if="overCapacity && !canOverride" class="warn">{{ t('terrenos.overCapacity') }}</small>
      <small v-else-if="overCapacity && canOverride" class="warn">{{ t('terrenos.overCapacityOverride') }}</small>
      <label>
        <span>{{ t('terrenos.observaciones') }}</span>
        <Textarea v-model="observaciones" rows="3" />
      </label>
    </div>
    <template #footer>
      <Button :label="t('common.cancel')" text @click="dialogVisible = false" />
      <Button
        :label="selfAssign ? t('terrenos.confirmarLote') : t('common.save')"
        icon="pi pi-check"
        :loading="saving"
        :disabled="(!selfAssign && !clubId) || (overCapacity && !canOverride)"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.form-stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.form-stack label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.warn {
  color: #c62828;
}

.self-assign-hint {
  margin: 0;
  padding: 0.65rem;
  border-radius: 8px;
  background: color-mix(in srgb, var(--p-primary-color) 8%, transparent);
  color: var(--pj-text-muted);
  font-size: 0.85rem;
}
</style>
