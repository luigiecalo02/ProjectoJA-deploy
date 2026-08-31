<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import InputNumber from 'primevue/inputnumber'
import ToggleSwitch from 'primevue/toggleswitch'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import EventOrganizationsStep from '@/components/events/EventOrganizationsStep.vue'
import type { EventAudienceKey } from '@/components/events/EventOrganizationsStep.vue'
import EventSubeventsStep from '@/components/events/EventSubeventsStep.vue'
import EventTerrenoStep from '@/components/events/EventTerrenoStep.vue'
import EventCabanasStep from '@/components/events/EventCabanasStep.vue'
import CategoriaSubeventosAdminDrawer from '@/components/events/CategoriaSubeventosAdminDrawer.vue'
import CriteriosEvaluacionAdminDrawer from '@/components/events/CriteriosEvaluacionAdminDrawer.vue'
import { eventsService } from '@/services/eventsService'
import MediaCoverUpload from '@/components/media/MediaCoverUpload.vue'
import MediaProfileUpload from '@/components/media/MediaProfileUpload.vue'
import EventBannerCard from '@/components/events/EventBannerCard.vue'
import { clubsService } from '@/services/clubsService'
import { organizacionesService } from '@/services/organizacionesService'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { cuentasBancariasService } from '@/services/cuentasBancariasService'
import { lugaresService } from '@/services/lugaresService'
import { useAuthStore } from '@/stores/auth'
import { usePageChrome } from '@/composables/usePageChrome'
import type { OrganizacionTreeNode, TipoOrganizacion } from '@/modules/organizaciones/types'
import {
  TIPO_AVENTUREROS,
  TIPO_CONQUISTADORES,
  TIPO_GUIAS_MAYORES,
} from '@/modules/organizaciones/types'
import { audienceKeyFromTipo } from '@/modules/events/audienceTipo'
import type { Club } from '@/modules/clubs/types'
import type { CuentaBancaria } from '@/modules/settings/types'
import type { Lugar } from '@/modules/lugares/types'
import type {
  ClubEvent,
  EventFormPayload,
  EventoDescuentoDirectiva,
  EventoVisibilidad,
  ProductoServicio,
  TipoSeguro,
} from '@/modules/events/types'
import { dateOnly, toApiDateOrEmpty as toApiDate } from '@/modules/events/dateUtils'

type ClubAudienceKey = EventAudienceKey
type WizardStep = 'basica' | 'organizaciones' | 'configuracion' | 'terreno' | 'alojamiento' | 'subeventos' | 'revision'

const DEFAULT_DESCUENTOS_DIRECTIVA: EventoDescuentoDirectiva[] = [
  { codigo: 'director', nombre: 'Director', porcentaje: 100 },
  { codigo: 'economia', nombre: 'Economía', porcentaje: 50 },
  { codigo: 'hermano_2', nombre: 'Hermano 2', porcentaje: 25 },
  { codigo: 'hermano_3', nombre: 'Hermano 3', porcentaje: 33.3 },
  { codigo: 'hermano_4', nombre: 'Hermano 4', porcentaje: 37 },
]

function slugCodigo(nombre: string): string {
  return nombre
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '')
    .slice(0, 64) || `cargo_${Date.now()}`
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const loading = ref(false)
const saving = ref(false)
const uploading = ref(false)
const errorMessage = ref('')
const persistedId = ref<number | null>(null)
const currentStep = ref<WizardStep>('basica')
const orgOptions = ref<Array<{ id: number; label: string }>>([])
const orgTree = ref<OrganizacionTreeNode[]>([])
const clubsCatalog = ref<Club[]>([])
const tipoOptions = ref<TipoOrganizacion[]>([])
const clubAudience = ref<ClubAudienceKey[]>(['libre'])
const pendingImage = ref<File | null>(null)
const pendingPreview = ref<string | null>(null)
const pendingBanner = ref<File | null>(null)
const pendingBannerPreview = ref<string | null>(null)
const categoriesAdminVisible = ref(false)
const criteriosAdminVisible = ref(false)
const configTab = ref<'inscripcion' | 'descuentos' | 'seguro' | 'categorias' | 'criterios' | 'servicios'>(
  'inscripcion',
)
const categoriasVersion = ref(0)
const previewVisible = ref(
  typeof localStorage === 'undefined' ? true : localStorage.getItem('pj.eventWizardPreview') !== '0',
)

const tiposSeguroOptions = ref<TipoSeguro[]>([])
const cuentasBancarias = ref<CuentaBancaria[]>([])
const cuentaBancariaOptions = computed(() =>
  cuentasBancarias.value.map((item) => ({
    label: `${item.nombre} · ${item.numero_cuenta}`,
    value: item.id,
  })),
)
const productosCatalog = ref<ProductoServicio[]>([])
const serviceOffers = ref<
  Array<{
    producto_servicio_id: number
    nombre: string
    tipo: string
    precio_catalogo: number | null
    precio: number
    activo: boolean
  }>
>([])
const serviceToAddId = ref<number | null>(null)
const savingServices = ref(false)

const availableCatalogProducts = computed(() => {
  const used = new Set(serviceOffers.value.map((row) => row.producto_servicio_id))
  return productosCatalog.value
    .filter((p) => p.activo !== false && !used.has(p.id))
    .map((p) => ({
      label: `${p.nombre} (${p.tipo})`,
      value: p.id,
      precio: p.precio ?? 0,
      nombre: p.nombre,
      tipo: p.tipo,
    }))
})

watch(previewVisible, (visible) => {
  try {
    localStorage.setItem('pj.eventWizardPreview', visible ? '1' : '0')
  } catch {
    /* ignore */
  }
})

const form = reactive({
  name: '',
  descripcion: '',
  lugar: '',
  lugar_id: null as number | null,
  usar_lotes: false,
  usar_cabanas: false,
  starts_at: null as Date | null,
  ends_at: null as Date | null,
  is_active: true,
  estado: 'borrador' as string,
  visibilidad: 'organizacion' as EventoVisibilidad,
  organizacion_id: null as number | null,
  organizacion_ids: [] as number[],
  tipo_organizacion_ids: [] as number[],
  es_en_sitio: true,
  es_calificable: false,
  puntaje_maximo: null as number | null,
  requiere_pago: false,
  precio: null as number | null,
  precio_fuera_tiempo: null as number | null,
  precio_acompanante: null as number | null,
  precio_acompanante_fuera_tiempo: null as number | null,
  precio_acompanante_menor: null as number | null,
  precio_acompanante_menor_fuera_tiempo: null as number | null,
  precio_directiva: null as number | null,
  precio_directiva_fuera_tiempo: null as number | null,
  descuentos_directiva: [] as EventoDescuentoDirectiva[],
  fecha_limite_pago: null as Date | null,
  metodo_pago: '' as string | null,
  cuenta_bancaria_id: null as number | null,
  requiere_seguro: false,
  tipo_seguro_id: null as number | null,
  seguro_valor: null as number | null,
  seguro_fecha_inicio: null as Date | null,
  seguro_fecha_fin: null as Date | null,
  cupo_minimo: null as number | null,
  cupo_maximo: null as number | null,
  cupo_ilimitado: true,
  cupo_max_organizacion: null as number | null,
  cupo_max_club: null as number | null,
  cupo_max_iglesia: null as number | null,
  permite_inscripcion_individual: true,
  permite_inscripcion_organizacion: false,
  permite_inscripcion_club: true,
  permite_inscripcion_iglesia: false,
  fecha_limite_inscripcion: null as Date | null,
  puntos_inscripcion_a_tiempo: null as number | null,
  puntos_inscripcion_fuera_tiempo: null as number | null,
  image_url: null as string | null,
  banner_url: null as string | null,
})

const selectedCuentaBancaria = computed(
  () => cuentasBancarias.value.find((item) => item.id === form.cuenta_bancaria_id) ?? null,
)
const lugares = ref<Lugar[]>([])
const lugarOptions = computed(() =>
  lugares.value.map((item) => ({ label: item.nombre, value: item.id })),
)
const selectedLugar = computed(() => lugares.value.find((item) => item.id === form.lugar_id) ?? null)

const terrenoSummary = ref<{ terrenoNombre: string | null; lotes: number; capacidad: number }>({
  terrenoNombre: null,
  lotes: 0,
  capacidad: 0,
})
const cabanasSummary = ref({ cabanas: 0, capacidad: 0 })

const steps = computed(() => {
  const all: Array<{ key: WizardStep; label: string; icon: string }> = [
    { key: 'basica', label: t('events.wizard.stepBasic'), icon: 'pi pi-calendar' },
    { key: 'organizaciones', label: t('events.wizard.stepOrgs'), icon: 'pi pi-sitemap' },
    { key: 'configuracion', label: t('events.wizard.stepConfig'), icon: 'pi pi-cog' },
  ]
  if (form.usar_lotes) {
    all.push({ key: 'terreno', label: t('events.wizard.stepTerreno'), icon: 'pi pi-map' })
  }
  if (form.usar_cabanas) {
    all.push({ key: 'alojamiento', label: t('events.wizard.stepCabanas'), icon: 'pi pi-building' })
  }
  all.push(
    { key: 'subeventos', label: t('events.wizard.stepSubevents'), icon: 'pi pi-share-alt' },
    { key: 'revision', label: t('events.wizard.stepReview'), icon: 'pi pi-check-circle' },
  )
  return all
})

const stepIndex = computed(() => steps.value.findIndex((s) => s.key === currentStep.value))
const isFirstStep = computed(() => stepIndex.value <= 0)
const isLastStep = computed(() => stepIndex.value >= steps.value.length - 1)

watch(
  () => form.lugar_id,
  (id) => {
    const lugar = lugares.value.find((item) => item.id === id)
    if (lugar) form.lugar = lugar.nombre
    if (!id) {
      form.usar_lotes = false
      form.usar_cabanas = false
    }
  },
)

watch(steps, (list) => {
  if (list.some((step) => step.key === currentStep.value) || !list.length) return
  currentStep.value = list.find((step) => step.key === 'configuracion')?.key ?? list[0].key
})
const isEditMode = computed(() => persistedId.value != null)

