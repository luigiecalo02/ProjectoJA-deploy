<script setup lang="ts">
import AppSearchField from '@/components/AppSearchField.vue'

withDefaults(
  defineProps<{
    modelValue: string
    label: string
    placeholder: string
    hint?: string
    icon?: string
    inputId?: string
  }>(),
  {
    hint: '',
    icon: 'pi pi-search',
    inputId: undefined,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  search: []
}>()
</script>

<template>
  <div class="search-panel pj-panel">
    <div class="search-panel__icon"><i :class="icon" /></div>
    <div class="search-panel__content">
      <label :for="inputId">{{ label }}</label>
      <AppSearchField
        :model-value="modelValue"
        :input-id="inputId"
        :placeholder="placeholder"
        :aria-label="label"
        @update:model-value="emit('update:modelValue', $event)"
        @search="emit('search')"
      />
      <small v-if="hint" class="search-panel__hint">
        <i class="pi pi-info-circle" />
        {{ hint }}
      </small>
    </div>
  </div>
</template>

<style scoped>
.search-panel {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.05rem 1.15rem;
}

.search-panel__icon {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 3rem;
  height: 3rem;
  border-radius: 12px;
  color: var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 12%, transparent);
  font-size: 1.25rem;
}

.search-panel__content {
  width: 100%;
  min-width: 0;
}

.search-panel__content label {
  display: block;
  margin-bottom: 0.45rem;
  font-weight: 700;
}

.search-panel__hint {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.45rem;
  color: var(--pj-text-muted);
}
</style>
