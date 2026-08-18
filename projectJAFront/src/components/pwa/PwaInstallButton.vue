<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { usePwaInstall } from '@/composables/usePwaInstall'

const { t } = useI18n()
const { visible, ios, helpOpen, install } = usePwaInstall()
</script>

<template>
  <div v-if="visible" class="pwa-install">
    <Button
      type="button"
      class="pwa-install__button"
      icon="pi pi-download"
      :label="t('pwa.install')"
      @click="install"
    />

    <Dialog
      v-model:visible="helpOpen"
      modal
      :header="t('pwa.install')"
      :style="{ width: 'min(92vw, 22rem)' }"
    >
      <ol v-if="ios" class="pwa-install__steps">
        <li>{{ t('pwa.iosStep1') }}</li>
        <li>{{ t('pwa.iosStep2') }}</li>
        <li>{{ t('pwa.iosStep3') }}</li>
      </ol>
      <p v-else class="pwa-install__hint">{{ t('pwa.androidHint') }}</p>
      <template #footer>
        <Button type="button" :label="t('common.close')" @click="helpOpen = false" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.pwa-install {
  display: none;
  width: min(100%, 420px);
}

.pwa-install__button {
  width: 100%;
}

.pwa-install__steps,
.pwa-install__hint {
  margin: 0;
  padding-left: 1.15rem;
  color: #334155;
  line-height: 1.5;
}

.pwa-install__hint {
  padding-left: 0;
}

@media (max-width: 959px) {
  .pwa-install {
    display: block;
  }
}
</style>
