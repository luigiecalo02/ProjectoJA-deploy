<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import ComprobanteComments from '@/components/events/ComprobanteComments.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type {
  EventoInscripcion,
  EventoInscripcionComprobante,
  EventoInscripcionComprobanteComentario,
  EventoInscripcionEstado,
  EventoComprobanteEstado,
} from '@/modules/events/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const inscripciones = ref<EventoInscripcion[]>([])
const selectedId = ref<number | null>(null)
const detail = ref<EventoInscripcion | null>(null)
const revisionObservacion = ref('')
const clubSearch = ref('')
const activeTab = ref<'participants' | 'history' | 'decision'>('participants')
const expandedParticipantIds = ref<Set<number>>(new Set())

const eventId = computed(() => Number(route.params.id))
const filteredInscripciones = computed(() => {
  const term = clubSearch.value.trim().toLocaleLowerCase('es')
  if (!term) return inscripciones.value
  return inscripciones.value.filter((item) =>
    (item.organizacion?.nombre || '').toLocaleLowerCase('es').includes(term),
  )
})

const inscripcionEstadoOptions = computed(() => [
  { label: t('events.revisionEstado.pendiente_revision'), value: 'pendiente_revision' },
  { label: t('events.revisionEstado.en_revision'), value: 'en_revision' },
  { label: t('events.revisionEstado.aprobada'), value: 'aprobada' },
  { label: t('events.revisionEstado.no_aprobada'), value: 'no_aprobada' },
])

function estadoLabel(estado: string): string {
  return t(`events.revisionEstado.${estado}`, estado)
}

function comprobanteEstadoLabel(estado: string): string {
  return t(`events.comprobanteEstado.${estado}`, estado)
}

function participantTypeLabel(type?: string): string {
  const key = {
    miembro: 'enrollTypeMember',
    directiva: 'enrollTypeDirective',
    acompanante: 'enrollTypeCompanion',
    acompanante_menor: 'enrollTypeMinorCompanion',
    visitante_pasadia: 'enrollTypeDayVisitor',
  }[type || 'miembro'] ?? 'enrollTypeMember'
  return t(`events.${key}`)
}

function boardRoleLabel(role?: string | null): string {
  const key = {
    director: 'enrollBoardDirector',
    subdirector: 'enrollBoardDeputyDirector',
    secretario: 'enrollBoardSecretary',
    tesorero: 'enrollBoardTreasurer',
  }[role || '']
  return key ? t(`events.${key}`) : ''
}