const clubAudienceOptions = computed(() => [
  { key: 'libre' as const, label: t('events.audienceLibre'), css: 'badge--all' },
  { key: 'conquistadores' as const, label: t('events.audienceConquistadores'), css: 'badge--conquistadores' },
  { key: 'aventureros' as const, label: t('events.audienceAventureros'), css: 'badge--aventureros' },
  { key: 'guias_mayores' as const, label: t('events.audienceGuias'), css: 'badge--guias' },
])

const visibilityOptions = computed(() => [
  { label: t('events.visibilityPublic'), value: 'publico' as const },
  { label: t('events.visibilityOrganization'), value: 'organizacion' as const },
  { label: t('events.visibilityPrivate'), value: 'privado' as const },
])

const audienceLabel = computed(() => {
  if (clubAudience.value.includes('libre') || !clubAudience.value.length) {
    return t('events.audienceLibre')
  }
  return clubAudience.value
    .map((key) => clubAudienceOptions.value.find((o) => o.key === key)?.label || key)
    .join(', ')
})

const pageTitle = computed(() =>
  isEditMode.value ? t('events.edit') : t('events.wizard.createTitle'),
)
const pageSubtitle = computed(() => t('events.wizard.createSubtitle'))

usePageChrome(() => ({
  title: pageTitle.value,
  subtitle: pageSubtitle.value,
  backTo: { name: 'events' },
  actions: [
    {
      key: 'draft',
      label: t('events.wizard.saveDraft'),
      outlined: true,
      loading: saving.value || uploading.value,
      disabled: loading.value,
      onClick: () => void saveDraft(),
    },
    {
      key: 'publish',
      label: t('events.wizard.publish'),
      icon: 'pi pi-send',
      loading: saving.value || uploading.value,
      disabled: loading.value,
      onClick: () => void publishEvent(),
    },
  ],
}))
const imagePreview = computed(
  () => pendingPreview.value || resolveFileUrl(form.image_url) || form.image_url,
)
const bannerPreview = computed(
  () => pendingBannerPreview.value || resolveFileUrl(form.banner_url) || form.banner_url,
)
const descCount = computed(() => form.descripcion.length)
const descMax = 200

const previewDates = computed(() => {
  if (!form.starts_at && !form.ends_at) return t('events.wizard.previewDatesPending')
  const fmt = (d: Date | null) =>
    d
      ? d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' })
      : '—'
  return `${fmt(form.starts_at)} – ${fmt(form.ends_at)}`
})

const previewCupo = computed(() => {
  if (form.cupo_ilimitado) return t('events.wizard.cupoIlimitado')
  if (form.cupo_maximo) return String(form.cupo_maximo)
  return t('events.wizard.previewPending')
})

