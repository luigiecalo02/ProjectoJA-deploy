<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import type {
  JudgeDetailTab,
  JudgeSubevento,
  JudgeTreeNode,
  ParticipationCalificacion,
} from '@/modules/events/types'
import { formatDateOnly } from '@/modules/events/dateUtils'

type EvidenceKind = 'faltante' | 'cargada' | 'no_aplica' | 'mixto'

type ObsMessage = {
  key: string
  role: 'juez' | 'director'
  autor: string
  texto: string
  at?: string | null
  mine?: boolean
}

const props = withDefaults(
  defineProps<{
    actividad: JudgeSubevento
    defaultTab?: JudgeDetailTab
    showCalificacion?: boolean
    showResultado?: boolean
    showObservaciones?: boolean
    /** Quién usa la pestaña de observaciones */
    observacionesMode?: 'judge' | 'director'
    resultado?: ParticipationCalificacion | null
    tipText?: string
    subeventos?: JudgeTreeNode[]
    evidenciaById?: Record<number, number>
    hasClubSelected?: boolean
    canEditDirectorObs?: boolean
    savingDirectorObs?: boolean
    /** Borrador / texto de observación propia (juez) */
    judgeObservacion?: string
    canWriteJudgeObs?: boolean
    savingJudgeObs?: boolean
    showParticipantes?: boolean
  }>(),
  {
    defaultTab: 'info',
    showCalificacion: true,
    showResultado: false,
    showObservaciones: false,
    observacionesMode: 'judge',
    resultado: null,
    tipText: '',
    subeventos: () => [],
    evidenciaById: () => ({}),
    hasClubSelected: false,
    canEditDirectorObs: false,
    savingDirectorObs: false,
    judgeObservacion: '',
    canWriteJudgeObs: false,
    savingJudgeObs: false,
    showParticipantes: false,
  },
)

const emit = defineEmits<{
  selectSubevento: [node: JudgeTreeNode]
  saveDirectorObs: [observaciones: string]
  'update:judgeObservacion': [value: string]
  saveJudgeObs: []
}>()

const { t } = useI18n()
const detailTab = ref<JudgeDetailTab>(props.defaultTab)
const directorObsDraft = ref('')

watch(
  () => [props.actividad.id, props.defaultTab] as const,
  ([, tab]) => {
    detailTab.value = tab
  },
)

watch(
  () => props.resultado?.observaciones_director ?? '',
  (value) => {
    directorObsDraft.value = value || ''
  },
  { immediate: true },
)

const hasSubeventos = computed(() => (props.subeventos?.length ?? 0) > 0)

const tabs = computed(() => {
  const base: Array<{ id: JudgeDetailTab; label: string }> = [
    { id: 'info', label: t('events.wizard.subTabInfo') },
  ]
  if (hasSubeventos.value) {
    base.push({ id: 'subeventos', label: t('events.judgeTabSubeventos') })
  }
  if (props.showParticipantes) {
    base.push({ id: 'participantes', label: t('events.activityRosterTab') })
  }
  base.push(
    { id: 'descripcion', label: t('events.judgeTabDescripcion') },
    { id: 'reglas', label: t('events.wizard.subTabRules') },
    { id: 'puntaje', label: t('events.wizard.subTabScore') },
    { id: 'categoria', label: t('events.wizard.subTabCategory') },
  )
  if (props.showResultado) {
    base.push({ id: 'resultado', label: t('events.judgeTabCalificacion') })
  }
  if (props.showCalificacion) {
    base.push({ id: 'calificacion', label: t('events.judgeTabCalificacion') })
  }
  if (props.showObservaciones) {
    base.push({
      id: 'observaciones',
      label:
        props.observacionesMode === 'director'
          ? t('events.judgeTabObsJueces')
          : t('events.judgeTabObsDirectores'),
    })
  }
  return base
})

