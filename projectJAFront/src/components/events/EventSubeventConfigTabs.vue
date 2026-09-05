<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import ToggleSwitch from 'primevue/toggleswitch'
import Checkbox from 'primevue/checkbox'
import EventMaterialsPanel from '@/components/events/EventMaterialsPanel.vue'
import { dateOnly } from '@/modules/events/dateUtils'
import type { CriterioEvaluacion, EventoArchivoMaterial } from '@/modules/events/types'
import { iconBoxStyle } from '@/utils/iconVisual'

export type SubeventOptionsTab =
  | 'calificaciones'
  | 'control'
  | 'evidencias'
  | 'jueces'
  | 'hijos'
  | 'recursos'

export type SubeventOpts = {
  manejaPuntaje: boolean
  puntajeDesdeHijos: boolean
  configCalificacion: boolean
  controlParticipantes: boolean
  esConjunto: boolean
  manejaFechaFin: boolean
  manejaPenalizaciones: boolean
  tieneValor: boolean
  requiereEvidencia: boolean
  esEnSitio: boolean
  tieneSubeventos: boolean
}

export type SubeventEditorForm = {
  puntaje_maximo: number | null
  puntaje_por_participar: boolean
  ends_at: Date | null
  requiere_puesto_entrega: boolean
  requiere_tiempo_entrega: boolean
  resultado_esperado: number | null
  participantes_min: number | null
  participantes_max: number | null
  permite_inscribir_no_participantes: boolean
  participantes_genero: 'mixto' | 'M' | 'F' | 'cualquiera'
  participantes_min_m: number | null
  participantes_max_m: number | null
  participantes_min_f: number | null
  participantes_max_f: number | null
  nivel_conjunto: 'club' | 'iglesia' | 'distrito' | 'asociacion' | null
  puntos_penalizacion: number | null
  reglas_penalizacion: string
  precio: number | null
  tipos_evidencia: Array<'link' | 'pdf' | 'imagen' | 'audio' | 'video'>
  juez_ids: number[]
  supervisor_ids: number[]
}

const props = withDefaults(
  defineProps<{
    form: SubeventEditorForm
    opts: SubeventOpts
    assignedCriterioIds: number[]
    criterioPoints: Record<number, number | null>
    criterioOptions: Array<CriterioEvaluacion & { label: string }>
    juezOptions: Array<{ id: number; label: string }>
    supervisorOptions: Array<{ id: number; label: string }>
    participantesGeneroOptions: Array<{ label: string; value: string }>
    nivelConjuntoOptions: Array<{ label: string; value: string }>
    materiales: EventoArchivoMaterial[]
    eventId: number | null
    hideChildren?: boolean
    showScoreFromChildren?: boolean
    childrenScoreSum?: number
    loadingChildrenScore?: boolean
    minDate?: Date
    maxDate?: Date
  }>(),
  {
    hideChildren: false,
    showScoreFromChildren: true,
    childrenScoreSum: 0,
    loadingChildrenScore: false,
  },
)

const emit = defineEmits<{
  'update:assignedCriterioIds': [value: number[]]
  queued: [files: File[]]
  'queued-youtube': [payload: { url: string; titulo?: string }]
  uploaded: [item: EventoArchivoMaterial]
  removed: [id: number]
}>()

const { t } = useI18n()
const optionsTab = ref<SubeventOptionsTab>('calificaciones')

const evidenciaTipoOptions = computed(() => [
  { key: 'link' as const, label: t('events.wizard.subEvidenceLink') },
  { key: 'pdf' as const, label: t('events.wizard.subEvidencePdf') },
  { key: 'imagen' as const, label: t('events.wizard.subEvidenceImage') },
  { key: 'audio' as const, label: t('events.wizard.subEvidenceAudio') },
  { key: 'video' as const, label: t('events.wizard.subEvidenceVideo') },
])

const criteriosSum = computed(() =>
  props.assignedCriterioIds.reduce((sum, id) => sum + (Number(props.criterioPoints[id]) || 0), 0),
)

const criteriosSumOk = computed(() => {
  if (!props.assignedCriterioIds.length) return true
  if (props.form.puntaje_maximo == null) return false
  return Math.abs(criteriosSum.value - Number(props.form.puntaje_maximo)) < 0.01
})