const previewEnrollment = computed(() => {
  if (!form.requiere_pago || form.precio == null) return '—'
  return Number(form.precio).toLocaleString('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  })
})

const tips = computed(() => [
  t('events.wizard.tip1'),
  t('events.wizard.tip2'),
  t('events.wizard.tip3'),
  t('events.wizard.tip4'),
])

const tipoSeguroSelectOptions = computed(() =>
  tiposSeguroOptions.value
    .filter((tipo) => tipo.activo !== false)
    .map((tipo) => ({ label: tipo.nombre, value: tipo.id })),
)

watch(
  () => route.params.id,
  (id) => {
    const n = Number(id)
    persistedId.value = Number.isFinite(n) && n > 0 ? n : null
  },
  { immediate: true },
)

function resolveClubTipoId(key: Exclude<ClubAudienceKey, 'libre'>): number | null {
  const matchers: Record<Exclude<ClubAudienceKey, 'libre'>, (nombre: string) => boolean> = {
    conquistadores: (n) => n.includes('conquistador'),
    aventureros: (n) => n.includes('aventurero'),
    guias_mayores: (n) => n.includes('guía') || n.includes('guia'),
  }
  const fallback: Record<Exclude<ClubAudienceKey, 'libre'>, number> = {
    conquistadores: TIPO_CONQUISTADORES,
    aventureros: TIPO_AVENTUREROS,
    guias_mayores: TIPO_GUIAS_MAYORES,
  }
  const found = tipoOptions.value.find((tipo) => matchers[key]((tipo.nombre || '').toLowerCase()))
  return found?.id ?? fallback[key]
}

function syncTipoIdsFromAudience(): void {
  if (clubAudience.value.includes('libre') || clubAudience.value.length === 0) {
    form.tipo_organizacion_ids = []
    return
  }
  form.tipo_organizacion_ids = clubAudience.value
    .filter((k): k is Exclude<ClubAudienceKey, 'libre'> => k !== 'libre')
    .map((k) => resolveClubTipoId(k))
    .filter((id): id is number => id != null)
}

function currentAudiencia(): Exclude<ClubAudienceKey, never> {
  return clubAudience.value.includes('libre') || !clubAudience.value.length
    ? 'libre'
    : clubAudience.value[0]
}

function applyAudienceFromEvent(event: {
  tipo_organizacion_ids?: number[]
  tipos_organizacion?: Array<{ id: number; nombre: string }>
}): void {
  form.tipo_organizacion_ids = [...(event.tipo_organizacion_ids || [])]
  if (event.tipos_organizacion?.length) {
    const keys = event.tipos_organizacion
      .map((tipo) => audienceKeyFromTipo(tipo.id, tipo.nombre))
      .filter((key): key is Exclude<ClubAudienceKey, 'libre'> => key != null)
    clubAudience.value = keys.length ? [...new Set(keys)] : ['libre']
    return
  }
  clubAudience.value = audienceFromTipoIds(form.tipo_organizacion_ids)
}

function toggleAudience(key: ClubAudienceKey): void {
  if (key === 'libre' || clubAudience.value.includes(key)) {
    clubAudience.value = ['libre']
  } else {
    clubAudience.value = [key]
  }
  syncTipoIdsFromAudience()
  if (persistedId.value) {
    void persistAudience()
  }
}

async function persistAudience(): Promise<void> {
  if (!persistedId.value) return
  try {
    const saved = await persistEvent(form.estado === 'publicado' ? 'publicado' : 'borrador')
    applyAudienceFromEvent(saved)
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
}

function audienceFromTipoIds(ids: number[]): ClubAudienceKey[] {
  if (!ids.length) return ['libre']
  const keys = ids
    .map((id) => {
      const tipo = tipoOptions.value.find((item) => item.id === id)
      return audienceKeyFromTipo(id, tipo?.nombre)
    })
    .filter((key): key is Exclude<ClubAudienceKey, 'libre'> => key != null)
  return keys.length ? [...new Set(keys)] : ['libre']
}

function applyHomeOrganization(): void {
  const homeId = auth.contexto?.organizacion_id ?? null
  if (!homeId) return
  if (form.organizacion_id == null) form.organizacion_id = homeId
  if (form.organizacion_ids.length === 0) form.organizacion_ids = [homeId]
}

function flattenOrgs(nodes: OrganizacionTreeNode[], depth = 0): Array<{ id: number; label: string }> {
  const rows: Array<{ id: number; label: string }> = []
  for (const node of nodes) {
    const prefix = depth > 0 ? `${'— '.repeat(depth)}` : ''
    rows.push({
      id: node.id,
      label: `${prefix}${node.nombre}${node.tipo_nombre ? ` · ${node.tipo_nombre}` : ''}`,
    })
    if (node.children?.length) {
      rows.push(...flattenOrgs(node.children, depth + 1))
    }
  }
  return rows
}

function onTerrenoSummary(payload: { terrenoNombre: string | null; lotes: number; capacidad: number }): void {
  terrenoSummary.value = payload
}

function applyTerrenoCupo(capacidad: number): void {
  if (capacidad <= 0) return
  form.cupo_ilimitado = false
  form.cupo_maximo = capacidad
  toast.add({
    severity: 'success',
    summary: t('common.success'),
    detail: t('events.wizard.terrenoCupoApplied', { n: capacidad }),
    life: 2800,
  })
}

function provisionalDates(): { starts_at: string; ends_at: string } {
  const start = new Date()
  start.setDate(start.getDate() + 7)
  start.setHours(0, 0, 0, 0)
  const end = new Date(start)
  end.setDate(end.getDate() + 1)
  end.setHours(0, 0, 0, 0)
  return { starts_at: toApiDate(start), ends_at: toApiDate(end) }
}

function goStep(key: WizardStep): void {
  currentStep.value = key
  errorMessage.value = ''
}

function prevStep(): void {
  if (isFirstStep.value) return
  goStep(steps.value[stepIndex.value - 1].key)
}

async function nextStep(): Promise<void> {
  if (currentStep.value === 'basica' && !form.name.trim()) {
    errorMessage.value = t('events.wizard.nameRequired')
    return
  }
  if (!isLastStep.value) {
    await saveDraft({ silent: true, advance: true })
  }
}

function revokeUrl(url: string | null): void {
  if (url) URL.revokeObjectURL(url)
}

function persistedMediaUrl(url: string | null | undefined): string | null {
  if (!url || url.startsWith('blob:') || url.startsWith('data:')) return null
  return url
}

function applyServerMedia(event: ClubEvent): void {
  if (event.image_url) form.image_url = event.image_url
  if (event.banner_url) form.banner_url = event.banner_url
}

async function persistPickedMedia(): Promise<void> {
  if (!persistedId.value) {
    await persistEvent(form.estado === 'publicado' ? 'publicado' : 'borrador')
    return
  }
  if (pendingImage.value) await uploadPendingImage(persistedId.value)
  if (pendingBanner.value) await uploadPendingBanner(persistedId.value)
}

async function onPickImage(file: File): Promise<void> {
  if (!file) return
  pendingImage.value = file
  revokeUrl(pendingPreview.value)
  pendingPreview.value = URL.createObjectURL(file)
  try {
    await persistPickedMedia()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
}

async function onPickBanner(file: File): Promise<void> {
  if (!file) return
  pendingBanner.value = file
  revokeUrl(pendingBannerPreview.value)
  pendingBannerPreview.value = URL.createObjectURL(file)
  try {
    await persistPickedMedia()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
}

function buildPayload(estado: string): EventFormPayload {
  syncTipoIdsFromAudience()
  const dates =
    form.starts_at && form.ends_at
      ? { starts_at: toApiDate(form.starts_at), ends_at: toApiDate(form.ends_at) }
      : provisionalDates()

  return {
    name: form.name.trim() || t('events.wizard.untitled'),
    descripcion: form.descripcion.trim() || null,
    lugar: selectedLugar.value?.nombre ?? (form.lugar.trim() || null),
    lugar_id: form.lugar_id,
    usar_lotes: form.usar_lotes,
    usar_cabanas: form.usar_cabanas,
    starts_at: dates.starts_at,
    ends_at: dates.ends_at,
    is_active: form.is_active,
    estado,
    visibilidad: form.visibilidad,
    organizacion_id: form.organizacion_id ?? auth.contexto?.organizacion_id ?? null,
    tipo_evento_id: null,
    organizacion_ids:
      form.organizacion_ids.length > 0
        ? [...form.organizacion_ids]
        : auth.contexto?.organizacion_id
          ? [auth.contexto.organizacion_id]
          : [],
    tipo_organizacion_ids: [...form.tipo_organizacion_ids],
    audiencia: currentAudiencia(),
    es_en_sitio: form.es_en_sitio,
    es_calificable: form.es_calificable,
    puntaje_maximo: form.es_calificable ? form.puntaje_maximo : null,
    requiere_pago: form.requiere_pago,
    precio: form.requiere_pago ? form.precio : null,
    precio_fuera_tiempo: form.requiere_pago ? form.precio_fuera_tiempo : null,
    precio_acompanante: form.requiere_pago ? form.precio_acompanante : null,
    precio_acompanante_fuera_tiempo: form.requiere_pago
      ? form.precio_acompanante_fuera_tiempo
      : null,
    precio_acompanante_menor: form.requiere_pago ? form.precio_acompanante_menor : null,
    precio_acompanante_menor_fuera_tiempo: form.requiere_pago
      ? form.precio_acompanante_menor_fuera_tiempo
      : null,
    precio_directiva: form.requiere_pago ? form.precio_directiva : null,
    precio_directiva_fuera_tiempo: form.requiere_pago
      ? form.precio_directiva_fuera_tiempo
      : null,
    descuentos_directiva: form.requiere_pago
      ? form.descuentos_directiva
          .filter((d) => d.nombre.trim())
          .map((d) => ({
            codigo: d.codigo.trim() || slugCodigo(d.nombre),
            nombre: d.nombre.trim(),
            porcentaje: Math.max(0, Math.min(100, Number(d.porcentaje) || 0)),
          }))
      : [],
    fecha_limite_pago:
      form.requiere_pago && form.fecha_limite_pago ? toApiDate(form.fecha_limite_pago) : null,
    metodo_pago: form.requiere_pago ? form.metodo_pago : null,
    cuenta_bancaria_id: form.requiere_pago ? form.cuenta_bancaria_id : null,
    requiere_seguro: form.requiere_seguro,
    tipo_seguro_id: form.requiere_seguro ? form.tipo_seguro_id : null,
    seguro_valor: form.requiere_seguro ? form.seguro_valor : null,
    seguro_fecha_inicio:
      form.requiere_seguro && form.seguro_fecha_inicio
        ? toApiDate(form.seguro_fecha_inicio)
        : null,
    seguro_fecha_fin:
      form.requiere_seguro && form.seguro_fecha_fin ? toApiDate(form.seguro_fecha_fin) : null,
    cupo_minimo: form.cupo_ilimitado ? null : form.cupo_minimo,
    cupo_maximo: form.cupo_ilimitado ? null : form.cupo_maximo,
    cupo_ilimitado: form.cupo_ilimitado,
    cupo_max_organizacion: form.cupo_max_organizacion,
    cupo_max_club: form.cupo_max_club,
    cupo_max_iglesia: form.cupo_max_iglesia,
    permite_inscripcion_individual: form.permite_inscripcion_individual,
    permite_inscripcion_organizacion: form.permite_inscripcion_organizacion,
    permite_inscripcion_club: form.permite_inscripcion_club,
    permite_inscripcion_iglesia: form.permite_inscripcion_iglesia,
    fecha_limite_inscripcion: form.fecha_limite_inscripcion
      ? toApiDate(form.fecha_limite_inscripcion)
      : null,
    puntos_inscripcion_a_tiempo: form.puntos_inscripcion_a_tiempo,
    puntos_inscripcion_fuera_tiempo: form.puntos_inscripcion_fuera_tiempo,
    ...(persistedMediaUrl(form.image_url)
      ? { image_url: persistedMediaUrl(form.image_url) }
      : {}),
    ...(persistedMediaUrl(form.banner_url)
      ? { banner_url: persistedMediaUrl(form.banner_url) }
      : {}),
  }
}

async function uploadPendingImage(id: number): Promise<void> {
  if (!pendingImage.value) return
  uploading.value = true
  try {
    const updated = await eventsService.uploadImage(id, pendingImage.value)
    applyServerMedia(updated)
    if (updated.image_url) {
      pendingImage.value = null
    }
  } finally {
    uploading.value = false
  }
}

async function uploadPendingBanner(id: number): Promise<void> {
  if (!pendingBanner.value) return
  uploading.value = true
  try {
    const updated = await eventsService.uploadBanner(id, pendingBanner.value)
    applyServerMedia(updated)
    if (updated.banner_url) {
      pendingBanner.value = null
    }
  } finally {
    uploading.value = false
  }
}

async function persistEvent(estado: string): Promise<ClubEvent> {
  const payload = buildPayload(estado)
  const hadPendingMedia = Boolean(pendingImage.value || pendingBanner.value)
  let saved: ClubEvent
  if (persistedId.value) {
    saved = await eventsService.update(persistedId.value, payload)
    if (pendingImage.value) await uploadPendingImage(persistedId.value)
    if (pendingBanner.value) await uploadPendingBanner(persistedId.value)
  } else {
    saved = await eventsService.create(payload)
    persistedId.value = saved.id
    if (pendingImage.value) await uploadPendingImage(saved.id)
    if (pendingBanner.value) await uploadPendingBanner(saved.id)
    await router.replace({ name: 'events.edit', params: { id: saved.id } })
  }

  if (hadPendingMedia && persistedId.value) {
    saved = await eventsService.get(persistedId.value)
  }
  applyServerMedia(saved)
  applyAudienceFromEvent(saved)
  if (!form.starts_at && saved.starts_at) form.starts_at = dateOnly(saved.starts_at)
  if (!form.ends_at && saved.ends_at) form.ends_at = dateOnly(saved.ends_at)
  form.estado = saved.estado || estado
  return saved
}

async function saveDraft(opts: { silent?: boolean; advance?: boolean } = {}): Promise<boolean> {
  if (!form.name.trim() && currentStep.value === 'basica') {
    errorMessage.value = t('events.wizard.nameRequired')
    return false
  }
  if (!form.name.trim()) {
    errorMessage.value = t('events.wizard.nameRequired')
    return false
  }

  saving.value = true
  errorMessage.value = ''
  try {
    form.estado = 'borrador'
    await persistEvent('borrador')
    if (!opts.silent) {
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.draftSaved'),
        life: 2200,
      })
    }
    if (opts.advance && !isLastStep.value) {
      goStep(steps.value[stepIndex.value + 1].key)
    }
    return true
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    return false
  } finally {
    saving.value = false
  }
}

function validateForPublish(): string | null {
  if (!form.name.trim()) return t('events.wizard.nameRequired')
  if (!form.starts_at || !form.ends_at) return t('events.wizard.datesRequired')
  if (form.ends_at.getTime() < form.starts_at.getTime()) return t('events.dateOrder')
  return null
}

async function publishEvent(): Promise<void> {
  const validationError = validateForPublish()
  if (validationError) {
    errorMessage.value = validationError
    if (!form.starts_at || !form.ends_at) currentStep.value = 'basica'
    return
  }

  saving.value = true
  errorMessage.value = ''
  try {
    form.estado = 'publicado'
    form.is_active = true
    await persistEvent('publicado')
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.publishSuccess'),
      life: 2500,
    })
    await router.push({ name: 'events' })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

function addDescuentoDirectiva(): void {
  form.descuentos_directiva.push({
    codigo: `cargo_${form.descuentos_directiva.length + 1}`,
    nombre: '',
    porcentaje: 0,
  })
}

function removeDescuentoDirectiva(index: number): void {
  form.descuentos_directiva.splice(index, 1)
}

function onDescuentoNombreBlur(row: EventoDescuentoDirectiva): void {
  if (!row.codigo || row.codigo.startsWith('cargo_')) {
    row.codigo = slugCodigo(row.nombre)
  }
}

function formatDescuentoPreview(porcentaje: number | null | undefined): string {
  const base = Number(form.precio_directiva ?? form.precio ?? 0)
  const pct = Math.max(0, Math.min(100, Number(porcentaje) || 0))
  const valor = Math.round(base * (1 - pct / 100) * 100) / 100
  return t('events.directiveDiscountPays', { value: valor.toLocaleString('es-CO') })
}

watch(
  () => form.requiere_pago,
  (on) => {
    if (on && form.descuentos_directiva.length === 0) {
      form.descuentos_directiva = DEFAULT_DESCUENTOS_DIRECTIVA.map((d) => ({ ...d }))
    }
  },
)

async function loadCatalogs(): Promise<void> {
  const [tree, tipos, clubsPage, tiposSeguro, productos, cuentas, lugaresPage] = await Promise.all([
    organizacionesService.tree(),
    organizacionesService.tipos(),
    clubsService.list({ per_page: 500, is_active: true }),
    eventsService.tiposSeguro(),
    eventsService.productosServicios(),
    cuentasBancariasService.list({ activas: true }).catch(() => [] as CuentaBancaria[]),
    lugaresService.list({ per_page: 200, estado: 'activo' }).catch(() => ({ items: [] as Lugar[], pagination: null })),
  ])
  orgTree.value = tree
  orgOptions.value = flattenOrgs(tree)
  tipoOptions.value = tipos
  clubsCatalog.value = clubsPage.items
  tiposSeguroOptions.value = tiposSeguro
  productosCatalog.value = productos
  cuentasBancarias.value = cuentas
  lugares.value = lugaresPage.items
  applyHomeOrganization()
}

async function loadServiceOffers(): Promise<void> {
  if (!persistedId.value) return
  const ofertas = await eventsService.eventProductosServicios(persistedId.value)
  serviceOffers.value = ofertas
    .filter((o) => o.activo !== false)
    .map((o) => ({
      producto_servicio_id: o.producto_servicio_id,
      nombre: o.producto?.nombre ?? `#${o.producto_servicio_id}`,
      tipo: o.producto?.tipo ?? '',
      precio_catalogo: o.producto?.precio ?? null,
      precio: o.precio ?? o.producto?.precio ?? 0,
      activo: true,
    }))
  serviceToAddId.value = null
}

function addServiceOffer(): void {
  const option = availableCatalogProducts.value.find((p) => p.value === serviceToAddId.value)
  if (!option) return
  serviceOffers.value.push({
    producto_servicio_id: option.value,
    nombre: option.nombre,
    tipo: option.tipo,
    precio_catalogo: option.precio,
    precio: option.precio,
    activo: true,
  })
  serviceToAddId.value = null
}

function removeServiceOffer(productoServicioId: number): void {
  serviceOffers.value = serviceOffers.value.filter(
    (row) => row.producto_servicio_id !== productoServicioId,
  )
}

async function saveServiceOffers(): Promise<void> {
  if (!persistedId.value) return
  savingServices.value = true
  try {
    const items = serviceOffers.value.map((row) => ({
      producto_servicio_id: row.producto_servicio_id,
      precio: row.precio,
      activo: true,
    }))
    await eventsService.syncEventProductos(persistedId.value, { items })
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.servicesSaved'),
      life: 2500,
    })
    await loadServiceOffers()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    savingServices.value = false
  }
}

