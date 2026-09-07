<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { brandConfig } from '@/config/brand'
import { useAuthStore } from '@/stores/auth'
import { useBrandStore } from '@/stores/brand'
import { useFieldModeStore } from '@/stores/fieldMode'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const brand = useBrandStore()
const fieldMode = useFieldModeStore()

const isDesk = computed(() => route.name === 'campo')
const isEventPage = computed(() => route.name === 'campo.judge')
const pending = computed(() => fieldMode.pendingCount + fieldMode.failedCount)
const eventId = computed(() => Number(route.params.id) || 0)
const campoLink = computed(() => {
  if (!isEventPage.value || !eventId.value) return ''
  return `${window.location.origin}/campo/${eventId.value}`
})
const eventPending = computed(() => (eventId.value ? fieldMode.pendingForEvent(eventId.value) : 0))
const eventUploaded = computed(() => (eventId.value ? fieldMode.uploadedForEvent(eventId.value) : 0))

async function copyCampoLink(): Promise<void> {
  if (!campoLink.value) return
  try {
    await navigator.clipboard.writeText(campoLink.value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('fieldMode.linkCopied'),
      life: 3500,
    })
  } catch {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t('common.error'),
      life: 3000,
    })
  }
}

async function uploadAll(): Promise<void> {
  if (!fieldMode.online) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('fieldMode.uploadOffline'),
      life: 3500,
    })
    return
  }
  const result = await fieldMode.syncPending()
  if (result.failed > 0) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t('fieldMode.uploadFailedGeneric'),
      life: 4000,
    })
    return
  }
  toast.add({
    severity: 'success',
    summary: t('common.success'),
    detail: t('fieldMode.uploadedAll', { count: result.synced }),
    life: 3500,
  })
}

function warnOfflineReload(event: BeforeUnloadEvent): void {
  if (fieldMode.online) return
  event.preventDefault()
  event.returnValue = t('fieldMode.reloadWarn')
}

onMounted(() => {
  void fieldMode.init()
  window.addEventListener('beforeunload', warnOfflineReload)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', warnOfflineReload)
})
</script>

<template>
  <div class="field-shell">
    <header class="field-bar">
      <div class="field-bar__brand">
        <Button
          v-if="!isDesk"
          text
          rounded
          icon="pi pi-arrow-left"
          :aria-label="t('common.back')"
          @click="router.push({ name: 'campo' })"
        />
        <img :src="brandConfig.appIcon" :alt="t('fieldMode.campoTitle')" />
        <div>
          <strong>{{ t('fieldMode.campoTitle') }}</strong>
          <small>{{ auth.user?.name }}</small>
        </div>
      </div>
      <div class="field-bar__actions">
        <span
          class="field-status"
          :class="{ 'field-status--off': !fieldMode.online }"
        >
          <i :class="fieldMode.online ? 'pi pi-wifi' : 'pi pi-wifi'" />
          {{ fieldMode.online ? t('fieldMode.onlineNow') : t('fieldMode.offlineNow') }}
        </span>
        <Button
          v-if="pending > 0"
          size="small"
          icon="pi pi-cloud-upload"
          :label="t('fieldMode.uploadWithCount', { count: pending })"
          :disabled="!fieldMode.online"
          :loading="fieldMode.syncing"
          @click="() => void uploadAll()"
        />
        <Button
          v-if="fieldMode.online"
          size="small"
          outlined
          icon="pi pi-th-large"
          :label="t('fieldMode.goPlatform')"
          @click="router.push({ name: 'dashboard' })"
        />
      </div>
    </header>
    <aside v-if="campoLink" class="field-link" role="note">
      <div class="field-link__text">
        <strong>{{ t('fieldMode.linkSave') }}</strong>
        <code>{{ campoLink }}</code>
        <small>{{ t('fieldMode.linkHint') }}</small>
        <small v-if="fieldMode.deviceLabel">
          {{ t('fieldMode.savedOnDevice', { device: fieldMode.deviceLabel }) }}
        </small>
        <small>
          {{ t('fieldMode.uploadedCount', { count: eventUploaded }) }}
          ·
          {{
            eventPending
              ? t('fieldMode.pendingCount', { count: eventPending })
              : t('fieldMode.nothingPending')
          }}
        </small>
      </div>
      <Button
        size="small"
        icon="pi pi-copy"
        :label="t('fieldMode.linkCopy')"
        @click="() => void copyCampoLink()"
      />
    </aside>
    <main class="field-main" :style="{ '--pj-pattern': brand.patternCss }">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.field-shell {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  background: var(--pj-bg);
  color: var(--pj-text);
}

.field-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.7rem 1rem;
  background: var(--pj-navy);
  color: #fff;
}

.field-bar__brand {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}

.field-bar__brand img {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  object-fit: cover;
}

.field-bar__brand strong,
.field-bar__brand small {
  display: block;
}

.field-bar__brand small {
  opacity: 0.8;
}

.field-bar__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.field-status {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.55rem;
  border-radius: 999px;
  background: rgba(57, 181, 74, 0.25);
  font-size: 0.8rem;
  font-weight: 700;
}

.field-status--off {
  background: rgba(237, 28, 36, 0.28);
}

.field-link {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.65rem 1rem;
  background: #fff7ed;
  color: #7c2d12;
  border-bottom: 1px solid #fdba74;
}

.field-link__text {
  display: grid;
  gap: 0.2rem;
  min-width: 0;
  flex: 1;
}

.field-link__text code {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.8rem;
}

.field-link__text small {
  opacity: 0.85;
}

.field-main {
  flex: 1;
  padding: 1rem;
  background-image: var(--pj-pattern);
  background-size: 280px;
}

:deep(.p-button-outlined) {
  color: #fff;
  border-color: rgba(255, 255, 255, 0.45);
}
</style>
