<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import { useFieldModeStore } from '@/stores/fieldMode'

const { t } = useI18n()
const field = useFieldModeStore()

const visible = computed(
  () =>
    field.canUseFieldMode &&
    (!field.online || field.hasPending || Boolean(field.lastSyncError) || field.syncing),
)

const message = computed(() => {
  if (!field.online && field.pendingCount > 0) {
    return t('fieldMode.offlinePending', { count: field.pendingCount })
  }
  if (!field.online) {
    return t('fieldMode.offline')
  }
  if (field.syncing) {
    return t('fieldMode.syncing')
  }
  if (field.failedCount > 0) {
    return t('fieldMode.failed', { count: field.failedCount })
  }
  if (field.pendingCount > 0) {
    return t('fieldMode.pending', { count: field.pendingCount })
  }
  return field.lastSyncError || t('fieldMode.offline')
})
</script>

<template>
  <div
    v-if="visible"
    class="field-banner"
    :class="{ 'field-banner--offline': !field.online, 'field-banner--warn': field.online && field.failedCount > 0 }"
    role="status"
  >
    <span>
      <i :class="field.online ? 'pi pi-cloud-upload' : 'pi pi-wifi'" />
      {{ message }}
    </span>
    <Button
      v-if="field.online && field.hasPending"
      size="small"
      icon="pi pi-refresh"
      :label="t('fieldMode.retry')"
      :loading="field.syncing"
      @click="() => void field.syncPending()"
    />
  </div>
</template>

<style scoped>
.field-banner {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  background: #1d4ed8;
  color: #eff6ff;
  z-index: 19;
}

.field-banner--offline {
  background: #b45309;
  color: #fff7ed;
}

.field-banner--warn {
  background: #b91c1c;
  color: #fef2f2;
}

.field-banner span {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.65rem;
  font-weight: 700;
}
</style>