async function loadEvent(): Promise<void> {
  if (!persistedId.value) return
  const event = await eventsService.get(persistedId.value)
  form.name = event.name
  form.descripcion = (event.descripcion || '').slice(0, descMax)
  form.lugar = event.lugar || event.lugar_catalogo?.nombre || ''
  form.lugar_id = event.lugar_id ?? null
  form.usar_lotes = event.usar_lotes ?? false
  form.usar_cabanas = event.usar_cabanas ?? false
  form.starts_at = dateOnly(event.starts_at)
  form.ends_at = dateOnly(event.ends_at)
  form.is_active = event.is_active
  form.estado = event.estado || 'borrador'
  form.visibilidad = event.visibilidad ?? 'organizacion'
  form.organizacion_id = event.organizacion_id ?? null
  form.organizacion_ids = [...(event.organizacion_ids || [])]
  applyAudienceFromEvent(event)
  form.es_en_sitio = event.es_en_sitio ?? true
  form.es_calificable = event.es_calificable ?? false
  form.puntaje_maximo = event.puntaje_maximo ?? null
  form.requiere_pago = event.requiere_pago ?? false
  form.precio = event.precio ?? null
  form.precio_fuera_tiempo = event.precio_fuera_tiempo ?? null
  form.precio_acompanante = event.precio_acompanante ?? null
  form.precio_acompanante_fuera_tiempo = event.precio_acompanante_fuera_tiempo ?? null
  form.precio_acompanante_menor = event.precio_acompanante_menor ?? null
  form.precio_acompanante_menor_fuera_tiempo =
    event.precio_acompanante_menor_fuera_tiempo ?? null
  form.precio_directiva = event.precio_directiva ?? null
  form.precio_directiva_fuera_tiempo = event.precio_directiva_fuera_tiempo ?? null
  form.descuentos_directiva =
    event.descuentos_directiva && event.descuentos_directiva.length
      ? event.descuentos_directiva.map((d) => ({
          codigo: d.codigo,
          nombre: d.nombre,
          porcentaje: Number(d.porcentaje) || 0,
        }))
      : [...DEFAULT_DESCUENTOS_DIRECTIVA]
  form.fecha_limite_pago = dateOnly(event.fecha_limite_pago)
  form.metodo_pago = event.metodo_pago ?? null
  form.cuenta_bancaria_id = event.cuenta_bancaria_id ?? null
  if (event.cuenta_bancaria && !cuentasBancarias.value.some((item) => item.id === event.cuenta_bancaria?.id)) {
    cuentasBancarias.value = [...cuentasBancarias.value, event.cuenta_bancaria]
  }
  form.requiere_seguro = event.requiere_seguro ?? false
  form.tipo_seguro_id = event.tipo_seguro_id ?? null
  form.seguro_valor = event.seguro_valor ?? null
  form.seguro_fecha_inicio = dateOnly(event.seguro_fecha_inicio)
  form.seguro_fecha_fin = dateOnly(event.seguro_fecha_fin)
  form.cupo_minimo = event.cupo_minimo ?? null
  form.cupo_maximo = event.cupo_maximo ?? null
  form.cupo_ilimitado = event.cupo_ilimitado ?? true
  form.cupo_max_organizacion = event.cupo_max_organizacion ?? null
  form.cupo_max_club = event.cupo_max_club ?? null
  form.cupo_max_iglesia = event.cupo_max_iglesia ?? null
  form.permite_inscripcion_individual = event.permite_inscripcion_individual ?? true
  form.permite_inscripcion_organizacion = event.permite_inscripcion_organizacion ?? false
  form.permite_inscripcion_club = event.permite_inscripcion_club ?? true
  form.permite_inscripcion_iglesia = event.permite_inscripcion_iglesia ?? false
  form.fecha_limite_inscripcion = event.fecha_limite_inscripcion
    ? new Date(event.fecha_limite_inscripcion)
    : null
  form.puntos_inscripcion_a_tiempo = event.puntos_inscripcion_a_tiempo ?? null
  form.puntos_inscripcion_fuera_tiempo = event.puntos_inscripcion_fuera_tiempo ?? null
  form.image_url = event.image_url
  form.banner_url = event.banner_url ?? null
  applyHomeOrganization()
}