const hasConfig = computed(() => ({
  calificaciones:
    props.opts.manejaPuntaje ||
    props.opts.puntajeDesdeHijos ||
    props.opts.configCalificacion ||
    props.opts.manejaPenalizaciones ||
    !props.opts.esEnSitio,
  control:
    props.opts.controlParticipantes ||
    props.opts.esConjunto ||
    props.opts.manejaFechaFin ||
    props.opts.tieneValor,
  evidencias: props.opts.requiereEvidencia,
  jueces: props.form.juez_ids.length > 0 || props.form.supervisor_ids.length > 0,
  hijos: props.opts.tieneSubeventos,
  recursos: props.materiales.length > 0,
}))

function syncCriterioPointsSelection(ids: number[]): void {
  emit('update:assignedCriterioIds', ids)
  for (const id of ids) {
    if (props.criterioPoints[id] == null) props.criterioPoints[id] = null
  }
  for (const key of Object.keys(props.criterioPoints)) {
    const id = Number(key)
    if (!ids.includes(id)) delete props.criterioPoints[id]
  }
}

function toggleEvidenciaTipo(tipo: 'link' | 'pdf' | 'imagen' | 'audio' | 'video'): void {
  const set = new Set(props.form.tipos_evidencia)
  if (set.has(tipo)) set.delete(tipo)
  else set.add(tipo)
  props.form.tipos_evidencia = [...set]
}

watch(
  () => props.opts.controlParticipantes,
  (on) => {
    if (on) props.form.permite_inscribir_no_participantes = true
  },
)

watch(
  () => props.hideChildren,
  (hidden) => {
    if (hidden && optionsTab.value === 'hijos') optionsTab.value = 'calificaciones'
  },
)

const pendingCount = reactive({ files: 0, youtube: 0 })
const recursosHasConfig = computed(
  () => hasConfig.value.recursos || pendingCount.files > 0 || pendingCount.youtube > 0,
)
</script>

