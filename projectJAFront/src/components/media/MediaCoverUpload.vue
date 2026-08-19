<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import MediaUploadShell from '@/components/media/MediaUploadShell.vue'
import { useMediaPicker } from '@/composables/useMediaPicker'
import { MEDIA_UPLOAD_KIND } from '@/modules/media/types'

const props = withDefaults(
  defineProps<{
    src?: string | null
    busy?: boolean
    disabled?: boolean
    showRemove?: boolean
    title?: string
    subtitle?: string
    hint?: string
  }>(),
  { showRemove: false },
)

const emit = defineEmits<{
  select: [file: File]
  remove: []
}>()

const { t } = useI18n()
const toast = useToast()
const { inputRef, openPicker, takeFiles } = useMediaPicker({
  accept: 'image/jpeg,image/png,image/webp',
  maxBytes: 10 * 1024 * 1024,
  optimizeImages: true,
})

async function apply(files: File[]): Promise<void> {
  if (props.disabled || props.busy) return
  const file = files[0]
  if (file) emit('select', file)
}

async function onInput(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  try {
    await apply(await takeFiles(input.files))
  } catch {
    toast.add({ severity: 'warn', summary: t('common.error'), detail: t('media.tooLarge'), life: 3000 })
  }
  input.value = ''
}
</script>

<template>
  <MediaUploadShell
    :kind="MEDIA_UPLOAD_KIND.cover"
    icon="pi pi-image"
    :title="title || t('media.coverTitle')"
    :subtitle="subtitle || t('media.coverSubtitle')"
    :meta="t('media.coverMeta')"
    :hint="hint"
  >
    <button
      type="button"
      class="cover"
      :class="{ 'has-image': !!src }"
      :disabled="disabled || busy"
      :aria-label="t('media.changeCover')"
      @click="openPicker()"
    >
      <img v-if="src" :src="src" alt="" />
      <span class="cover__action">
        <i class="pi pi-camera" />
        <em>{{ src ? t('media.changeCover') : t('media.selectImage') }}</em>
      </span>
    </button>
    <input
      ref="inputRef"
      type="file"
      class="sr-only"
      accept="image/jpeg,image/png,image/webp"
      :disabled="disabled || busy"
      @change="onInput"
    />
    <template #actions>
      <div class="actions">
        <Button
          :label="t('media.selectImage')"
          icon="pi pi-upload"
          severity="success"
          :loading="busy"
          :disabled="disabled"
          @click="openPicker()"
        />
        <Button
          v-if="showRemove && src"
          :label="t('media.removeCover')"
          icon="pi pi-trash"
          severity="danger"
          outlined
          :disabled="disabled || busy"
          @click="emit('remove')"
        />
      </div>
    </template>
  </MediaUploadShell>
</template>

<style scoped>
.cover {
  position: relative;
  display: block;
  width: 100%;
  aspect-ratio: 16 / 9;
  border: 0;
  border-radius: 16px;
  overflow: hidden;
  padding: 0;
  background:
    linear-gradient(180deg, rgb(15 23 42 / 8%), rgb(15 23 42 / 35%)),
    #dcfce7;
  cursor: pointer;
}
.cover:disabled { cursor: not-allowed; }
.cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.cover__action {
  position: absolute;
  inset: 0;
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 0.4rem;
  color: #fff;
}
.cover__action i {
  width: 3rem;
  height: 3rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: #fff;
  color: #16a34a;
  font-size: 1.15rem;
  box-shadow: 0 8px 18px rgb(15 23 42 / 18%);
}
.cover__action em {
  font-style: normal;
  font-weight: 800;
  font-size: 0.92rem;
  text-shadow: 0 1px 8px rgb(15 23 42 / 45%);
}
.actions { display: grid; gap: 0.5rem; }
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