onMounted(async () => {
  loading.value = true
  try {
    await loadCatalogs()
    await loadEvent()
    await loadServiceOffers()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  revokeUrl(pendingPreview.value)
  revokeUrl(pendingBannerPreview.value)
})
</script>

<template>
  <section class="pj-page wizard">
    <header class="wizard__header">
      <div class="wizard__heading">
        <h1 class="pj-page__title">{{ pageTitle }}</h1>
        <p class="pj-page__subtitle">{{ pageSubtitle }}</p>
      </div>
      <div class="wizard__header-actions">
        <Button
          :label="t('events.wizard.saveDraft')"
          severity="secondary"
          outlined
          :loading="saving || uploading"
          :disabled="loading"
          @click="saveDraft()"
        />
        <Button
          :label="t('events.wizard.publish')"
          icon="pi pi-send"
          :loading="saving || uploading"
          :disabled="loading"
          @click="publishEvent"
        />
      </div>
    </header>

    <nav class="wizard-stepper" aria-label="Pasos del evento">
      <ol class="wizard-stepper__list">
        <li
          v-for="(step, index) in steps"
          :key="step.key"
          class="wizard-stepper__item"
          :class="{
            'is-active': step.key === currentStep,
            'is-done': index < stepIndex,
          }"
        >
          <button type="button" class="wizard-stepper__btn" @click="goStep(step.key)">
            <span class="wizard-stepper__num">
              <i v-if="index < stepIndex" class="pi pi-check" />
              <template v-else>{{ index + 1 }}</template>
            </span>
            <span class="wizard-stepper__label">{{ step.label }}</span>
          </button>
          <span v-if="index < steps.length - 1" class="wizard-stepper__line" aria-hidden="true" />
        </li>
      </ol>
    </nav>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <div v-else class="wizard__body pj-panel">
      <Message v-if="errorMessage" severity="error" :closable="false" class="wizard__error">
        {{ errorMessage }}
      </Message>

      <div class="wizard__main" :class="{ 'preview-open': previewVisible }">
        <div class="wizard__steps">
      <!-- Paso 1: Información básica -->
      <div v-show="currentStep === 'basica'" class="step-basica">
        <div class="step-basica__form">
          <div class="step-section-title">
            <i class="pi pi-calendar" />
            <h2>{{ t('events.wizard.stepBasic') }}</h2>
          </div>

          <div class="media-uploads">
            <div class="field">
              <MediaProfileUpload
                :src="imagePreview"
                :title="t('events.wizard.eventLogo')"
                :subtitle="t('events.wizard.eventLogoHint')"
                :meta="t('events.wizard.imageHint')"
                hint=""
                :busy="uploading"
                @select="onPickImage"
              />
            </div>

            <div class="field">
              <MediaCoverUpload
                :src="bannerPreview"
                :title="t('events.wizard.eventBanner')"
                :subtitle="t('events.wizard.bannerHint')"
                meta=""
                :busy="uploading"
                @select="onPickBanner"
              />
            </div>
          </div>

          <div class="field">
            <label for="name">{{ t('events.name') }}</label>
            <InputText
              id="name"
              v-model="form.name"
              class="w-full"
              :placeholder="t('events.wizard.namePlaceholder')"
            />
          </div>

          <div class="field">
            <div class="field__label-row">
              <label for="descripcion">{{ t('events.wizard.shortDescription') }}</label>
              <span class="char-count" :class="{ 'is-limit': descCount >= descMax }">
                {{ descCount }}/{{ descMax }}
              </span>
            </div>
            <Textarea
              id="descripcion"
              v-model="form.descripcion"
              class="w-full"
              rows="3"
              :maxlength="descMax"
              :placeholder="t('events.wizard.shortDescriptionPlaceholder')"
              auto-resize
            />
          </div>

          <section class="basic-config-section">
            <div class="basic-config-section__head">
              <i class="pi pi-calendar" />
              <div>
                <h3>{{ t('events.wizard.basicDatePlaceTitle') }}</h3>
                <p>{{ t('events.wizard.basicDatePlaceLead') }}</p>
              </div>
            </div>
            <div class="field-grid">
              <div class="field">
                <label for="lugar">{{ t('events.lugar') }}</label>
                <Select
                  input-id="lugar"
                  v-model="form.lugar_id"
                  :options="lugarOptions"
                  option-label="label"
                  option-value="value"
                  filter
                  show-clear
                  class="w-full"
                  :placeholder="t('events.lugar')"
                />
              </div>
              <div class="field field--row">
                <label for="usar_lotes">{{ t('events.wizard.useLots') }}</label>
                <ToggleSwitch input-id="usar_lotes" v-model="form.usar_lotes" :disabled="!form.lugar_id" />
              </div>
              <div class="field field--row">
                <label for="usar_cabanas">{{ t('events.wizard.useCabins') }}</label>
                <ToggleSwitch input-id="usar_cabanas" v-model="form.usar_cabanas" :disabled="!form.lugar_id" />
              </div>
              <div class="field field--row">
                <label for="es_en_sitio">{{ t('events.esEnSitio') }}</label>
                <ToggleSwitch input-id="es_en_sitio" v-model="form.es_en_sitio" />
              </div>
              <div class="field">
                <label for="starts_at">{{ t('events.startsAt') }}</label>
                <DatePicker
                  input-id="starts_at"
                  :model-value="form.starts_at"
                  date-format="dd/mm/yy"
                  class="w-full"
                  @update:model-value="(v) => (form.starts_at = dateOnly(Array.isArray(v) ? v[0] : v))"
                />
              </div>
              <div class="field">
                <label for="ends_at">{{ t('events.endsAt') }}</label>
                <DatePicker
                  input-id="ends_at"
                  :model-value="form.ends_at"
                  date-format="dd/mm/yy"
                  class="w-full"
                  @update:model-value="(v) => (form.ends_at = dateOnly(Array.isArray(v) ? v[0] : v))"
                />
              </div>
            </div>
          </section>

          <section class="basic-config-section">
            <div class="basic-config-section__head">
              <i class="pi pi-users" />
              <div>
                <h3>{{ t('events.wizard.basicParticipationTitle') }}</h3>
                <p>{{ t('events.wizard.basicParticipationLead') }}</p>
              </div>
            </div>

            <div class="field field--row">
              <label for="cupo_ilimitado">{{ t('events.wizard.cupoIlimitado') }}</label>
              <ToggleSwitch input-id="cupo_ilimitado" v-model="form.cupo_ilimitado" />
            </div>
            <div v-if="!form.cupo_ilimitado" class="field-grid">
              <div class="field">
                <label for="cupo_minimo">{{ t('events.wizard.cupoMinimo') }}</label>
                <InputNumber id="cupo_minimo" v-model="form.cupo_minimo" class="w-full" :min="1" />
              </div>
              <div class="field">
                <label for="cupo_maximo">{{ t('events.wizard.cupoMaximo') }}</label>
                <InputNumber id="cupo_maximo" v-model="form.cupo_maximo" class="w-full" :min="1" />
              </div>
              <div class="field">
                <label for="cupo_max_club">{{ t('events.wizard.cupoClub') }}</label>
                <InputNumber id="cupo_max_club" v-model="form.cupo_max_club" class="w-full" :min="1" />
              </div>
            </div>

            <h4 class="basic-config-section__subtitle">{{ t('events.wizard.registrationTitle') }}</h4>
            <div class="toggle-grid">
              <label class="toggle-item">
                <ToggleSwitch v-model="form.permite_inscripcion_individual" />
                <span>{{ t('events.wizard.regIndividual') }}</span>
              </label>
              <label class="toggle-item">
                <ToggleSwitch v-model="form.permite_inscripcion_club" />
                <span>{{ t('events.wizard.regClub') }}</span>
              </label>
              <label class="toggle-item">
                <ToggleSwitch v-model="form.permite_inscripcion_organizacion" />
                <span>{{ t('events.wizard.regOrg') }}</span>
              </label>
              <label class="toggle-item">
                <ToggleSwitch v-model="form.permite_inscripcion_iglesia" />
                <span>{{ t('events.wizard.regIglesia') }}</span>
              </label>
            </div>
            <div class="field-grid" style="margin-top: 0.85rem">
              <div class="field">
                <label for="fecha_limite_inscripcion">{{ t('events.wizard.enrollmentDeadline') }}</label>
                <DatePicker
                  input-id="fecha_limite_inscripcion"
                  v-model="form.fecha_limite_inscripcion"
                  show-time
                  hour-format="24"
                  date-format="dd/mm/yy"
                  class="w-full"
                />
              </div>
              <div class="field">
                <label for="puntos_inscripcion_a_tiempo">
                  {{ t('events.wizard.enrollmentPointsOnTime') }}
                </label>
                <InputNumber
                  id="puntos_inscripcion_a_tiempo"
                  v-model="form.puntos_inscripcion_a_tiempo"
                  class="w-full"
                  :min="0"
                />
              </div>
              <div class="field">
                <label for="puntos_inscripcion_fuera_tiempo">
                  {{ t('events.wizard.enrollmentPointsLate') }}
                </label>
                <InputNumber
                  id="puntos_inscripcion_fuera_tiempo"
                  v-model="form.puntos_inscripcion_fuera_tiempo"
                  class="w-full"
                  :min="0"
                />
              </div>
            </div>
            <p class="step-lead" style="margin-top: 0.5rem">
              {{ t('events.wizard.enrollmentPointsHint') }}
            </p>
          </section>
        </div>

        <aside class="step-basica__tips">
          <div class="tips-card">
            <div class="tips-card__head">
              <i class="pi pi-lightbulb" />
              <h3>{{ t('events.wizard.tipsTitle') }}</h3>
            </div>
            <ul>
              <li v-for="(tip, i) in tips" :key="i">{{ tip }}</li>
            </ul>
          </div>
        </aside>
      </div>

      <!-- Paso 2: Organizaciones -->
      <div v-show="currentStep === 'organizaciones'">
        <section class="step-block visibility-block">
          <div class="step-section-title">
            <i class="pi pi-eye" />
            <h2>{{ t('events.visibilityTitle') }}</h2>
          </div>
          <p class="step-lead">{{ t('events.visibilityLead') }}</p>
          <div class="field">
            <Select
              v-model="form.visibilidad"
              :options="visibilityOptions"
              option-label="label"
              option-value="value"
              class="w-full"
            />
            <small class="pj-muted">
              {{ t(`events.visibilityHint.${form.visibilidad}`) }}
            </small>
          </div>
        </section>
        <EventOrganizationsStep
          :organizacion-id="form.organizacion_id"
          :organizacion-ids="form.organizacion_ids"
          :org-options="orgOptions"
          :org-tree="orgTree"
          :clubs="clubsCatalog"
          :audience="clubAudience"
          :audience-label="audienceLabel"
          :audience-options="clubAudienceOptions"
          @update:organizacion-id="(v) => (form.organizacion_id = v)"
          @update:organizacion-ids="(v) => (form.organizacion_ids = v)"
          @toggle-audience="toggleAudience"
        />
      </div>

      <!-- Paso 3: Configuración -->
      <div v-show="currentStep === 'configuracion'" class="step-block">
        <div class="step-section-title">
          <i class="pi pi-cog" />
          <h2>{{ t('events.wizard.stepConfig') }}</h2>
        </div>
        <p class="step-lead">{{ t('events.wizard.configLead') }}</p>

        <div class="config-tabs" role="tablist">
          <button type="button" :class="{ 'is-active': configTab === 'inscripcion' }" @click="configTab = 'inscripcion'">
            {{ t('events.wizard.configTabInscription') }}
          </button>
          <button type="button" :class="{ 'is-active': configTab === 'descuentos' }" @click="configTab = 'descuentos'">
            {{ t('events.wizard.configTabDiscounts') }}
          </button>
          <button type="button" :class="{ 'is-active': configTab === 'seguro' }" @click="configTab = 'seguro'">
            {{ t('events.wizard.configTabInsurance') }}
          </button>
          <button type="button" :class="{ 'is-active': configTab === 'categorias' }" @click="configTab = 'categorias'">
            {{ t('events.wizard.configTabCategories') }}
          </button>
          <button type="button" :class="{ 'is-active': configTab === 'criterios' }" @click="configTab = 'criterios'">
            {{ t('events.wizard.configTabCriteria') }}
          </button>
          <button type="button" :class="{ 'is-active': configTab === 'servicios' }" @click="configTab = 'servicios'">
            {{ t('events.wizard.configTabServices') }}
          </button>
        </div>

        <div v-show="configTab === 'inscripcion'" class="config-section config-section--scoring">
          <h3>{{ t('events.wizard.scoringTitle') }}</h3>
          <div class="field field--row">
            <label for="es_calificable">{{ t('events.wizard.scorable') }}</label>
            <ToggleSwitch input-id="es_calificable" v-model="form.es_calificable" />
          </div>
          <div v-if="form.es_calificable" class="field" style="max-width: 16rem">
            <label for="puntaje_maximo">{{ t('events.wizard.maxScore') }}</label>
            <InputNumber id="puntaje_maximo" v-model="form.puntaje_maximo" class="w-full" :min="0" />
          </div>
        </div>

        <div v-show="configTab === 'categorias'" class="config-section config-section--categories">
          <h3>{{ t('events.wizard.catAdminTitle') }}</h3>
          <p class="step-lead" style="margin-bottom: 0.75rem">{{ t('events.wizard.catAdminLead') }}</p>
          <Button
            type="button"
            icon="pi pi-tags"
            outlined
            :label="t('events.wizard.catAdminButton')"
            @click="categoriesAdminVisible = true"
          />
        </div>

        <div v-show="configTab === 'criterios'" class="config-section config-section--criteria">
          <h3>{{ t('events.wizard.criteriaAdminTitle') }}</h3>
          <p class="step-lead" style="margin-bottom: 0.75rem">{{ t('events.wizard.criteriaAdminLead') }}</p>
          <Button
            type="button"
            icon="pi pi-list-check"
            outlined
            :label="t('events.wizard.criteriaBank')"
            @click="criteriosAdminVisible = true"
          />
          <!-- TODO(panel-juez): calificación por criterio o genérica — entrega posterior -->
          <p class="pj-muted" style="margin-top: 0.65rem; font-size: 0.82rem">
            {{ t('events.wizard.judgePanelTodo') }}
          </p>
        </div>

        <div v-show="configTab === 'inscripcion'" class="config-section config-section--payment">
          <h3>{{ t('events.wizard.paymentTitle') }}</h3>
          <div class="field field--row">
            <label for="requiere_pago">{{ t('events.enrollmentRequires') }}</label>
            <ToggleSwitch input-id="requiere_pago" v-model="form.requiere_pago" />
          </div>
          <div v-if="form.requiere_pago" class="field-grid">
            <div class="field">
              <label for="precio">{{ t('events.enrollmentValue') }}</label>
              <InputNumber
                id="precio"
                v-model="form.precio"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="precio_acompanante">{{ t('events.companionPrice') }}</label>
              <InputNumber
                id="precio_acompanante"
                v-model="form.precio_acompanante"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="precio_acompanante_menor">{{ t('events.companionMinorPrice') }}</label>
              <InputNumber
                id="precio_acompanante_menor"
                v-model="form.precio_acompanante_menor"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="precio_directiva">{{ t('events.directivePrice') }}</label>
              <InputNumber
                id="precio_directiva"
                v-model="form.precio_directiva"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
              <small class="pj-muted">{{ t('events.directivePriceHint') }}</small>
            </div>
            <div class="field">
              <label for="precio_fuera_tiempo">{{ t('events.lateEnrollmentPrice') }}</label>
              <InputNumber
                id="precio_fuera_tiempo"
                v-model="form.precio_fuera_tiempo"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
              <small class="pj-muted">{{ t('events.latePriceHint') }}</small>
            </div>
            <div class="field">
              <label for="precio_acompanante_fuera_tiempo">
                {{ t('events.lateCompanionPrice') }}
              </label>
              <InputNumber
                id="precio_acompanante_fuera_tiempo"
                v-model="form.precio_acompanante_fuera_tiempo"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="precio_acompanante_menor_fuera_tiempo">
                {{ t('events.lateCompanionMinorPrice') }}
              </label>
              <InputNumber
                id="precio_acompanante_menor_fuera_tiempo"
                v-model="form.precio_acompanante_menor_fuera_tiempo"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="precio_directiva_fuera_tiempo">
                {{ t('events.lateDirectivePrice') }}
              </label>
              <InputNumber
                id="precio_directiva_fuera_tiempo"
                v-model="form.precio_directiva_fuera_tiempo"
                mode="currency"
                currency="COP"
                locale="es-CO"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="fecha_limite_pago">{{ t('events.wizard.paymentDeadline') }}</label>
              <DatePicker
                input-id="fecha_limite_pago"
                :model-value="form.fecha_limite_pago"
                date-format="dd/mm/yy"
                class="w-full"
                @update:model-value="(v) => (form.fecha_limite_pago = dateOnly(Array.isArray(v) ? v[0] : v))"
              />
            </div>
            <div class="field">
              <label for="metodo_pago">{{ t('events.wizard.paymentMethod') }}</label>
              <InputText id="metodo_pago" v-model="form.metodo_pago" class="w-full" />
            </div>
            <div class="field">
              <label for="cuenta_bancaria_id">{{ t('events.bankAccount') }}</label>
              <Select
                input-id="cuenta_bancaria_id"
                v-model="form.cuenta_bancaria_id"
                :options="cuentaBancariaOptions"
                option-label="label"
                option-value="value"
                class="w-full"
                :placeholder="t('events.bankAccountPlaceholder')"
                show-clear
              />
              <small class="pj-muted">{{ t('events.bankAccountHint') }}</small>
            </div>
          </div>

        </div>

        <div v-show="configTab === 'descuentos'" class="config-section">
          <div class="descuentos-block">
            <div class="descuentos-block__head">
              <div>
                <h4>{{ t('events.directiveDiscountsTitle') }}</h4>
                <p class="step-lead">{{ t('events.directiveDiscountsLead') }}</p>
              </div>
              <Button
                type="button"
                icon="pi pi-plus"
                outlined
                size="small"
                :label="t('events.directiveDiscountAdd')"
                @click="addDescuentoDirectiva"
              />
            </div>
            <div v-if="!form.descuentos_directiva.length" class="pj-muted">
              {{ t('events.directiveDiscountsEmpty') }}
            </div>
            <div v-else class="descuentos-table">
              <div
                v-for="(row, idx) in form.descuentos_directiva"
                :key="`${row.codigo}-${idx}`"
                class="descuentos-table__row"
              >
                <InputText
                  v-model="row.nombre"
                  :placeholder="t('events.directiveDiscountName')"
                  class="w-full"
                  @blur="onDescuentoNombreBlur(row)"
                />
                <InputNumber
                  v-model="row.porcentaje"
                  suffix=" %"
                  :min="0"
                  :max="100"
                  :min-fraction-digits="0"
                  :max-fraction-digits="2"
                  class="w-full"
                />
                <span class="descuentos-table__preview pj-muted">
                  {{ formatDescuentoPreview(row.porcentaje) }}
                </span>
                <Button
                  type="button"
                  icon="pi pi-trash"
                  text
                  rounded
                  severity="danger"
                  :aria-label="t('common.delete')"
                  @click="removeDescuentoDirectiva(idx)"
                />
              </div>
            </div>
          </div>
        </div>

        <div v-show="configTab === 'seguro'" class="config-section config-section--insurance">
          <h3>{{ t('events.insuranceTitle') }}</h3>
          <div class="field field--row">
            <label for="requiere_seguro">{{ t('events.requiresInsurance') }}</label>
            <ToggleSwitch input-id="requiere_seguro" v-model="form.requiere_seguro" />
          </div>
          <div v-if="form.requiere_seguro" class="field-grid">
            <div class="field">
              <label for="tipo_seguro_id">{{ t('events.insuranceType') }}</label>
              <Select
                input-id="tipo_seguro_id"
                v-model="form.tipo_seguro_id"
                :options="tipoSeguroSelectOptions"
                option-label="label"
                option-value="value"
                :placeholder="t('events.insuranceTypePlaceholder')"
                class="w-full"
              />
            </div>
            <div class="field">
              <label for="seguro_valor">{{ t('events.insuranceValue') }}</label>
              <InputNumber
                id="seguro_valor"
                v-model="form.seguro_valor"
                mode="currency"
                currency="USD"
                locale="en-US"
                class="w-full"
                :min="0"
              />
            </div>
            <div class="field">
              <label for="seguro_fecha_inicio">{{ t('events.insuranceStart') }}</label>
              <DatePicker
                input-id="seguro_fecha_inicio"
                :model-value="form.seguro_fecha_inicio"
                date-format="dd/mm/yy"
                class="w-full"
                @update:model-value="(v) => (form.seguro_fecha_inicio = dateOnly(Array.isArray(v) ? v[0] : v))"
              />
            </div>
            <div class="field">
              <label for="seguro_fecha_fin">{{ t('events.insuranceEnd') }}</label>
              <DatePicker
                input-id="seguro_fecha_fin"
                :model-value="form.seguro_fecha_fin"
                date-format="dd/mm/yy"
                class="w-full"
                @update:model-value="(v) => (form.seguro_fecha_fin = dateOnly(Array.isArray(v) ? v[0] : v))"
              />
            </div>
          </div>
        </div>

        <div v-show="configTab === 'servicios'" class="config-section config-section--services">
          <p v-if="!persistedId" class="pj-muted">{{ t('events.wizard.servicesNeedSave') }}</p>
          <template v-else>
          <h3>{{ t('events.servicesTitle') }}</h3>
          <p class="step-lead" style="margin-bottom: 0.75rem">{{ t('events.servicesLead') }}</p>

          <div class="services-add">
            <Select
              v-model="serviceToAddId"
              :options="availableCatalogProducts"
              option-label="label"
              option-value="value"
              :placeholder="t('events.servicesAddPlaceholder')"
              class="services-add__select"
              :disabled="!availableCatalogProducts.length"
              show-clear
            />
            <Button
              type="button"
              icon="pi pi-plus"
              :label="t('events.servicesAdd')"
              :disabled="!serviceToAddId"
              @click="addServiceOffer"
            />
          </div>

          <p v-if="!productosCatalog.length" class="pj-muted">{{ t('events.servicesEmpty') }}</p>
          <p v-else-if="!serviceOffers.length" class="pj-muted">{{ t('events.servicesNoneAdded') }}</p>
          <div v-else class="services-table">
            <div
              v-for="row in serviceOffers"
              :key="row.producto_servicio_id"
              class="services-table__row"
            >
              <div class="services-table__info">
                <strong>{{ row.nombre }}</strong>
                <span class="pj-muted">{{ row.tipo }}</span>
              </div>
              <InputNumber
                v-model="row.precio"
                mode="currency"
                currency="COP"
                locale="es-CO"
                :min="0"
                class="services-table__price"
              />
              <Button
                type="button"
                icon="pi pi-trash"
                text
                rounded
                severity="danger"
                :aria-label="t('events.servicesRemove')"
                @click="removeServiceOffer(row.producto_servicio_id)"
              />
            </div>
          </div>
          <Button
            type="button"
            icon="pi pi-save"
            :label="t('events.servicesSave')"
            :loading="savingServices"
            class="mt-3"
            @click="saveServiceOffers"
          />
          </template>
        </div>
      </div>

      <!-- Paso: Terreno / distribución -->
      <div v-show="currentStep === 'terreno'" class="step-block">
        <EventTerrenoStep
          :event-id="persistedId"
          :event-name="form.name"
          :lugar-id="form.lugar_id"
          @summary="onTerrenoSummary"
          @apply-cupo="applyTerrenoCupo"
        />
      </div>

      <!-- Paso: Cabañas y alojamiento -->
      <div v-show="currentStep === 'alojamiento'" class="step-block">
        <EventCabanasStep
          :event-id="persistedId"
          :lugar-id="form.lugar_id"
          @summary="cabanasSummary = $event"
        />
      </div>

      <!-- Paso: Subeventos -->
      <div v-show="currentStep === 'subeventos'">
        <EventSubeventsStep
          :parent-id="persistedId"
          :parent-name="form.name"
          :parent-puntaje-maximo="form.puntaje_maximo"
          :parent-starts-at="form.starts_at"
          :parent-ends-at="form.ends_at"
          :parent-organizacion-id="form.organizacion_id"
          :parent-es-en-sitio="form.es_en_sitio"
          :parent-visibilidad="form.visibilidad"
          :categorias-version="categoriasVersion"
        />
      </div>

      <!-- Paso 5: Revisión -->
      <div v-show="currentStep === 'revision'" class="step-block">
        <div class="step-section-title">
          <i class="pi pi-check-circle" />
          <h2>{{ t('events.wizard.stepReview') }}</h2>
        </div>
        <p class="step-lead">{{ t('events.wizard.reviewLead') }}</p>

        <div class="review-grid">
          <div class="review-card">
            <h3>{{ t('events.wizard.stepBasic') }}</h3>
            <dl>
              <div><dt>{{ t('events.name') }}</dt><dd>{{ form.name || '—' }}</dd></div>
              <div>
                <dt>{{ t('events.wizard.shortDescription') }}</dt>
                <dd>{{ form.descripcion || '—' }}</dd>
              </div>
              <div><dt>{{ t('events.lugar') }}</dt><dd>{{ selectedLugar?.nombre || form.lugar || '—' }}</dd></div>
              <div><dt>{{ t('events.wizard.useLots') }}</dt><dd>{{ form.usar_lotes ? t('common.yes') : t('common.no') }}</dd></div>
              <div><dt>{{ t('events.wizard.useCabins') }}</dt><dd>{{ form.usar_cabanas ? t('common.yes') : t('common.no') }}</dd></div>
              <div><dt>{{ t('events.startsAt') }}</dt><dd>{{ previewDates }}</dd></div>
              <div><dt>{{ t('events.wizard.participants') }}</dt><dd>{{ previewCupo }}</dd></div>
            </dl>
            <Button
              type="button"
              :label="t('events.wizard.editStep')"
              text
              size="small"
              @click="goStep('basica')"
            />
          </div>
          <div class="review-card">
            <h3>{{ t('events.wizard.stepOrgs') }}</h3>
            <dl>
              <div><dt>{{ t('events.audienceTitle') }}</dt><dd>{{ audienceLabel }}</dd></div>
              <div>
                <dt>{{ t('events.organizador') }}</dt>
                <dd>
                  {{
                    orgOptions.find((o) => o.id === form.organizacion_id)?.label ||
                    t('events.wizard.previewPending')
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ t('events.organizaciones') }}</dt>
                <dd>{{ form.organizacion_ids.length || 0 }}</dd>
              </div>
            </dl>
            <Button
              type="button"
              :label="t('events.wizard.editStep')"
              text
              size="small"
              @click="goStep('organizaciones')"
            />
          </div>
          <div class="review-card">
            <h3>{{ t('events.wizard.stepConfig') }}</h3>
            <dl>
              <div>
                <dt>{{ t('events.enrollmentRequires') }}</dt>
                <dd>{{ form.requiere_pago ? t('common.yes') : t('common.no') }}</dd>
              </div>
              <div v-if="form.requiere_pago">
                <dt>{{ t('events.bankAccount') }}</dt>
                <dd>{{ selectedCuentaBancaria?.nombre || t('events.bankAccountEmpty') }}</dd>
              </div>
              <div>
                <dt>{{ t('events.requiresInsurance') }}</dt>
                <dd>{{ form.requiere_seguro ? t('common.yes') : t('common.no') }}</dd>
              </div>
              <div>
                <dt>{{ t('events.wizard.scorable') }}</dt>
                <dd>{{ form.es_calificable ? t('common.yes') : t('common.no') }}</dd>
              </div>
            </dl>
            <Button
              type="button"
              :label="t('events.wizard.editStep')"
              text
              size="small"
              @click="goStep('configuracion')"
            />
          </div>
          <div v-if="form.usar_lotes" class="review-card">
            <h3>{{ t('events.wizard.stepTerreno') }}</h3>
            <dl>
              <div>
                <dt>{{ t('terrenos.terreno') }}</dt>
                <dd>{{ terrenoSummary.terrenoNombre || t('events.wizard.terrenoNotAssigned') }}</dd>
              </div>
              <div>
                <dt>{{ t('events.wizard.terrenoLotesTotal') }}</dt>
                <dd>{{ terrenoSummary.lotes }}</dd>
              </div>
              <div>
                <dt>{{ t('events.wizard.terrenoCapacidadEstimada') }}</dt>
                <dd>
                  {{
                    terrenoSummary.capacidad
                      ? `${terrenoSummary.capacidad} ${t('events.wizard.terrenoAcampantes')}`
                      : '—'
                  }}
                </dd>
              </div>
            </dl>
            <Button
              type="button"
              :label="t('events.wizard.editStep')"
              text
              size="small"
              @click="goStep('terreno')"
            />
          </div>
          <div v-if="form.usar_cabanas" class="review-card">
            <h3>{{ t('events.wizard.stepCabanas') }}</h3>
            <dl>
              <div><dt>{{ t('cabanas.cabins') }}</dt><dd>{{ cabanasSummary.cabanas }}</dd></div>
              <div><dt>{{ t('cabanas.capacity') }}</dt><dd>{{ cabanasSummary.capacidad }}</dd></div>
            </dl>
            <Button
              type="button"
              :label="t('events.wizard.editStep')"
              text
              size="small"
              @click="goStep('alojamiento')"
            />
          </div>
        </div>
      </div>
        </div>

        <aside class="wizard-preview" :class="{ 'is-collapsed': !previewVisible }">
          <button
            type="button"
            class="wizard-preview__toggle"
            @click="previewVisible = !previewVisible"
          >
            <span>
              <i class="pi pi-eye" />
              {{ t('events.wizard.livePreview') }}
            </span>
            <i :class="previewVisible ? 'pi pi-chevron-right' : 'pi pi-chevron-left'" />
          </button>

          <EventBannerCard
            v-show="previewVisible"
            :name="form.name || t('events.wizard.previewTitle')"
            :banner-url="bannerPreview"
            :logo-url="imagePreview"
            :status-label="form.estado === 'publicado' ? t('events.estadoPublicado') : t('events.estadoBorrador')"
            :status-css="form.estado === 'publicado' ? 'status--publicado' : 'status--borrador'"
            :audience-label="audienceLabel"
            :dates-label="previewDates"
            :place-label="form.lugar || t('events.wizard.previewPlace')"
            :description="form.descripcion || t('events.wizard.previewDesc')"
            :cupo-label="previewCupo"
            :score-label="previewEnrollment"
            :cupo-caption="t('events.wizard.participants')"
            :score-caption="t('events.enrollmentValue')"
          />
        </aside>
      </div>

      <footer class="wizard__footer">
        <Button
          type="button"
          :label="t('common.cancel')"
          text
          @click="router.push({ name: 'events' })"
        />
        <div class="wizard__footer-nav">
          <Button
            type="button"
            :label="t('events.wizard.prev')"
            severity="secondary"
            outlined
            :disabled="isFirstStep || saving"
            icon="pi pi-arrow-left"
            @click="prevStep"
          />
          <Button
            v-if="!isLastStep"
            type="button"
            :label="t('events.wizard.next')"
            icon="pi pi-arrow-right"
            icon-pos="right"
            :loading="saving || uploading"
            @click="nextStep"
          />
          <Button
            v-else
            type="button"
            :label="t('events.wizard.publish')"
            icon="pi pi-send"
            :loading="saving || uploading"
            @click="publishEvent"
          />
        </div>
      </footer>
    </div>

    <CategoriaSubeventosAdminDrawer
      v-model:visible="categoriesAdminVisible"
      @changed="categoriasVersion += 1"
    />
    <CriteriosEvaluacionAdminDrawer
      v-model:visible="criteriosAdminVisible"
      @changed="categoriasVersion += 1"
    />
  </section>