function initials(name?: string | null): string {
  return (name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')
}

function participantServicesTotal(
  participant: NonNullable<EventoInscripcion['personas']>[number],
): number {
  return (participant.reservas || []).reduce(
    (sum, reservation) => sum + Number(reservation.valor_total),
    0,
  )
}

function participantTotal(participant: NonNullable<EventoInscripcion['personas']>[number]): number {
  return (
    Number(participant.valor_inscripcion ?? 0) +
    Number(participant.valor_seguro ?? 0) +
    participantServicesTotal(participant)
  )
}

function hasParticipantExtras(
  participant: NonNullable<EventoInscripcion['personas']>[number],
): boolean {
  return (
    Boolean(participant.descuento_nombre) ||
    Boolean(participant.parentesco) ||
    (participant.reservas || []).length > 0
  )
}

function isParticipantExpanded(participantId: number): boolean {
  return expandedParticipantIds.value.has(participantId)
}

function toggleParticipantExtras(participantId: number): void {
  const next = new Set(expandedParticipantIds.value)
  if (next.has(participantId)) {
    next.delete(participantId)
  } else {
    next.add(participantId)
  }
  expandedParticipantIds.value = next
}

function money(value: number | string | null | undefined): string {
  return Number(value ?? 0).toLocaleString('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  })
}

function movementChangeCount(movement: NonNullable<EventoInscripcion['movimientos']>[number]): number {
  return Object.values(movement.cambios).reduce((sum, items) => sum + items.length, 0)
}

async function loadList(): Promise<void> {
  loading.value = true
  try {
    inscripciones.value = await eventsService.inscripcionesRevision(eventId.value)
    if (inscripciones.value.length && !selectedId.value) {
      await selectInscripcion(inscripciones.value[0].id)
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loading.value = false
  }
}

async function selectInscripcion(id: number): Promise<void> {
  selectedId.value = id
  try {
    detail.value = await eventsService.getInscripcion(id)
    revisionObservacion.value = detail.value.observacion_revision ?? ''
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

async function updateComprobante(
  comprobante: EventoInscripcionComprobante,
  estado: EventoComprobanteEstado,
): Promise<void> {
  saving.value = true
  try {
    await eventsService.reviewComprobante(comprobante.id, { estado })
    if (selectedId.value) await selectInscripcion(selectedId.value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.comprobantesReviewSuccess'),
      life: 2200,
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

function onReceiptCommentAdded(
  receiptId: number,
  comment: EventoInscripcionComprobanteComentario,
): void {
  const receipt = detail.value?.comprobantes?.find((item) => item.id === receiptId)
  if (receipt) receipt.comentarios = [...(receipt.comentarios ?? []), comment]
  for (const movement of detail.value?.movimientos ?? []) {
    const movementReceipt = movement.comprobantes?.find((item) => item.id === receiptId)
    if (movementReceipt && movementReceipt !== receipt) {
      movementReceipt.comentarios = [...(movementReceipt.comentarios ?? []), comment]
    }
  }
}

async function updateInscripcionEstado(estado: EventoInscripcionEstado): Promise<void> {
  if (!detail.value) return
  saving.value = true
  try {
    await eventsService.reviewInscripcion(detail.value.id, {
      estado,
      observacion_revision: revisionObservacion.value.trim() || null,
    })
    await loadList()
    if (selectedId.value) await selectInscripcion(selectedId.value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.revisionUpdateSuccess'),
      life: 2200,
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

onMounted(() => {
  void loadList()
})
</script>

<template>
  <section class="pj-page revision-page">
    <header class="pj-page__header">
      <div>
        <Button
          type="button"
          icon="pi pi-arrow-left"
          text
          rounded
          :aria-label="t('common.back')"
          @click="router.push({ name: 'events' })"
        />
        <h1 class="pj-page__title">{{ t('events.revisionTitle') }}</h1>
        <p class="pj-page__subtitle">{{ t('events.revisionSubtitle') }}</p>
      </div>
    </header>

    <PageLoader v-if="loading && !inscripciones.length" :label="t('common.loading')" />

    <div v-else class="revision-layout">
      <aside class="revision-list">
        <div class="revision-list__head">
          <h2>{{ t('events.revisionListTitle') }}</h2>
          <span>{{ inscripciones.length }}</span>
        </div>
        <AppSearchField
          v-model="clubSearch"
          class="club-search"
          :placeholder="t('events.revisionSearchClub')"
        />
        <p v-if="!inscripciones.length" class="pj-muted">{{ t('events.revisionEmpty') }}</p>
        <p v-else-if="!filteredInscripciones.length" class="revision-list__empty pj-muted">
          {{ t('events.revisionNoClubMatches') }}
        </p>
        <button
          v-for="item in filteredInscripciones"
          :key="item.id"
          type="button"
          class="revision-list__item"
          :class="{ 'is-active': selectedId === item.id }"
          @click="selectInscripcion(item.id)"
        >
          <span class="club-logo club-logo--small">
            <img
              v-if="item.organizacion?.logo_url"
              :src="item.organizacion.logo_url"
              :alt="item.organizacion.nombre"
            />
            <span v-else>{{ initials(item.organizacion?.nombre) }}</span>
          </span>
          <span class="revision-list__content">
            <strong>{{ item.organizacion?.nombre || `#${item.organizacion_id}` }}</strong>
            <small>{{ item.personas?.length ?? 0 }} {{ t('events.revisionMembers') }}</small>
            <span class="revision-list__meta">
              <span class="status-pill" :class="`status-pill--${item.estado}`">
                {{ estadoLabel(item.estado) }}
              </span>
              <strong>{{ money(item.total_declarado) }}</strong>
            </span>
          </span>
        </button>
      </aside>

      <main v-if="detail" class="revision-detail">
        <header class="revision-detail__head">
          <div class="club-identity">
            <span class="club-logo">
              <img
                v-if="detail.organizacion?.logo_url"
                :src="detail.organizacion.logo_url"
                :alt="detail.organizacion.nombre"
              />
              <span v-else>{{ initials(detail.organizacion?.nombre) }}</span>
            </span>
            <div>
              <small>{{ t('events.revisionEnrollmentNumber', { number: detail.id }) }}</small>
              <h2>{{ detail.organizacion?.nombre || `#${detail.organizacion_id}` }}</h2>
              <span class="status-pill" :class="`status-pill--${detail.estado}`">
                {{ estadoLabel(detail.estado) }}
              </span>
            </div>
          </div>
        </header>

        <div class="summary-strip">
          <div>
            <i class="pi pi-users" />
            <span>{{ t('events.revisionMembers') }}</span>
            <strong>{{ detail.personas?.length ?? 0 }}</strong>
          </div>
          <div>
            <i class="pi pi-wallet" />
            <span>{{ t('events.revisionTotalDeclared') }}</span>
            <strong>{{ money(detail.total_declarado) }}</strong>
          </div>
          <div>
            <i class="pi pi-check-circle" />
            <span>{{ t('events.comprobantesApprovedTotal') }}</span>
            <strong>{{ money(detail.total_consignado_aprobado) }}</strong>
          </div>
          <div :class="{ 'has-balance': Number(detail.saldo_por_soportar ?? 0) > 0 }">
            <i class="pi pi-exclamation-circle" />
            <span>{{ t('events.comprobantesPendingBalance') }}</span>
            <strong>{{ money(detail.saldo_por_soportar) }}</strong>
          </div>
          <div>
            <i class="pi pi-building" />
            <span>{{ t('alojamiento.assigned') }}</span>
            <strong>{{ detail.alojamiento?.asignadas ?? (detail.personas || []).filter((p) => p.asignacion_cama).length }}</strong>
          </div>
        </div>

        <nav class="review-tabs" :aria-label="t('events.revisionTabsLabel')">
          <button
            type="button"
            :class="{ 'is-active': activeTab === 'participants' }"
            @click="activeTab = 'participants'"
          >
            <i class="pi pi-users" />
            {{ t('events.revisionMembersTitle') }}
            <span>{{ detail.personas?.length ?? 0 }}</span>
          </button>
          <button
            type="button"
            :class="{ 'is-active': activeTab === 'history' }"
            @click="activeTab = 'history'"
          >
            <i class="pi pi-history" />
            {{ t('events.enrollHistoryTitle') }}
            <span>{{ detail.movimientos?.length ?? 0 }}</span>
          </button>
          <button
            type="button"
            :class="{ 'is-active': activeTab === 'decision' }"
            @click="activeTab = 'decision'"
          >
            <i class="pi pi-verified" />
            {{ t('events.revisionDecisionTitle') }}
          </button>
        </nav>

        <section v-if="activeTab === 'participants'" class="tab-panel">
          <div class="participants-table-wrap">
            <table class="participants-table">
              <thead>
                <tr>
                  <th>{{ t('events.revisionPerson') }}</th>
                  <th>{{ t('events.revisionParticipantType') }}</th>
                  <th>{{ t('events.enrollmentValue') }}</th>
                  <th>{{ t('events.insuranceTitle') }}</th>
                  <th>{{ t('alojamiento.bed') }}</th>
                  <th>{{ t('events.enrollTotal') }}</th>
                  <th>{{ t('events.revisionAdditionalDetails') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="p in detail.personas || []" :key="p.id">
                  <tr>
                    <td>
                      <strong>{{ p.nombre || `#${p.persona_id}` }}</strong>
                      <small>{{ p.identificacion || '—' }}</small>
                    </td>
                    <td>
                      {{ participantTypeLabel(p.tipo) }}
                      <small v-if="p.cargo_directiva" class="board-role">
                        {{ boardRoleLabel(p.cargo_directiva) }}
                      </small>
                    </td>
                    <td>{{ money(p.valor_inscripcion) }}</td>
                    <td>{{ money(p.valor_seguro) }}</td>
                    <td>
                      <template v-if="p.asignacion_cama">
                        <strong>{{ p.asignacion_cama.cabana?.nombre }}</strong>
                        <small>
                          {{ p.asignacion_cama.cuarto?.nombre }} ·
                          {{ p.asignacion_cama.cama?.codigo }}
                        </small>
                      </template>
                      <span v-else class="pj-muted">{{ t('alojamiento.unassigned') }}</span>
                    </td>
                    <td class="participant-total">{{ money(participantTotal(p)) }}</td>
                    <td>
                      <button
                        v-if="hasParticipantExtras(p)"
                        type="button"
                        class="participant-extras__toggle"
                        :aria-expanded="isParticipantExpanded(p.id)"
                        @click="toggleParticipantExtras(p.id)"
                      >
                        {{ t('events.revisionViewAdditional') }}
                        <i
                          class="pi pi-chevron-down"
                          :class="{ 'is-expanded': isParticipantExpanded(p.id) }"
                        />
                      </button>
                      <span v-else class="pj-muted">{{ t('events.revisionNoAdditional') }}</span>
                    </td>
                  </tr>
                  <tr v-if="hasParticipantExtras(p) && isParticipantExpanded(p.id)" class="participant-extras-row">
                    <td colspan="7">
                      <div class="participant-extras__content">
                        <div v-if="p.descuento_nombre">
                          <span>{{ t('events.revisionAppliedDiscount') }}</span>
                          <strong>
                            {{ p.descuento_nombre }} · {{ Number(p.descuento_porcentaje ?? 0) }}%
                          </strong>
                        </div>
                        <div v-if="p.parentesco">
                          <span>{{ t('events.enrollRelationship') }}</span>
                          <strong>{{ p.parentesco }}</strong>
                        </div>
                        <div
                          v-for="reserva in p.reservas || []"
                          :key="reserva.id"
                          class="participant-extra-service"
                        >
                          <span>{{ reserva.producto || reserva.tipo }}</span>
                          <strong>
                            {{ reserva.cantidad }} × {{ money(reserva.precio_unitario) }}
                            = {{ money(reserva.valor_total) }}
                          </strong>
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </section>

        <section v-else-if="activeTab === 'history'" class="tab-panel">
          <p v-if="!(detail.movimientos || []).length" class="pj-muted">
            {{ t('events.revisionNoHistory') }}
          </p>
          <details
            v-for="movement in [...(detail.movimientos || [])].reverse()"
            :key="movement.id"
            class="movement-review"
          >
            <summary>
              <span>
                <strong>{{ t('events.enrollChangeNumber', { number: movement.numero }) }}</strong>
                <small>
                  {{ movementChangeCount(movement) }} {{ t('events.enrollRecordedChanges') }}
                </small>
              </span>
              <span>
                <strong>{{ money(movement.valor_diferencia) }}</strong>
                <small>{{ money(movement.total_nuevo) }} {{ t('events.revisionAccumulated') }}</small>
              </span>
            </summary>
            <div class="movement-review__body">
              <div class="movement-values">
                <span>{{ t('events.enrollPreviousTotal') }} <strong>{{ money(movement.total_anterior) }}</strong></span>
                <span>{{ t('events.enrollUpdatedTotal') }} <strong>{{ money(movement.total_nuevo) }}</strong></span>
                <span>{{ t('events.comprobantesTotalConsigned') }} <strong>{{ money(movement.total_consignado) }}</strong></span>
              </div>
              <ul class="movement-changes">
                <li v-for="item in movement.cambios.participantes_agregados" :key="`pa-${movement.id}-${item.ref}`">
                  <i class="pi pi-user-plus" /> {{ t('events.enrollParticipantAdded') }}: {{ item.nombre }}
                </li>
                <li v-for="item in movement.cambios.participantes_retirados" :key="`pr-${movement.id}-${item.ref}`">
                  <i class="pi pi-user-minus" /> {{ t('events.enrollParticipantRemoved') }}: {{ item.nombre }}
                </li>
                <li v-for="item in movement.cambios.participantes_modificados" :key="`pm-${movement.id}-${item.ref}`">
                  <i class="pi pi-user-edit" /> {{ t('events.enrollParticipantUpdated') }}: {{ item.nombre }}
                </li>
                <li v-for="item in movement.cambios.servicios_agregados" :key="`sa-${movement.id}-${item.participante_ref}-${item.clave}`">
                  <i class="pi pi-plus-circle" /> {{ t('events.enrollServiceAdded') }}: {{ item.producto }} — {{ item.participante_nombre }}
                </li>
                <li v-for="item in movement.cambios.servicios_retirados" :key="`sr-${movement.id}-${item.participante_ref}-${item.clave}`">
                  <i class="pi pi-minus-circle" /> {{ t('events.enrollServiceRemoved') }}: {{ item.producto }} — {{ item.participante_nombre }}
                </li>
                <li v-for="item in movement.cambios.servicios_modificados" :key="`sm-${movement.id}-${item.clave}`">
                  <i class="pi pi-pencil" /> {{ t('events.enrollServiceUpdated') }}: {{ item.nuevo.producto }} — {{ item.nuevo.participante_nombre }}
                </li>
              </ul>
              <div class="movement-receipts">
                <h4>
                  <i class="pi pi-receipt" />
                  {{ t('events.revisionMovementReceipts') }}
                </h4>
                <p v-if="!(movement.comprobantes || []).length" class="pj-muted">
                  {{ t('events.comprobantesEmpty') }}
                </p>
                <article
                  v-for="c in movement.comprobantes || []"
                  :key="c.id"
                  class="comprobante-review"
                >
                  <div class="comprobante-review__info">
                    <i class="pi pi-file comprobante-review__icon" />
                    <span><strong>{{ money(c.valor) }}</strong><small>{{ c.archivo_nombre || '—' }}</small></span>
                    <span class="status-pill status-pill--muted">{{ comprobanteEstadoLabel(c.estado) }}</span>
                  </div>
                  <div class="comprobante-review__actions">
                    <a v-if="c.archivo_url" :href="c.archivo_url" target="_blank" rel="noopener">
                      <Button type="button" icon="pi pi-eye" text rounded :aria-label="t('common.view')" />
                    </a>
                    <Button type="button" icon="pi pi-check" severity="success" text rounded :disabled="c.estado === 'aprobado' || saving" :aria-label="t('events.comprobantesApprove')" @click="updateComprobante(c, 'aprobado')" />
                    <Button type="button" icon="pi pi-times" severity="danger" text rounded :disabled="c.estado === 'rechazado' || saving" :aria-label="t('events.comprobantesReject')" @click="updateComprobante(c, 'rechazado')" />
                    <Button type="button" icon="pi pi-clock" text rounded :disabled="c.estado === 'pendiente' || saving" :aria-label="t('events.comprobantesPending')" @click="updateComprobante(c, 'pendiente')" />
                  </div>
                  <ComprobanteComments
                    class="comprobante-review__comments"
                    :comprobante-id="c.id"
                    :comentarios="c.comentarios ?? []"
                    @added="onReceiptCommentAdded(c.id, $event)"
                  />
                </article>
              </div>
            </div>
          </details>
        </section>

        <section v-else class="tab-panel decision-panel">
          <div class="decision-panel__intro">
            <span><i class="pi pi-file-edit" /></span>
            <div>
              <h3>{{ t('events.revisionDecisionTitle') }}</h3>
              <p>{{ t('events.revisionDecisionLead') }}</p>
            </div>
          </div>
          <div class="observation-field">
            <label for="observacion_revision">{{ t('events.revisionObservation') }}</label>
            <Textarea
              id="observacion_revision"
              v-model="revisionObservacion"
              rows="5"
              auto-resize
              :placeholder="t('events.revisionObservationPlaceholder')"
              class="revision-textarea"
            />
            <small>{{ t('events.revisionObservationHint') }}</small>
          </div>
          <div class="revision-actions">
            <Button type="button" severity="success" icon="pi pi-check" :label="t('events.revisionApprove')" :loading="saving" @click="updateInscripcionEstado('aprobada')" />
            <Button type="button" severity="danger" outlined icon="pi pi-times" :label="t('events.revisionReject')" :loading="saving" @click="updateInscripcionEstado('no_aprobada')" />
            <Select
              :model-value="detail.estado"
              :options="inscripcionEstadoOptions"
              option-label="label"
              option-value="value"
              class="revision-estado-select"
              @update:model-value="(v) => updateInscripcionEstado(v as EventoInscripcionEstado)"
            />
          </div>
        </section>
      </main>

      <div v-else-if="!loading" class="revision-empty-detail pj-muted">
        {{ t('events.revisionSelectOne') }}
      </div>
    </div>
  </section>
</template>

<style scoped>
.revision-page .pj-page__header > div {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.revision-layout {
  display: grid;
  grid-template-columns: minmax(17rem, 20rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}

.revision-list,
.revision-detail {
  background: var(--pj-bg-elevated);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 14px;
  padding: 0.85rem 1rem;
}

.revision-list h2,
.revision-detail h2,
.revision-section h3 {
  margin: 0 0 0.65rem;
}

.revision-list {
  position: sticky;
  top: 1rem;
  max-height: calc(100vh - 2rem);
  overflow-y: auto;
}

.revision-list__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.65rem;
}

.revision-list__head h2 {
  margin: 0;
}

.revision-list__head span {
  display: grid;
  place-items: center;
  min-width: 1.75rem;
  height: 1.75rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  font-size: 0.78rem;
  font-weight: 700;
}

.club-search {
  margin-bottom: 0.7rem;
}

.revision-list__empty {
  padding: 1rem 0.35rem;
  text-align: center;
  font-size: 0.82rem;
}

.revision-list__item {
  width: 100%;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.65rem;
  text-align: left;
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 10px;
  padding: 0.65rem 0.75rem;
  margin-bottom: 0.45rem;
  background: #fff;
  cursor: pointer;
  font: inherit;
}

.revision-list__content {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.revision-list__content > strong,
.revision-list__content > small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.revision-list__content > small {
  color: var(--pj-text-muted);
  font-size: 0.75rem;
}

.revision-list__meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.2rem;
  font-size: 0.75rem;
}

.revision-list__item.is-active {
  border-color: color-mix(in srgb, #0f766e 45%, transparent);
  background: color-mix(in srgb, #0f766e 8%, #fff);
}

.club-logo {
  width: 4rem;
  height: 4rem;
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 12px;
  background: color-mix(in srgb, var(--pj-navy) 7%, #fff);
  color: var(--pj-navy);
  font-weight: 800;
}

.club-logo img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.club-logo--small {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 9px;
  font-size: 0.78rem;
}

.revision-detail__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.club-identity,
.club-identity > div {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.club-identity > div {
  align-items: flex-start;
  flex-direction: column;
  gap: 0.15rem;
}

.club-identity h2 {
  margin: 0;
}

.club-identity small {
  color: var(--pj-text-muted);
}

.summary-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: 0.6rem;
  margin-bottom: 1rem;
}

.summary-strip > div {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.1rem 0.5rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 60%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-navy) 2%, #fff);
}

.summary-strip i {
  grid-row: 1 / 3;
  align-self: center;
  color: #0f766e;
}

.summary-strip span {
  color: var(--pj-text-muted);
  font-size: 0.7rem;
}

.summary-strip strong {
  font-size: 0.9rem;
}

.summary-strip .has-balance {
  border-color: color-mix(in srgb, #dc2626 35%, transparent);
  background: color-mix(in srgb, #dc2626 5%, #fff);
}

.summary-strip .has-balance i,
.summary-strip .has-balance strong {
  color: #b91c1c;
}

.revision-section {
  margin-bottom: 1rem;
  padding-top: 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
}

.review-tabs {
  display: flex;
  gap: 0.35rem;
  overflow-x: auto;
  margin: 0 -1rem;
  padding: 0 1rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.review-tabs button {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.7rem 0.85rem;
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--pj-text-muted);
  white-space: nowrap;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
  font-weight: 650;
}

.review-tabs button:hover,
.review-tabs button.is-active {
  color: #0f766e;
}

.review-tabs button.is-active {
  border-bottom-color: #0f766e;
}

.review-tabs button span {
  min-width: 1.3rem;
  padding: 0.1rem 0.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, currentColor 9%, transparent);
  font-size: 0.68rem;
}

.tab-panel {
  padding-top: 1rem;
}

.participants-table-wrap {
  overflow-x: auto;
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 10px;
}

.participants-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.84rem;
}

.participants-table th {
  padding: 0.65rem 0.75rem;
  background: color-mix(in srgb, var(--pj-navy) 5%, #fff);
  color: var(--pj-text-muted);
  text-align: left;
  font-size: 0.7rem;
  letter-spacing: 0.035em;
  text-transform: uppercase;
}

.participants-table td {
  min-width: 7rem;
  padding: 0.65rem 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  vertical-align: top;
}

.participants-table td:first-child {
  min-width: 12rem;
}

.participants-table td:first-child strong,
.participants-table td:first-child small {
  display: block;
}

.participants-table td strong,
.participants-table td small {
  display: block;
}

.participants-table td:first-child small {
  margin-top: 0.15rem;
  color: var(--pj-text-muted);
}

.board-role {
  display: block;
  margin-top: 0.15rem;
  color: #0f766e;
  font-weight: 700;
}

.participant-total {
  color: #0f766e;
  font-weight: 750;
}

.participant-extras__toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.28rem 0.55rem;
  border: 0;
  border-radius: 6px;
  background: color-mix(in srgb, #2563eb 8%, transparent);
  color: #1d4ed8;
  cursor: pointer;
  font-size: 0.75rem;
  font-weight: 700;
  list-style: none;
}

.participant-extras__toggle i {
  font-size: 0.65rem;
  transition: transform 0.2s ease;
}

.participant-extras__toggle i.is-expanded {
  transform: rotate(180deg);
}

.participant-extras-row td {
  padding: 0 !important;
  background: color-mix(in srgb, var(--pj-navy) 2%, #fff);
}

.participant-extras__content {
  display: grid;
  width: 100%;
  box-sizing: border-box;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.65rem 1.25rem;
  padding: 0.85rem 1rem;
}

.participant-extras__content > div {
  display: flex;
  min-width: 0;
  justify-content: space-between;
  gap: 0.75rem;
}

.participant-extras__content span {
  color: var(--pj-text-muted);
}

.participant-extra-service {
  padding-top: 0.35rem;
  border-top: 1px dashed color-mix(in srgb, var(--pj-border) 65%, transparent);
}

.personas-list {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.persona-review {
  display: grid;
  grid-template-columns: minmax(11rem, 1.5fr) repeat(4, minmax(5rem, 0.7fr));
  gap: 0.65rem;
  align-items: center;
  padding: 0.55rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 60%, transparent);
  border-radius: 9px;
}

.persona-review > div {
  display: flex;
  flex-direction: column;
}

.persona-review__services {
  grid-column: 1 / -1;
  margin: -0.15rem 0 0;
  padding: 0.4rem 0 0 1.1rem;
  border-top: 1px dashed color-mix(in srgb, var(--pj-border) 60%, transparent);
  color: var(--pj-text-muted);
  font-size: 0.8rem;
}

.persona-review__total strong {
  color: #0f766e;
}

.movement-review {
  margin-bottom: 0.5rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 8px;
}

.movement-review summary {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.7rem;
  cursor: pointer;
}

.movement-review summary > span {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.movement-review summary > span:last-child {
  align-items: flex-end;
}

.movement-review summary small {
  color: var(--pj-text-muted);
  font-size: 0.72rem;
}

.movement-review__body {
  padding: 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
}

.movement-values {
  display: grid;
  grid-template-columns: repeat(3, minmax(8rem, 1fr));
  gap: 0.5rem;
}

.movement-values span {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.55rem;
  border-radius: 7px;
  background: color-mix(in srgb, var(--pj-navy) 4%, #fff);
  color: var(--pj-text-muted);
  font-size: 0.72rem;
}

.movement-values strong {
  color: var(--pj-text);
  font-size: 0.85rem;
}

.movement-changes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.35rem;
  margin: 0.75rem 0;
  padding: 0;
  list-style: none;
}

.movement-changes li {
  display: flex;
  gap: 0.4rem;
  padding: 0.4rem 0.55rem;
  border-radius: 6px;
  background: color-mix(in srgb, #0f766e 5%, transparent);
  font-size: 0.78rem;
}

.movement-changes i {
  margin-top: 0.1rem;
  color: #0f766e;
}

.movement-receipts {
  padding-top: 0.65rem;
  border-top: 1px dashed color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.movement-receipts h4 {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0 0 0.55rem;
  font-size: 0.85rem;
}

.comprobante-review {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.65rem;
  padding: 0.55rem 0.65rem;
  margin-bottom: 0.4rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  border-radius: 9px;
}

.comprobante-review__info {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
}

.comprobante-review__info > span:not(.status-pill):not(.movement-chip) {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.comprobante-review__info small {
  max-width: 14rem;
  overflow: hidden;
  color: var(--pj-text-muted);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comprobante-review__icon {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 7px;
  background: color-mix(in srgb, #2563eb 9%, transparent);
  color: #2563eb;
}

.movement-chip {
  padding: 0.15rem 0.4rem;
  border-radius: 5px;
  background: color-mix(in srgb, var(--pj-navy) 7%, transparent);
  font-size: 0.7rem;
  font-weight: 600;
}

.comprobante-review__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.comprobante-review__comments {
  flex: 0 0 100%;
}

.revision-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
  margin-top: 0.75rem;
}

.decision-panel {
  max-width: 52rem;
}

.decision-panel__intro {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  padding: 0.8rem;
  border-radius: 10px;
  background: linear-gradient(135deg, color-mix(in srgb, #0f766e 8%, #fff), #fff);
}

.decision-panel__intro > span {
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  flex: 0 0 auto;
  border-radius: 9px;
  background: #0f766e;
  color: #fff;
}

.decision-panel__intro h3,
.decision-panel__intro p {
  margin: 0;
}

.decision-panel__intro p {
  margin-top: 0.2rem;
  color: var(--pj-text-muted);
  font-size: 0.82rem;
}

.observation-field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.observation-field label {
  font-size: 0.85rem;
  font-weight: 750;
}

.revision-textarea {
  width: 100%;
  min-height: 8rem;
  padding: 0.85rem 0.95rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 85%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-navy) 1.5%, #fff);
  line-height: 1.5;
  resize: vertical;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.revision-textarea:focus {
  border-color: #0f766e;
  box-shadow: 0 0 0 3px color-mix(in srgb, #0f766e 14%, transparent);
}

.observation-field small {
  color: var(--pj-text-muted);
  font-size: 0.75rem;
}

.revision-estado-select {
  min-width: 12rem;
}

.status-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.status-pill--muted {
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  color: var(--pj-navy);
}

.status-pill--aprobada {
  background: color-mix(in srgb, #16a34a 12%, transparent);
  color: #15803d;
}

.status-pill--no_aprobada {
  background: color-mix(in srgb, #dc2626 12%, transparent);
  color: #b91c1c;
}

.status-pill--en_revision {
  background: color-mix(in srgb, #d97706 12%, transparent);
  color: #b45309;
}

.revision-empty-detail {
  padding: 2rem 1rem;
  text-align: center;
}

@media (max-width: 860px) {
  .revision-layout {
    grid-template-columns: 1fr;
  }

  .revision-list {
    position: static;
    max-height: 18rem;
  }

  .summary-strip {
    grid-template-columns: repeat(2, minmax(8rem, 1fr));
  }

  .persona-review {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .persona-review__name {
    grid-column: 1 / -1;
  }

  .movement-values,
  .movement-changes {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 540px) {
  .summary-strip {
    grid-template-columns: 1fr;
  }

  .comprobante-review__info {
    flex-wrap: wrap;
  }
}
</style>
