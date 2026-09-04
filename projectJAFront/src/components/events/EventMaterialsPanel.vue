<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import MediaDocumentsUpload from '@/components/media/MediaDocumentsUpload.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import { previewFromEvidenceUrl } from '@/modules/events/evidencePreview'
import { documentKindFromName, formatFileSize, type MediaDocumentItem } from '@/modules/media/types'
import type { EventoArchivoMaterial } from '@/modules/events/types'

const props = defineProps<{
  eventId: number | null
  files: EventoArchivoMaterial[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  queued: [files: File[]]
  'queued-youtube': [payload: { url: string; titulo?: string }]
  uploaded: [item: EventoArchivoMaterial]
  removed: [id: number]
}>()

const { t } = useI18n()
const toast = useToast()
const youtubeUrl = ref('')
const youtubeTitle = ref('')
const busy = ref(false)

const accept = '.pdf,.jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mov,application/pdf,image/*,video/*'
const maxBytes = 50 * 1024 * 1024

const documentItems = computed<MediaDocumentItem[]>(() =>
  props.files
    .filter((item) => item.tipo !== 'youtube')
    .map((item) => ({
      id: item.id,
      name: item.titulo || item.name || t('events.materials.untitled'),
      url: item.url || undefined,
      kind: item.tipo === 'pdf' ? 'pdf' : item.tipo === 'video' ? 'video' : documentKindFromName(item.name || ''),
      sizeLabel: item.size != null ? formatFileSize(item.size) : undefined,
    })),
)

const youtubeItems = computed(() => props.files.filter((item) => item.tipo === 'youtube'))

const youtubePreview = computed(() => previewFromEvidenceUrl(youtubeUrl.value))

async function addFiles(files: File[]): Promise<void> {
  if (props.disabled || !files.length) return
  if (!props.eventId) {
    emit('queued', files)
    return
  }
  busy.value = true
  try {
    for (const file of files) {
      const created = await eventsService.addArchivoFile(props.eventId, file, file.name)
      emit('uploaded', created)
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    busy.value = false
  }
}

async function addYoutube(): Promise<void> {
  const preview = youtubePreview.value
  if (!preview || preview.kind !== 'youtube') {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.materials.youtubeInvalid'),
      life: 3000,
    })
    return
  }
  if (!props.eventId) {
    emit('queued-youtube', { url: youtubeUrl.value.trim(), titulo: youtubeTitle.value.trim() || undefined })
    youtubeUrl.value = ''
    youtubeTitle.value = ''
    return
  }
  busy.value = true
  try {
    const created = await eventsService.addArchivoYoutube(
      props.eventId,
      youtubeUrl.value.trim(),
      youtubeTitle.value.trim() || undefined,
    )
    emit('uploaded', created)
    youtubeUrl.value = ''
    youtubeTitle.value = ''
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    busy.value = false
  }
}

async function remove(id: string | number): Promise<void> {
  if (props.disabled) return
  if (!props.eventId) {
    emit('removed', Number(id))
    return
  }
  busy.value = true
  try {
    await eventsService.removeArchivo(props.eventId, Number(id))
    emit('removed', Number(id))
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    busy.value = false
  }
}

function open(item: EventoArchivoMaterial | MediaDocumentItem): void {
  const url = 'url' in item ? item.url : undefined
  if (url) window.open(url, '_blank', 'noopener')
}
</script>

<template>
  <section class="materials">
    <header>
      <strong>{{ t('events.materials.title') }}</strong>
      <p>{{ t('events.materials.subtitle') }}</p>
    </header>
    <MediaDocumentsUpload
      :files="documentItems"
      :accept="accept"
      :max-bytes="maxBytes"
      :busy="busy"
      :disabled="disabled"
      :title="t('events.materials.filesTitle')"
      :subtitle="t('events.materials.filesHint')"
      :hint="t('events.materials.filesMeta')"
      @add="addFiles"
      @remove="remove"
      @download="open"
    />
    <div class="youtube">
      <label>
        {{ t('events.materials.youtube') }}
        <InputText
          v-model="youtubeUrl"
          class="w-full"
          :placeholder="t('events.materials.youtubePlaceholder')"
          :disabled="disabled || busy"
        />
      </label>
      <label>
        {{ t('events.materials.youtubeTitle') }}
        <InputText
          v-model="youtubeTitle"
          class="w-full"
          :placeholder="t('events.materials.youtubeTitlePlaceholder')"
          :disabled="disabled || busy"
        />
      </label>
      <Button
        type="button"
        icon="pi pi-youtube"
        :label="t('events.materials.addYoutube')"
        :disabled="disabled || busy || !youtubeUrl.trim()"
        :loading="busy"
        @click="addYoutube"
      />
    </div>
    <ul v-if="youtubeItems.length" class="youtube-list">
      <li v-for="item in youtubeItems" :key="item.id">
        <i class="pi pi-youtube" />
        <button type="button" class="link" @click="open(item)">
          {{ item.titulo || item.url }}
        </button>
        <Button
          v-if="!disabled"
          type="button"
          icon="pi pi-times"
          text
          rounded
          severity="danger"
          @click="remove(item.id)"
        />
      </li>
    </ul>
  </section>
</template>

<style scoped>
.materials { display: grid; gap: 0.85rem; }
.materials header p { margin: 0.2rem 0 0; color: var(--pj-text-muted); font-size: 0.85rem; }
.youtube { display: grid; gap: 0.55rem; }
.youtube label { display: grid; gap: 0.3rem; font-size: 0.82rem; font-weight: 600; }
.w-full { width: 100%; }
.youtube-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; }
.youtube-list li {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.45rem;
  align-items: center;
  padding: 0.4rem 0.5rem;
  border: 1px solid var(--pj-border);
  border-radius: 10px;
}
.link {
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}
</style>
