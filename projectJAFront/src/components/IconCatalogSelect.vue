<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import IconMark from '@/components/IconMark.vue'
import { useIconCatalog } from '@/composables/useIconCatalog'

export interface CatalogIconOption {
  label: string
  value: string
  preview: string
}

const model = defineModel<string | null>({ default: null })

const props = withDefaults(
  defineProps<{
    inputId?: string
    disabled?: boolean
    placeholder?: string
  }>(),
  {
    inputId: undefined,
    disabled: false,
    placeholder: undefined,
  },
)

const { t } = useI18n()
const { items, ensureLoaded } = useIconCatalog()

const options = computed<CatalogIconOption[]>(() => {
  const seen = new Set<string>()
  const catalog: CatalogIconOption[] = []

  for (const item of items.value) {
    if (item.estado === false || !item.valor || seen.has(item.valor)) continue
    seen.add(item.valor)
    catalog.push({
      label: item.nombre,
      value: item.valor,
      preview: item.url || item.valor,
    })
  }

  const current = model.value?.trim()
  if (current && !seen.has(current)) {
    catalog.unshift({
      label: current.replace(/^pi pi-/, ''),
      value: current,
      preview: current,
    })
  }

  return catalog
})

const selected = computed(() => options.value.find((item) => item.value === model.value) ?? null)

onMounted(() => {
  void ensureLoaded()
})
</script>

<template>
  <Select
    :id="props.inputId"
    v-model="model"
    :options="options"
    option-label="label"
    option-value="value"
    :placeholder="props.placeholder ?? t('roles.iconPlaceholder')"
    :disabled="props.disabled"
    filter
    show-clear
    class="w-full icon-catalog-select"
    :filter-fields="['label', 'value']"
  >
    <template #value="{ value, placeholder }">
      <span v-if="value" class="icon-catalog-select__row">
        <IconMark :icono="selected?.preview || value" />
        <span>{{ selected?.label || value.replace(/^pi pi-/, '') }}</span>
      </span>
      <span v-else class="icon-catalog-select__placeholder">{{ placeholder }}</span>
    </template>
    <template #option="{ option }">
      <span class="icon-catalog-select__row">
        <IconMark :icono="option.preview" />
        <span>{{ option.label }}</span>
      </span>
    </template>
  </Select>
</template>

<style scoped>
.icon-catalog-select {
  width: 100%;
}

.icon-catalog-select__row {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  color: var(--p-form-field-color, #071e48);
}

.icon-catalog-select__row :deep(i),
.icon-catalog-select__row :deep(.icon-mark__img) {
  width: 1.15rem;
  height: 1.15rem;
  text-align: center;
  color: #071e48;
}

.icon-catalog-select__placeholder {
  color: var(--p-form-field-placeholder-color, #5b6b82);
}
</style>
