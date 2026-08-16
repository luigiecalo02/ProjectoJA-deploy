<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import AutoComplete from 'primevue/autocomplete'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import PageLoader from '@/components/PageLoader.vue'
import ComprobanteComments from '@/components/events/ComprobanteComments.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type {
  EventoDescuentoDirectiva,
  EventoAcompanantePersona,
  EventoInscripcion,
  EventoInscripcionComprobante,
  EventoInscripcionComprobanteComentario,
  EventoInscripcionMovimiento,
  EventoInscripcionMovimientoParticipante,
  EventoProductoServicioOferta,
  RosterCobertura,
} from '@/modules/events/types'

type Step = 'participantes' | 'resumen' | 'pago'
type ParticipantType =
  | 'miembro'
  | 'directiva'
  | 'acompanante'
  | 'acompanante_menor'
  | 'visitante_pasadia'
type BoardRole = 'director' | 'subdirector' | 'secretario' | 'tesorero'

interface ParticipantDraft {
  ref: string
  personaId: number | null
  tipo: ParticipantType
  cargoDirectiva: BoardRole | null
  nombre: string
  identificacion: string
  fechaNacimiento: string | null
  parentesco: string
  descuentoCodigo: string | null
  seleccionado: boolean
  cubierta: boolean
  coberturaEstado?: string
  retainedInsuranceValue: number
}

interface ServiceDraft {
  enabled: boolean
  cantidad: number
}

interface SummaryServiceChange {
  key: string
  name: string
  quantityDelta: number
  valueDelta: number
  quantityLabel: string
}