</template>

<style scoped>
.wizard {
  gap: 1rem;
}

.wizard__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem 1rem;
}

.wizard__header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.wizard__body {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.25rem;
}

.wizard__main {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 1rem 1.15rem;
  align-items: start;
}

.wizard__main.preview-open {
  grid-template-columns: minmax(0, 1fr) minmax(280px, 340px);
}

.wizard__steps {
  min-width: 0;
}

.wizard-preview {
  position: sticky;
  top: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  min-width: 2.75rem;
}

.wizard-preview.is-collapsed {
  width: 2.75rem;
}

.wizard-preview__toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: #fff;
  border-radius: 10px;
  padding: 0.45rem 0.55rem;
  cursor: pointer;
  color: var(--pj-text, #0f172a);
  font: inherit;
  font-size: 0.78rem;
  font-weight: 700;
}

.wizard-preview.is-collapsed .wizard-preview__toggle {
  flex-direction: column;
  writing-mode: vertical-rl;
  text-orientation: mixed;
  min-height: 9rem;
  padding: 0.55rem 0.35rem;
}

.wizard-preview.is-collapsed .wizard-preview__toggle span {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  transform: rotate(180deg);
}

.wizard-preview__toggle span {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.wizard__error {
  width: 100%;
}

.wizard-stepper {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.wizard-stepper__list {
  list-style: none;
  margin: 0;
  padding: 0.25rem 0 0.5rem;
  display: flex;
  align-items: center;
  min-width: max-content;
  gap: 0;
}

.wizard-stepper__item {
  display: flex;
  align-items: center;
  flex: 1;
}

.wizard-stepper__btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: 0;
  background: transparent;
  cursor: pointer;
  padding: 0.25rem;
  color: var(--pj-text-muted);
  white-space: nowrap;
}

.wizard-stepper__num {
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 999px;
  display: grid;
  place-content: center;
  font-size: 0.8rem;
  font-weight: 700;
  border: 2px solid color-mix(in srgb, var(--pj-border) 90%, transparent);
  background: #fff;
  flex-shrink: 0;
}

.wizard-stepper__label {
  font-size: 0.82rem;
  font-weight: 600;
}

.wizard-stepper__item.is-active .wizard-stepper__num {
  background: var(--pj-primary, #2563eb);
  border-color: var(--pj-primary, #2563eb);
  color: #fff;
}

.wizard-stepper__item.is-active .wizard-stepper__label {
  color: var(--pj-primary, #2563eb);
}

.wizard-stepper__item.is-done .wizard-stepper__num {
  background: color-mix(in srgb, #16a34a 18%, #fff);
  border-color: #16a34a;
  color: #15803d;
}

.wizard-stepper__line {
  height: 2px;
  flex: 1;
  min-width: 1.5rem;
  margin: 0 0.4rem;
  background: color-mix(in srgb, var(--pj-border) 85%, transparent);
}

.wizard-stepper__item.is-done .wizard-stepper__line {
  background: #16a34a;
}

.step-basica {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(200px, 0.6fr);
  gap: 1rem 1.15rem;
  align-items: start;
}

.step-basica__form,
.step-block {
  display: flex;
  flex-direction: column;
  gap: 0.95rem;
  min-width: 0;
}

.media-uploads {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}

.basic-config-section {
  padding: 1rem;
  border: 1px solid var(--pj-border);
  border-radius: 12px;
  /*background: color-mix(in srgb, var(--pj-bg-elevated) 92%, var(--p-primary-color) 8%);*/
}

.basic-config-section__head {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  margin-bottom: 0.9rem;
}

.basic-config-section__head > i {
  display: grid;
  place-items: center;
  flex: 0 0 2rem;
  width: 2rem;
  height: 2rem;
  border-radius: 8px;
  color: var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 12%, transparent);
}

.basic-config-section__head h3,
.basic-config-section__head p {
  margin: 0;
}

.basic-config-section__head p {
  margin-top: 0.15rem;
  color: var(--pj-text-muted);
  font-size: 0.82rem;
}

.basic-config-section__subtitle {
  margin: 1rem 0 0.65rem;
}

.step-section-title {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 0.15rem;
}

.step-section-title i {
  color: var(--pj-primary, #2563eb);
}

.step-section-title h2 {
  margin: 0;
  font-size: 1.05rem;
}

.step-lead {
  margin: -0.35rem 0 0.25rem;
  color: var(--pj-text-muted);
  font-size: 0.9rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field--row {
  flex-direction: row;
  align-items: center;
  gap: 0.75rem;
}

.field__label-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
}

.char-count {
  font-size: 0.75rem;
  color: var(--pj-text-muted);
}

.char-count.is-limit {
  color: #dc2626;
  font-weight: 600;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.9rem;
}

.w-full {
  width: 100%;
}

.audience-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.audience-chip {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  color: var(--pj-text-muted);
}

.audience-chip.is-active.badge--conquistadores {
  background: color-mix(in srgb, #ea580c 16%, transparent);
  border-color: #ea580c;
  color: #c2410c;
}

.audience-chip.is-active.badge--aventureros {
  background: color-mix(in srgb, #16a34a 16%, transparent);
  border-color: #16a34a;
  color: #15803d;
}

.audience-chip.is-active.badge--guias {
  background: color-mix(in srgb, #7c3aed 16%, transparent);
  border-color: #7c3aed;
  color: #6d28d9;
}

.audience-chip.is-active.badge--all {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-color: #2563eb;
  color: #1d4ed8;
}

.dropzone {
  position: relative;
  display: block;
  border: 1.5px dashed color-mix(in srgb, var(--pj-border) 90%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-navy) 3%, #fff);
  min-height: 2.4rem;
  overflow: hidden;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}

.dropzone:hover {
  border-color: var(--pj-primary, #2563eb);
  background: color-mix(in srgb, #2563eb 5%, #fff);
}

.dropzone--compact {
  min-height: 2.4rem;
}

.dropzone--banner {
  min-height: 2.4rem;
}

.dropzone img {
  width: 100%;
  height: 2.6rem;
  object-fit: cover;
  display: block;
}

.dropzone__empty {
  height: 100%;
  min-height: inherit;
  display: grid;
  place-content: center;
  gap: 0.05rem;
  text-align: center;
  padding: 0.4rem 0.5rem;
  color: var(--pj-text-muted);
}

.dropzone__empty i {
  font-size: 0.85rem;
  color: var(--pj-primary, #2563eb);
}

.dropzone__empty strong {
  color: var(--pj-text, #0f172a);
  font-size: 0.68rem;
  line-height: 1.2;
  font-weight: 600;
}

.dropzone__empty span {
  font-size: 0.6rem;
  line-height: 1.2;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

.preview-label {
  margin: 0 0 0.5rem;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--pj-text-muted);
}

.event-preview-card {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 14px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 8px 24px color-mix(in srgb, var(--pj-navy) 8%, transparent);
}

.event-preview-card__media {
  position: relative;
  aspect-ratio: 16 / 9;
  background: color-mix(in srgb, var(--pj-navy) 8%, #e2e8f0);
}

.event-preview-card__media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.event-preview-card__placeholder {
  height: 100%;
  display: grid;
  place-content: center;
  color: color-mix(in srgb, var(--pj-navy) 35%, transparent);
  font-size: 1.75rem;
}

.event-preview-card__status {
  position: absolute;
  top: 0.65rem;
  left: 0.65rem;
  background: #f59e0b;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
}

.event-preview-card__body {
  padding: 0.9rem 1rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.event-preview-card__body h3 {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.25;
}

.event-preview-card__type {
  align-self: flex-start;
  font-size: 0.72rem;
  font-weight: 700;
  color: #1d4ed8;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-radius: 999px;
  padding: 0.15rem 0.55rem;
}

.event-preview-card__type--audience {
  color: #0f766e;
  background: color-mix(in srgb, #14b8a6 14%, transparent);
}

.event-preview-card__meta {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: var(--pj-text-muted);
}

.event-preview-card__desc {
  margin: 0.25rem 0 0;
  font-size: 0.84rem;
  color: color-mix(in srgb, var(--pj-text) 75%, transparent);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.event-preview-card__stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin-top: 0.55rem;
}

.event-preview-card__stats > div {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 10px;
  padding: 0.45rem 0.55rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.event-preview-card__stats span {
  font-size: 0.68rem;
  color: var(--pj-text-muted);
}

.event-preview-card__stats strong {
  font-size: 0.9rem;
}

.tips-card {
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, #f59e0b 28%, transparent);
  background: color-mix(in srgb, #fbbf24 10%, #fff);
  padding: 0.9rem 1rem;
}

.tips-card__head {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.45rem;
}

.tips-card__head i {
  color: #d97706;
}

.tips-card__head h3 {
  margin: 0;
  font-size: 0.92rem;
}

.tips-card ul {
  margin: 0;
  padding-left: 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.8rem;
  color: color-mix(in srgb, var(--pj-text) 80%, transparent);
}

.config-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.35rem 0 0.85rem;
}

.config-tabs button {
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 999px;
  padding: 0.4rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: #475569;
  cursor: pointer;
}

.config-tabs button.is-active {
  background: var(--pj-navy);
  border-color: var(--pj-navy);
  color: #fff;
}

.config-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.config-section--payment { order: 1; }
.config-section--insurance { order: 2; }
.config-section--scoring { order: 3; }
.config-section--categories { order: 4; }
.config-section--criteria { order: 5; }
.config-section--services { order: 6; }

.config-section h3 {
  margin: 0;
  font-size: 0.95rem;
}

.toggle-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.65rem;
}

.toggle-item {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.88rem;
  cursor: pointer;
}

.review-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.85rem;
}

.review-card {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.9rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.review-card h3 {
  margin: 0;
  font-size: 0.95rem;
}

.review-card dl {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.review-card dt {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--pj-text-muted);
}

.review-card dd {
  margin: 0.1rem 0 0;
  font-size: 0.9rem;
  word-break: break-word;
}

.wizard__footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.wizard__footer-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-left: auto;
}

@media (max-width: 1100px) {
  .wizard__main,
  .wizard__main.preview-open {
    grid-template-columns: 1fr;
  }

  .wizard-preview {
    position: static;
    order: -1;
  }

  .wizard-preview.is-collapsed {
    width: 100%;
  }

  .wizard-preview.is-collapsed .wizard-preview__toggle {
    writing-mode: horizontal-tb;
    flex-direction: row;
    min-height: auto;
  }

  .wizard-preview.is-collapsed .wizard-preview__toggle span {
    transform: none;
  }

  .step-basica {
    grid-template-columns: minmax(0, 1.2fr) minmax(200px, 0.8fr);
  }

  .step-basica__tips {
    grid-column: 1 / -1;
  }
}

@media (max-width: 768px) {
  .wizard__body {
    padding: 1rem;
  }

  .step-basica,
  .media-uploads {
    grid-template-columns: 1fr;
  }

  .wizard-stepper__label {
    display: none;
  }

  .wizard-stepper__item.is-active .wizard-stepper__label {
    display: inline;
  }

  .wizard__footer {
    flex-direction: column;
    align-items: stretch;
  }

  .wizard__footer-nav {
    margin-left: 0;
    width: 100%;
  }

  .wizard__footer-nav :deep(.p-button) {
    flex: 1;
  }
}

.descuentos-block {
  margin-top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.descuentos-block__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.descuentos-block__head h4 {
  margin: 0;
  font-size: 1rem;
}

.descuentos-table {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.descuentos-table__row {
  display: grid;
  grid-template-columns: minmax(8rem, 1.4fr) minmax(6rem, 7rem) minmax(6rem, 8rem) auto;
  gap: 0.55rem;
  align-items: center;
}

.descuentos-table__preview {
  font-size: 0.82rem;
  white-space: nowrap;
}

.services-add {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: center;
  margin-bottom: 0.85rem;
}

.services-add__select {
  flex: 1 1 16rem;
  min-width: 12rem;
}

.services-table {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.services-table__row {
  display: grid;
  grid-template-columns: 1fr minmax(8rem, 10rem) auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.65rem 0.75rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 10px;
}

.services-table__info {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.services-table__price {
  width: 100%;
}

.mt-3 {
  margin-top: 0.75rem;
}
</style>
