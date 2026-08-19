<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import MediaUploadShell from '@/components/media/MediaUploadShell.vue'
import { useMediaPicker } from '@/composables/useMediaPicker'
import { MEDIA_UPLOAD_KIND, type MediaDocumentItem } from '@/modules/media/types'

const props = withDefaults(
  defineProps<{
    files?: MediaDocumentItem[]
    accept?: string
    maxBytes?: number
    busy?: boolean
    disabled?: boolean
    title?: string
    subtitle?: string
    hint?: string
    optimizeImages?: boolean
  }>(),
  {
    files: () => [],
    accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,application/pdf',
    maxBytes: 20 * 1024 * 1024,
    optimizeImages: false,
  },
)

const emit = defineEmits<{
  add: [files: File[]]
  remove: [id: string | number]
  download: [item: MediaDocumentItem]
}>()

const { t } = useI18n()
const toast = useToast()
const { inputRef, dragging, openPicker, takeFiles, onDragOver, onDragLeave, onDrop } = useMediaPicker({
  accept: props.accept,
  maxBytes: props.maxBytes,
  multiple: true,
  optimizeImages: props.optimizeImages,
})

async function apply(files: File[]): Promise<void> {
  if (props.disabled || props.busy || !files.length) return
  emit('add', files)
}

async function handle(list: FileList | File[] | null): Promise<void> {
  try {
    await apply(await takeFiles(list))
  } catch {
    toast.add({ severity: 'warn', summary: t('common.error'), detail: t('media.tooLarge'), life: 3000 })
  }
}

async function onDropped(event: DragEvent): Promise<void> {
  try {
    await apply(await onDrop(event))
  } catch {
    toast.add({ severity: 'warn', summary: t('common.error'), detail: t('media.tooLarge'), life: 3000 })
  }
}

function kindClass(kind?: string): string {
  return `kind-${kind || 'file'}`
}
</script>

<template>
  <MediaUploadShell
    :kind="MEDIA_UPLOAD_KIND.documents"
    icon="pi pi-file"
    :title="title || t('media.docsTitle')"
    :subtitle="subtitle || t('media.docsSubtitle')"
    :meta="t('media.docsMeta')"
    :hint="hint || t('media.docsHint')"
  >
    <div class="docs">
      <button
        type="button"
        class="drop"
        :class="{ dragging }"
        :disabled="disabled || busy"
        @click="openPicker()"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDropped"
      >
        <i class="pi pi-cloud-upload" />
        <span>{{ t('media.docsDrop') }}</span>
        <div class="badges" aria-hidden="true">
          <em class="kind-pdf">PDF</em>
          <em class="kind-doc">DOC</em>
          <em class="kind-xls">XLS</em>
          <em class="kind-ppt">PPT</em>
          <em class="kind-zip">ZIP</em>
        </div>
      </button>
      <input
        ref="inputRef"
        type="file"
        class="sr-only"
        :accept="accept"
        multiple
        :disabled="disabled || busy"
        @change="handle(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''"
      />
      <section v-if="files.length" class="list">
        <header>
          <strong>{{ t('media.docsLoaded', { count: files.length }) }}</strong>
        </header>
        <article v-for="item in files" :key="item.id">
          <i class="pi pi-file" :class="kindClass(item.kind)" />
          <div>
            <strong>{{ item.name }}</strong>
            <small>{{ [item.sizeLabel, item.dateLabel].filter(Boolean).join(' · ') }}</small>
          </div>
          <button
            v-if="item.url"
            type="button"
            class="icon-btn"
            :aria-label="t('common.view')"
            @click="emit('download', item)"
          >
            <i class="pi pi-download" />
          </button>
          <button
            v-if="!disabled"
            type="button"
            class="icon-btn"
            :aria-label="t('common.delete')"
            @click="emit('remove', item.id)"
          >
            <i class="pi pi-times" />
          </button>
        </article>
      </section>
    </div>
  </MediaUploadShell>
</template>

<style scoped>
.docs {
  display: grid;
  gap: 0.85rem;
}
@media (min-width: 860px) {
  .docs { grid-template-columns: 1fr 1fr; align-items: start; }
}
.drop {
  display: grid;
  justify-items: center;
  gap: 0.45rem;
  min-height: 10rem;
  border: 2px dashed #93c5fd;
  border-radius: 16px;
  background: #eff6ff;
  color: #1d4ed8;
  cursor: pointer;
}
.drop.dragging,
.drop:hover { background: #dbeafe; }
.drop i { font-size: 1.7rem; }
.drop span { font-size: 0.86rem; font-weight: 700; text-align: center; padding: 0 0.8rem; }
.badges { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.3rem; }
.badges em {
  font-style: normal;
  font-size: 0.68rem;
  font-weight: 800;
  padding: 0.18rem 0.4rem;
  border-radius: 6px;
  color: #fff;
}
.kind-pdf { background: #dc2626; color: #fff; }
.kind-doc { background: #2563eb; color: #fff; }
.kind-xls { background: #16a34a; color: #fff; }
.kind-ppt { background: #ea580c; color: #fff; }
.kind-zip { background: #7c3aed; color: #fff; }
.kind-audio { color: #0f766e; }
.kind-video { color: #be185d; }
.kind-file { color: #475569; }
.list { display: grid; gap: 0.45rem; }
.list header { font-size: 0.82rem; }
.list article {
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  gap: 0.5rem;
  align-items: center;
  padding: 0.55rem 0.6rem;
  border: 1px solid var(--pj-border);
  border-radius: 12px;
  background: #fff;
}
.list article > .pi-file { font-size: 1.15rem; }
.list strong { display: block; font-size: 0.82rem; }
.list small { color: var(--pj-text-muted); font-size: 0.72rem; }
.icon-btn {
  border: 0;
  background: transparent;
  color: #64748b;
  cursor: pointer;
  padding: 0.2rem;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
