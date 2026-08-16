<script setup lang="ts">
import InputText from 'primevue/inputtext'

withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
  ariaLabel?: string
  inputId?: string
  disabled?: boolean
}>(), {
  placeholder: '',
  ariaLabel: '',
  inputId: undefined,
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  search: []
}>()
</script>

<template>
  <div class="app-search-field">
    <InputText
      :id="inputId"
      :model-value="modelValue"
      :placeholder="placeholder"
      :disabled="disabled"
      @update:model-value="emit('update:modelValue', String($event ?? ''))"
      @keyup.enter="emit('search')"
    />
    <button
      type="button"
      :aria-label="ariaLabel || placeholder"
      :disabled="disabled"
      @click="emit('search')"
    >
      <i class="pi pi-search" />
    </button>
  </div>
</template>

<style scoped>
.app-search-field {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--p-primary-color) 18%, var(--pj-border));
  border-radius: 999px;
  background: #fff;
  box-shadow: 0 8px 24px rgb(29 78 216 / 8%);
}

.app-search-field :deep(.p-inputtext) {
  width: 100%;
  min-width: 0;
  height: 3rem;
  padding: 0 1rem 0 1.2rem;
  border: 0;
  border-radius: 999px 0 0 999px;
  box-shadow: none;
  background: transparent;
  font-size: 0.9rem;
}

.app-search-field button {
  display: grid;
  flex: 0 0 3rem;
  width: 3rem;
  height: 3rem;
  padding: 0;
  border: 0;
  border-radius: 50%;
  place-items: center;
  background: var(--p-primary-color);
  color: #fff;
  cursor: pointer;
  font-size: 1rem;
}

.app-search-field button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.app-search-field:focus-within {
  border-color: var(--p-primary-color);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--p-primary-color) 14%, transparent);
}
</style>
