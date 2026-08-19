<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import MediaUploadShell from '@/components/media/MediaUploadShell.vue'
import { useMediaPicker } from '@/composables/useMediaPicker'
import { MEDIA_UPLOAD_KIND, type MediaGalleryItem } from '@/modules/media/types'

const props = withDefaults(
  defineProps<{
    items?: MediaGalleryItem[]
    max?: number
    busy?: boolean
    disabled?: boolean
    title?: string
    subtitle?: string
    hint?: string
  }>(),
  { items: () => [], max: 20 },
)

const emit = defineEmits<{
  add: [files: File[]]
  remove: [id: string | number]
}>()

const { t } = useI18n()
const toast = useToast()
const { inputRef, dragging, openPicker, takeFiles, onDragOver, onDragLeave, onDrop } = useMediaPicker({
  accept: 'image/jpeg,image/png,image/webp',
  maxBytes: 5 * 1024 * 1024,
  multiple: true,
  optimizeImages: true,
})

async function apply(files: File[]): Promise<void> {
  if (props.disabled || props.busy || !files.length) return
  const room = Math.max(0, props.max - props.items.length)
  emit('add', files.slice(0, room || files.length))
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
</script>

<template>
  <MediaUploadShell
    :kind="MEDIA_UPLOAD_KIND.gallery"
    icon="pi pi-images"
    :title="title || t('media.galleryTitle')"
    :subtitle="subtitle || t('media.gallerySubtitle')"
    :meta="t('media.galleryMeta')"
    :hint="hint || t('media.galleryHint', { max })"
  >
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
      <span>{{ t('media.galleryDrop') }}</span>
    </button>
    <input
      ref="inputRef"
      type="file"
      class="sr-only"
      accept="image/jpeg,image/png,image/webp"
      multiple
      :disabled="disabled || busy"
      @change="handle(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''"
    />
    <div v-if="items.length" class="grid">
      <figure v-for="item in items" :key="item.id">
        <img :src="item.src" :alt="item.name || ''" />
        <button
          v-if="!disabled"
          type="button"
          class="grid__remove"
          :aria-label="t('common.delete')"
          @click="emit('remove', item.id)"
        >
          <i class="pi pi-times" />
        </button>
      </figure>
      <button
        v-if="items.length < max && !disabled"
        type="button"
        class="grid__add"
        :aria-label="t('media.selectImage')"
        @click="openPicker()"
      >
        <i class="pi pi-plus" />
      </button>
    </div>
  </MediaUploadShell>
</template>

<style scoped>
.drop {
  display: grid;
  justify-items: center;
  gap: 0.45rem;
  width: 100%;
  min-height: 7.5rem;
  border: 2px dashed #c4b5fd;
  border-radius: 16px;
  background: #f5f3ff;
  color: #6d28d9;
  cursor: pointer;
}
.drop.dragging,
.drop:hover { background: #ede9fe; }
.drop i { font-size: 1.6rem; }
.drop span { font-size: 0.86rem; font-weight: 700; }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(5.4rem, 1fr));
  gap: 0.55rem;
}
.grid figure {
  position: relative;
  margin: 0;
  aspect-ratio: 1;
  border-radius: 12px;
  overflow: hidden;
}
.grid img { width: 100%; height: 100%; object-fit: cover; }
.grid__remove {
  position: absolute;
  top: 0.25rem;
  right: 0.25rem;
  width: 1.4rem;
  height: 1.4rem;
  border: 0;
  border-radius: 50%;
  background: rgb(15 23 42 / 65%);
  color: #fff;
  cursor: pointer;
}
.grid__add {
  aspect-ratio: 1;
  border: 2px dashed #c4b5fd;
  border-radius: 12px;
  background: #f5f3ff;
  color: #7c3aed;
  font-size: 1.3rem;
  cursor: pointer;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