interface SummaryRow {
  ref: string
  name: string
  type: ParticipantType
  registration: number
  insurance: number
  services: number
  total: number
  change: 'new' | 'modified' | 'removed' | 'current'
  serviceChanges: SummaryServiceChange[]
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const uploading = ref(false)
const editingReceiptId = ref<number | null>(null)
const receiptFileInputKey = ref(0)
const currentStep = ref<Step>('participantes')
const roster = ref<RosterCobertura | null>(null)
const offers = ref<EventoProductoServicioOferta[]>([])
const members = ref<ParticipantDraft[]>([])
const companions = ref<ParticipantDraft[]>([])
const serviceDrafts = reactive<Record<string, ServiceDraft>>({})
const inscripcion = ref<EventoInscripcion | null>(null)
const comprobantes = ref<EventoInscripcionComprobante[]>([])
const companionDialog = ref(false)
const companionDialogKind = ref<'acompanante' | 'pasadia'>('acompanante')
const companionMode = ref<'existing' | 'new'>('existing')
const selectedCompanionPersona = ref<EventoAcompanantePersona | null>(null)
const companionSearchShell = ref<HTMLElement | null>(null)
const companionCandidates = ref<EventoAcompanantePersona[]>([])
const searchingCompanions = ref(false)
const savingCompanion = ref(false)

const companionForm = reactive({
  tipoIdentificacion: 'CC',
  identificacion: '',
  nombre1: '',
  nombre2: '',
  apellido1: '',
  apellido2: '',
  fechaNacimiento: null as string | null,
  sexo: null as string | null,
  telefono: '',
  correo: '',
  parentesco: '',
  menor: false,
  pasadia: false,
  pasadiaDias: 1,
})

const comprobanteForm = reactive({
  valor: null as number | null,
  archivo: null as File | null,
})

const eventId = computed(() => Number(route.params.id))
const isEnrollmentModification = computed(() => Boolean(inscripcion.value?.id))
const activeOffers = computed(() => offers.value.filter((offer) => offer.activo && offer.id))
const tableOffers = computed(() =>
  activeOffers.value.filter((offer) => offer.producto?.tipo !== 'PASADIA'),
)
const selectedParticipants = computed(() => [
  ...members.value.filter((member) => member.seleccionado),
  ...companions.value,
])

const descuentos = computed<EventoDescuentoDirectiva[]>(
  () => roster.value?.evento.descuentos_directiva ?? [],
)

const descuentoOptions = computed(() => [
  { label: t('events.enrollNoDiscount'), value: null },
  ...descuentos.value.map((item) => ({
    label: `${item.nombre} (${Number(item.porcentaje)}%)`,
    value: item.codigo,
  })),
])

const boardRoleOptions = computed(() => [
  { label: t('events.enrollTypeMember'), value: null },
  { label: t('events.enrollBoardDirector'), value: 'director' },
  { label: t('events.enrollBoardDeputyDirector'), value: 'subdirector' },
  { label: t('events.enrollBoardSecretary'), value: 'secretario' },
  { label: t('events.enrollBoardTreasurer'), value: 'tesorero' },
])

const identificationTypeOptions = ['CC', 'TI', 'CE', 'PASAPORTE', 'RC']
const sexOptions = computed(() => [
  { label: t('events.enrollCompanionMale'), value: 'M' },
  { label: t('events.enrollCompanionFemale'), value: 'F' },
])

const steps = computed(() => [
  { key: 'participantes' as Step, label: t('events.enrollStepParticipants') },
  { key: 'resumen' as Step, label: t('events.enrollStepSummary') },
  { key: 'pago' as Step, label: t('events.enrollStepPayment') },
])

const stepIndex = computed(() => steps.value.findIndex((step) => step.key === currentStep.value))

function serviceKey(participantRef: string, offerId: number): string {
  return `${participantRef}:${offerId}`
}

function serviceDraft(participantRef: string, offerId: number): ServiceDraft {
  const key = serviceKey(participantRef, offerId)
  if (!serviceDrafts[key]) {
    serviceDrafts[key] = { enabled: false, cantidad: 1 }
  }
  return serviceDrafts[key]
}

function basePrice(participant: ParticipantDraft): number {
  const event = roster.value?.evento
  if (!event?.requiere_pago) return 0
  const late = event.inscripcion_fuera_tiempo
  const generalPrice = late ? (event.precio_fuera_tiempo ?? event.precio) : event.precio
  switch (participant.tipo) {
    case 'visitante_pasadia':
      return 0
    case 'directiva':
      return Number(
        (late ? event.precio_directiva_fuera_tiempo : null) ??
          event.precio_directiva ??
          generalPrice ??
          0,
      )
    case 'acompanante':
      return Number(
        (late ? event.precio_acompanante_fuera_tiempo : null) ??
          event.precio_acompanante ??
          generalPrice ??
          0,
      )
    case 'acompanante_menor':
      return Number(
        (late ? event.precio_acompanante_menor_fuera_tiempo : null) ??
          event.precio_acompanante_menor ??
          (late ? event.precio_acompanante_fuera_tiempo : null) ??
          event.precio_acompanante ??
          generalPrice ??
          0,
      )
    default:
      return Number(generalPrice ?? 0)
  }
}

function discountPercentage(participant: ParticipantDraft): number {
  const discount = descuentos.value.find((item) => item.codigo === participant.descuentoCodigo)
  return Math.max(0, Math.min(100, Number(discount?.porcentaje ?? 0)))
}

function enrollmentPrice(participant: ParticipantDraft): number {
  return basePrice(participant) * (1 - discountPercentage(participant) / 100)
}

function insurancePrice(participant: ParticipantDraft): number {
  if (participant.retainedInsuranceValue > 0) return participant.retainedInsuranceValue
  if (!roster.value?.evento.requiere_seguro || participant.cubierta) return 0
  return Number(roster.value.evento.seguro_valor ?? 0)
}

function insuranceStatus(participant: ParticipantDraft): {
  label: string
  className: string
} {
  if (!roster.value?.evento.requiere_seguro || participant.coberturaEstado === 'NO_REQUIERE') {
    return {
      label: t('events.enrollWizardInsuranceNotRequired'),
      className: 'not-required',
    }
  }
  return participant.cubierta
    ? { label: t('events.enrollWizardInsured'), className: 'insured' }
    : { label: t('events.enrollWizardUninsured'), className: 'uninsured' }
}

function participantTypeLabel(type: ParticipantType): string {
  const key = {
    miembro: 'enrollTypeMember',
    directiva: 'enrollTypeDirective',
    acompanante: 'enrollTypeCompanion',
    acompanante_menor: 'enrollTypeMinorCompanion',
    visitante_pasadia: 'enrollTypeDayVisitor',
  }[type]
  return t(`events.${key}`)
}

function onMemberRoleChange(participant: ParticipantDraft): void {
  participant.tipo = participant.cargoDirectiva ? 'directiva' : 'miembro'
  if (!participant.cargoDirectiva) {
    participant.descuentoCodigo = null
    return
  }

  const aliases: Record<BoardRole, string[]> = {
    director: ['director'],
    subdirector: ['subdirector'],
    secretario: ['secretario', 'secretaria'],
    tesorero: ['tesorero', 'economia', 'economa'],
  }
  participant.descuentoCodigo =
    aliases[participant.cargoDirectiva].find((code) =>
      descuentos.value.some((discount) => discount.codigo === code),
    ) ?? null
}

function canUseOffer(
  participant: ParticipantDraft,
  offer: EventoProductoServicioOferta,
): boolean {
  const isDayPass = offer.producto?.tipo === 'PASADIA'
  const isDayVisitor = participant.tipo === 'visitante_pasadia'
  return isDayPass ? isDayVisitor : !isDayVisitor
}

function serviceTotal(participant: ParticipantDraft, offer: EventoProductoServicioOferta): number {
  if (!offer.id) return 0
  const draft = serviceDraft(participant.ref, offer.id)
  if (!draft.enabled) return 0
  return Number(offer.precio) * Math.max(1, draft.cantidad)
}

function serviceQuantitySuffix(offer: EventoProductoServicioOferta): string {
  const tipo = offer.producto?.tipo
  return tipo === 'PASADIA' || tipo === 'CABANA' ? ` ${t('events.enrollDays')}` : ''
}

function participantTotal(participant: ParticipantDraft): number {
  return (
    enrollmentPrice(participant) +
    insurancePrice(participant) +
    activeOffers.value.reduce((sum, offer) => sum + serviceTotal(participant, offer), 0)
  )
}

const totals = computed(() => {
  const participantes = selectedParticipants.value
  const inscripciones = participantes.reduce((sum, participant) => sum + enrollmentPrice(participant), 0)
  const seguros = participantes.reduce((sum, participant) => sum + insurancePrice(participant), 0)
  const servicios = activeOffers.value.reduce(
    (sum, offer) =>
      sum + participantes.reduce((subtotal, participant) => subtotal + serviceTotal(participant, offer), 0),
    0,
  )
  return {
    inscripciones,
    seguros,
    servicios,
    total: inscripciones + seguros + servicios,
  }
})

const summaryRows = computed<SummaryRow[]>(() => {
  const movements = inscripcion.value?.movimientos ?? []
  const previousParticipants = movements.length
    ? movements[movements.length - 1].snapshot.participantes
    : []
  const previousByRef = new Map(previousParticipants.map((participant) => [participant.ref, participant]))
  const isModification = Boolean(inscripcion.value?.id && previousParticipants.length)
  const rows: SummaryRow[] = []

  for (const participant of selectedParticipants.value) {
    const previous = previousByRef.get(participant.ref)
    const registration = enrollmentPrice(participant)
    const insurance = insurancePrice(participant)
    const previousServices = new Map<number, { quantity: number; value: number }>()
    for (const service of previous?.servicios ?? []) {
      const id = Number(service.evento_producto_servicio_id)
      const accumulated = previousServices.get(id) ?? { quantity: 0, value: 0 }
      accumulated.quantity += Number(service.cantidad)
      accumulated.value += Number(service.valor_total)
      previousServices.set(id, accumulated)
    }

    const serviceChanges = activeOffers.value.flatMap((offer): SummaryServiceChange[] => {
      if (!offer.id || !canUseOffer(participant, offer)) return []
      const draft = serviceDraft(participant.ref, offer.id)
      const currentQuantity = draft.enabled ? Math.max(1, draft.cantidad) : 0
      const currentValue = draft.enabled ? serviceTotal(participant, offer) : 0
      const old = previousServices.get(offer.id) ?? { quantity: 0, value: 0 }
      previousServices.delete(offer.id)
      const quantityDelta = currentQuantity - old.quantity
      const valueDelta = currentValue - old.value
      if (isModification && quantityDelta === 0 && Math.abs(valueDelta) < 0.01) return []
      if (!isModification && currentQuantity === 0) return []
      return [{
        key: `${participant.ref}:${offer.id}`,
        name: offer.producto?.nombre || offer.producto?.tipo || t('events.servicesTitle'),
        quantityDelta,
        valueDelta,
        quantityLabel:
          offer.producto?.tipo === 'PASADIA' || offer.producto?.tipo === 'CABANA'
            ? t('events.enrollDays')
            : t('events.enrollUnits'),
      }]
    })

    for (const [offerId, old] of previousServices) {
      const previousService = previous?.servicios?.find(
        (service) => Number(service.evento_producto_servicio_id) === offerId,
      )
      serviceChanges.push({
        key: `${participant.ref}:${offerId}:removed`,
        name: previousService?.producto || t('events.servicesTitle'),
        quantityDelta: -old.quantity,
        valueDelta: -old.value,
        quantityLabel:
          previousService?.tipo === 'PASADIA' || previousService?.tipo === 'CABANA'
            ? t('events.enrollDays')
            : t('events.enrollUnits'),
      })
    }

    const registrationDelta = isModification
      ? registration - Number(previous?.valor_inscripcion ?? 0)
      : registration
    const insuranceDelta = isModification
      ? insurance - Number(previous?.valor_seguro ?? 0)
      : insurance
    const servicesDelta = serviceChanges.reduce((sum, service) => sum + service.valueDelta, 0)
    const metadataChanged = Boolean(previous) && (
      previous?.tipo !== participant.tipo
      || previous?.cargo_directiva !== participant.cargoDirectiva
      || Number(previous?.descuento_porcentaje ?? 0) !== discountPercentage(participant)
    )
    const changed = !previous
      || Math.abs(registrationDelta) >= 0.01
      || Math.abs(insuranceDelta) >= 0.01
      || Math.abs(servicesDelta) >= 0.01
      || metadataChanged

    if (!isModification || changed) {
      rows.push({
        ref: participant.ref,
        name: participant.nombre,
        type: participant.tipo,
        registration: registrationDelta,
        insurance: insuranceDelta,
        services: servicesDelta,
        total: registrationDelta + insuranceDelta + servicesDelta,
        change: !isModification ? 'current' : previous ? 'modified' : 'new',
        serviceChanges,
      })
    }
    previousByRef.delete(participant.ref)
  }

  if (isModification) {
    for (const previous of previousByRef.values()) {
      const services = (previous.servicios ?? []).reduce(
        (sum, service) => sum + Number(service.valor_total),
        0,
      )
      rows.push({
        ref: previous.ref,
        name: previous.nombre || previous.identificacion || previous.ref,
        type: previous.tipo as ParticipantType,
        registration: -Number(previous.valor_inscripcion),
        insurance: -Number(previous.valor_seguro),
        services: -services,
        total: -snapshotParticipantTotal(previous),
        change: 'removed',
        serviceChanges: [],
      })
    }
  }

  return rows
})

const summaryAmountToPay = computed(() =>
  Math.max(0, summaryRows.value.reduce((sum, row) => sum + row.total, 0)),
)

const paymentSummary = computed(() => {
  const total = Number(inscripcion.value?.total_declarado ?? totals.value.total)
  const totalConsignado = comprobantes.value
    .filter((receipt) => receipt.estado !== 'rechazado')
    .reduce((sum, receipt) => sum + Number(receipt.valor), 0)
  const totalAprobado = comprobantes.value
    .filter((receipt) => receipt.estado === 'aprobado')
    .reduce((sum, receipt) => sum + Number(receipt.valor), 0)

  return {
    total,
    totalConsignado,
    totalAprobado,
    saldo: Math.max(0, total - totalConsignado),
  }
})

const currentMovement = computed<EventoInscripcionMovimiento | null>(() => {
  const movimientos = inscripcion.value?.movimientos ?? []
  return movimientos.length ? movimientos[movimientos.length - 1] : null
})
const movementHistory = computed(() => [...(inscripcion.value?.movimientos ?? [])].reverse())

function movementPaymentState(movimiento: EventoInscripcionMovimiento | null) {
  if (!movimiento) return { valor: 0, consignado: 0, saldo: 0 }
  const valor = Math.max(0, Number(movimiento.valor_diferencia))
  const consignado = comprobantes.value
    .filter((receipt) => receipt.movimiento_id === movimiento.id && receipt.estado !== 'rechazado')
    .reduce((sum, receipt) => sum + Number(receipt.valor), 0)

  return {
    valor,
    consignado,
    saldo: Math.max(0, Math.min(paymentSummary.value.saldo, valor - consignado)),
  }
}

const currentMovementPayment = computed(() => movementPaymentState(currentMovement.value))
const paymentMovement = computed<EventoInscripcionMovimiento | null>(() => {
  return movementHistory.value.find((movement) => movementPaymentState(movement).saldo > 0) ?? null
})

function suggestedPayment(): number | null {
  return movementPaymentState(paymentMovement.value).saldo || paymentSummary.value.saldo || null
}

function movementChangeCount(movement: EventoInscripcionMovimiento): number {
  return Object.values(movement.cambios).reduce((sum, items) => sum + items.length, 0)
}

function receiptsForMovement(movementId: number): EventoInscripcionComprobante[] {
  return comprobantes.value.filter((receipt) => receipt.movimiento_id === movementId)
}

function onReceiptCommentAdded(
  receiptId: number,
  comment: EventoInscripcionComprobanteComentario,
): void {
  const receipt = comprobantes.value.find((item) => item.id === receiptId)
  if (receipt) receipt.comentarios = [...(receipt.comentarios ?? []), comment]
  for (const movement of inscripcion.value?.movimientos ?? []) {
    const movementReceipt = movement.comprobantes?.find((item) => item.id === receiptId)
    if (movementReceipt && movementReceipt !== receipt) {
      movementReceipt.comentarios = [...(movementReceipt.comentarios ?? []), comment]
    }
  }
}

function snapshotParticipantTotal(participant: EventoInscripcionMovimientoParticipante): number {
  return Number(participant.valor_inscripcion)
    + Number(participant.valor_seguro)
    + (participant.servicios ?? []).reduce((sum, service) => sum + Number(service.valor_total), 0)
}

function snapshotServicesTotal(participant: EventoInscripcionMovimientoParticipante): number {
  return (participant.servicios ?? []).reduce(
    (sum, service) => sum + Number(service.valor_total),
    0,
  )
}

function money(value: number | string | null | undefined): string {
  return Number(value ?? 0).toLocaleString('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  })
}

function age(fecha: string | null): number | null {
  if (!fecha) return null
  const birth = new Date(`${fecha}T00:00:00`)
  if (Number.isNaN(birth.getTime())) return null
  const today = new Date()
  let years = today.getFullYear() - birth.getFullYear()
  const beforeBirthday =
    today.getMonth() < birth.getMonth() ||
    (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())
  if (beforeBirthday) years--
  return years
}

function resetCompanionForm(): void {
  companionForm.tipoIdentificacion = 'CC'
  companionForm.identificacion = ''
  companionForm.nombre1 = ''
  companionForm.nombre2 = ''
  companionForm.apellido1 = ''
  companionForm.apellido2 = ''
  companionForm.fechaNacimiento = null
  companionForm.sexo = null
  companionForm.telefono = ''
  companionForm.correo = ''
  companionForm.parentesco = ''
  companionForm.menor = false
  companionForm.pasadia = false
  companionForm.pasadiaDias = 1
  selectedCompanionPersona.value = null
  companionCandidates.value = []
}

function openCompanionDialog(kind: 'acompanante' | 'pasadia'): void {
  resetCompanionForm()
  companionDialogKind.value = kind
  companionForm.pasadia = kind === 'pasadia'
  companionMode.value = 'existing'
  companionDialog.value = true
}

function focusCompanionSearch(): void {
  const input = companionSearchShell.value?.querySelector('input')
  input?.focus()
}

function appendCompanion(persona: EventoAcompanantePersona): void {
  if (
    members.value.some((member) => member.personaId === persona.id)
    || companions.value.some((companion) => companion.personaId === persona.id)
  ) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.enrollCompanionAlreadyAdded'),
      life: 3000,
    })
    return
  }
  const dayPassOffer = activeOffers.value.find((offer) => offer.producto?.tipo === 'PASADIA')
  if (companionForm.pasadia && !dayPassOffer?.id) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.enrollDayPassUnavailable'),
      life: 3000,
    })
    return
  }
  const ref = `persona:${persona.id}`
  const participant: ParticipantDraft = {
    ref,
    personaId: persona.id,
    tipo: companionForm.pasadia
      ? 'visitante_pasadia'
      : companionForm.menor
        ? 'acompanante_menor'
        : 'acompanante',
    nombre: persona.full_name,
    cargoDirectiva: null,
    identificacion: persona.identificacion,
    fechaNacimiento: persona.fecha_nacimiento ?? null,
    parentesco: companionForm.parentesco.trim(),
    descuentoCodigo: null,
    seleccionado: true,
    cubierta: Boolean(persona.cubierta),
    retainedInsuranceValue: 0,
  }
  companions.value.push(participant)
  if (dayPassOffer?.id && participant.tipo === 'visitante_pasadia') {
    const draft = serviceDraft(participant.ref, dayPassOffer.id)
    draft.enabled = true
    draft.cantidad = Math.max(1, companionForm.pasadiaDias)
  }
  companionDialog.value = false
  resetCompanionForm()
}

