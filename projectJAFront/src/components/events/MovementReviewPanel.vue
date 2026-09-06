<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ComprobanteComments from '@/components/events/ComprobanteComments.vue'
import type {
  EventoComprobanteEstado,
  EventoInscripcionComprobante,
  EventoInscripcionComprobanteComentario,
  EventoInscripcionMovimiento,
} from '@/modules/events/types'

defineProps<{
  movement: EventoInscripcionMovimiento
  saving?: boolean
}>()

const emit = defineEmits<{
  review: [comprobante: EventoInscripcionComprobante, estado: EventoComprobanteEstado]
  commentAdded: [comprobanteId: number, comment: EventoInscripcionComprobanteComentario]
}>()

const { t } = useI18n()

function money(value: number | string | null | undefined): string {
  return Number(value ?? 0).toLocaleString('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  })
}

function comprobanteEstadoLabel(estado: string): string {
  return t(`events.comprobanteEstado.${estado}`, estado)
}
</script>

<template>
  <div class="movement-review-panel">
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
          <Button type="button" icon="pi pi-check" severity="success" text rounded :disabled="c.estado === 'aprobado' || saving" :aria-label="t('events.comprobantesApprove')" @click="emit('review', c, 'aprobado')" />
          <Button type="button" icon="pi pi-times" severity="danger" text rounded :disabled="c.estado === 'rechazado' || saving" :aria-label="t('events.comprobantesReject')" @click="emit('review', c, 'rechazado')" />
          <Button type="button" icon="pi pi-clock" text rounded :disabled="c.estado === 'pendiente' || saving" :aria-label="t('events.comprobantesPending')" @click="emit('review', c, 'pendiente')" />
        </div>
        <ComprobanteComments
          class="comprobante-review__comments"
          :comprobante-id="c.id"
          :comentarios="c.comentarios ?? []"
          @added="emit('commentAdded', c.id, $event)"
        />
      </article>
    </div>
  </div>
</template>

<style scoped>
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

.comprobante-review__info > span:not(.status-pill) {
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

.comprobante-review__actions {
  display: flex;
  align-items: center;
  gap: 0.15rem;
}

.comprobante-review__comments {
  flex: 1 1 100%;
}

.status-pill {
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
}

.status-pill--muted {
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  color: var(--pj-navy);
}

@media (max-width: 640px) {
  .movement-values,
  .movement-changes {
    grid-template-columns: 1fr;
  }
}
</style>
