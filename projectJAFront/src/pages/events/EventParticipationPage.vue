<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import PageLoader from '@/components/PageLoader.vue'
import EventJudgeActivityCard from '@/components/events/EventJudgeActivityCard.vue'
import EventMaterialsViewer from '@/components/events/EventMaterialsViewer.vue'
import EventActivityRosterTab from '@/components/events/EventActivityRosterTab.vue'
import MediaGalleryUpload from '@/components/media/MediaGalleryUpload.vue'
import MediaDocumentsUpload from '@/components/media/MediaDocumentsUpload.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import { resolveAssetUrl, toCssImageUrl } from '@/modules/settings/assetUrl'
import { extractBannerHeroVars } from '@/utils/dominantColor'
import type {
  EventParticipation,
  ParticipationNode,
  EventoEvidenciaItem,
  JudgeSubevento,
  JudgeTreeNode,
} from '@/modules/events/types'
import {
  previewFromEvidenceFile,
  previewFromEvidenceUrl,
  parseEvidenceUrl,
  type EvidencePreview,
} from '@/modules/events/evidencePreview'
import {
  documentKindFromName,
  formatFileSize,
  type MediaDocumentItem,
  type MediaGalleryItem,
} from '@/modules/media/types'

type EvalStatus = 'calificada' | 'en_revision' | 'pendiente'
type FlatNode = ParticipationNode & { depth: number }

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const isMobile = useMediaQuery('(max-width: 900px)')
const detailSheetVisible = ref(false)

const loading = ref(true)
const saving = ref(false)
const savingDirectorObs = ref(false)
const data = ref<EventParticipation | null>(null)
const selectedId = ref<number | null>(null)
const editingEvidence = ref(true)
const pendingFile = ref<File | null>(null)
const fileObjectUrl = ref<string | null>(null)
const evidenceForm = ref({
  tipo: 'link' as string,
  titulo: '',
  url: '',
  descripcion: '',
})

const eventId = computed(() => Number(route.params.id))
const bannerUrl = computed(() => resolveAssetUrl(data.value?.evento.banner_url))
const eventLogoUrl = computed(() => resolveAssetUrl(data.value?.evento.image_url))
const clubLogoUrl = computed(() => resolveAssetUrl(data.value?.organizacion.logo_url))
const heroCoverUrl = computed(() => bannerUrl.value || eventLogoUrl.value)
const showClubLogo = computed(() => Boolean(clubLogoUrl.value && heroCoverUrl.value))
const heroTheme = ref<Record<string, string>>({})
let heroThemeSequence = 0
const clubHeroStyle = computed(() => {
  const url = heroCoverUrl.value
  if (!url) return undefined
  return {
    '--hero-image': toCssImageUrl(url),
    ...heroTheme.value,
  }
})

watch(
  heroCoverUrl,
  async (url) => {
    heroTheme.value = {}
    if (!url) return
    const sequence = ++heroThemeSequence
    try {
      const vars = await extractBannerHeroVars(url)
      if (sequence !== heroThemeSequence) return
      heroTheme.value = vars
    } catch {
      if (sequence !== heroThemeSequence) return
      heroTheme.value = {}
    }
  },
  { immediate: true },
)

const flatNodes = computed(() => {
  if (!data.value) return [] as FlatNode[]
  const out: FlatNode[] = []
  function walk(node: ParticipationNode, depth = 0) {
    if (!node.is_root) {
      out.push({ ...node, depth })
    }
    for (const hijo of node.hijos || []) walk(hijo, depth + 1)
  }
  walk(data.value.evento, 0)
  return out
})

const selected = computed(() => {
  if (!data.value || !selectedId.value) return null
  function find(node: ParticipationNode): ParticipationNode | null {
    if (node.id === selectedId.value) return node
    for (const hijo of node.hijos || []) {
      const found = find(hijo)
      if (found) return found
    }
    return null
  }
  return find(data.value.evento)
})

const directorLocked = computed(() =>
  Boolean(data.value?.modificacion_bloqueada || selected.value?.modificacion_bloqueada),
)

function toJudgeSubevento(node: ParticipationNode): JudgeSubevento {
  return {
    id: node.id,
    name: node.name,
    descripcion: node.descripcion,
    reglas: node.reglas,
    estado: node.estado,
    image_url: node.image_url,
    icono: node.icono || node.categoria_subevento?.icono || node.tipo_evento?.icono || 'pi pi-flag',
    color: node.color || node.categoria_subevento?.color || node.tipo_evento?.color || null,
    starts_at: node.starts_at,
    ends_at: node.ends_at,
    puntaje_maximo: node.puntaje_maximo,
    requiere_evidencia: node.requiere_evidencia,
    tipos_evidencia: node.tipos_evidencia,
    es_calificable: node.es_calificable,
    puntaje_desde_hijos: node.puntaje_desde_hijos,
    puntaje_por_participar: node.puntaje_por_participar,
    tiempo_estimado_minutos: node.tiempo_estimado_minutos,
    requiere_puesto_entrega: node.requiere_puesto_entrega,
    requiere_tiempo_entrega: node.requiere_tiempo_entrega,
    resultado_esperado: node.resultado_esperado,
    participantes_min: node.participantes_min,
    participantes_max: node.participantes_max,
    permite_inscribir_no_participantes: node.permite_inscribir_no_participantes,
    participantes_genero: node.participantes_genero,
    participantes_min_m: node.participantes_min_m,
    participantes_max_m: node.participantes_max_m,
    participantes_min_f: node.participantes_min_f,
    participantes_max_f: node.participantes_max_f,
    es_conjunto: node.es_conjunto,
    nivel_conjunto: node.nivel_conjunto,
    maneja_fecha_fin: node.maneja_fecha_fin,
    maneja_penalizaciones: node.maneja_penalizaciones,
    puntos_penalizacion: node.puntos_penalizacion,
    reglas_penalizacion: node.reglas_penalizacion,
    requiere_pago: node.requiere_pago,
    precio: node.precio,
    tipo_evento: node.tipo_evento,
    categoria_subevento: node.categoria_subevento,
    jueces: node.jueces,
    supervisores: node.supervisores,
    criterios: node.criterios,
    hijos: [],
  }
}

function toJudgeTreeNode(node: ParticipationNode): JudgeTreeNode {
  return {
    id: node.id,
    name: node.name,
    puntaje_maximo: node.puntaje_maximo,
    es_calificable: node.es_calificable,
    requiere_evidencia: node.requiere_evidencia,
    icono: node.icono || node.categoria_subevento?.icono || node.tipo_evento?.icono || 'pi pi-flag',
    color: node.color || node.categoria_subevento?.color || node.tipo_evento?.color || null,
    categoria: node.categoria_subevento?.nombre || null,
    tipo: node.tipo_evento?.nombre || null,
    hijos: (node.hijos || []).map(toJudgeTreeNode),
  }
}

const selectedActivity = computed(() =>
  selected.value ? toJudgeSubevento(selected.value) : null,
)