async function searchCompanionPersonas(event: { query: string }): Promise<void> {
  if (event.query.trim().length < 3) {
    companionCandidates.value = []
    return
  }
  searchingCompanions.value = true
  try {
    const excludedIds = new Set([
      ...members.value.map((member) => member.personaId),
      ...companions.value.map((companion) => companion.personaId),
    ])
    companionCandidates.value = (await eventsService.searchCompanionPersonas(eventId.value, event.query))
      .filter((persona) => !excludedIds.has(persona.id))
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    searchingCompanions.value = false
  }
}

async function addCompanion(): Promise<void> {
  if (companionMode.value === 'existing') {
    if (!selectedCompanionPersona.value) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('events.enrollCompanionSelectRequired'),
        life: 3000,
      })
      return
    }
    appendCompanion(selectedCompanionPersona.value)
    return
  }

  if (
    !companionForm.identificacion.trim()
    || !companionForm.nombre1.trim()
    || !companionForm.apellido1.trim()
  ) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('validation.required'),
      life: 3000,
    })
    return
  }

  savingCompanion.value = true
  try {
    const persona = await eventsService.createCompanionPersona(eventId.value, {
      tipo_identificacion: companionForm.tipoIdentificacion,
      identificacion: companionForm.identificacion.trim(),
      nombre1: companionForm.nombre1.trim(),
      nombre2: companionForm.nombre2.trim() || null,
      apellido1: companionForm.apellido1.trim(),
      apellido2: companionForm.apellido2.trim() || null,
      fecha_nacimiento: companionForm.fechaNacimiento,
      sexo: companionForm.sexo,
      telefono: companionForm.telefono.trim() || null,
      correo: companionForm.correo.trim() || null,
    })
    appendCompanion(persona)
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    savingCompanion.value = false
  }
}

function removeCompanion(ref: string): void {
  companions.value = companions.value.filter((companion) => companion.ref !== ref)
  for (const key of Object.keys(serviceDrafts)) {
    if (key.startsWith(`${ref}:`)) delete serviceDrafts[key]
  }
}

function buildPayload() {
  return {
    participantes: selectedParticipants.value.map((participant) => ({
      ref: participant.ref,
      persona_id: participant.personaId,
      tipo: participant.tipo,
      cargo_directiva: participant.cargoDirectiva,
      nombre: participant.personaId ? undefined : participant.nombre,
      identificacion: participant.identificacion || undefined,
      fecha_nacimiento: participant.fechaNacimiento || undefined,
      parentesco: participant.parentesco || undefined,
      descuento_codigo: participant.descuentoCodigo,
    })),
    reservas: selectedParticipants.value.flatMap((participant) =>
      activeOffers.value.flatMap((offer) => {
        if (!offer.id || !canUseOffer(participant, offer)) return []
        const draft = serviceDraft(participant.ref, offer.id)
        if (!draft.enabled) return []
        return [{
          evento_producto_servicio_id: offer.id,
          participante_ref: participant.ref,
          cantidad: Math.max(1, draft.cantidad),
        }]
      }),
    ),
  }
}

function hydrateExistingEnrollment(existing: EventoInscripcion): void {
  for (const line of existing.personas ?? []) {
    const member = line.persona_id
      ? members.value.find((item) => item.personaId === line.persona_id)
      : null
    let participant: ParticipantDraft | null = null

    if (member) {
      member.seleccionado = line.estado !== 'cancelada'
      member.tipo = line.tipo === 'directiva' ? 'directiva' : 'miembro'
      member.cargoDirectiva = line.cargo_directiva ?? null
      member.descuentoCodigo = line.descuento_codigo ?? null
      participant = member
    } else {
      participant = {
        ref: line.referencia_cliente || `acompanante:${line.id}`,
        personaId: line.persona_id ?? null,
        tipo:
          line.tipo === 'visitante_pasadia'
            ? 'visitante_pasadia'
            : line.tipo === 'acompanante_menor'
              ? 'acompanante_menor'
              : 'acompanante',
        nombre: line.nombre ?? '',
        cargoDirectiva: line.cargo_directiva ?? null,
        identificacion: line.identificacion ?? '',
        fechaNacimiento: line.fecha_nacimiento ?? null,
        parentesco: line.parentesco ?? '',
        descuentoCodigo: line.descuento_codigo ?? null,
        seleccionado: true,
        cubierta: Number(line.valor_seguro ?? 0) === 0,
        retainedInsuranceValue: Number(line.valor_seguro ?? 0),
      }
      companions.value.push(participant)
    }

    if (!participant) continue
    for (const reserva of line.reservas ?? []) {
      const key = serviceKey(participant.ref, reserva.evento_producto_servicio_id)
      serviceDrafts[key] = {
        enabled: true,
        cantidad: Math.max(1, Number(reserva.cantidad ?? 1)),
      }
    }
  }
}

