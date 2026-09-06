<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { resolveCachedEvidenceUrl } from '@/modules/fieldMode/evidenceCache'
import { summarizeEventPack, type FieldEventSummary } from '@/modules/fieldMode/packSummary'
import { useFieldModeStore } from '@/stores/fieldMode'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const fieldMode = useFieldModeStore()

const loading = ref(true)
const summaries = ref<FieldEventSummary[]>([])
const cardImages = ref<Record<number, string>>({})

function formatPackDate(iso?: string | null): string {
  if (!iso) return ''
  return new Date(iso).toLocaleString()
}

async function loadDesk(): Promise<void> {
  loading.value = true
  try {
    await fieldMode.refreshMeta()
    const pack = await fieldMode.cachedPack()
    summaries.value = (pack?.events ?? []).map((item) =>
      summarizeEventPack(item, pack?.downloaded_at),
    )
    const images: Record<number, string> = {}
    await Promise.all(
      summaries.value.map(async (item) => {
        if (!item.imageUrl) return
        images[item.eventId] = await resolveCachedEvidenceUrl(item.imageUrl)
      }),
    )
    cardImages.value = images
  } finally {
    loading.value = false
  }
}

async function uploadEvent(eventId: number, name: string): Promise<void> {
  if (!fieldMode.online) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('fieldMode.uploadOffline'),
      life: 3500,
    })
    return
  }
  const result = await fieldMode.syncPending(eventId)
  if (result.failed > 0) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t('fieldMode.uploadFailed', { name }),
      life: 4000,
    })
    return
  }
  if (result.synced <= 0) {
    toast.add({
      severity: 'info',
      summary: t('common.info'),
      detail: t('fieldMode.uploadEmpty'),
      life: 3000,
    })
    return
  }
  toast.add({
    severity: 'success',
    summary: t('common.success'),
    detail: t('fieldMode.uploadedEvent', { count: result.synced, name }),
    life: 3500,
  })
  await loadDesk()
}

onMounted(() => {
  void loadDesk()
})
</script>

<template>
  <section class="campo-desk">
    <header class="campo-desk__head">
      <h1>{{ t('fieldMode.campoTitle') }}</h1>
      <p>{{ t('fieldMode.campoSubtitle') }}</p>
    </header>

    <p v-if="loading" class="pj-muted">{{ t('common.loading') }}</p>

    <div v-else-if="!summaries.length" class="campo-empty">
      <i class="pi pi-cloud-download" />
      <p>{{ t('fieldMode.campoEmpty') }}</p>
    </div>

    <ul v-else class="campo-list">
      <li v-for="item in summaries" :key="item.eventId">
        <button type="button" class="campo-card" @click="router.push({ name: 'campo.judge', params: { id: item.eventId } })">
          <img
            v-if="cardImages[item.eventId]"
            class="campo-card__img"
            :src="cardImages[item.eventId]"
            :alt="item.eventName"
          />
          <div class="campo-card__body">
            <strong>{{ item.eventName }}</strong>
            <small v-if="item.downloadedAt">
              {{ t('fieldMode.packReadyAt', { date: formatPackDate(item.downloadedAt) }) }}
            </small>
            <span>
              {{ t('fieldMode.packSummaryChip', {
                subeventos: item.subeventos.length,
                clubes: item.clubes,
              }) }}
            </span>
            <span v-if="fieldMode.pendingForEvent(item.eventId)" class="campo-card__pending">
              {{ t('fieldMode.pending', { count: fieldMode.pendingForEvent(item.eventId) }) }}
            </span>
          </div>
        </button>
        <Button
          v-if="fieldMode.pendingForEvent(item.eventId)"
          size="small"
          icon="pi pi-cloud-upload"
          :label="t('fieldMode.uploadWithCount', { count: fieldMode.pendingForEvent(item.eventId) })"
          :disabled="!fieldMode.online"
          :loading="fieldMode.syncing"
          @click.stop="() => void uploadEvent(item.eventId, item.eventName)"
        />
      </li>
    </ul>
  </section>
</template>

<style scoped>
.campo-desk {
  max-width: 720px;
  margin: 0 auto;
}

.campo-desk__head h1 {
  margin: 0 0 0.25rem;
  font-family: var(--pj-font-display);
}

.campo-desk__head p,
.campo-card small,
.campo-card span {
  color: var(--pj-text-muted);
}

.campo-empty {
  display: grid;
  place-items: center;
  gap: 0.75rem;
  padding: 2.5rem 1rem;
  text-align: center;
  background: var(--pj-surface);
  border: 1px dashed var(--pj-border);
  border-radius: var(--pj-radius);
}

.campo-empty i {
  font-size: 1.8rem;
  color: var(--pj-primary);
}

.campo-list {
  list-style: none;
  margin: 1rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

.campo-list li {
  display: grid;
  gap: 0.5rem;
}

.campo-card {
  width: 100%;
  display: flex;
  gap: 0.8rem;
  text-align: left;
  border: 1px solid var(--pj-border);
  border-radius: var(--pj-radius);
  background: var(--pj-surface);
  padding: 0.9rem 1rem;
  box-shadow: var(--pj-shadow);
  cursor: pointer;
}

.campo-card__img {
  width: 64px;
  height: 64px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
}

.campo-card__body {
  display: grid;
  gap: 0.2rem;
}

.campo-card__pending {
  color: var(--pj-navy);
  font-weight: 700;
}
</style>
