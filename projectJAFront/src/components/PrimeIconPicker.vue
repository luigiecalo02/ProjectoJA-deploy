<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppSearchField from '@/components/AppSearchField.vue'
import { primeIconOptions } from '@/utils/primeIcons'
import { iconBoxStyle } from '@/utils/iconVisual'

const props = withDefaults(
  defineProps<{
    modelValue?: string | null
    color?: string | null
  }>(),
  {
    modelValue: null,
    color: null,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const search = ref('')

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return primeIconOptions
  return primeIconOptions.filter((item) => item.name.includes(q) || item.value.includes(q))
})

function select(value: string): void {
  emit('update:modelValue', value)
}
</script>

<template>
  <div class="icon-picker">
    <div class="icon-picker__head">
      <span class="icon-picker__preview" :style="iconBoxStyle(color)">
        <i :class="modelValue || 'pi pi-tag'" />
      </span>
      <AppSearchField
        v-model="search"
        class="icon-picker__search"
        :placeholder="t('events.catalogos.iconSearch')"
      />
    </div>
    <div class="icon-picker__grid" role="listbox">
      <button
        v-for="item in filtered"
        :key="item.value"
        type="button"
        class="icon-picker__item"
        :class="{ 'is-selected': modelValue === item.value }"
        :title="item.name"
        :aria-label="item.name"
        :aria-selected="modelValue === item.value"
        @click="select(item.value)"
      >
        <i :class="item.value" />
      </button>
      <p v-if="!filtered.length" class="pj-muted icon-picker__empty">
        {{ t('events.catalogos.iconEmpty') }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.icon-picker {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.icon-picker__head {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.icon-picker__preview {
  width: 2.4rem;
  height: 2.4rem;
  display: grid;
  place-items: center;
  border-radius: 10px;
  flex-shrink: 0;
  font-size: 1.15rem;
  border: 1px solid transparent;
}

.icon-picker__search {
  flex: 1;
  min-width: 0;
}

.icon-picker__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(2.15rem, 1fr));
  gap: 0.3rem;
  max-height: 11.5rem;
  overflow: auto;
  padding: 0.35rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-bg) 70%, transparent);
}

.icon-picker__item {
  width: 2.15rem;
  height: 2.15rem;
  display: grid;
  place-items: center;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--pj-text);
  cursor: pointer;
}

.icon-picker__item:hover {
  background: color-mix(in srgb, var(--p-primary-color) 10%, transparent);
}

.icon-picker__item.is-selected {
  color: var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 16%, transparent);
  border-color: color-mix(in srgb, var(--p-primary-color) 40%, transparent);
}

.icon-picker__empty {
  grid-column: 1 / -1;
  margin: 0.5rem 0;
  text-align: center;
}
</style>