async function loadData(): Promise<void> {
  loading.value = true
  try {
    const [rosterData, offersData] = await Promise.all([
      eventsService.rosterCobertura(eventId.value),
      eventsService.eventProductosServicios(eventId.value),
    ])
    roster.value = rosterData
    offers.value = offersData
    members.value = rosterData.miembros.map((member) => {
      const participant: ParticipantDraft = {
        ref: `persona:${member.id}`,
        personaId: member.id,
        tipo: member.cargo_directiva ? 'directiva' : 'miembro',
        cargoDirectiva: member.cargo_directiva ?? null,
        nombre: member.nombre,
        identificacion: member.identificacion ?? '',
        fechaNacimiento: member.fecha_nacimiento ?? null,
        parentesco: '',
        descuentoCodigo: null,
        seleccionado: false,
        cubierta: member.cobertura.cubierta || member.cobertura.estado === 'ASEGURADO',
        coberturaEstado: member.cobertura.estado,
        retainedInsuranceValue: 0,
      }
      if (participant.cargoDirectiva) onMemberRoleChange(participant)
      return participant
    })

    const existingId = Number(route.query.inscripcion_id)
    if (Number.isFinite(existingId) && existingId > 0) {
      inscripcion.value = await eventsService.getInscripcion(existingId)
      comprobantes.value = inscripcion.value.comprobantes ?? []
      comprobanteForm.valor = suggestedPayment()
      hydrateExistingEnrollment(inscripcion.value)
      currentStep.value = 'participantes'
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
    await router.push({ name: 'events' })
  } finally {
    loading.value = false
  }
}

function goNext(): void {
  if (currentStep.value === 'participantes') {
    if (!selectedParticipants.value.length) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('events.enrollWizardSelectMembers'),
        life: 3000,
      })
      return
    }
    currentStep.value = 'resumen'
    return
  }
  if (currentStep.value === 'resumen') {
    void submitEnroll()
  }
}

function goBack(): void {
  if (currentStep.value === 'pago' && inscripcion.value) {
    router.push({ name: 'events' })
    return
  }
  if (stepIndex.value <= 0) {
    router.push({ name: 'events' })
    return
  }
  currentStep.value = steps.value[stepIndex.value - 1].key
}

async function submitEnroll(): Promise<void> {
  saving.value = true
  try {
    const created = await eventsService.enroll(eventId.value, buildPayload())
    inscripcion.value = created
    comprobantes.value = created.comprobantes ?? []
    comprobanteForm.valor = suggestedPayment()
    currentStep.value = 'pago'
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.enrollSuccess'),
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
    saving.value = false
  }
}

function onPickComprobante(event: Event): void {
  comprobanteForm.archivo = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function uploadComprobante(): Promise<void> {
  if (!inscripcion.value?.id || comprobanteForm.valor == null || !comprobanteForm.archivo) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.comprobantesRequired'),
      life: 3000,
    })
    return
  }
  uploading.value = true
  try {
    const saved = editingReceiptId.value
      ? await eventsService.replaceComprobante(editingReceiptId.value, {
          valor: comprobanteForm.valor,
          archivo: comprobanteForm.archivo,
        })
      : await eventsService.uploadComprobante(inscripcion.value.id, {
          valor: comprobanteForm.valor,
          archivo: comprobanteForm.archivo,
          movimiento_id: paymentMovement.value?.id,
        })
    if (editingReceiptId.value) {
      comprobantes.value = comprobantes.value.map((receipt) =>
        receipt.id === saved.id ? saved : receipt,
      )
      for (const movement of inscripcion.value.movimientos ?? []) {
        movement.comprobantes = (movement.comprobantes ?? []).map((receipt) =>
          receipt.id === saved.id ? saved : receipt,
        )
      }
    } else {
      comprobantes.value = [saved, ...comprobantes.value]
      const movement = inscripcion.value.movimientos?.find(
        (item) => item.id === saved.movimiento_id,
      )
      if (movement) movement.comprobantes = [saved, ...(movement.comprobantes ?? [])]
    }
    const wasReplacing = editingReceiptId.value !== null
    editingReceiptId.value = null
    comprobanteForm.archivo = null
    receiptFileInputKey.value += 1
    comprobanteForm.valor = suggestedPayment()
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: wasReplacing
        ? t('events.comprobantesReplaceSuccess')
        : t('events.comprobantesUploadSuccess'),
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
    uploading.value = false
  }
}

function startReplacingComprobante(receipt: EventoInscripcionComprobante): void {
  if (receipt.estado === 'aprobado') return
  editingReceiptId.value = receipt.id
  comprobanteForm.valor = Number(receipt.valor)
  comprobanteForm.archivo = null
  receiptFileInputKey.value += 1
}

function cancelReplacingComprobante(): void {
  editingReceiptId.value = null
  comprobanteForm.archivo = null
  receiptFileInputKey.value += 1
  comprobanteForm.valor = suggestedPayment()
}