const conversation = computed<ObsMessage[]>(() => {
  const msgs: ObsMessage[] = []
  const r = props.resultado
  if (!r) return msgs

  const aportes = r.aportes ?? []
  if (aportes.length > 0) {
    aportes.forEach((aporte, index) => {
      const texto = (aporte.observaciones || '').trim()
      if (!texto) return
      msgs.push({
        key: `juez-${index}-${aporte.updated_at || index}`,
        role: 'juez',
        autor: aporte.etiqueta || `Juez ${index + 1}`,
        texto,
        at: aporte.updated_at,
        mine: props.observacionesMode === 'judge' && aportes.length === 1,
      })
    })
  } else {
    const texto = (r.observaciones || '').trim()
    if (texto && !r.es_agregado && !r.es_promedio) {
      msgs.push({
        key: `juez-own-${r.updated_at || 'x'}`,
        role: 'juez',
        autor: props.observacionesMode === 'judge' ? t('events.obsYou') : t('events.obsJudgeLabel'),
        texto,
        at: r.updated_at,
        mine: props.observacionesMode === 'judge',
      })
    }
  }

  const directorTexto = (r.observaciones_director || '').trim()
  if (directorTexto) {
    msgs.push({
      key: `director-${r.observaciones_director_updated_at || 'x'}`,
      role: 'director',
      autor:
        props.observacionesMode === 'director' ? t('events.obsYou') : t('events.obsDirectorLabel'),
      texto: directorTexto,
      at: r.observaciones_director_updated_at,
      mine: props.observacionesMode === 'director',
    })
  }

  return msgs.sort((a, b) => {
    const ta = a.at ? Date.parse(a.at) : 0
    const tb = b.at ? Date.parse(b.at) : 0
    if (ta && tb && ta !== tb) return ta - tb
    if (a.role === b.role) return 0
    return a.role === 'juez' ? -1 : 1
  })
})