const participationChildNodes = computed(() =>
  (selected.value?.hijos || []).map(toJudgeTreeNode),
)

const participationEvidenciaById = computed(() => {
  const map: Record<number, number> = {}
  if (!data.value) return map
  function walk(node: ParticipationNode) {
    map[node.id] = node.evidencias?.length ?? 0
    for (const hijo of node.hijos || []) walk(hijo)
  }
  walk(data.value.evento)
  return map
})

const latestEvidence = computed(() => selected.value?.evidencias?.[0] ?? null)

const nowTick = ref(Date.now())
let deadlineTimer: ReturnType<typeof setInterval> | null = null

function evidenceDeadlineMs(iso: string | null | undefined): number | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  // Fechas guardadas a medianoche → fin del día calendario
  if (d.getHours() === 0 && d.getMinutes() === 0 && d.getSeconds() === 0) {
    d.setHours(23, 59, 59, 999)
  }
  return d.getTime()
}

const evidenceDeadline = computed(() => {
  const node = selected.value
  if (!node?.maneja_fecha_fin || !node.ends_at) return null
  return evidenceDeadlineMs(node.ends_at)
})

const deadlineCountdown = computed(() => {
  const end = evidenceDeadline.value
  if (end == null) return null
  const diff = end - nowTick.value
  if (diff <= 0) {
    return { expired: true, days: 0, hours: 0, minutes: 0, seconds: 0, label: t('events.evidenceDeadlineExpired') }
  }
  const totalSec = Math.floor(diff / 1000)
  const days = Math.floor(totalSec / 86400)
  const hours = Math.floor((totalSec % 86400) / 3600)
  const minutes = Math.floor((totalSec % 3600) / 60)
  const seconds = totalSec % 60
  return { expired: false, days, hours, minutes, seconds, label: t('events.evidenceDeadlineLabel') }
})

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function startDeadlineTicker(): void {
  if (deadlineTimer) return
  deadlineTimer = setInterval(() => {
    nowTick.value = Date.now()
  }, 1000)
}

function stopDeadlineTicker(): void {
  if (!deadlineTimer) return
  clearInterval(deadlineTimer)
  deadlineTimer = null
}

watch(
  evidenceDeadline,
  (end) => {
    if (end != null) {
      nowTick.value = Date.now()
      startDeadlineTicker()
    } else {
      stopDeadlineTicker()
    }
  },
  { immediate: true },
)

const evidenceStats = computed(() => {
  const nodes = flatNodes.value.filter((n) => n.requiere_evidencia)
  const loaded = nodes.filter((n) => (n.evidencias?.length ?? 0) > 0).length
  const total = nodes.length || flatNodes.value.length
  const pct = total ? Math.round((loaded / total) * 100) : 0
  return { loaded, total, pct }
})

const evalStats = computed(() => {
  const nodes = flatNodes.value.filter(
    (n) => (n.es_calificable || n.puntaje_maximo != null) && !n.puntaje_desde_hijos,
  )
  const scored = nodes.filter((n) => n.calificacion != null).length
  const total = nodes.length
  const pct = total ? Math.round((scored / total) * 100) : 0
  return { scored, total, pct }
})

const progressPct = computed(() => {
  const p = data.value?.progreso
  if (!p || !p.puntos_total_max) return 0
  return Math.min(100, Math.round((p.puntos_total / p.puntos_total_max) * 100))
})

const tipoOptions = computed(() => {
  const tipos = selected.value?.tipos_evidencia || []
  const meta: Record<string, { label: string; icon: string }> = {
    link: { label: t('events.wizard.subEvidenceLink'), icon: 'pi pi-link' },
    pdf: { label: t('events.wizard.subEvidencePdf'), icon: 'pi pi-file-pdf' },
    imagen: { label: t('events.wizard.subEvidenceImage'), icon: 'pi pi-image' },
    audio: { label: t('events.wizard.subEvidenceAudio'), icon: 'pi pi-volume-up' },
    video: { label: t('events.wizard.subEvidenceVideo'), icon: 'pi pi-video' },
  }
  return tipos.map((tipo) => ({
    value: tipo,
    label: meta[tipo]?.label || tipo,
    icon: meta[tipo]?.icon || 'pi pi-file',
  }))
})

const needsFile = computed(() => evidenceForm.value.tipo !== 'link')

const acceptForTipo = computed(() => {
  switch (evidenceForm.value.tipo) {
    case 'pdf':
      return '.pdf,application/pdf'
    case 'imagen':
      return 'image/*'
    case 'audio':
      return 'audio/*,.mp3,.wav,.ogg,.m4a,.aac,.flac,.oga,.opus'
    case 'video':
      return 'video/*'
    default:
      return '*/*'
  }
})

const fileHint = computed(() => {
  switch (evidenceForm.value.tipo) {
    case 'pdf':
      return t('events.evidenceFileHintPdf')
    case 'imagen':
      return t('events.evidenceFileHintImage')
    case 'audio':
      return t('events.evidenceFileHintAudio')
    case 'video':
      return t('events.evidenceFileHintVideo')
    default:
      return t('events.evidenceFileHint')
  }
})

const evidenceGalleryItems = computed<MediaGalleryItem[]>(() => {
  if (evidenceForm.value.tipo !== 'imagen' || !pendingFile.value || !fileObjectUrl.value) return []
  return [{ id: 'pending', src: fileObjectUrl.value, name: pendingFile.value.name }]
})

const evidenceDocumentItems = computed<MediaDocumentItem[]>(() => {
  if (evidenceForm.value.tipo === 'link' || evidenceForm.value.tipo === 'imagen' || !pendingFile.value) return []
  return [{
    id: 'pending',
    name: pendingFile.value.name,
    sizeLabel: formatFileSize(pendingFile.value.size),
    kind: documentKindFromName(pendingFile.value.name),
  }]
})

const evidenceDocsAccept = computed(() => acceptForTipo.value)

function revokeFileObjectUrl(): void {
  if (fileObjectUrl.value) {
    URL.revokeObjectURL(fileObjectUrl.value)
    fileObjectUrl.value = null
  }
}

const livePreview = computed<EvidencePreview | null>(() => {
  if (pendingFile.value && fileObjectUrl.value) {
    return previewFromEvidenceFile(pendingFile.value, fileObjectUrl.value, evidenceForm.value.tipo)
  }
  const url = evidenceForm.value.url.trim()
  if (!url) return null
  return previewFromEvidenceUrl(url, {
    preferredTipo: evidenceForm.value.tipo,
    title: evidenceForm.value.titulo,
  })
})

const savedPreview = computed<EvidencePreview | null>(() => {
  const ev = latestEvidence.value
  if (!ev?.url) return null
  return previewFromEvidenceUrl(ev.url, {
    preferredTipo: ev.tipo,
    title: ev.titulo,
  })
})

watch(pendingFile, (file) => {
  revokeFileObjectUrl()
  if (file) {
    fileObjectUrl.value = URL.createObjectURL(file)
  }
})