async function removeComprobante(id: number): Promise<void> {
  try {
    await eventsService.deleteComprobante(id)
    comprobantes.value = comprobantes.value.filter((item) => item.id !== id)
    for (const movement of inscripcion.value?.movimientos ?? []) {
      movement.comprobantes = (movement.comprobantes ?? []).filter((item) => item.id !== id)
    }
    if (editingReceiptId.value === id) editingReceiptId.value = null
    comprobanteForm.valor = suggestedPayment()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

onMounted(() => void loadData())
</script>

<template>
  <section class="pj-page enroll-page">
    <header class="enroll-header">
      <Button icon="pi pi-arrow-left" text rounded :aria-label="t('common.back')" @click="router.push({ name: 'events' })" />
      <div>
        <h1 class="pj-page__title">{{ t('events.enrollWizardTitle') }}</h1>
        <p class="pj-page__subtitle">{{ roster?.evento.name }}</p>
      </div>
      <nav class="enroll-stepper">
        <button
          v-for="(step, index) in steps"
          :key="step.key"
          type="button"
          :class="{ active: currentStep === step.key, done: index < stepIndex }"
          :disabled="step.key === 'pago' && !inscripcion"
          @click="currentStep = step.key"
        >
          <span>{{ index + 1 }}</span>
          {{ step.label }}
        </button>
      </nav>
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <template v-else>
      <Message
        v-if="roster?.evento.inscripcion_fuera_tiempo"
        severity="warn"
        :closable="false"
        class="late-price-message"
      >
        {{ t('events.latePriceActive') }}
      </Message>
      <div v-show="currentStep === 'participantes'" class="enroll-layout">
        <main class="enroll-main">
          <section class="enroll-card">
            <div class="section-head">
              <div>
                <h2>{{ t('events.enrollSelectParticipants') }}</h2>
                <p>{{ t('events.enrollParticipantsLead') }}</p>
              </div>
              <div class="section-actions">
                <Button icon="pi pi-user-plus" outlined :label="t('events.enrollAddCompanion')" @click="openCompanionDialog('acompanante')" />
                <Button icon="pi pi-ticket" :label="t('events.enrollAddDayPass')" @click="openCompanionDialog('pasadia')" />
              </div>
            </div>

            <div class="table-scroll">
              <table class="enroll-table">
                <thead>
                  <tr>
                    <th>{{ t('events.enrollParticipant') }}</th>
                    <th>{{ t('events.enrollClubRole') }}</th>
                    <th>{{ t('events.insuranceTitle') }}</th>
                    <th>{{ t('events.enrollmentValue') }}</th>
                    <th>{{ t('events.enrollDiscount') }}</th>
                    <th v-for="offer in tableOffers" :key="offer.id">{{ offer.producto?.nombre }}</th>
                    <th>{{ t('events.enrollTotal') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="member in members" :key="member.ref" :class="{ muted: !member.seleccionado }">
                    <td>
                      <div class="participant-cell">
                        <Checkbox v-model="member.seleccionado" binary />
                        <div>
                          <strong>{{ member.nombre }}</strong>
                          <small>{{ member.identificacion }}<template v-if="age(member.fechaNacimiento) !== null"> · {{ age(member.fechaNacimiento) }} años</template></small>
                        </div>
                      </div>
                    </td>
                    <td>
                      <Select
                        v-model="member.cargoDirectiva"
                        :options="boardRoleOptions"
                        option-label="label"
                        option-value="value"
                        :disabled="!member.seleccionado"
                        class="board-role-select"
                        @change="onMemberRoleChange(member)"
                      />
                    </td>
                    <td>
                      <span
                        class="status"
                        :class="insuranceStatus(member).className"
                      >
                        {{ insuranceStatus(member).label }}
                      </span>
                      <small>{{ money(insurancePrice(member)) }}</small>
                    </td>
                    <td>{{ money(enrollmentPrice(member)) }}</td>
                    <td>
                      <Select v-model="member.descuentoCodigo" :options="descuentoOptions" option-label="label" option-value="value" :disabled="!member.seleccionado" class="discount-select" />
                    </td>
                    <td v-for="offer in tableOffers" :key="offer.id">
                      <div v-if="offer.id && canUseOffer(member, offer)" class="service-cell">
                        <label class="service-toggle">
                          <Checkbox v-model="serviceDraft(member.ref, offer.id).enabled" binary :disabled="!member.seleccionado" />
                          <span>{{ serviceDraft(member.ref, offer.id).enabled ? t('common.yes') : t('common.no') }}</span>
                        </label>
                        <InputNumber v-if="serviceDraft(member.ref, offer.id).enabled" v-model="serviceDraft(member.ref, offer.id).cantidad" :min="1" :suffix="serviceQuantitySuffix(offer)" show-buttons button-layout="horizontal" :disabled="!member.seleccionado" class="days-input" />
                        <small>{{ money(serviceTotal(member, offer)) }}</small>
                      </div>
                      <span v-else class="not-applicable">—</span>
                    </td>
                    <td><strong>{{ member.seleccionado ? money(participantTotal(member)) : money(0) }}</strong></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-if="companions.length" class="enroll-card">
            <div class="section-head">
              <h2>{{ t('events.enrollExternalPeopleAdded') }}</h2>
              <div class="section-actions">
                <Button icon="pi pi-user-plus" outlined size="small" :label="t('events.enrollAddCompanion')" @click="openCompanionDialog('acompanante')" />
                <Button icon="pi pi-ticket" size="small" :label="t('events.enrollAddDayPass')" @click="openCompanionDialog('pasadia')" />
              </div>
            </div>
            <div class="table-scroll">
              <table class="enroll-table">
                <thead>
                  <tr>
                    <th>{{ t('events.enrollParticipant') }}</th>
                    <th>{{ t('events.enrollType') }}</th>
                    <th>{{ t('events.insuranceTitle') }}</th>
                    <th>{{ t('events.enrollmentValue') }}</th>
                    <th>{{ t('events.enrollDiscount') }}</th>
                    <th v-for="offer in tableOffers" :key="offer.id">{{ offer.producto?.nombre }}</th>
                    <th>{{ t('events.enrollTotal') }}</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="companion in companions" :key="companion.ref">
                    <td>
                      <div class="participant-cell">
                        <div>
                          <strong>{{ companion.nombre }}</strong>
                          <small>{{ companion.identificacion }} · {{ companion.parentesco }}</small>
                        </div>
                      </div>
                    </td>
                    <td>{{ participantTypeLabel(companion.tipo) }}</td>
                    <td>
                      <span class="status" :class="insuranceStatus(companion).className">
                        {{ insuranceStatus(companion).label }}
                      </span>
                      <small>{{ money(insurancePrice(companion)) }}</small>
                    </td>
                    <td>{{ money(enrollmentPrice(companion)) }}</td>
                    <td><Select v-model="companion.descuentoCodigo" :options="descuentoOptions" option-label="label" option-value="value" :disabled="companion.tipo === 'visitante_pasadia'" class="discount-select" /></td>
                    <td v-for="offer in tableOffers" :key="offer.id">
                      <div v-if="offer.id && canUseOffer(companion, offer)" class="service-cell">
                        <label class="service-toggle">
                          <Checkbox v-model="serviceDraft(companion.ref, offer.id).enabled" binary />
                          <span>{{ serviceDraft(companion.ref, offer.id).enabled ? t('common.yes') : t('common.no') }}</span>
                        </label>
                        <InputNumber v-if="serviceDraft(companion.ref, offer.id).enabled" v-model="serviceDraft(companion.ref, offer.id).cantidad" :min="1" :suffix="serviceQuantitySuffix(offer)" show-buttons button-layout="horizontal" class="days-input" />
                        <small>{{ money(serviceTotal(companion, offer)) }}</small>
                      </div>
                      <span v-else class="not-applicable">—</span>
                    </td>
                    <td><strong>{{ money(participantTotal(companion)) }}</strong></td>
                    <td><Button icon="pi pi-trash" text rounded severity="danger" @click="removeCompanion(companion.ref)" /></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </main>

        <aside class="summary-card">
          <h2>{{ t('events.enrollSummary') }}</h2>
          <div class="summary-counts">
            <div><strong>{{ members.filter((member) => member.seleccionado).length }}</strong><span>{{ t('events.enrollMembers') }}</span></div>
            <div><strong>{{ companions.length }}</strong><span>{{ t('events.enrollCompanions') }}</span></div>
          </div>
          <dl>
            <div><dt>{{ t('events.enrollRegistrations') }}</dt><dd>{{ money(totals.inscripciones) }}</dd></div>
            <div><dt>{{ t('events.insuranceTitle') }}</dt><dd>{{ money(totals.seguros) }}</dd></div>
            <div><dt>{{ t('events.servicesTitle') }}</dt><dd>{{ money(totals.servicios) }}</dd></div>
          </dl>
          <div class="grand-total"><span>{{ t('events.enrollTotalPay') }}</span><strong>{{ money(totals.total) }}</strong></div>
          <Button :label="t('events.enrollContinueSummary')" icon="pi pi-arrow-right" icon-pos="right" fluid @click="goNext" />
        </aside>
      </div>

      <section v-show="currentStep === 'resumen'" class="enroll-card summary-step">
        <h2>{{ t('events.enrollStepSummary') }}</h2>
        <p class="pj-muted">
          {{
            isEnrollmentModification
              ? t('events.enrollReviewChangesLead')
              : t('events.enrollReviewLead')
          }}
        </p>
        <Message
          v-if="isEnrollmentModification && !summaryRows.length"
          severity="info"
          :closable="false"
        >
          {{ t('events.enrollNoPendingChanges') }}
        </Message>
        <div v-else class="summary-table-wrap">
          <table class="summary-table">
            <thead>
              <tr>
                <th>{{ t('events.enrollParticipant') }}</th>
                <th>{{ t('events.enrollmentValue') }}</th>
                <th>{{ t('events.insuranceTitle') }}</th>
                <th>{{ t('events.servicesTitle') }}</th>
                <th>{{ t('events.enrollNewValueToPay') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in summaryRows" :key="row.ref">
                <td>
                  <strong>{{ row.name }}</strong>
                  <span>{{ participantTypeLabel(row.type) }}</span>
                  <small v-if="isEnrollmentModification" class="summary-change-label">
                    {{
                      row.change === 'new'
                        ? t('events.enrollSummaryNew')
                        : row.change === 'removed'
                          ? t('events.enrollSummaryRemoved')
                          : t('events.enrollSummaryModified')
                    }}
                  </small>
                </td>
                <td :class="{ 'negative-value': row.registration < 0 }">
                  {{ money(row.registration) }}
                </td>
                <td :class="{ 'negative-value': row.insurance < 0 }">
                  {{ money(row.insurance) }}
                </td>
                <td :class="{ 'negative-value': row.services < 0 }">
                  <strong>{{ money(row.services) }}</strong>
                  <ul v-if="row.serviceChanges.length" class="summary-service-changes">
                    <li v-for="service in row.serviceChanges" :key="service.key">
                      {{ service.name }}:
                      <template v-if="service.quantityDelta > 0">
                        +{{ service.quantityDelta }} {{ service.quantityLabel }}
                      </template>
                      <template v-else>
                        {{ service.quantityDelta }} {{ service.quantityLabel }}
                      </template>
                      · {{ money(service.valueDelta) }}
                    </li>
                  </ul>
                </td>
                <td class="summary-row-total" :class="{ 'negative-value': row.total < 0 }">
                  {{ money(row.total) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="grand-total summary-total">
          <span>
            {{
              isEnrollmentModification
                ? t('events.enrollNewValueToPay')
                : t('events.enrollTotalPay')
            }}
          </span>
          <strong>{{ money(summaryAmountToPay) }}</strong>
        </div>
      </section>

      <section v-show="currentStep === 'pago'" class="enroll-card payment-step">
        <h2>{{ t('events.enrollStepPayment') }}</h2>
        <Message v-if="inscripcion" severity="info" :closable="false">
          {{ t('events.inscripcionEstado') }}:
          {{ t(`events.revisionEstado.${inscripcion.estado}`, inscripcion.estado) }}
        </Message>
        <section v-if="currentMovement" class="current-change">
          <div class="current-change__head">
            <div>
              <small>{{ t('events.enrollCurrentChange', { number: currentMovement.numero }) }}</small>
              <strong>{{ t('events.enrollNewValueToPay') }}: {{ money(currentMovementPayment.valor) }}</strong>
            </div>
            <div>
              <small>{{ t('events.comprobantesPendingBalance') }}</small>
              <strong>{{ money(currentMovementPayment.saldo) }}</strong>
            </div>
          </div>
          <ul class="change-list">
            <li v-for="item in currentMovement.cambios.participantes_agregados" :key="`pa-${item.ref}`">
              <i class="pi pi-user-plus" />
              {{ t('events.enrollParticipantAdded') }}: {{ item.nombre }}
              ({{ money(Number(item.valor_inscripcion) + Number(item.valor_seguro)) }})
            </li>
            <li v-for="item in currentMovement.cambios.servicios_agregados" :key="`sa-${item.participante_ref}-${item.clave}`">
              <i class="pi pi-plus-circle" />
              {{ t('events.enrollServiceAdded') }}: {{ item.producto }} — {{ item.participante_nombre }}
              ({{ money(item.valor_total) }})
            </li>
            <li v-for="item in currentMovement.cambios.participantes_modificados" :key="`pm-${item.ref}`">
              <i class="pi pi-user-edit" />
              {{ t('events.enrollParticipantUpdated') }}: {{ item.nombre }}
              ({{ money(Number(item.anterior.valor_inscripcion) + Number(item.anterior.valor_seguro)) }}
              → {{ money(Number(item.nuevo.valor_inscripcion) + Number(item.nuevo.valor_seguro)) }})
            </li>
            <li v-for="item in currentMovement.cambios.servicios_modificados" :key="`sm-${item.clave}`">
              <i class="pi pi-pencil" />
              {{ t('events.enrollServiceUpdated') }}: {{ item.nuevo.producto }} — {{ item.nuevo.participante_nombre }}
              ({{ money(item.anterior.valor_total) }} → {{ money(item.nuevo.valor_total) }})
            </li>
            <li v-for="item in currentMovement.cambios.participantes_retirados" :key="`pr-${item.ref}`">
              <i class="pi pi-user-minus" />
              {{ t('events.enrollParticipantRemoved') }}: {{ item.nombre }}
            </li>
            <li v-for="item in currentMovement.cambios.servicios_retirados" :key="`sr-${item.participante_ref}-${item.clave}`">
              <i class="pi pi-minus-circle" />
              {{ t('events.enrollServiceRemoved') }}: {{ item.producto }} — {{ item.participante_nombre }}
            </li>
          </ul>
          <div class="current-change__totals">
            <span>{{ t('events.enrollPreviousTotal') }}: {{ money(currentMovement.total_anterior) }}</span>
            <span>{{ t('events.enrollUpdatedTotal') }}: <strong>{{ money(currentMovement.total_nuevo) }}</strong></span>
          </div>
        </section>
        <div class="payment-summary">
          <div>
            <small>{{ t('events.enrollTotalPay') }}</small>
            <strong>{{ money(paymentSummary.total) }}</strong>
          </div>
          <div>
            <small>{{ t('events.comprobantesTotalConsigned') }}</small>
            <strong>{{ money(paymentSummary.totalConsignado) }}</strong>
          </div>
          <div>
            <small>{{ t('events.comprobantesApprovedTotal') }}</small>
            <strong>{{ money(paymentSummary.totalAprobado) }}</strong>
          </div>
          <div class="payment-summary__balance">
            <small>{{ t('events.comprobantesPendingBalance') }}</small>
            <strong>{{ money(paymentSummary.saldo) }}</strong>
          </div>
        </div>
        <Message v-if="paymentSummary.saldo > 0" severity="warn" :closable="false">
          {{ t('events.comprobantesNewValueRequired', { value: money(suggestedPayment() ?? 0) }) }}
        </Message>
        <Message v-else severity="success" :closable="false">
          {{ t('events.comprobantesValueCovered') }}
        </Message>
        <Message v-if="editingReceiptId" severity="info" :closable="false">
          {{ t('events.comprobantesReplacing') }}
        </Message>
        <div v-if="paymentSummary.saldo > 0 || editingReceiptId" class="comprobante-form">
          <InputNumber v-model="comprobanteForm.valor" mode="currency" currency="COP" locale="es-CO" :placeholder="t('events.comprobantesValue')" />
          <input :key="receiptFileInputKey" type="file" accept="image/*,.pdf" @change="onPickComprobante" />
          <Button
            icon="pi pi-upload"
            :label="editingReceiptId ? t('events.comprobantesReplace') : t('events.comprobantesUpload')"
            :loading="uploading"
            @click="uploadComprobante"
          />
          <Button
            v-if="editingReceiptId"
            :label="t('common.cancel')"
            text
            @click="cancelReplacingComprobante"
          />
        </div>
        <div v-for="receipt in comprobantes" :key="receipt.id" class="receipt-row">
          <div>
            <strong>{{ money(receipt.valor) }}</strong>
            <small>{{ receipt.archivo_nombre }}</small>
            <small v-if="receipt.movimiento_numero">{{ t('events.enrollChangeNumber', { number: receipt.movimiento_numero }) }}</small>
          </div>
          <span>{{ t(`events.comprobanteEstado.${receipt.estado}`, receipt.estado) }}</span>
          <div class="receipt-row__actions">
            <a v-if="receipt.archivo_url" :href="receipt.archivo_url" target="_blank" rel="noopener">
              <Button icon="pi pi-eye" text rounded :aria-label="t('common.view')" />
            </a>
            <Button
              v-if="receipt.estado !== 'aprobado'"
              icon="pi pi-pencil"
              text
              rounded
              :aria-label="t('events.comprobantesReplace')"
              @click="startReplacingComprobante(receipt)"
            />
            <Button v-if="receipt.estado === 'pendiente'" icon="pi pi-trash" text rounded severity="danger" @click="removeComprobante(receipt.id)" />
          </div>
          <ComprobanteComments
            class="receipt-row__comments"
            :comprobante-id="receipt.id"
            :comentarios="receipt.comentarios ?? []"
            @added="onReceiptCommentAdded(receipt.id, $event)"
          />
        </div>
        <section v-if="movementHistory.length" class="movement-history">
          <h3>{{ t('events.enrollHistoryTitle') }}</h3>
          <details v-for="movement in movementHistory" :key="movement.id" :open="movement.id === currentMovement?.id">
            <summary>
              <span>
                <strong>{{ t('events.enrollChangeNumber', { number: movement.numero }) }}</strong>
                · {{ movementChangeCount(movement) }} {{ t('events.enrollRecordedChanges') }}
              </span>
              <span>{{ money(movement.valor_diferencia) }} → {{ money(movement.total_nuevo) }}</span>
            </summary>
            <div class="history-detail">
              <p>
                {{ t('events.enrollPreviousTotal') }}: {{ money(movement.total_anterior) }}
                · {{ t('events.enrollUpdatedTotal') }}: {{ money(movement.total_nuevo) }}
              </p>
              <ul class="change-list">
                <li v-for="item in movement.cambios.participantes_agregados" :key="`hpa-${movement.id}-${item.ref}`">
                  {{ t('events.enrollParticipantAdded') }}: {{ item.nombre }}
                </li>
                <li v-for="item in movement.cambios.participantes_retirados" :key="`hpr-${movement.id}-${item.ref}`">
                  {{ t('events.enrollParticipantRemoved') }}: {{ item.nombre }}
                </li>
                <li v-for="item in movement.cambios.servicios_agregados" :key="`hsa-${movement.id}-${item.participante_ref}-${item.clave}`">
                  {{ t('events.enrollServiceAdded') }}: {{ item.producto }} — {{ item.participante_nombre }}
                </li>
                <li v-for="item in movement.cambios.participantes_modificados" :key="`hpm-${movement.id}-${item.ref}`">
                  {{ t('events.enrollParticipantUpdated') }}: {{ item.nombre }}
                </li>
                <li v-for="item in movement.cambios.servicios_modificados" :key="`hsm-${movement.id}-${item.clave}`">
                  {{ t('events.enrollServiceUpdated') }}: {{ item.nuevo.producto }} — {{ item.nuevo.participante_nombre }}
                </li>
                <li v-for="item in movement.cambios.servicios_retirados" :key="`hsr-${movement.id}-${item.participante_ref}-${item.clave}`">
                  {{ t('events.enrollServiceRemoved') }}: {{ item.producto }} — {{ item.participante_nombre }}
                </li>
              </ul>
              <div class="movement-receipts">
                <h4>{{ t('events.revisionMovementReceipts') }}</h4>
                <p v-if="!receiptsForMovement(movement.id).length" class="pj-muted">
                  {{ t('events.comprobantesEmpty') }}
                </p>
                <div
                  v-for="receipt in receiptsForMovement(movement.id)"
                  :key="`movement-receipt-${movement.id}-${receipt.id}`"
                  class="movement-receipt"
                >
                  <div>
                    <strong>{{ money(receipt.valor) }}</strong>
                    <small>{{ receipt.archivo_nombre }}</small>
                  </div>
                  <span>{{ t(`events.comprobanteEstado.${receipt.estado}`, receipt.estado) }}</span>
                  <div class="receipt-row__actions">
                    <a v-if="receipt.archivo_url" :href="receipt.archivo_url" target="_blank" rel="noopener">
                      <Button icon="pi pi-eye" text rounded size="small" :aria-label="t('common.view')" />
                    </a>
                    <Button
                      v-if="receipt.estado !== 'aprobado'"
                      icon="pi pi-pencil"
                      text
                      rounded
                      size="small"
                      :aria-label="t('events.comprobantesReplace')"
                      @click="startReplacingComprobante(receipt)"
                    />
                  </div>
                </div>
              </div>
              <strong>{{ movement.snapshot.participantes.length }} {{ t('events.revisionMembers') }}</strong>
            </div>
          </details>
        </section>
        <section v-if="currentMovement" class="overall-enrollment">
          <h3>{{ t('events.enrollOverallListTitle') }}</h3>
          <div class="overall-table-wrap">
            <table class="overall-table">
              <thead>
                <tr>
                  <th>{{ t('events.enrollParticipant') }}</th>
                  <th>{{ t('events.enrollmentValue') }}</th>
                  <th>{{ t('events.insuranceTitle') }}</th>
                  <th>{{ t('events.revisionAdditionalDetails') }}</th>
                  <th>{{ t('events.enrollTotal') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="participant in currentMovement.snapshot.participantes"
                  :key="`overall-${participant.ref}`"
                >
                  <td>
                    <strong>{{ participant.nombre }}</strong>
                    <small>
                      {{ participantTypeLabel(participant.tipo as ParticipantType) }}
                      · {{ participant.identificacion || '—' }}
                    </small>
                  </td>
                  <td>{{ money(participant.valor_inscripcion) }}</td>
                  <td>{{ money(participant.valor_seguro) }}</td>
                  <td>
                    <details
                      v-if="(participant.servicios || []).length"
                      class="overall-additions"
                    >
                      <summary>
                        <span>{{ money(snapshotServicesTotal(participant)) }}</span>
                        {{ t('events.revisionViewAdditional') }}
                      </summary>
                      <ul>
                        <li v-for="service in participant.servicios" :key="service.clave">
                          <span>{{ service.producto }}</span>
                          <strong>
                            {{ service.cantidad }} × {{ money(service.precio_unitario) }}
                            = {{ money(service.valor_total) }}
                          </strong>
                        </li>
                      </ul>
                    </details>
                    <span v-else class="pj-muted">{{ t('events.revisionNoAdditional') }}</span>
                  </td>
                  <td class="overall-total">{{ money(snapshotParticipantTotal(participant)) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="grand-total">
            <span>{{ t('events.enrollUpdatedTotal') }}</span>
            <strong>{{ money(currentMovement.total_nuevo) }}</strong>
          </div>
        </section>
      </section>

      <footer v-if="currentStep !== 'participantes'" class="enroll-footer">
        <Button :label="t('common.back')" outlined @click="goBack" />
        <Button
          v-if="currentStep === 'resumen'"
          :label="t('events.enrollWizardSubmit')"
          icon="pi pi-arrow-right"
          icon-pos="right"
          :loading="saving"
          :disabled="isEnrollmentModification && !summaryRows.length"
          @click="goNext"
        />
        <Button v-else :label="t('events.enrollWizardFinish')" icon="pi pi-check" @click="router.push({ name: 'events' })" />
      </footer>
    </template>

    <Dialog
      v-model:visible="companionDialog"
      modal
      :header="companionDialogKind === 'pasadia' ? t('events.enrollAddDayPass') : t('events.enrollAddCompanion')"
      :style="{ width: 'min(32rem, 94vw)' }"
    >
      <div class="companion-mode">
        <Button
          :label="t('events.enrollCompanionSelectExisting')"
          :outlined="companionMode !== 'existing'"
          @click="companionMode = 'existing'"
        />
        <Button
          :label="t('events.enrollCompanionCreateNew')"
          :outlined="companionMode !== 'new'"
          @click="companionMode = 'new'"
        />
      </div>
      <div class="companion-form">
        <label v-if="companionMode === 'existing'" class="companion-search">
          <span>{{ t('events.enrollCompanionSearch') }}</span>
          <div ref="companionSearchShell" class="autocomplete-search-shell">
            <AutoComplete
              v-model="selectedCompanionPersona"
              :suggestions="companionCandidates"
              option-label="full_name"
              force-selection
              :loading="searchingCompanions"
              :placeholder="t('events.enrollCompanionSearchHint')"
              @complete="searchCompanionPersonas"
            >
              <template #option="{ option }">
                <div class="companion-option">
                  <strong>{{ option.full_name }}</strong>
                  <small>{{ option.tipo_identificacion }} {{ option.identificacion }}</small>
                </div>
              </template>
            </AutoComplete>
            <button
              type="button"
              :aria-label="t('events.enrollCompanionSearch')"
              @click="focusCompanionSearch"
            >
              <i class="pi pi-search" />
            </button>
          </div>
        </label>
        <template v-else>
          <label><span>{{ t('personas.idType') }}</span><Select v-model="companionForm.tipoIdentificacion" :options="identificationTypeOptions" /></label>
          <label><span>{{ t('events.enrollCompanionDocument') }}</span><InputText v-model="companionForm.identificacion" /></label>
          <label><span>{{ t('personas.firstName') }}</span><InputText v-model="companionForm.nombre1" /></label>
          <label><span>{{ t('personas.secondName') }}</span><InputText v-model="companionForm.nombre2" /></label>
          <label><span>{{ t('personas.lastName') }}</span><InputText v-model="companionForm.apellido1" /></label>
          <label><span>{{ t('personas.secondLastName') }}</span><InputText v-model="companionForm.apellido2" /></label>
          <label><span>{{ t('events.enrollCompanionBirthDate') }}</span><InputText v-model="companionForm.fechaNacimiento" type="date" /></label>
          <label><span>{{ t('personas.sex') }}</span><Select v-model="companionForm.sexo" :options="sexOptions" option-label="label" option-value="value" show-clear /></label>
          <label><span>{{ t('personas.phone') }}</span><InputText v-model="companionForm.telefono" /></label>
          <label><span>{{ t('personas.email') }}</span><InputText v-model="companionForm.correo" type="email" /></label>
        </template>
        <label><span>{{ t('events.enrollCompanionRelationship') }}</span><InputText v-model="companionForm.parentesco" /></label>
        <label v-if="companionDialogKind === 'pasadia'">
          <span>{{ t('events.enrollDayPassDays') }}</span>
          <InputNumber
            v-model="companionForm.pasadiaDias"
            :min="1"
            show-buttons
            button-layout="horizontal"
            :suffix="` ${t('events.enrollDays')}`"
            class="day-pass-dialog-input"
          />
        </label>
        <small v-if="companionDialogKind === 'pasadia'" class="day-pass-hint">
          {{ t('events.enrollDayVisitorHint') }}
        </small>
        <label v-if="companionDialogKind === 'acompanante'" class="minor-check">
          <Checkbox v-model="companionForm.menor" binary :disabled="companionForm.pasadia" />
          {{ t('events.enrollCompanionIsMinor') }}
        </label>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="companionDialog = false" />
        <Button :label="t('events.servicesAdd')" icon="pi pi-plus" :loading="savingCompanion" @click="addCompanion" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.enroll-page { max-width: 100%; }
.enroll-header { display: grid; grid-template-columns: auto 1fr auto; gap: .75rem; align-items: center; margin-bottom: 1rem; }
.enroll-header h1, .enroll-header p { margin: 0; }
.enroll-stepper { display: flex; gap: .35rem; }
.enroll-stepper button { display: flex; align-items: center; gap: .35rem; border: 0; background: transparent; color: var(--pj-text-muted); cursor: pointer; }
.enroll-stepper button span { display: grid; place-items: center; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--pj-bg-elevated); border: 1px solid var(--pj-border); }
.enroll-stepper button.active { color: var(--p-primary-color); font-weight: 700; }
.enroll-stepper button.active span, .enroll-stepper button.done span { color: white; background: var(--p-primary-color); }
.enroll-layout { display: grid; grid-template-columns: minmax(0, 1fr) 18rem; gap: 1rem; align-items: start; }
.enroll-main { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.enroll-card, .summary-card { background: var(--pj-bg-elevated); border: 1px solid var(--pj-border); border-radius: 12px; padding: 1rem; }
.section-head { display: flex; justify-content: space-between; gap: .75rem; align-items: start; margin-bottom: .75rem; }
.section-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .5rem; }
.section-head h2, .section-head p { margin: 0; }
.section-head p { color: var(--pj-text-muted); margin-top: .2rem; }
.table-scroll { overflow-x: auto; }
.enroll-table { width: 100%; border-collapse: collapse; min-width: 860px; font-size: .82rem; }
.enroll-table th, .enroll-table td { padding: .55rem; border-bottom: 1px solid var(--pj-border); text-align: left; vertical-align: middle; }
.enroll-table th { font-size: .72rem; color: var(--pj-text-muted); white-space: nowrap; }
.enroll-table tr.muted { opacity: .55; }
.participant-cell { display: flex; gap: .55rem; align-items: center; min-width: 12rem; }
.participant-cell div { display: flex; flex-direction: column; }
.participant-cell small, .service-cell small, .payment-step small, .receipt-row small { color: var(--pj-text-muted); }
.status { display: block; font-size: .7rem; font-weight: 700; white-space: nowrap; }
.insured { color: #15803d; }
.uninsured { color: #c2410c; }
.not-required { color: var(--pj-text-muted); }
.not-applicable { display: block; color: var(--pj-text-muted); text-align: center; }
.compact-select { width: 8.5rem; }
.board-role-select { width: 10.5rem; }
.discount-select { width: 10rem; }
.service-cell { display: grid; grid-template-columns: auto minmax(4.5rem, 5.25rem); align-items: center; gap: .3rem; min-width: 6.5rem; }
.service-cell small { grid-column: 1 / -1; text-align: center; }
.service-toggle { display: inline-flex; align-items: center; gap: .25rem; color: var(--pj-text-muted); font-size: .72rem; }
.days-input { width: 5.25rem; }
.days-input :deep(.p-inputnumber-input) { width: 2.2rem; padding: .35rem .2rem; text-align: center; }
.days-input :deep(.p-inputnumber-button) { width: 1.35rem; padding: 0; }
.summary-card { position: sticky; top: 1rem; }
.summary-card h2 { margin-top: 0; }
.summary-counts { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-bottom: 1rem; }
.summary-counts div { display: flex; flex-direction: column; text-align: center; padding: .65rem; border-radius: 8px; background: color-mix(in srgb, var(--p-primary-color) 7%, transparent); }
.summary-counts strong { font-size: 1.25rem; color: var(--p-primary-color); }
.summary-counts span { font-size: .72rem; color: var(--pj-text-muted); }
.summary-card dl { margin: 0; }
.summary-card dl div { display: flex; justify-content: space-between; padding: .35rem 0; }
.summary-card dd { margin: 0; }
.grand-total { display: flex; justify-content: space-between; align-items: center; margin: 1rem 0; padding: .85rem; border-radius: 8px; background: color-mix(in srgb, var(--p-primary-color) 10%, transparent); }
.grand-total strong { color: var(--p-primary-color); font-size: 1.3rem; }
.summary-step, .payment-step { max-width: 62rem; margin: 0 auto; }
.summary-table-wrap { overflow-x: auto; margin-top: 1rem; border: 1px solid var(--pj-border); border-radius: 10px; }
.summary-table { width: 100%; min-width: 48rem; border-collapse: collapse; font-size: .84rem; }
.summary-table th { padding: .7rem .75rem; background: color-mix(in srgb, var(--p-primary-color) 5%, #fff); color: var(--pj-text-muted); text-align: left; font-size: .72rem; text-transform: uppercase; }
.summary-table td { padding: .75rem; border-top: 1px solid var(--pj-border); vertical-align: top; }
.summary-table td:first-child { min-width: 13rem; }
.summary-table td:first-child strong, .summary-table td:first-child span, .summary-table td:first-child small { display: block; }
.summary-table td:first-child span { margin-top: .15rem; color: var(--pj-text-muted); font-size: .76rem; }
.summary-change-label { width: fit-content; margin-top: .3rem; padding: .12rem .4rem; border-radius: 999px; background: color-mix(in srgb, var(--p-primary-color) 9%, transparent); color: var(--p-primary-color); font-weight: 700; }
.summary-service-changes { min-width: 11rem; margin: .35rem 0 0; padding-left: 1rem; color: var(--pj-text-muted); font-size: .74rem; }
.summary-row-total { color: var(--p-primary-color); font-weight: 800; white-space: nowrap; }
.negative-value { color: var(--p-red-600); }
.summary-total { max-width: 24rem; margin-left: auto; }
.payment-summary { display: grid; grid-template-columns: repeat(4, minmax(9rem, 1fr)); gap: .65rem; margin: 1rem 0; }
.payment-summary > div { display: flex; flex-direction: column; padding: .75rem; border: 1px solid var(--pj-border); border-radius: 8px; }
.payment-summary strong { font-size: 1.1rem; }
.payment-summary__balance { background: color-mix(in srgb, var(--p-primary-color) 10%, transparent); }
.payment-summary__balance strong { color: var(--p-primary-color); }
.current-change { margin: 1rem 0; padding: 1rem; border: 1px solid color-mix(in srgb, var(--p-primary-color) 45%, var(--pj-border)); border-radius: 10px; background: color-mix(in srgb, var(--p-primary-color) 6%, transparent); }
.current-change__head, .current-change__totals { display: flex; justify-content: space-between; gap: 1rem; }
.current-change__head > div { display: flex; flex-direction: column; }
.current-change__head strong { font-size: 1.1rem; }
.current-change__totals { padding-top: .65rem; border-top: 1px solid var(--pj-border); }
.change-list { display: grid; gap: .35rem; margin: .75rem 0; padding-left: 1.2rem; }
.change-list i { margin-right: .3rem; color: var(--p-primary-color); }
.movement-history { margin-top: 1.25rem; }
.movement-history details { border: 1px solid var(--pj-border); border-radius: 8px; margin-bottom: .5rem; }
.movement-history summary { display: flex; justify-content: space-between; gap: .75rem; padding: .75rem; cursor: pointer; }
.history-detail { padding: 0 .75rem .75rem; border-top: 1px solid var(--pj-border); }
.overall-enrollment { margin-top: 1.25rem; }
.overall-table-wrap { overflow-x: auto; border: 1px solid var(--pj-border); border-radius: 10px; }
.overall-table { width: 100%; min-width: 47rem; border-collapse: collapse; font-size: .82rem; }
.overall-table th { padding: .65rem .75rem; background: color-mix(in srgb, var(--p-primary-color) 5%, #fff); color: var(--pj-text-muted); text-align: left; font-size: .7rem; text-transform: uppercase; }
.overall-table td { padding: .7rem .75rem; border-top: 1px solid var(--pj-border); vertical-align: top; }
.overall-table td:first-child { min-width: 13rem; }
.overall-table td:first-child strong, .overall-table td:first-child small { display: block; }
.overall-table td:first-child small { margin-top: .15rem; color: var(--pj-text-muted); }
.overall-total { color: var(--p-primary-color); font-weight: 800; white-space: nowrap; }
.overall-additions summary { display: flex; align-items: center; gap: .45rem; width: fit-content; padding: .25rem .5rem; border-radius: 6px; background: color-mix(in srgb, var(--p-primary-color) 8%, transparent); color: var(--p-primary-color); cursor: pointer; list-style: none; font-size: .73rem; font-weight: 700; }
.overall-additions summary span { color: var(--pj-text); }
.overall-additions ul { display: grid; gap: .35rem; min-width: 15rem; margin: .45rem 0 0; padding: .55rem; border-radius: 7px; background: color-mix(in srgb, var(--pj-navy) 3%, #fff); list-style: none; }
.overall-additions li { display: flex; justify-content: space-between; gap: .75rem; }
.overall-additions li span { color: var(--pj-text-muted); }
.comprobante-form { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; }
.receipt-row { display: grid; grid-template-columns: 1fr auto auto; gap: .75rem; align-items: center; padding: .65rem; border-bottom: 1px solid var(--pj-border); }
.receipt-row > div { display: flex; flex-direction: column; }
.receipt-row__actions { display: flex !important; flex-direction: row !important; align-items: center; gap: .15rem; }
.receipt-row__comments { grid-column: 1 / -1; }
.movement-receipts { margin: .75rem 0; padding-top: .65rem; border-top: 1px dashed var(--pj-border); }
.movement-receipts h4 { margin: 0 0 .45rem; }
.movement-receipt { display: grid; grid-template-columns: minmax(10rem, 1fr) auto auto; gap: .5rem; align-items: center; padding: .4rem .5rem; border-radius: 7px; background: color-mix(in srgb, var(--p-primary-color) 4%, transparent); }
.movement-receipt + .movement-receipt { margin-top: .35rem; }
.movement-receipt > div:first-child { display: flex; flex-direction: column; }
.movement-receipt small { color: var(--pj-text-muted); }
.enroll-footer { display: flex; justify-content: space-between; margin: 1rem auto 0; max-width: 62rem; }
.companion-mode { display: flex; gap: .5rem; margin-bottom: 1rem; }
.companion-form { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.companion-form label { display: flex; flex-direction: column; gap: .3rem; }
.companion-form .companion-search { grid-column: 1 / -1; }
.autocomplete-search-shell {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--p-primary-color) 18%, var(--pj-border));
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 8px 24px rgb(29 78 216 / 8%);
}
.autocomplete-search-shell :deep(.p-autocomplete) { width: 100%; min-width: 0; }
.autocomplete-search-shell :deep(.p-autocomplete-input) {
  width: 100%;
  height: 3rem;
  padding: 0 1rem 0 1.2rem;
  border: 0;
  border-radius: 999px 0 0 999px;
  box-shadow: none;
}
.autocomplete-search-shell > button {
  display: grid;
  flex: 0 0 3rem;
  width: 3rem;
  height: 3rem;
  padding: 0;
  border: 0;
  border-radius: 50%;
  place-items: center;
  background: var(--p-primary-color);
  color: #fff;
  cursor: pointer;
}
.autocomplete-search-shell:focus-within {
  border-color: var(--p-primary-color);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--p-primary-color) 14%, transparent);
}
.companion-option { display: flex; flex-direction: column; }
.companion-option small { color: var(--pj-text-muted); }
.companion-form .minor-check { grid-column: 1 / -1; flex-direction: row; align-items: center; }
.day-pass-hint { grid-column: 1 / -1; margin-top: -.45rem; color: var(--pj-text-muted); }
.day-pass-dialog-input { max-width: 11rem; }
@media (max-width: 1000px) {
  .enroll-layout { grid-template-columns: 1fr; }
  .summary-card { position: static; }
  .enroll-header { grid-template-columns: auto 1fr; }
  .enroll-stepper { grid-column: 1 / -1; }
}
@media (max-width: 640px) {
  .companion-form { grid-template-columns: 1fr; }
  .payment-summary { grid-template-columns: repeat(2, minmax(8rem, 1fr)); }
}
</style>