function formatObsAt(value?: string | null): string {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleString('es-ES', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const activityTip = computed(
  () => props.tipText || t('events.judgeActivityTip'),
)

const resultadoPct = computed(() => {
  const score = props.resultado?.puntaje_obtenido
  const max = props.actividad.puntaje_maximo
  if (score == null || max == null || max <= 0) return null
  return Math.min(100, Math.round((Number(score) / Number(max)) * 100))
})

const resultadoDetalles = computed(() => {
  const detalles = props.resultado?.detalles ?? []
  if (!detalles.length || !props.actividad.criterios?.length) return []
  return props.actividad.criterios.map((c) => {
    const found = detalles.find((d) => d.criterio_evaluacion_id === c.id)
    return {
      id: c.id,
      nombre: c.nombre,
      descripcion: c.descripcion,
      max: Number(c.puntos),
      puntos: found ? Number(found.puntos) : 0,
    }
  })
})

watch(hasSubeventos, (ok) => {
  if (!ok && detailTab.value === 'subeventos') {
    detailTab.value = 'info'
  }
})

watch(
  () => props.showCalificacion,
  (ok) => {
    if (!ok && detailTab.value === 'calificacion') {
      detailTab.value = 'info'
    }
  },
)

const estadoLabel = computed(() => {
  const estado = props.actividad.estado
  if (estado === 'publicado') return t('events.estadoPublicado')
  if (estado === 'en_proceso') return t('events.estadoEnProceso')
  if (estado === 'borrador') return t('events.estadoBorrador')
  if (estado === 'cerrado') return t('events.estadoCerrado')
  if (estado === 'cancelado') return t('events.estadoCancelado')
  return estado || '—'
})

function iconFor(item: JudgeSubevento | JudgeTreeNode): string {
  const icon =
    ('categoria_subevento' in item ? item.categoria_subevento?.icono : null) ||
    ('tipo_evento' in item ? item.tipo_evento?.icono : null) ||
    ('icono' in item ? item.icono : null) ||
    'pi pi-flag'
  const value = icon || 'pi pi-flag'
  return value.startsWith('pi ') ? value : `pi ${value}`
}

function peopleNames(
  people: Array<{ id: number; name: string }> | null | undefined,
  emptyLabel: string,
): string {
  if (!people?.length) return emptyLabel
  return people.map((p) => p.name).join(', ')
}

function evidenciaTiposLabel(tipos: string[] | null | undefined): string {
  if (!tipos?.length) return '—'
  const labels: Record<string, string> = {
    link: t('events.wizard.subEvidenceLink'),
    pdf: t('events.wizard.subEvidencePdf'),
    imagen: t('events.wizard.subEvidenceImage'),
    audio: t('events.wizard.subEvidenceAudio'),
    video: t('events.wizard.subEvidenceVideo'),
  }
  return tipos.map((tipo) => labels[tipo] || tipo).join(', ')
}

function nivelConjuntoLabel(nivel: string | null | undefined): string {
  if (!nivel) return '—'
  const map: Record<string, string> = {
    club: t('events.wizard.subNivelClub'),
    iglesia: t('events.wizard.subNivelIglesia'),
    distrito: t('events.wizard.subNivelDistrito'),
    asociacion: t('events.wizard.subNivelAsociacion'),
  }
  return map[nivel] || nivel
}

function evidenceCount(id: number): number {
  return Number(props.evidenciaById[id] || 0)
}

function collectEvidenceTargets(node: JudgeTreeNode): JudgeTreeNode[] {
  const children = node.hijos ?? []
  if (children.length) {
    return children.flatMap((child) => collectEvidenceTargets(child))
  }
  if (node.requiere_evidencia || node.es_calificable) {
    return [node]
  }
  return []
}

function evidenceMeta(node: JudgeTreeNode): { kind: EvidenceKind; label: string; count: number } {
  const targets = collectEvidenceTargets(node)
  if (!targets.length) {
    return { kind: 'no_aplica', label: t('events.judgeEvidenceNotRequired'), count: 0 }
  }

  let loaded = 0
  let total = 0
  for (const target of targets) {
    total += 1
    if (evidenceCount(target.id) > 0) loaded += 1
  }

  if (loaded === 0) {
    return { kind: 'faltante', label: t('events.judgeEvidenceMissing'), count: 0 }
  }
  if (loaded === total) {
    const count = evidenceCount(node.id) || targets.reduce((s, n) => s + evidenceCount(n.id), 0)
    return { kind: 'cargada', label: t('events.judgeEvidenceLoaded'), count }
  }
  return {
    kind: 'mixto',
    label: t('events.judgeEvidenceMixed', { loaded, total }),
    count: loaded,
  }
}

const subeventoRows = computed(() =>
  (props.subeventos ?? []).map((child) => ({
    child,
    meta: evidenceMeta(child),
  })),
)
</script>

<template>
  <article class="judge-activity">
    <div v-if="actividad.image_url" class="judge-activity__media">
      <img :src="actividad.image_url" :alt="actividad.name" />
    </div>

    <div class="judge-activity__head">
      <span
        v-if="!actividad.image_url"
        class="judge-activity__icon"
        :style="{ color: actividad.categoria_subevento?.color || undefined }"
      >
        <i :class="iconFor(actividad)" />
      </span>
      <div class="judge-activity__titles">
        <h3>{{ actividad.name }}</h3>
        <span
          v-if="actividad.categoria_subevento"
          class="cat-pill"
          :style="{
            color: actividad.categoria_subevento.color || undefined,
            borderColor: actividad.categoria_subevento.color || undefined,
          }"
        >
          {{ actividad.categoria_subevento.nombre }}
        </span>
      </div>
      <span class="estado-pill" :class="`is-${actividad.estado || 'borrador'}`">
        {{ estadoLabel }}
      </span>
    </div>

    <div class="judge-activity__tabs" role="tablist">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        role="tab"
        :class="{ 'is-active': detailTab === tab.id }"
        :aria-selected="detailTab === tab.id"
        @click="detailTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </div>

    <div v-show="detailTab === 'info'" class="judge-activity__body">
      <h4>{{ t('events.wizard.shortDescription') }}</h4>
      <p>{{ actividad.descripcion || t('events.wizard.previewPending') }}</p>
      <ul class="meta-list">
        <li v-if="actividad.puntaje_por_participar">
          <i class="pi pi-verified" />
          <span>{{ t('events.wizard.subOptScoreByParticipation') }}</span>
          <strong>{{ t('common.yes') }}</strong>
        </li>
        <li v-if="actividad.puntaje_maximo != null || actividad.es_calificable || actividad.puntaje_desde_hijos">
          <i class="pi pi-star" />
          <span>{{ t('events.wizard.subColScore') }}</span>
          <strong>
            {{ Number(actividad.puntaje_maximo || 0) }} pts
            <template v-if="actividad.puntaje_desde_hijos">
              · {{ t('events.wizard.subScoreFromChildrenBadge') }}
            </template>
          </strong>
        </li>
        <li>
          <i class="pi pi-bookmark" />
          <span>{{ t('events.tipoEvento') }}</span>
          <strong>{{ actividad.tipo_evento?.nombre || '—' }}</strong>
        </li>
        <li>
          <i class="pi pi-tag" />
          <span>{{ t('events.wizard.subColCategory') }}</span>
          <strong>{{ actividad.categoria_subevento?.nombre || '—' }}</strong>
        </li>
        <li v-if="actividad.requiere_puesto_entrega">
          <i class="pi pi-map-marker" />
          <span>{{ t('events.wizard.subPuestoEntrega') }}</span>
          <strong>{{ t('common.yes') }}</strong>
        </li>
        <li v-if="actividad.requiere_tiempo_entrega">
          <i class="pi pi-clock" />
          <span>{{ t('events.wizard.subTiempoEntrega') }}</span>
          <strong>{{ t('common.yes') }}</strong>
        </li>
        <li v-if="actividad.resultado_esperado != null">
          <i class="pi pi-check-square" />
          <span>{{ t('events.wizard.subResultadoEsperado') }}</span>
          <strong>{{ actividad.resultado_esperado }}</strong>
        </li>
        <li
          v-if="
            actividad.participantes_min != null ||
            actividad.participantes_max != null ||
            actividad.participantes_genero != null
          "
        >
          <i class="pi pi-users" />
          <span>{{ t('events.wizard.subParticipants') }}</span>
          <strong>
            <template v-if="actividad.participantes_genero === 'mixto'">
              {{ t('events.wizard.subParticipantsGenderMixto') }}
              · M
              {{ actividad.participantes_min_m ?? '—' }}<template v-if="actividad.participantes_max_m != null">–{{ actividad.participantes_max_m }}</template>
              / F
              {{ actividad.participantes_min_f ?? '—' }}<template v-if="actividad.participantes_max_f != null">–{{ actividad.participantes_max_f }}</template>
            </template>
            <template v-else>
              {{ actividad.participantes_min ?? '—' }}<template v-if="actividad.participantes_max != null">–{{ actividad.participantes_max }}</template>
              <template v-if="actividad.participantes_genero === 'M'">
                · {{ t('events.wizard.subParticipantsGenderM') }}
              </template>
              <template v-else-if="actividad.participantes_genero === 'F'">
                · {{ t('events.wizard.subParticipantsGenderF') }}
              </template>
              <template v-else-if="actividad.participantes_genero === 'cualquiera'">
                · {{ t('events.wizard.subParticipantsGenderCualquiera') }}
              </template>
            </template>
          </strong>
        </li>
        <li v-if="actividad.es_conjunto">
          <i class="pi pi-share-alt" />
          <span>{{ t('events.wizard.subOptJoint') }}</span>
          <strong>{{ nivelConjuntoLabel(actividad.nivel_conjunto) }}</strong>
        </li>
        <li v-if="actividad.maneja_fecha_fin">
          <i class="pi pi-calendar-times" />
          <span>{{ t('events.endsAt') }}</span>
          <strong>{{ actividad.ends_at ? formatDateOnly(actividad.ends_at) : '—' }}</strong>
        </li>
        <li v-if="actividad.maneja_penalizaciones">
          <i class="pi pi-exclamation-triangle" />
          <span>{{ t('events.wizard.subOptPenalties') }}</span>
          <strong>
            −{{ Number(actividad.puntos_penalizacion || 0) }} pts
            <template v-if="actividad.reglas_penalizacion">
              · {{ actividad.reglas_penalizacion }}
            </template>
          </strong>
        </li>
        <li v-if="actividad.requiere_pago || actividad.precio != null">
          <i class="pi pi-dollar" />
          <span>{{ t('events.wizard.subOptValue') }}</span>
          <strong>
            {{
              Number(actividad.precio || 0).toLocaleString('es-ES', {
                style: 'currency',
                currency: 'USD',
              })
            }}
          </strong>
        </li>
        <li v-if="actividad.requiere_evidencia">
          <i class="pi pi-paperclip" />
          <span>{{ t('events.wizard.subOptEvidence') }}</span>
          <strong>{{ evidenciaTiposLabel(actividad.tipos_evidencia) }}</strong>
        </li>
        <li>
          <i class="pi pi-user" />
          <span>{{ t('events.wizard.subJudge') }}</span>
          <strong>{{ peopleNames(actividad.jueces, t('events.wizard.subJudgeEmpty')) }}</strong>
        </li>
        <li>
          <i class="pi pi-eye" />
          <span>{{ t('events.wizard.subSupervisor') }}</span>
          <strong>
            {{ peopleNames(actividad.supervisores, t('events.wizard.subSupervisorEmpty')) }}
          </strong>
        </li>
      </ul>
      <div class="tips-mini">
        <i class="pi pi-lightbulb" />
        <p>{{ activityTip }}</p>
      </div>
    </div>

    <div v-show="detailTab === 'subeventos'" class="judge-activity__body">
      <p class="pj-muted subevents-hint">
        {{
          hasClubSelected
            ? t('events.judgeSubeventsHint')
            : t('events.judgeSubeventsPickClub')
        }}
      </p>
      <ul v-if="subeventoRows.length" class="subevents-list">
        <li v-for="{ child, meta } in subeventoRows" :key="child.id">
          <button type="button" class="subevents-item" @click="emit('selectSubevento', child)">
            <span
              class="subevents-item__thumb"
              :style="!child.image_url && child.color ? { color: child.color } : undefined"
            >
              <img v-if="child.image_url" :src="child.image_url" :alt="child.name" />
              <i v-else :class="iconFor(child)" />
            </span>
            <div class="subevents-item__body">
              <strong>{{ child.name }}</strong>
              <small v-if="child.puntaje_maximo != null">{{ child.puntaje_maximo }} pts</small>
            </div>
            <span
              v-if="hasClubSelected"
              class="subevents-item__badge"
              :class="`is-${meta.kind}`"
            >
              <template v-if="meta.kind === 'cargada' && meta.count > 0">
                {{ meta.label }} ({{ meta.count }})
              </template>
              <template v-else>
                {{ meta.label }}
              </template>
            </span>
            <i class="pi pi-angle-right subevents-item__go" />
          </button>
        </li>
      </ul>
      <p v-else class="pj-muted">{{ t('events.judgeSubeventsEmpty') }}</p>
    </div>

    <div v-show="detailTab === 'descripcion'" class="judge-activity__body">
      <h4>{{ t('events.judgeTabDescripcion') }}</h4>
      <p class="judge-activity__prose">
        {{ actividad.descripcion || t('events.wizard.previewPending') }}
      </p>
    </div>

    <div v-show="detailTab === 'reglas'" class="judge-activity__body">
      <p class="judge-activity__prose">
        {{ actividad.reglas || t('events.wizard.subNoRules') }}
      </p>
    </div>

    <div v-show="detailTab === 'puntaje'" class="judge-activity__body">
      <p>
        <strong>{{ Number(actividad.puntaje_maximo || 0) }}</strong>
        {{ t('events.wizard.subPointsLabel') }}
      </p>
      <ul v-if="actividad.criterios?.length" class="criteria-preview">
        <li v-for="c in actividad.criterios" :key="c.id">
          <div>
            <strong>{{ c.nombre }}</strong>
            <p v-if="c.descripcion" class="pj-muted">{{ c.descripcion }}</p>
          </div>
          <span>{{ c.puntos }} pts</span>
        </li>
      </ul>
      <p v-else class="pj-muted">{{ t('events.criteriaGenericHint') }}</p>
    </div>

    <div v-show="detailTab === 'categoria'" class="judge-activity__body">
      <p>{{ actividad.categoria_subevento?.nombre || '—' }}</p>
    </div>

    <div v-show="detailTab === 'resultado'" class="judge-activity__body">
      <template v-if="resultado">
        <div class="result-score">
          <span>{{ t('events.judgeResultScore') }}</span>
          <strong>
            {{ resultado.puntaje_obtenido }}
            /
            {{ actividad.puntaje_maximo ?? '—' }}
            pts
            <small v-if="resultadoPct != null">({{ resultadoPct }}%)</small>
          </strong>
        </div>
        <p v-if="resultado.es_agregado" class="pj-muted result-rollup">
          {{ resultado.observaciones || t('events.judgeResultFromChildren') }}
        </p>
        <p v-else-if="resultado.es_promedio" class="pj-muted result-rollup">
          {{ t('events.judgeResultAverage', { count: resultado.jueces_count || resultado.aportes?.length || 0 }) }}
        </p>

        <ul
          v-if="resultado.aportes && resultado.aportes.length > 1"
          class="result-aportes"
        >
          <li class="result-aportes__head">
            <strong>{{ t('events.judgeResultAportes') }}</strong>
          </li>
          <li v-for="aporte in resultado.aportes" :key="aporte.etiqueta">
            <span>{{ aporte.etiqueta }}</span>
            <strong>{{ aporte.puntaje_obtenido }} pts</strong>
          </li>
        </ul>

        <ul v-if="resultadoDetalles.length" class="criteria-preview">
          <li class="criteria-preview__head">
            <strong>{{ t('events.judgeResultCriteria') }}</strong>
          </li>
          <li v-for="row in resultadoDetalles" :key="row.id">
            <div>
              <strong>{{ row.nombre }}</strong>
              <p v-if="row.descripcion" class="pj-muted">{{ row.descripcion }}</p>
            </div>
            <span>{{ row.puntos }} / {{ row.max }} pts</span>
          </li>
        </ul>

      </template>
      <p v-else class="pj-muted">{{ t('events.judgeResultPending') }}</p>
    </div>

    <div v-if="showParticipantes" v-show="detailTab === 'participantes'" class="judge-activity__body">
      <slot name="participantes" />
    </div>

    <div v-show="detailTab === 'observaciones'" class="judge-activity__body">
      <p class="pj-muted obs-hint">
        {{
          observacionesMode === 'director'
            ? t('events.obsHintDirector')
            : t('events.obsHintJudge')
        }}
      </p>

      <div v-if="conversation.length" class="obs-thread" role="log">
        <article
          v-for="msg in conversation"
          :key="msg.key"
          class="obs-bubble"
          :class="[`is-${msg.role}`, { 'is-mine': msg.mine }]"
        >
          <header class="obs-bubble__head">
            <strong>{{ msg.autor }}</strong>
            <time v-if="formatObsAt(msg.at)">{{ formatObsAt(msg.at) }}</time>
          </header>
          <p class="obs-bubble__text">{{ msg.texto }}</p>
        </article>
      </div>
      <p v-else class="pj-muted obs-empty">{{ t('events.obsConversationEmpty') }}</p>

      <div v-if="observacionesMode === 'director' && canEditDirectorObs" class="obs-composer">
        <label>{{ t('events.directorObsTitle') }}</label>
        <Textarea
          v-model="directorObsDraft"
          rows="3"
          auto-resize
          class="w-full"
          :placeholder="t('events.directorObsPlaceholder')"
        />
        <Button
          type="button"
          icon="pi pi-send"
          :label="t('events.directorObsSave')"
          :loading="savingDirectorObs"
          :disabled="!directorObsDraft.trim()"
          @click="emit('saveDirectorObs', directorObsDraft.trim())"
        />
      </div>

      <div v-else-if="observacionesMode === 'judge' && canWriteJudgeObs" class="obs-composer">
        <label>{{ t('events.judgeObservations') }}</label>
        <Textarea
          :model-value="judgeObservacion"
          rows="3"
          auto-resize
          class="w-full"
          :maxlength="500"
          :placeholder="t('events.obsJudgePlaceholder')"
          @update:model-value="emit('update:judgeObservacion', String($event ?? ''))"
        />
        <div class="obs-composer__footer">
          <small class="pj-muted">{{ (judgeObservacion || '').length }}/500</small>
          <Button
            type="button"
            icon="pi pi-send"
            :label="t('events.obsJudgeSave')"
            :loading="savingJudgeObs"
            :disabled="!(judgeObservacion || '').trim()"
            @click="emit('saveJudgeObs')"
          />
        </div>
      </div>
      <p
        v-else-if="observacionesMode === 'judge' && !canWriteJudgeObs"
        class="pj-muted obs-composer-locked"
      >
        {{ t('events.obsJudgeNeedScore') }}
      </p>
    </div>

    <div v-show="detailTab === 'calificacion'" class="judge-activity__body judge-activity__body--score">
      <slot name="calificacion" />
    </div>
  </article>
</template>

<style scoped>
.judge-activity {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.15rem 0 0;
  min-width: 0;
}

.judge-activity__media {
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 5rem;
  max-height: 12rem;
  padding: 0.4rem;
  background: color-mix(in srgb, var(--pj-navy, #1e3a5f) 8%, #e2e8f0);
}

.judge-activity__media img {
  max-width: 100%;
  max-height: 11.2rem;
  width: auto;
  height: auto;
  object-fit: contain;
  object-position: center;
  display: block;
}

.judge-activity__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.65rem;
}

.judge-activity__icon {
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 10px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #2563eb 10%, transparent);
  font-size: 1.05rem;
  flex-shrink: 0;
}

.judge-activity__titles {
  min-width: 0;
  flex: 1;
}

.judge-activity__titles h3 {
  margin: 0 0 0.25rem;
  font-size: 1.05rem;
  color: var(--pj-navy, #1e3a5f);
  line-height: 1.25;
}

.cat-pill {
  display: inline-flex;
  padding: 0.12rem 0.5rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  font-size: 0.72rem;
  font-weight: 650;
}

.estado-pill {
  margin-left: auto;
  padding: 0.28rem 0.7rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
  white-space: nowrap;
}

.estado-pill.is-en_proceso {
  background: color-mix(in srgb, #2563eb 14%, transparent);
  color: #1d4ed8;
}

.estado-pill.is-borrador {
  background: color-mix(in srgb, #64748b 14%, transparent);
  color: #475569;
}

.estado-pill.is-cerrado,
.estado-pill.is-cancelado {
  background: color-mix(in srgb, #dc2626 12%, transparent);
  color: #b91c1c;
}

.judge-activity__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  padding-bottom: 0.35rem;
}

.judge-activity__tabs button {
  border: 0;
  background: transparent;
  padding: 0.35rem 0.55rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--pj-text-muted);
  cursor: pointer;
  border-radius: 8px;
}

.judge-activity__tabs button.is-active {
  color: var(--pj-primary, #2563eb);
  background: color-mix(in srgb, #2563eb 10%, transparent);
}

.judge-activity__body h4 {
  margin: 0 0 0.35rem;
  font-size: 0.82rem;
}

.judge-activity__body p {
  margin: 0 0 0.75rem;
  font-size: 0.88rem;
  color: color-mix(in srgb, var(--pj-text) 85%, transparent);
}

.judge-activity__prose {
  white-space: pre-wrap;
  line-height: 1.55;
}

.judge-activity__body--score {
  margin: 0;
}

.meta-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.meta-list li {
  display: grid;
  grid-template-columns: 1.1rem 1fr auto;
  gap: 0.45rem;
  align-items: center;
  font-size: 0.84rem;
}

.meta-list i {
  color: var(--pj-primary, #2563eb);
}

.tips-mini {
  margin-top: 0.85rem;
  display: flex;
  gap: 0.45rem;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  background: color-mix(in srgb, #fbbf24 12%, #fff);
  border: 1px solid color-mix(in srgb, #f59e0b 25%, transparent);
  font-size: 0.8rem;
}

.tips-mini i {
  color: #d97706;
}

.tips-mini p {
  margin: 0;
}

.criteria-preview {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.criteria-preview li {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.55rem 0.65rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  font-size: 0.84rem;
}

.criteria-preview .pj-muted {
  margin: 0.2rem 0 0;
  font-size: 0.78rem;
}

.criteria-preview span {
  font-weight: 700;
  white-space: nowrap;
}

.subevents-hint {
  margin: 0 0 0.65rem;
  font-size: 0.8rem;
}

.subevents-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.subevents-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  gap: 0.65rem;
  align-items: center;
  width: 100%;
  text-align: left;
  padding: 0.45rem 0.55rem 0.45rem 0.45rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  background: #fff;
  cursor: pointer;
  color: inherit;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    box-shadow 0.15s ease;
}

.subevents-item:hover {
  border-color: color-mix(in srgb, #2563eb 35%, transparent);
  background: color-mix(in srgb, #2563eb 5%, #fff);
  box-shadow: 0 4px 12px -8px rgba(37, 99, 235, 0.35);
}

.subevents-item__thumb {
  width: 3rem;
  height: 3rem;
  border-radius: 10px;
  overflow: hidden;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #2563eb 10%, #f1f5f9);
  color: #1d4ed8;
  font-size: 1rem;
  flex-shrink: 0;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--pj-border) 40%, transparent);
}

.subevents-item__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.subevents-item__body {
  min-width: 0;
  display: grid;
  gap: 0.12rem;
}

.subevents-item__body strong {
  font-size: 0.86rem;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.subevents-item__body small {
  font-size: 0.72rem;
  color: var(--pj-text-muted, #64748b);
}

.subevents-item__badge {
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
  white-space: nowrap;
}

.subevents-item__badge.is-faltante {
  background: color-mix(in srgb, #dc2626 12%, transparent);
  color: #b91c1c;
}

.subevents-item__badge.is-cargada {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.subevents-item__badge.is-mixto {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.subevents-item__badge.is-no_aplica {
  background: color-mix(in srgb, #64748b 12%, transparent);
  color: #475569;
}

.subevents-item__go {
  color: var(--pj-text-muted, #64748b);
  font-size: 0.85rem;
}

.result-score {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, #2563eb 8%, transparent);
  border: 1px solid color-mix(in srgb, #2563eb 20%, transparent);
  margin-bottom: 0.75rem;
  font-size: 0.88rem;
}

.result-score strong {
  font-size: 1rem;
}

.result-score small {
  margin-left: 0.3rem;
  font-weight: 600;
  opacity: 0.8;
}

.result-aportes {
  list-style: none;
  margin: 0 0 0.85rem;
  padding: 0.7rem 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, var(--pj-navy, #1e3a5f) 6%, white);
  border: 1px solid color-mix(in srgb, var(--pj-navy, #1e3a5f) 12%, transparent);
}

.result-aportes__head {
  margin-bottom: 0.35rem;
  font-size: 0.8rem;
  color: #334155;
}

.result-aportes li {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.35rem 0;
  border-top: 1px solid color-mix(in srgb, var(--pj-navy, #1e3a5f) 8%, transparent);
  color: #475569;
  font-size: 0.88rem;
}

.result-aportes li:first-child,
.result-aportes__head + li {
  border-top: 0;
  padding-top: 0;
}

.result-obs h4 {
  margin: 0.85rem 0 0.35rem;
  font-size: 0.82rem;
}

.obs-hint {
  margin: 0 0 0.75rem;
  font-size: 0.82rem;
}

.obs-empty {
  margin: 0 0 1rem;
  font-size: 0.86rem;
}

.obs-thread {
  display: grid;
  gap: 0.65rem;
  margin-bottom: 1rem;
  max-height: 22rem;
  overflow: auto;
  padding: 0.25rem 0.15rem 0.35rem;
}

.obs-bubble {
  max-width: min(92%, 28rem);
  padding: 0.65rem 0.8rem;
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  background: #f8fafc;
}

.obs-bubble.is-juez {
  justify-self: start;
  border-bottom-left-radius: 4px;
  background: color-mix(in srgb, #2563eb 7%, #fff);
  border-color: color-mix(in srgb, #2563eb 18%, transparent);
}

.obs-bubble.is-director {
  justify-self: end;
  border-bottom-right-radius: 4px;
  background: color-mix(in srgb, #0f766e 8%, #fff);
  border-color: color-mix(in srgb, #0f766e 20%, transparent);
}

.obs-bubble.is-mine {
  box-shadow: inset 0 0 0 1px color-mix(in srgb, #2563eb 12%, transparent);
}

.obs-bubble__head {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: baseline;
  margin-bottom: 0.3rem;
}

.obs-bubble__head strong {
  font-size: 0.75rem;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #334155;
}

.obs-bubble__head time {
  font-size: 0.68rem;
  color: var(--pj-text-muted, #64748b);
  white-space: nowrap;
}

.obs-bubble__text {
  margin: 0 !important;
  font-size: 0.88rem;
  line-height: 1.45;
  white-space: pre-wrap;
  color: color-mix(in srgb, var(--pj-text) 90%, transparent);
}

.obs-composer {
  display: grid;
  gap: 0.45rem;
  padding-top: 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.obs-composer label {
  font-size: 0.8rem;
  font-weight: 650;
  color: #334155;
}

.obs-composer > .p-button,
.obs-composer__footer .p-button {
  justify-self: start;
}

.obs-composer__footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
}

.obs-composer-locked {
  margin: 0.5rem 0 0;
  font-size: 0.84rem;
}

.result-director-obs {
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-navy, #1e3a5f) 12%, transparent);
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.result-director-obs h4 {
  margin: 0;
  font-size: 0.88rem;
  color: var(--pj-navy, #1e3a5f);
}

.result-director-obs__hint {
  margin: 0;
  font-size: 0.82rem;
}

.result-director-obs__save {
  align-self: flex-start;
}

.w-full {
  width: 100%;
}

.criteria-preview__head {
  border: 0 !important;
  padding: 0.15rem 0.15rem 0.25rem !important;
  background: transparent !important;
  font-size: 0.78rem;
  color: var(--pj-text-muted, #64748b);
}
</style>