onBeforeUnmount(() => {
  revokeFileObjectUrl()
  stopDeadlineTicker()
})

function nodeStatus(node: ParticipationNode): EvalStatus {
  if (node.calificacion) {
    if (node.puntaje_desde_hijos && node.calificacion.es_agregado && node.calificacion.observaciones?.includes('Parcial')) {
      return 'en_revision'
    }
    return 'calificada'
  }
  if ((node.evidencias?.length ?? 0) > 0) return 'en_revision'
  if (node.puntaje_desde_hijos) {
    const leaves = collectScoreableLeaves(node)
    if (leaves.length && leaves.every((n) => n.calificacion)) return 'calificada'
    if (leaves.some((n) => n.calificacion || (n.evidencias?.length ?? 0) > 0)) return 'en_revision'
  }
  return 'pendiente'
}

function collectScoreableLeaves(node: ParticipationNode): ParticipationNode[] {
  const out: ParticipationNode[] = []
  function walk(n: ParticipationNode) {
    if (n.es_calificable && !n.puntaje_desde_hijos) {
      out.push(n)
      return
    }
    for (const hijo of n.hijos || []) walk(hijo)
  }
  for (const hijo of node.hijos || []) walk(hijo)
  return out
}

function statusMeta(status: EvalStatus): { label: string; css: string; icon: string } {
  if (status === 'calificada') {
    return { label: t('events.statusScored'), css: 'is-scored', icon: 'pi pi-check-circle' }
  }
  if (status === 'en_revision') {
    return { label: t('events.statusReview'), css: 'is-review', icon: 'pi pi-clock' }
  }
  return { label: t('events.statusPending'), css: 'is-pending', icon: 'pi pi-circle' }
}

function nodeDepth(node: FlatNode): number {
  return node.depth
}

function ringStyle(pct: number, color: string): Record<string, string> {
  const value = Math.max(0, Math.min(100, pct))
  return {
    background: `conic-gradient(${color} ${value * 3.6}deg, color-mix(in srgb, var(--pj-border) 55%, transparent) 0deg)`,
  }
}

async function load(keepSelection = false): Promise<void> {
  const prev = keepSelection ? selectedId.value : null
  loading.value = true
  try {
    data.value = await eventsService.participation(eventId.value)
    const nodes = flatNodes.value
    const qEvent = route.query.evento_id ?? route.query.subevento_id
    let preferred: number | null = null
    if (!keepSelection && qEvent != null && qEvent !== '') {
      const qid = Number(qEvent)
      if (Number.isFinite(qid) && nodes.some((n) => n.id === qid)) {
        preferred = qid
      }
    }
    const stillThere = prev && nodes.some((n) => n.id === prev)
    selectedId.value = preferred ?? (stillThere ? prev : null)
    const current = selected.value
    if (current) {
      selectNode(current)
    } else {
      editingEvidence.value = false
      pendingFile.value = null
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
    router.push({ name: 'events' })
  } finally {
    loading.value = false
  }
}

function selectNode(node: ParticipationNode): void {
  selectedId.value = node.id
  if (isMobile.value) detailSheetVisible.value = true
  const latest = node.evidencias?.[0]
  const defaultTipo = node.tipos_evidencia?.[0] || 'link'
  evidenceForm.value = {
    tipo: latest?.tipo || defaultTipo,
    titulo: latest?.titulo || '',
    url: latest?.url || '',
    descripcion: latest?.descripcion || '',
  }
  pendingFile.value = null
  editingEvidence.value = !latest && !data.value?.modificacion_bloqueada
}

function onSelectChildFromCard(node: JudgeTreeNode): void {
  if (!data.value) return
  function find(n: ParticipationNode): ParticipationNode | null {
    if (n.id === node.id) return n
    for (const hijo of n.hijos || []) {
      const found = find(hijo)
      if (found) return found
    }
    return null
  }
  const found = find(data.value.evento)
  if (found) selectNode(found)
}

function patchNodeCalificacion(
  eventoId: number,
  patch: Partial<NonNullable<ParticipationNode['calificacion']>>,
): void {
  if (!data.value) return
  function walk(node: ParticipationNode): boolean {
    if (node.id === eventoId) {
      node.calificacion = {
        ...(node.calificacion || { puntaje_obtenido: 0 }),
        ...patch,
      }
      return true
    }
    for (const hijo of node.hijos || []) {
      if (walk(hijo)) return true
    }
    return false
  }
  walk(data.value.evento)
}

async function saveDirectorObservacion(observaciones: string): Promise<void> {
  if (!selected.value?.calificacion || selected.value.puntaje_desde_hijos) return
  savingDirectorObs.value = true
  try {
    const saved = await eventsService.saveDirectorObservacion(selected.value.id, observaciones)
    patchNodeCalificacion(selected.value.id, {
      observaciones_director: saved.observaciones_director,
      observaciones_director_updated_at: saved.observaciones_director_updated_at,
    })
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.directorObsSaved'),
      life: 2500,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    savingDirectorObs.value = false
  }
}

function selectEvidenceTipo(tipo: string): void {
  evidenceForm.value.tipo = tipo
  clearPendingFile()
  if (tipo === 'link') {
    // keep url
  } else {
    evidenceForm.value.url = ''
  }
}

function startChangeEvidence(): void {
  if (directorLocked.value) return
  const latest = latestEvidence.value
  evidenceForm.value = {
    tipo: latest?.tipo || selected.value?.tipos_evidencia?.[0] || 'link',
    titulo: latest?.titulo || '',
    url: latest?.url || '',
    descripcion: latest?.descripcion || '',
  }
  pendingFile.value = null
  editingEvidence.value = true
}

const EVIDENCE_MAX_BYTES = 100 * 1024 * 1024

function setPendingEvidenceFile(file: File | null): void {
  revokeFileObjectUrl()
  if (file && file.size > EVIDENCE_MAX_BYTES) {
    pendingFile.value = null
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.evidenceFileTooLarge'),
      life: 4000,
    })
    return
  }
  pendingFile.value = file
  if (file?.type.startsWith('image/')) {
    fileObjectUrl.value = URL.createObjectURL(file)
  }
  if (file && !evidenceForm.value.titulo) {
    evidenceForm.value.titulo = file.name.replace(/\.[^.]+$/, '')
  }
}

function onEvidenceFiles(files: File[]): void {
  setPendingEvidenceFile(files[0] ?? null)
}

function clearPendingFile(): void {
  revokeFileObjectUrl()
  pendingFile.value = null
}

