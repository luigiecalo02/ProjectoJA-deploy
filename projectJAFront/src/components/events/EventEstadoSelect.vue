<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'

const props = withDefaults(
  defineProps<{
    modelValue: string
    loading?: boolean
    compact?: boolean
  }>(),
  {
    loading: false,
    compact: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()

const options = computed(() => {
  const base = [
    { label: t('events.estadoBorrador'), value: 'borrador' },
    { label: t('events.estadoPublicado'), value: 'publicado' },
    { label: t('events.estadoEnProceso'), value: 'en_proceso' },
    { label: t('events.estadoCerrado'), value: 'cerrado' },
  ]
  if (props.modelValue === 'cancelado') {
    return [...base, { label: t('events.estadoCancelado'), value: 'cancelado' }]
  }
  return base
})
</script>

<template>
  <Select
    :model-value="modelValue || 'borrador'"
    :options="options"
    option-label="label"
    option-value="value"
    :disabled="loading"
    :loading="loading"
    append-to="body"
    class="event-estado-select"
    :class="{ 'event-estado-select--compact': compact, [`is-${modelValue || 'borrador'}`]: true }"
    :aria-label="t('events.workflowStatus')"
    @update:model-value="emit('update:modelValue', $event)"
    @click.stop
  />
</template>

<style scoped>
.event-estado-select {
  min-width: 10.5rem;
}

.event-estado-select--compact {
  min-width: 9.5rem;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.18);
}

.event-estado-select.is-publicado :deep(.p-select-label) {
  color: #15803d;
  font-weight: 700;
}

.event-estado-select.is-en_proceso :deep(.p-select-label) {
  color: #1d4ed8;
  font-weight: 700;
}

.event-estado-select.is-borrador :deep(.p-select-label) {
  color: #b45309;
  font-weight: 700;
}

.event-estado-select.is-cerrado :deep(.p-select-label),
.event-estado-select.is-cancelado :deep(.p-select-label) {
  color: #475569;
  font-weight: 700;
}
</style>
