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
    compact?: boolean
    dense?: boolean
    title?: string
    subtitle?: string
    hint?: string
  }>(),
  { showRemove: false, compact: false, dense: false },
)

const emit = defineEmits<{
  select: [file: File]
  remove: []
}>()

const { t } = useI18n()
const toast = useToast()
const { inputRef, openPicker, takeFiles } = useMediaPicker({
  accept: 'image/jpeg,image/png,image/webp',
  maxBytes: 5 * 1024 * 1024,
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
    :kind="MEDIA_UPLOAD_KIND.profile"
    icon="pi pi-user"
    :title="title || t('media.profileTitle')"
    :subtitle="dense ? undefined : subtitle || t('media.profileSubtitle')"
    :meta="t('media.profileMeta')"
    :hint="dense ? undefined : hint || t('media.profileHint')"
    :dense="dense"
  >
    <div class="profile" :class="{ compact }">
      <button
        type="button"
        class="profile__preview"
        :disabled="disabled || busy"
        :aria-label="t('media.selectImage')"
        @click="openPicker()"
      >
        <img v-if="src" :src="src" alt="" />
        <i v-else class="pi pi-user" />
        <span class="profile__cam" aria-hidden="true"><i class="pi pi-camera" /></span>
      </button>
      <input
        ref="inputRef"
        type="file"
        class="sr-only"
        accept="image/jpeg,image/png,image/webp"
        :disabled="disabled || busy"
        @change="onInput"
      />
    </div>
    <template #actions>
      <div class="actions">
        <Button
          :label="t('media.selectImage')"
          icon="pi pi-upload"
          :size="compact || dense ? 'small' : undefined"
          :loading="busy"
          :disabled="disabled"
          @click="openPicker()"
        />
        <Button
          v-if="showRemove && src"
          :label="t('media.removePhoto')"
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
.profile { display: grid; justify-items: center; }
.profile__preview {
  position: relative;
  width: 10.5rem;
  height: 10.5rem;
  border: 3px dashed #93c5fd;
  border-radius: 50%;
  background: #eff6ff;
  overflow: visible;
  padding: 0;
  cursor: pointer;
}
.compact .profile__preview { width: 7.5rem; height: 7.5rem; }
.profile__preview:disabled { cursor: not-allowed; opacity: 0.7; }
.profile__preview img,
.profile__preview > .pi-user {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}
.profile__preview > .pi-user {
  display: grid;
  place-items: center;
  font-size: 2.4rem;
  color: #60a5fa;
}
.profile__cam {
  position: absolute;
  right: 0.15rem;
  bottom: 0.15rem;
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 50%;
  background: #4f46e5;
  color: #fff;
  display: grid;
  place-items: center;
  box-shadow: 0 4px 10px rgb(79 70 229 / 35%);
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