<template>
  <div class="sub-options">
    <p class="sub-options__lead">{{ t('events.wizard.subOptionsLead') }}</p>

    <div class="sub-tabs sub-tabs--options">
      <button
        type="button"
        :class="{ 'is-active': optionsTab === 'calificaciones', 'has-config': hasConfig.calificaciones }"
        @click="optionsTab = 'calificaciones'"
      >
        {{ t('events.wizard.subTabCalificaciones') }}
      </button>
      <button
        type="button"
        :class="{ 'is-active': optionsTab === 'control', 'has-config': hasConfig.control }"
        @click="optionsTab = 'control'"
      >
        {{ t('events.wizard.subTabControl') }}
      </button>
      <button
        type="button"
        :class="{ 'is-active': optionsTab === 'evidencias', 'has-config': hasConfig.evidencias }"
        @click="optionsTab = 'evidencias'"
      >
        {{ t('events.wizard.subTabEvidencias') }}
      </button>
      <button
        type="button"
        :class="{ 'is-active': optionsTab === 'jueces', 'has-config': hasConfig.jueces }"
        @click="optionsTab = 'jueces'"
      >
        {{ t('events.wizard.subTabJueces') }}
      </button>
      <button
        v-if="!hideChildren"
        type="button"
        :class="{ 'is-active': optionsTab === 'hijos', 'has-config': hasConfig.hijos }"
        @click="optionsTab = 'hijos'"
      >
        {{ t('events.wizard.subTabChildren') }}
      </button>
      <button
        type="button"
        :class="{ 'is-active': optionsTab === 'recursos', 'has-config': recursosHasConfig }"
        @click="optionsTab = 'recursos'"
      >
        {{ t('events.wizard.subTabResources') }}
      </button>
    </div>

    <div v-show="optionsTab === 'calificaciones'" class="sub-options__pane">
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.esEnSitio" />
          <span>{{ t('events.esEnSitio') }}</span>
        </label>
        <small class="pj-muted">{{ t('events.esEnSitioSubHint') }}</small>
      </div>
      <div v-if="showScoreFromChildren" class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.puntajeDesdeHijos" />
          <span>{{ t('events.wizard.subOptScoreFromChildren') }}</span>
        </label>
        <div v-if="opts.puntajeDesdeHijos" class="sub-option__fields">
          <small class="pj-muted">{{ t('events.wizard.subOptScoreFromChildrenHint') }}</small>
          <div class="field">
            <label>{{ t('events.wizard.subColScore') }}</label>
            <InputNumber :model-value="childrenScoreSum" class="w-full" :min="0" disabled />
            <small class="pj-muted">
              {{
                loadingChildrenScore
                  ? t('common.loading')
                  : t('events.wizard.subScoreFromChildrenSum', { sum: childrenScoreSum })
              }}
            </small>
          </div>
        </div>
      </div>
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.manejaPuntaje" />
          <span>{{ t('events.wizard.subOptScore') }}</span>
        </label>
        <div v-if="opts.manejaPuntaje && !opts.puntajeDesdeHijos" class="sub-option__fields">
          <label class="sub-option__nested">
            <ToggleSwitch v-model="form.puntaje_por_participar" />
            <span>{{ t('events.wizard.subOptScoreByParticipation') }}</span>
          </label>
          <small class="pj-muted">{{ t('events.wizard.subOptScoreByParticipationHint') }}</small>
          <div class="field">
            <label>{{ t('events.wizard.subColScore') }}</label>
            <InputNumber v-model="form.puntaje_maximo" class="w-full" :min="0" />
          </div>
          <div v-if="!form.puntaje_por_participar" class="field">
            <label>{{ t('events.wizard.criteriaAssign') }}</label>
            <small class="pj-muted">{{ t('events.wizard.criteriaAssignHint') }}</small>
            <MultiSelect
              :model-value="assignedCriterioIds"
              :options="criterioOptions"
              option-label="label"
              option-value="id"
              display="chip"
              class="w-full"
              :placeholder="t('events.wizard.criteriaSelect')"
              @update:model-value="syncCriterioPointsSelection"
            >
              <template #option="{ option }">
                <span class="crit-opt">
                  <span class="crit-opt__icon" :style="iconBoxStyle(option.color)">
                    <i :class="option.icono || 'pi pi-list-check'" />
                  </span>
                  {{ option.label }}
                </span>
              </template>
            </MultiSelect>
            <div v-for="id in assignedCriterioIds" :key="id" class="field" style="margin-top: 0.45rem">
              <label>
                {{ criterioOptions.find((c) => c.id === id)?.nombre || id }} —
                {{ t('events.wizard.criteriaPoints') }}
              </label>
              <InputNumber v-model="criterioPoints[id]" class="w-full" :min="0" />
            </div>
            <small
              v-if="assignedCriterioIds.length"
              :class="criteriosSumOk ? 'pj-muted' : 'criteria-sum--bad'"
            >
              {{ t('events.wizard.criteriaSum', { sum: criteriosSum, max: form.puntaje_maximo ?? 0 }) }}
            </small>
          </div>
        </div>
      </div>
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.configCalificacion" />
          <span>{{ t('events.wizard.subOptGrading') }}</span>
        </label>
        <div v-if="opts.configCalificacion" class="sub-option__fields">
          <label class="sub-option__toggle">
            <Checkbox v-model="form.requiere_puesto_entrega" :binary="true" />
            <span>{{ t('events.wizard.subPuestoEntrega') }}</span>
          </label>
          <label class="sub-option__toggle">
            <Checkbox v-model="form.requiere_tiempo_entrega" :binary="true" />
            <span>{{ t('events.wizard.subTiempoEntrega') }}</span>
          </label>
          <div class="field">
            <label>{{ t('events.wizard.subResultadoEsperado') }}</label>
            <InputNumber
              v-model="form.resultado_esperado"
              class="w-full"
              :min="1"
              :placeholder="t('events.wizard.subResultadoEsperadoHint')"
            />
            <small class="pj-muted">{{ t('events.wizard.subResultadoEsperadoHint') }}</small>
          </div>
        </div>
      </div>
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.manejaPenalizaciones" />
          <span>{{ t('events.wizard.subOptPenalties') }}</span>
        </label>
        <div v-if="opts.manejaPenalizaciones" class="sub-option__fields">
          <small class="pj-muted">{{ t('events.wizard.subPenaltyLead') }}</small>
          <div class="field">
            <label>{{ t('events.wizard.subPenaltyPoints') }}</label>
            <InputNumber v-model="form.puntos_penalizacion" class="w-full" :min="0" suffix=" pts" />
          </div>
          <div class="field">
            <label>{{ t('events.wizard.subPenaltyRules') }}</label>
            <Textarea
              v-model="form.reglas_penalizacion"
              rows="3"
              class="w-full"
              auto-resize
              :placeholder="t('events.wizard.subPenaltyRulesPlaceholder')"
            />
          </div>
        </div>
      </div>
    </div>

    <div v-show="optionsTab === 'control'" class="sub-options__pane">
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.controlParticipantes" />
          <span>{{ t('events.wizard.subOptParticipants') }}</span>
        </label>
        <div v-if="opts.controlParticipantes" class="sub-option__fields field-grid">
          <div class="field field--wide">
            <label class="sub-option__toggle">
              <ToggleSwitch v-model="form.permite_inscribir_no_participantes" />
              <span>{{ t('events.wizard.subOptEnrollNonParticipants') }}</span>
            </label>
            <small class="pj-muted">{{ t('events.wizard.subOptEnrollNonParticipantsHint') }}</small>
          </div>
          <div v-if="form.permite_inscribir_no_participantes" class="field field--wide">
            <label>{{ t('events.wizard.subParticipantsGender') }}</label>
            <Select
              v-model="form.participantes_genero"
              :options="participantesGeneroOptions"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
          <template v-if="form.permite_inscribir_no_participantes && form.participantes_genero !== 'mixto'">
            <div class="field">
              <label>{{ t('events.wizard.subParticipantsMin') }}</label>
              <InputNumber v-model="form.participantes_min" class="w-full" :min="1" />
            </div>
            <div class="field">
              <label>{{ t('events.wizard.subParticipantsMax') }}</label>
              <InputNumber v-model="form.participantes_max" class="w-full" :min="1" />
              <small class="pj-muted">{{ t('events.wizard.subParticipantsMaxHint') }}</small>
            </div>
          </template>
          <template v-else-if="form.permite_inscribir_no_participantes">
            <div class="field">
              <label>{{ t('events.wizard.subParticipantsMinM') }}</label>
              <InputNumber v-model="form.participantes_min_m" class="w-full" :min="0" />
            </div>
            <div class="field">
              <label>{{ t('events.wizard.subParticipantsMaxM') }}</label>
              <InputNumber v-model="form.participantes_max_m" class="w-full" :min="0" />
            </div>
            <div class="field">
              <label>{{ t('events.wizard.subParticipantsMinF') }}</label>
              <InputNumber v-model="form.participantes_min_f" class="w-full" :min="0" />
            </div>
            <div class="field">
              <label>{{ t('events.wizard.subParticipantsMaxF') }}</label>
              <InputNumber v-model="form.participantes_max_f" class="w-full" :min="0" />
            </div>
            <div class="field field--wide">
              <small class="pj-muted">{{ t('events.wizard.subParticipantsMaxHint') }}</small>
            </div>
          </template>
        </div>
      </div>
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.esConjunto" />
          <span>{{ t('events.wizard.subOptJoint') }}</span>
        </label>
        <div v-if="opts.esConjunto" class="sub-option__fields">
          <div class="field">
            <label>{{ t('events.wizard.subJointLevel') }}</label>
            <Select
              v-model="form.nivel_conjunto"
              :options="nivelConjuntoOptions"
              option-label="label"
              option-value="value"
              class="w-full"
              :placeholder="t('events.wizard.subJointLevelPlaceholder')"
            />
            <small class="pj-muted">{{ t('events.wizard.subJointLevelHint') }}</small>
          </div>
        </div>
      </div>
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.manejaFechaFin" />
          <span>{{ t('events.wizard.subOptEndDate') }}</span>
        </label>
        <div v-if="opts.manejaFechaFin" class="sub-option__fields">
          <div class="field">
            <label>{{ t('events.endsAt') }}</label>
            <DatePicker
              :model-value="form.ends_at"
              date-format="dd/mm/yy"
              class="w-full"
              :min-date="opts.esEnSitio ? minDate : undefined"
              :max-date="opts.esEnSitio ? maxDate : undefined"
              @update:model-value="(v) => (form.ends_at = dateOnly(Array.isArray(v) ? v[0] : v))"
            />
          </div>
        </div>
      </div>
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.tieneValor" />
          <span>{{ t('events.wizard.subOptValue') }}</span>
        </label>
        <div v-if="opts.tieneValor" class="sub-option__fields">
          <small class="pj-muted">{{ t('events.wizard.subValueLead') }}</small>
          <div class="field">
            <label>{{ t('events.wizard.subValueAmount') }}</label>
            <InputNumber
              v-model="form.precio"
              class="w-full"
              mode="currency"
              currency="USD"
              locale="en-US"
              :min="0"
            />
          </div>
        </div>
      </div>
    </div>

    <div v-show="optionsTab === 'evidencias'" class="sub-options__pane">
      <div class="sub-option">
        <label class="sub-option__toggle">
          <ToggleSwitch v-model="opts.requiereEvidencia" />
          <span>{{ t('events.wizard.subOptEvidence') }}</span>
        </label>
        <div v-if="opts.requiereEvidencia" class="sub-option__fields">
          <small class="pj-muted">{{ t('events.wizard.subEvidenceLead') }}</small>
          <div class="evidence-types">
            <button
              v-for="opt in evidenciaTipoOptions"
              :key="opt.key"
              type="button"
              class="evidence-type"
              :class="{ 'is-active': form.tipos_evidencia.includes(opt.key) }"
              @click="toggleEvidenciaTipo(opt.key)"
            >
              {{ opt.label }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="!hideChildren" v-show="optionsTab === 'hijos'" class="sub-options__pane">
      <slot name="hijos" />
    </div>

    <div v-show="optionsTab === 'jueces'" class="sub-options__pane">
      <div class="field">
        <label>{{ t('events.wizard.subJudge') }}</label>
        <MultiSelect
          v-model="form.juez_ids"
          :options="juezOptions"
          option-label="label"
          option-value="id"
          class="w-full"
          display="chip"
          filter
          :placeholder="t('events.wizard.subJudgePlaceholder')"
          :empty-message="t('events.wizard.subJudgeNoneAvailable')"
        />
        <small class="pj-muted">{{ t('events.wizard.subJudgeHint') }}</small>
      </div>
      <div class="field">
        <label>{{ t('events.wizard.subSupervisor') }}</label>
        <MultiSelect
          v-model="form.supervisor_ids"
          :options="supervisorOptions"
          option-label="label"
          option-value="id"
          class="w-full"
          display="chip"
          filter
          :placeholder="t('events.wizard.subSupervisorPlaceholder')"
          :empty-message="t('events.wizard.subSupervisorNoneAvailable')"
        />
        <small class="pj-muted">{{ t('events.wizard.subSupervisorHint') }}</small>
      </div>
    </div>

    <div v-show="optionsTab === 'recursos'" class="sub-options__pane">
      <EventMaterialsPanel
        :event-id="eventId"
        :files="materiales"
        @queued="pendingCount.files += $event.length; emit('queued', $event)"
        @queued-youtube="pendingCount.youtube += 1; emit('queued-youtube', $event)"
        @uploaded="emit('uploaded', $event)"
        @removed="emit('removed', $event)"
      />
    </div>
  </div>
</template>

<style scoped>
.sub-options {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.sub-options__lead {
  margin: 0;
  font-size: 0.82rem;
  color: var(--pj-text-muted);
}

.sub-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.sub-tabs button {
  border: 0;
  background: color-mix(in srgb, var(--pj-navy) 6%, #fff);
  border-radius: 999px;
  padding: 0.35rem 0.7rem;
  font: inherit;
  font-size: 0.78rem;
  font-weight: 650;
  color: var(--pj-text-muted);
  cursor: pointer;
}

.sub-tabs button.is-active {
  background: color-mix(in srgb, #2563eb 14%, transparent);
  color: #1d4ed8;
}

.sub-tabs--options button.has-config::after {
  content: '';
  display: inline-block;
  width: 0.4rem;
  height: 0.4rem;
  margin-left: 0.35rem;
  border-radius: 999px;
  background: #2563eb;
  vertical-align: middle;
}

.sub-options__pane {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.sub-option {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.65rem 0.75rem;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.sub-option__toggle,
.sub-option__nested {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  cursor: pointer;
  font-size: 0.88rem;
  font-weight: 600;
}

.sub-option__fields {
  padding-top: 0.15rem;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.criteria-sum--bad {
  color: #b91c1c;
  font-weight: 600;
}

.crit-opt {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.crit-opt__icon {
  width: 1.45rem;
  height: 1.45rem;
  display: grid;
  place-items: center;
  border-radius: 6px;
  border: 1px solid transparent;
  flex-shrink: 0;
}

.evidence-types {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.evidence-type {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  color: var(--pj-text-muted);
}

.evidence-type.is-active {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-color: #2563eb;
  color: #1d4ed8;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.field--wide {
  grid-column: 1 / -1;
}

.w-full {
  width: 100%;
}
</style>