async function addEvidence(): Promise<void> {
  if (!selected.value) return

  if (evidenceForm.value.tipo === 'link' && !evidenceForm.value.url.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.evidenceUrlRequired'),
      life: 3000,
    })
    return
  }

  if (needsFile.value && !pendingFile.value && !evidenceForm.value.url.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.evidenceFileRequired'),
      life: 3000,
    })
    return
  }

  if (pendingFile.value && pendingFile.value.size > EVIDENCE_MAX_BYTES) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.evidenceFileTooLarge'),
      life: 4000,
    })
    return
  }

  saving.value = true
  try {
    const rawUrl = evidenceForm.value.url.trim()
    const normalizedUrl = rawUrl ? parseEvidenceUrl(rawUrl)?.href || rawUrl : null
    await eventsService.createEvidencia(selected.value.id, {
      tipo: evidenceForm.value.tipo,
      titulo: evidenceForm.value.titulo || null,
      url: normalizedUrl,
      descripcion: evidenceForm.value.descripcion || null,
      archivo: pendingFile.value,
    })
    pendingFile.value = null
    editingEvidence.value = false
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.evidenceSaved'),
      life: 2500,
    })
    await load(true)
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function removeEvidence(item: EventoEvidenciaItem): Promise<void> {
  try {
    await eventsService.removeEvidencia(item.id)
    await load(true)
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

onMounted(() => {
  void load()
})

watch(isMobile, (mobile) => {
  if (!mobile) detailSheetVisible.value = false
})
</script>

<template>
  <section class="pj-page participate-page">
    <PageLoader v-if="loading" :label="t('common.loading')" />

    <template v-else-if="data">
      <div class="summary-row">
        <article
          class="summary-card summary-card--club"
          :class="{
            'has-cover': Boolean(heroCoverUrl),
            'has-logo': showClubLogo,
          }"
          :style="clubHeroStyle"
        >
          <div class="club-hero__intro">
            <img
              v-if="showClubLogo && clubLogoUrl"
              class="club-hero__logo"
              :src="clubLogoUrl"
              :alt="data.organizacion.nombre"
            />
            <div v-else class="club-avatar">
              <img
                v-if="data.organizacion.logo_url"
                :src="data.organizacion.logo_url"
                :alt="data.organizacion.nombre"
              />
              <i v-else class="pi pi-flag" />
            </div>
            <div class="club-hero__copy">
              <h2>{{ data.organizacion.nombre }}</h2>
              <p class="pj-muted">{{ data.evento.name }}</p>
              <p v-if="data.inscripcion" class="chip-enrolled">
                <i class="pi pi-check" />
                {{ t('events.enrolled') }}
              </p>
              <p v-else class="chip-open">{{ t('events.notEnrolledYet') }}</p>
            </div>
          </div>
        </article>

        <article class="summary-card">
          <div class="summary-card__body">
            <h3>{{ t('events.clubProgressTitle') }}</h3>
            <p class="summary-card__metric">
              {{ evidenceStats.loaded }} / {{ evidenceStats.total }}
              {{ t('events.evidencesLoaded') }}
            </p>
            <div class="bar">
              <span :style="{ width: `${evidenceStats.pct}%` }" class="bar__fill bar__fill--green" />
            </div>
          </div>
          <div class="ring" :style="ringStyle(evidenceStats.pct, '#16a34a')">
            <div class="ring__inner">{{ evidenceStats.pct }}%</div>
          </div>
        </article>

        <article class="summary-card">
          <div class="summary-card__body">
            <h3>{{ t('events.evalProgressTitle') }}</h3>
            <p class="summary-card__metric">
              {{ evalStats.scored }} / {{ evalStats.total }}
              {{ t('events.evalsScored') }}
            </p>
            <div class="bar">
              <span :style="{ width: `${evalStats.pct}%` }" class="bar__fill bar__fill--blue" />
            </div>
            <p class="pj-muted summary-card__points">
              {{ data.progreso.puntos_total }} / {{ data.progreso.puntos_total_max }} pts ·
              {{ progressPct }}%
            </p>
          </div>
          <div class="ring" :style="ringStyle(evalStats.pct, '#2563eb')">
            <div class="ring__inner">{{ evalStats.pct }}%</div>
          </div>
        </article>
      </div>

      <div class="participate-layout">
        <aside class="panel panel--list">
          <header class="panel__head">
            <h2>{{ t('events.evalListTitle') }}</h2>
            <p class="pj-muted">
              {{ t('events.evalListCount', { count: flatNodes.length }) }}
            </p>
          </header>

          <button
            v-for="node in flatNodes"
            :key="node.id"
            type="button"
            class="eval-item"
            :class="{ 'is-active': selectedId === node.id }"
            :style="{ paddingLeft: `${0.85 + nodeDepth(node) * 0.7}rem` }"
            @click="selectNode(node)"
          >
            <span v-if="node.image_url" class="eval-item__thumb">
              <img :src="node.image_url" :alt="node.name" />
            </span>
            <span v-else class="eval-item__icon" :class="statusMeta(nodeStatus(node)).css">
              <i :class="statusMeta(nodeStatus(node)).icon" />
            </span>
            <span class="eval-item__body">
              <strong>{{ node.name }}</strong>
              <span class="eval-item__pts">
                {{ node.puntaje_maximo != null ? `${node.puntaje_maximo} pts` : '—' }}
              </span>
            </span>
            <span class="status-badge" :class="statusMeta(nodeStatus(node)).css">
              {{ statusMeta(nodeStatus(node)).label }}
            </span>
          </button>

          <p v-if="!flatNodes.length" class="pj-muted empty">{{ t('events.wizard.subeventsEmpty') }}</p>
        </aside>

        <div
          v-if="isMobile && detailSheetVisible"
          class="detail-sheet-backdrop"
          @click="detailSheetVisible = false"
        />

        <main
          class="panel panel--detail"
          :class="{
            'is-sheet': isMobile,
            'is-sheet-open': isMobile && detailSheetVisible,
          }"
        >
          <div v-if="isMobile" class="detail-sheet__handle" aria-hidden="true" />
          <button
            v-if="isMobile"
            type="button"
            class="detail-sheet__close"
            :aria-label="t('common.close')"
            @click="detailSheetVisible = false"
          >
            <i class="pi pi-times" />
          </button>
          <template v-if="selected">
            <header class="detail-head">
              <div class="detail-head__main">
                <div class="detail-avatar">
                  <img
                    v-if="selected.image_url"
                    :src="selected.image_url"
                    :alt="selected.name"
                  />
                  <i v-else class="pi pi-bookmark" />
                </div>
                <div>
                  <div class="detail-head__title-row">
                    <h2>{{ selected.name }}</h2>
                    <span class="status-badge" :class="statusMeta(nodeStatus(selected)).css">
                      {{ statusMeta(nodeStatus(selected)).label }}
                    </span>
                  </div>
                  <p v-if="selected.descripcion" class="pj-muted">{{ selected.descripcion }}</p>
                </div>
              </div>
              <div class="detail-head__stats">
                <div
                  v-if="deadlineCountdown"
                  class="deadline-box"
                  :class="{ 'deadline-box--expired': deadlineCountdown.expired }"
                >
                <span class="deadline-box__label">{{ deadlineCountdown.label }}</span>
                <template v-if="deadlineCountdown.expired">
                  <strong class="deadline-box__expired-text">{{ t('events.evidenceDeadlineClosed') }}</strong>
                </template>
                <div v-else class="deadline-box__digits">
                  <span v-if="deadlineCountdown.days > 0">
                    <strong>{{ deadlineCountdown.days }}</strong>
                    <small>{{ t('events.evidenceDeadlineDays') }}</small>
                  </span>
                  <span>
                    <strong>{{ pad2(deadlineCountdown.hours) }}</strong>
                    <small>{{ t('events.evidenceDeadlineHours') }}</small>
                  </span>
                  <span>
                    <strong>{{ pad2(deadlineCountdown.minutes) }}</strong>
                    <small>{{ t('events.evidenceDeadlineMins') }}</small>
                  </span>
                  <span>
                    <strong>{{ pad2(deadlineCountdown.seconds) }}</strong>
                    <small>{{ t('events.evidenceDeadlineSecs') }}</small>
                  </span>
                </div>
              </div>
              <div class="score-box">
                <strong>
                  {{
                    selected.calificacion
                      ? selected.calificacion.puntaje_obtenido
                      : '—'
                  }}
                  /
                  {{ selected.puntaje_maximo ?? '—' }}
                </strong>
                <span>{{ t('events.maxScoreLabel') }}</span>
                <small
                  v-if="selected.calificacion?.es_promedio"
                  class="score-box__avg"
                >
                  {{
                    t('events.judgeResultAverage', {
                      count:
                        selected.calificacion.jueces_count ||
                        selected.calificacion.aportes?.length ||
                        0,
                    })
                  }}
                </small>
              </div>
              </div>
            </header>

            <div class="detail-sheet__body">
            <EventJudgeActivityCard
              v-if="selectedActivity"
              :actividad="selectedActivity"
              :default-tab="selected.calificacion ? 'resultado' : 'info'"
              :show-calificacion="false"
              :show-resultado="true"
              :show-observaciones="true"
              :show-participantes="
                selected.participantes_min != null ||
                selected.participantes_max != null ||
                Boolean(selected.permite_inscribir_no_participantes)
              "
              observaciones-mode="director"
              :resultado="selected.calificacion"
              :tip-text="t('events.participateActivityTip')"
              :subeventos="participationChildNodes"
              :evidencia-by-id="participationEvidenciaById"
              :has-club-selected="true"
              :can-edit-director-obs="
                Boolean(
                  selected.calificacion &&
                    !selected.puntaje_desde_hijos &&
                    !selected.calificacion.es_agregado,
                )
              "
              :saving-director-obs="savingDirectorObs"
              @select-subevento="onSelectChildFromCard"
              @save-director-obs="saveDirectorObservacion"
            >
              <template #participantes>
                <EventActivityRosterTab :actividad-id="selected.id" :locked="directorLocked" />
              </template>
            </EventJudgeActivityCard>

            <EventMaterialsViewer :files="selected.archivos" />

            <section class="detail-section">
              <h3>{{ t('events.clubEvidenceTitle') }}</h3>

              <template v-if="!selected.requiere_evidencia">
                <p class="pj-muted">{{ t('events.noEvidenceRequired') }}</p>
              </template>

              <template v-else>
                <div v-if="latestEvidence && !editingEvidence" class="evidence-preview">
                  <div class="evidence-preview__top">
                    <div class="evidence-preview__meta">
                      <span class="tipo-chip">{{ latestEvidence.tipo }}</span>
                      <strong>{{ latestEvidence.titulo || t('events.evidenceTitle') }}</strong>
                      <a
                        v-if="latestEvidence.url"
                        :href="latestEvidence.url"
                        target="_blank"
                        rel="noopener"
                        class="evidence-link"
                      >
                        {{ latestEvidence.url }}
                        <i class="pi pi-external-link" />
                      </a>
                      <p v-if="latestEvidence.descripcion" class="pj-muted">
                        {{ latestEvidence.descripcion }}
                      </p>
                    </div>
                    <div v-if="!directorLocked" class="evidence-preview__actions">
                      <Button
                        type="button"
                        outlined
                        icon="pi pi-pencil"
                        :label="t('events.changeEvidence')"
                        @click="startChangeEvidence"
                      />
                      <Button
                        type="button"
                        text
                        severity="danger"
                        icon="pi pi-trash"
                        @click="removeEvidence(latestEvidence)"
                      />
                    </div>
                  </div>

                  <div v-if="savedPreview" class="media-preview">
                    <p class="media-preview__label">{{ t('events.evidencePreview') }}</p>
                    <div v-if="savedPreview.kind === 'youtube' || savedPreview.kind === 'vimeo'" class="media-preview__embed">
                      <iframe
                        :src="savedPreview.embedSrc"
                        title="video"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                      />
                    </div>
                    <img
                      v-else-if="savedPreview.kind === 'image'"
                      :src="savedPreview.src"
                      :alt="savedPreview.title || t('events.evidencePreview')"
                      class="media-preview__image"
                    />
                    <video
                      v-else-if="savedPreview.kind === 'video'"
                      :src="savedPreview.src"
                      class="media-preview__video"
                      controls
                      preload="metadata"
                    />
                    <audio
                      v-else-if="savedPreview.kind === 'audio'"
                      :src="savedPreview.src"
                      class="media-preview__audio"
                      controls
                      preload="metadata"
                    />
                    <iframe
                      v-else-if="savedPreview.kind === 'pdf'"
                      :src="savedPreview.embedSrc || savedPreview.src"
                      class="media-preview__pdf"
                      title="pdf"
                    />
                    <div v-else class="media-preview__link">
                      <div class="media-preview__link-icon">
                        <i class="pi pi-link" />
                      </div>
                      <div class="media-preview__link-body">
                        <strong>{{ savedPreview.title || savedPreview.host }}</strong>
                        <p class="pj-muted truncate">{{ savedPreview.src }}</p>
                        <a
                          :href="savedPreview.src"
                          target="_blank"
                          rel="noopener"
                          class="media-preview__open"
                        >
                          {{ t('events.judgeOpenEvidence') }}
                          <i class="pi pi-external-link" />
                        </a>
                      </div>
                    </div>
                  </div>
                </div>

                <p v-else-if="directorLocked" class="pj-muted">{{ t('events.evidenceLocked') }}</p>

                <div v-else class="evidence-form">
                  <div class="field">
                    <label>{{ t('events.evidenceTipo') }}</label>
                    <div v-if="tipoOptions.length" class="tipo-chips" role="group">
                      <button
                        v-for="opt in tipoOptions"
                        :key="opt.value"
                        type="button"
                        class="tipo-option"
                        :class="{ 'tipo-option--active': evidenceForm.tipo === opt.value }"
                        @click="selectEvidenceTipo(opt.value)"
                      >
                        <i :class="opt.icon" />
                        <span>{{ opt.label }}</span>
                      </button>
                    </div>
                    <p v-else class="pj-muted hint">{{ t('events.evidenceTypesEmpty') }}</p>
                  </div>

                  <div class="field">
                    <label>{{ t('events.evidenceTitulo') }}</label>
                    <InputText v-model="evidenceForm.titulo" class="w-full" />
                  </div>

                  <div v-if="evidenceForm.tipo === 'link'" class="field">
                    <label>{{ t('events.evidenceUrl') }} *</label>
                    <InputText
                      v-model="evidenceForm.url"
                      class="w-full"
                      :placeholder="t('events.evidenceUrlPlaceholder')"
                    />
                  </div>

                  <div v-else class="field">
                    <MediaGalleryUpload
                      v-if="evidenceForm.tipo === 'imagen'"
                      :items="evidenceGalleryItems"
                      :max="1"
                      :subtitle="t('media.evidenceGallerySubtitle')"
                      @add="onEvidenceFiles"
                      @remove="clearPendingFile"
                    />
                    <MediaDocumentsUpload
                      v-else
                      :files="evidenceDocumentItems"
                      :accept="evidenceDocsAccept"
                      :max-bytes="EVIDENCE_MAX_BYTES"
                      :optimize-images="false"
                      :subtitle="t('media.evidenceDocsSubtitle')"
                      :hint="fileHint"
                      @add="onEvidenceFiles"
                      @remove="clearPendingFile"
                    />
                    <div class="field field--nested">
                      <label>{{ t('events.evidenceUrlOptional') }}</label>
                      <InputText
                        v-model="evidenceForm.url"
                        class="w-full"
                        :placeholder="t('events.evidenceUrlOptionalPlaceholder')"
                      />
                    </div>
                  </div>

                  <div class="field">
                    <label>{{ t('events.description') }}</label>
                    <Textarea v-model="evidenceForm.descripcion" rows="2" class="w-full" auto-resize />
                  </div>

                  <div v-if="livePreview" class="media-preview media-preview--live">
                    <p class="media-preview__label">{{ t('events.evidencePreview') }}</p>
                    <div v-if="livePreview.kind === 'youtube' || livePreview.kind === 'vimeo'" class="media-preview__embed">
                      <iframe
                        :src="livePreview.embedSrc"
                        title="video"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                      />
                    </div>
                    <img
                      v-else-if="livePreview.kind === 'image'"
                      :src="livePreview.src"
                      :alt="livePreview.title || t('events.evidencePreview')"
                      class="media-preview__image"
                    />
                    <video
                      v-else-if="livePreview.kind === 'video'"
                      :src="livePreview.src"
                      class="media-preview__video"
                      controls
                      preload="metadata"
                    />
                    <audio
                      v-else-if="livePreview.kind === 'audio'"
                      :src="livePreview.src"
                      class="media-preview__audio"
                      controls
                      preload="metadata"
                    />
                    <iframe
                      v-else-if="livePreview.kind === 'pdf'"
                      :src="livePreview.embedSrc || livePreview.src"
                      class="media-preview__pdf"
                      title="pdf"
                    />
                    <div v-else class="media-preview__link">
                      <div class="media-preview__link-icon">
                        <i class="pi pi-link" />
                      </div>
                      <div class="media-preview__link-body">
                        <strong>{{ livePreview.title || livePreview.host }}</strong>
                        <p class="pj-muted truncate">{{ livePreview.src }}</p>
                        <span v-if="livePreview.host" class="media-preview__host">{{ livePreview.host }}</span>
                        <a
                          :href="livePreview.src"
                          target="_blank"
                          rel="noopener"
                          class="media-preview__open"
                        >
                          {{ t('events.judgeOpenEvidence') }}
                          <i class="pi pi-external-link" />
                        </a>
                      </div>
                    </div>
                  </div>

                  <div class="form-actions">
                    <Button
                      v-if="latestEvidence"
                      type="button"
                      text
                      :label="t('common.cancel')"
                      @click="pendingFile = null; editingEvidence = false"
                    />
                    <Button
                      type="button"
                      icon="pi pi-save"
                      :label="t('events.saveEvidence')"
                      :loading="saving"
                      :disabled="!tipoOptions.length"
                      @click="addEvidence"
                    />
                  </div>
                </div>

                <ul v-if="selected.evidencias.length > 1" class="evidence-history">
                  <li v-for="ev in selected.evidencias.slice(1)" :key="ev.id">
                    <span>{{ ev.titulo || ev.tipo }}</span>
                    <Button
                      type="button"
                      icon="pi pi-trash"
                      text
                      rounded
                      severity="danger"
                      size="small"
                      @click="removeEvidence(ev)"
                    />
                  </li>
                </ul>
              </template>
            </section>
            </div>
          </template>
          <p v-else class="pj-muted empty">{{ t('events.selectSubevent') }}</p>
        </main>
      </div>
    </template>
  </section>
