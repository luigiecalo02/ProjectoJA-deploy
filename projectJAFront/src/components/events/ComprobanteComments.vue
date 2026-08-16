<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type {
  EventoInscripcionComprobanteComentario,
} from '@/modules/events/types'

const props = defineProps<{
  comprobanteId: number
  comentarios: EventoInscripcionComprobanteComentario[]
}>()

const emit = defineEmits<{
  added: [comment: EventoInscripcionComprobanteComentario]
}>()

const { t } = useI18n()
const toast = useToast()
const message = ref('')
const saving = ref(false)

function formatDate(value?: string | null): string {
  if (!value) return ''
  return new Date(value).toLocaleString('es-CO', {
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

async function submit(): Promise<void> {
  const text = message.value.trim()
  if (!text || saving.value) return
  saving.value = true
  try {
    const comment = await eventsService.addComprobanteComentario(props.comprobanteId, text)
    emit('added', comment)
    message.value = ''
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 3500,
    })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <details class="receipt-comments">
    <summary>
      <i class="pi pi-comments" />
      {{ t('events.comprobantesComments') }}
      <span>{{ comentarios.length }}</span>
    </summary>
    <div class="receipt-comments__body">
      <p v-if="!comentarios.length" class="receipt-comments__empty">
        {{ t('events.comprobantesCommentsEmpty') }}
      </p>
      <div
        v-for="comment in comentarios"
        :key="comment.id"
        class="receipt-comment"
        :class="`is-${comment.autor_tipo}`"
      >
        <div>
          <strong>{{ comment.autor_nombre || t('events.comprobantesCommentAuthor') }}</strong>
          <span>{{ comment.autor_tipo === 'supervisor' ? t('events.comprobantesSupervisor') : t('events.comprobantesDirector') }}</span>
          <small>{{ formatDate(comment.created_at) }}</small>
        </div>
        <p>{{ comment.mensaje }}</p>
      </div>
      <div class="receipt-comments__form">
        <Textarea
          v-model="message"
          rows="2"
          auto-resize
          :placeholder="t('events.comprobantesCommentPlaceholder')"
        />
        <Button
          icon="pi pi-send"
          :label="t('events.comprobantesCommentSend')"
          size="small"
          :loading="saving"
          :disabled="!message.trim()"
          @click="submit"
        />
      </div>
    </div>
  </details>
</template>

<style scoped>
.receipt-comments {
  width: 100%;
  border-top: 1px dashed var(--pj-border);
}

.receipt-comments summary {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0;
  color: var(--p-primary-color);
  cursor: pointer;
  font-size: 0.78rem;
  font-weight: 700;
  list-style: none;
}

.receipt-comments summary span {
  min-width: 1.25rem;
  padding: 0.08rem 0.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--p-primary-color) 10%, transparent);
  text-align: center;
  font-size: 0.65rem;
}

.receipt-comments__body {
  display: grid;
  gap: 0.5rem;
  padding: 0.25rem 0 0.5rem;
}

.receipt-comments__empty {
  margin: 0;
  color: var(--pj-text-muted);
  font-size: 0.76rem;
}

.receipt-comment {
  padding: 0.55rem 0.65rem;
  border-left: 3px solid #0f766e;
  border-radius: 7px;
  background: color-mix(in srgb, #0f766e 5%, #fff);
}

.receipt-comment.is-supervisor {
  border-left-color: #2563eb;
  background: color-mix(in srgb, #2563eb 5%, #fff);
}

.receipt-comment > div {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.72rem;
}

.receipt-comment > div span,
.receipt-comment > div small {
  color: var(--pj-text-muted);
}

.receipt-comment > div small {
  margin-left: auto;
}

.receipt-comment p {
  margin: 0.3rem 0 0;
  white-space: pre-wrap;
  font-size: 0.8rem;
}

.receipt-comments__form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.5rem;
  align-items: end;
}

.receipt-comments__form :deep(.p-textarea) {
  width: 100%;
  min-height: 3.5rem;
  border-radius: 8px;
  resize: vertical;
}

@media (max-width: 560px) {
  .receipt-comments__form {
    grid-template-columns: 1fr;
  }
}
</style>