</template>

<style scoped>
.participate-page {
  --participate-sheet-gap: calc(4.75rem + env(safe-area-inset-top, 0px));
  gap: 1rem;
}

.summary-row {
  display: grid;
  grid-template-columns: 1.1fr 1fr 1fr;
  gap: 0.85rem;
}

.summary-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.85rem;
  padding: 1rem 1.1rem;
  border-radius: 16px;
  background: var(--pj-bg-elevated);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  box-shadow: 0 8px 24px color-mix(in srgb, #0f172a 4%, transparent);
}

.summary-card--club {
  justify-content: flex-start;
  overflow: visible;
  isolation: isolate;
}

.summary-card--club.has-cover {
  min-height: 7.25rem;
  color: var(--hero-text, #fff);
  background-image:
    var(--hero-overlay, linear-gradient(180deg, rgba(7, 18, 42, 0.28) 0%, rgba(7, 18, 42, 0.78) 100%)),
    var(--hero-image);
  background-size: cover;
  background-position: center;
  border-color: transparent;
}

.summary-card--club.has-cover h2 {
  color: var(--hero-text, #fff);
  text-shadow: 0 1px 12px color-mix(in srgb, var(--hero-chip-bg, rgba(15, 23, 42, 0.5)) 70%, transparent);
}

.summary-card--club.has-cover .pj-muted {
  color: var(--hero-muted, rgba(255, 255, 255, 0.86));
}

.summary-card--club.has-cover .chip-enrolled,
.summary-card--club.has-cover .chip-open {
  background: var(--hero-chip-bg, rgba(15, 23, 42, 0.48));
  border: 1px solid var(--hero-chip-border, rgba(255, 255, 255, 0.28));
  color: var(--hero-chip-text, #fff);
}

.club-hero__intro {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  min-width: 0;
}

.club-hero__copy {
  min-width: 0;
}

.club-hero__logo {
  width: 4.5rem;
  height: 4.5rem;
  flex: 0 0 auto;
  object-fit: cover;
  border-radius: 0.9rem;
  border: 3px solid #fff;
  background: #fff;
  box-shadow: 0 10px 22px rgba(15, 23, 42, 0.28);
}

.summary-card--club.has-cover.has-logo {
  position: relative;
  margin-bottom: 2.4rem;
  align-items: flex-end;
}

.summary-card--club.has-cover.has-logo .club-hero__intro {
  align-items: flex-end;
}

.summary-card--club.has-cover.has-logo .club-hero__copy {
  padding-left: calc(4.5rem + 0.85rem);
}

.summary-card--club.has-cover.has-logo .club-hero__logo {
  position: absolute;
  left: 1.1rem;
  bottom: 0;
  z-index: 2;
  margin: 0;
  transform: translateY(50%);
}

.summary-card h2,
.summary-card h3 {
  margin: 0 0 0.2rem;
  font-size: 1rem;
}

.summary-card__metric {
  margin: 0.15rem 0 0.55rem;
  font-size: 0.86rem;
  color: var(--pj-text-muted);
}

.summary-card__points {
  margin: 0.45rem 0 0;
  font-size: 0.78rem;
}

.club-avatar,
.detail-avatar {
  width: 3.1rem;
  height: 3.1rem;
  border-radius: 14px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: color-mix(in srgb, #ea580c 16%, transparent);
  color: #c2410c;
  font-size: 1.25rem;
  overflow: hidden;
}

.club-avatar img,
.detail-avatar img,
.eval-item__thumb img,
.detail-hero img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.eval-item__thumb {
  width: 2.35rem;
  height: 2.35rem;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
  background: color-mix(in srgb, var(--pj-border) 40%, transparent);
}

.detail-hero {
  margin-top: 0.95rem;
  border-radius: 14px;
  overflow: hidden;
  height: 11rem;
  background: color-mix(in srgb, var(--pj-border) 35%, transparent);
}

.chip-enrolled,
.chip-open {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin: 0.4rem 0 0;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 650;
}

.chip-enrolled {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.chip-open {
  background: color-mix(in srgb, #64748b 12%, transparent);
  color: #475569;
}

.bar {
  height: 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--pj-border) 70%, transparent);
  overflow: hidden;
}

.bar__fill {
  display: block;
  height: 100%;
  border-radius: inherit;
}

.bar__fill--green {
  background: linear-gradient(90deg, #22c55e, #16a34a);
}

.bar__fill--blue {
  background: linear-gradient(90deg, #3b82f6, #2563eb);
}

.ring {
  width: 4.2rem;
  height: 4.2rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.ring__inner {
  width: 3.15rem;
  height: 3.15rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: var(--pj-bg-elevated);
  font-weight: 800;
  font-size: 0.92rem;
  color: var(--pj-navy);
}

.participate-layout {
  display: grid;
  grid-template-columns: minmax(16rem, 0.95fr) minmax(0, 1.55fr);
  gap: 0.85rem;
  align-items: start;
}

.panel {
  background: var(--pj-bg-elevated);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 16px;
  box-shadow: 0 8px 24px color-mix(in srgb, #0f172a 4%, transparent);
  min-height: 28rem;
}

.panel--list {
  padding: 0.85rem;
}

.panel--detail {
  padding: 1.1rem 1.2rem 1.25rem;
}

.panel__head {
  margin-bottom: 0.65rem;
  padding: 0.15rem 0.35rem 0.65rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
}

.panel__head h2 {
  margin: 0;
  font-size: 1rem;
}

.panel__head p {
  margin: 0.2rem 0 0;
  font-size: 0.8rem;
}

.eval-item {
  width: 100%;
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.65rem;
  align-items: center;
  text-align: left;
  padding: 0.7rem 0.75rem;
  margin-bottom: 0.35rem;
  border-radius: 12px;
  border: 1px solid transparent;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.eval-item:hover {
  background: color-mix(in srgb, var(--pj-navy) 5%, transparent);
}

.eval-item.is-active {
  background: color-mix(in srgb, #2563eb 8%, transparent);
  border-color: color-mix(in srgb, #2563eb 28%, transparent);
}

.eval-item__icon {
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 0.95rem;
}

.eval-item__icon.is-scored {
  color: #15803d;
  background: color-mix(in srgb, #16a34a 14%, transparent);
}

.eval-item__icon.is-review {
  color: #c2410c;
  background: color-mix(in srgb, #ea580c 14%, transparent);
}

.eval-item__icon.is-pending {
  color: #64748b;
  background: color-mix(in srgb, #94a3b8 16%, transparent);
}

.eval-item__body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.eval-item__body strong {
  font-size: 0.88rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.eval-item__pts {
  font-size: 0.75rem;
  color: var(--pj-text-muted);
}

.status-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.18rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
}

.status-badge.is-scored {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.status-badge.is-review {
  background: color-mix(in srgb, #ea580c 14%, transparent);
  color: #c2410c;
}

.status-badge.is-pending {
  background: color-mix(in srgb, #94a3b8 16%, transparent);
  color: #475569;
}

.detail-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  flex-wrap: wrap;
  padding-bottom: 1rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
}

.detail-head__main {
  display: flex;
  gap: 0.85rem;
  min-width: 0;
}

.detail-head__title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.detail-head h2 {
  margin: 0;
  font-size: 1.25rem;
}

.detail-avatar {
  background: color-mix(in srgb, #2563eb 14%, transparent);
  color: #1d4ed8;
}

.detail-head__stats {
  display: flex;
  flex: 1 1 auto;
  align-items: stretch;
  align-self: stretch;
  gap: 0.45rem;
  min-width: 0;
}

.score-box {
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
  justify-content: center;
  align-self: stretch;
  min-width: 0;
  height: auto;
  padding: 0.4rem 0.55rem;
  border-radius: 10px;
  text-align: center;
  background: color-mix(in srgb, var(--pj-navy) 6%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-navy) 12%, transparent);
}

.score-box strong {
  display: block;
  font-size: 0.92rem;
  color: var(--pj-navy);
  line-height: 1.2;
}

.score-box span {
  font-size: 0.62rem;
  color: var(--pj-text-muted);
}

.score-box__avg {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.6rem;
  font-weight: 600;
  color: var(--pj-navy);
  opacity: 0.85;
}

.deadline-box {
  flex: 1 1 0;
  align-self: stretch;
  min-width: 0;
  height: auto;
  padding: 0.4rem 0.55rem;
  border-radius: 10px;
  text-align: center;
  background: color-mix(in srgb, #ea580c 8%, transparent);
  border: 1px solid color-mix(in srgb, #ea580c 28%, transparent);
  color: #c2410c;
}

.deadline-box__label {
  display: block;
  margin-bottom: 0.12rem;
  font-size: 0.6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  opacity: 0.9;
}

.deadline-box__digits {
  display: flex;
  justify-content: center;
  gap: 0.3rem;
}

.deadline-box__digits span {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 1.4rem;
}

.deadline-box__digits strong {
  font-size: 0.88rem;
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
}

.deadline-box__digits small {
  font-size: 0.55rem;
  font-weight: 650;
  opacity: 0.85;
}

.deadline-box__expired-text {
  display: block;
  font-size: 0.78rem;
}

.deadline-box--expired {
  background: color-mix(in srgb, #dc2626 10%, transparent);
  border-color: color-mix(in srgb, #dc2626 30%, transparent);
  color: #b91c1c;
}

.detail-section {
  margin-top: 1.1rem;
}

.detail-section h3 {
  margin: 0 0 0.65rem;
  font-size: 0.95rem;
}

.detail-section h4 {
  margin: 0.75rem 0 0.45rem;
  font-size: 0.85rem;
}

.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
  margin: 0;
}

.info-grid dt {
  font-size: 0.72rem;
  color: var(--pj-text-muted);
  margin-bottom: 0.15rem;
}

.info-grid dd {
  margin: 0;
  font-weight: 600;
  font-size: 0.9rem;
}

.req-list ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.req-list li {
  display: flex;
  gap: 0.55rem;
  padding: 0.55rem 0.65rem;
  border-radius: 10px;
  background: color-mix(in srgb, #16a34a 6%, transparent);
}

.req-list i {
  color: #16a34a;
  margin-top: 0.15rem;
}

.req-list strong {
  display: block;
  font-size: 0.88rem;
}

.req-list span {
  font-size: 0.75rem;
  color: var(--pj-text-muted);
}

.hint {
  margin: 0.35rem 0 0;
  font-size: 0.84rem;
}

.evidence-preview {
  display: grid;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  background: color-mix(in srgb, var(--pj-bg) 70%, transparent);
}

.evidence-preview__top {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
}

.evidence-preview__meta {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: 0;
}

.evidence-preview__actions {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  align-items: flex-end;
}

.tipo-chip {
  align-self: flex-start;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.tipo-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.tipo-option {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.75rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 85%, transparent);
  background: color-mix(in srgb, var(--pj-surface, #fff) 92%, transparent);
  color: var(--pj-text, #1f2937);
  font-size: 0.84rem;
  font-weight: 600;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.tipo-option i {
  font-size: 0.95rem;
}

.tipo-option:hover {
  border-color: color-mix(in srgb, #2563eb 45%, var(--pj-border));
}

.tipo-option--active {
  border-color: #2563eb;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.file-drop {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  border-radius: 12px;
  border: 1px dashed color-mix(in srgb, #2563eb 40%, var(--pj-border));
  background: color-mix(in srgb, #2563eb 6%, transparent);
  cursor: pointer;
}

.file-drop > i {
  font-size: 1.35rem;
  color: #2563eb;
}

.file-drop strong {
  display: block;
  font-size: 0.9rem;
}

.file-drop p {
  margin: 0.15rem 0 0;
  font-size: 0.78rem;
}

.field--nested {
  margin-top: 0.65rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.media-preview {
  display: grid;
  gap: 0.45rem;
  margin-top: 0.35rem;
  padding: 0.75rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: color-mix(in srgb, var(--pj-bg) 70%, transparent);
}

.media-preview--live {
  margin-top: 0;
}

.media-preview__label {
  margin: 0;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: color-mix(in srgb, var(--pj-muted, #64748b) 90%, transparent);
}

.media-preview__embed {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  overflow: hidden;
  background: #0f172a;
}

.media-preview__embed iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.media-preview__image,
.media-preview__video {
  display: block;
  width: 100%;
  max-height: 240px;
  object-fit: contain;
  border-radius: 10px;
  background: color-mix(in srgb, #0f172a 8%, transparent);
}

.media-preview__audio {
  width: 100%;
}

.media-preview__pdf {
  width: 100%;
  height: 220px;
  border: 0;
  border-radius: 10px;
  background: #fff;
}

.media-preview__link {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.media-preview__link-body {
  min-width: 0;
  display: grid;
  gap: 0.15rem;
}

.media-preview__link-icon {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 10px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #2563eb;
}

.media-preview__host {
  display: inline-block;
  font-size: 0.72rem;
  font-weight: 650;
  color: #2563eb;
}

.media-preview__open {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.2rem;
  font-size: 0.82rem;
  font-weight: 650;
  color: #1d4ed8;
}

.evidence-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.84rem;
  word-break: break-all;
}

.evidence-form {
  display: grid;
  gap: 0.7rem;
}

.field-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.7rem;
}

.field label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.8rem;
  font-weight: 650;
}

.link-preview {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  padding: 0.75rem 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, var(--pj-bg) 75%, transparent);
  border: 1px dashed color-mix(in srgb, var(--pj-border) 80%, transparent);
}

.link-preview__thumb {
  width: 3rem;
  height: 3rem;
  border-radius: 10px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #2563eb;
}

.truncate {
  margin: 0.15rem 0 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 28rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.45rem;
}

.evidence-history {
  list-style: none;
  margin: 0.85rem 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.evidence-history li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.4rem 0.55rem;
  border-radius: 8px;
  background: color-mix(in srgb, var(--pj-bg) 70%, transparent);
  font-size: 0.82rem;
}

.empty {
  padding: 1.5rem 0.75rem;
  text-align: center;
}

@media (max-width: 1100px) {
  .summary-row {
    grid-template-columns: 1fr;
  }

  .participate-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 900px) {
  .participate-layout {
    display: block;
  }

  .panel,
  .panel--list {
    min-height: 0;
  }

  .detail-sheet-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1080;
    background: rgb(15 23 42 / 0.38);
  }

  .panel--detail.is-sheet {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1081;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: calc(100dvh - var(--participate-sheet-gap));
    max-height: calc(100dvh - var(--participate-sheet-gap));
    margin: 0;
    padding: 0.55rem 1.05rem calc(1rem + env(safe-area-inset-bottom, 0px));
    overflow: hidden;
    border-radius: 18px 18px 0 0;
    box-shadow: 0 -10px 32px rgb(15 23 42 / 0.22);
    transform: translateY(110%);
    transition: transform 0.28s ease;
    pointer-events: none;
  }

  .panel--detail.is-sheet-open {
    transform: translateY(0);
    pointer-events: auto;
  }

  .panel--detail.is-sheet .detail-head {
    flex: 0 0 auto;
    padding-right: 2.6rem;
    background: var(--pj-bg-elevated);
    z-index: 3;
  }

  .panel--detail.is-sheet .detail-sheet__body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  .detail-sheet__handle {
    width: 2.6rem;
    height: 0.28rem;
    margin: 0.15rem auto 0.45rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--pj-border, #cbd5e1) 80%, #94a3b8);
    flex-shrink: 0;
  }

  .detail-sheet__close {
    position: absolute;
    top: 0.55rem;
    right: 0.75rem;
    z-index: 20;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: var(--pj-bg-elevated);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--pj-border, #cbd5e1) 80%, transparent);
    color: #1e3a8a;
    cursor: pointer;
    flex-shrink: 0;
  }
}

@media (max-width: 720px) {
  .field-grid,
  .info-grid {
    grid-template-columns: 1fr;
  }

  .detail-head,
  .evidence-preview__top {
    flex-direction: column;
  }

  .detail-head__stats {
    flex: 0 0 auto;
    width: 100%;
  }

  .evidence-preview__actions {
    align-items: stretch;
  }
}
</style>
