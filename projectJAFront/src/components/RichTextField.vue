<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isRichTextEmpty, toEditorHtml } from '@/utils/richText'

const props = defineProps<{
  modelValue: string
  placeholder?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const editorRef = ref<HTMLDivElement | null>(null)

function syncFromModel(value: string): void {
  const el = editorRef.value
  if (!el) return
  const html = toEditorHtml(value)
  if (el.innerHTML === html) return
  if (document.activeElement === el) return
  el.innerHTML = html
}

function emitHtml(): void {
  emit('update:modelValue', editorRef.value?.innerHTML ?? '')
}

function run(command: string, value?: string): void {
  if (props.disabled) return
  editorRef.value?.focus()
  document.execCommand(command, false, value)
  emitHtml()
}

function applyBlock(tag: 'h2' | 'h3' | 'p'): void {
  editorRef.value?.focus()
  const applied = document.execCommand('formatBlock', false, tag)
  if (!applied) document.execCommand('formatBlock', false, `<${tag}>`)
  emitHtml()
}

onMounted(() => {
  syncFromModel(props.modelValue)
})

watch(
  () => props.modelValue,
  (value) => {
    syncFromModel(value)
  },
)
</script>

<template>
  <div class="rich-field" :class="{ 'is-disabled': disabled }">
    <div class="rich-field__toolbar" role="toolbar">
      <button type="button" :disabled="disabled" :title="t('common.richText.title')" @click="applyBlock('h2')">
        {{ t('common.richText.title') }}
      </button>
      <button type="button" :disabled="disabled" :title="t('common.richText.subtitle')" @click="applyBlock('h3')">
        {{ t('common.richText.subtitle') }}
      </button>
      <button type="button" :disabled="disabled" :title="t('common.richText.paragraph')" @click="applyBlock('p')">
        {{ t('common.richText.paragraph') }}
      </button>
      <span class="rich-field__sep" />
      <button type="button" class="is-mark" :disabled="disabled" :title="t('common.richText.bold')" @click="run('bold')">
        <b>B</b>
      </button>
      <button type="button" class="is-mark" :disabled="disabled" :title="t('common.richText.italic')" @click="run('italic')">
        <i>I</i>
      </button>
      <button type="button" class="is-mark" :disabled="disabled" :title="t('common.richText.underline')" @click="run('underline')">
        <u>U</u>
      </button>
      <span class="rich-field__sep" />
      <button type="button" :disabled="disabled" :title="t('common.richText.list')" @click="run('insertUnorderedList')">
        <i class="pi pi-list" />
      </button>
      <button type="button" :disabled="disabled" :title="t('common.richText.ordered')" @click="run('insertOrderedList')">
        <i class="pi pi-list-ol" />
      </button>
    </div>
    <div
      ref="editorRef"
      class="rich-field__editor"
      :class="{ 'is-empty': isRichTextEmpty(modelValue) }"
      :contenteditable="disabled ? 'false' : 'true'"
      :data-placeholder="placeholder || t('common.richText.placeholder')"
      role="textbox"
      :aria-disabled="disabled"
      @input="emitHtml"
      @blur="emitHtml"
    />
  </div>
</template>

<style scoped>
.rich-field {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 10px;
  background: #fff;
  overflow: hidden;
}

.rich-field.is-disabled {
  opacity: 0.65;
  pointer-events: none;
}

.rich-field__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  align-items: center;
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  background: color-mix(in srgb, var(--pj-navy) 4%, #f8fafc);
}

.rich-field__toolbar button {
  border: 0;
  background: transparent;
  color: var(--pj-text);
  border-radius: 7px;
  padding: 0.28rem 0.5rem;
  font: inherit;
  font-size: 0.78rem;
  font-weight: 650;
  cursor: pointer;
}

.rich-field__toolbar button.is-mark {
  min-width: 1.8rem;
  font-size: 0.86rem;
}

.rich-field__toolbar button:hover {
  background: color-mix(in srgb, var(--pj-navy) 10%, transparent);
}

.rich-field__sep {
  width: 1px;
  height: 1.1rem;
  background: color-mix(in srgb, var(--pj-border) 80%, transparent);
  margin: 0 0.15rem;
}

.rich-field__editor {
  min-height: 9.5rem;
  padding: 0.7rem 0.8rem;
  outline: none;
  line-height: 1.55;
  font-size: 0.92rem;
}

.rich-field__editor.is-empty:before {
  content: attr(data-placeholder);
  color: color-mix(in srgb, var(--pj-text-muted) 80%, transparent);
  pointer-events: none;
}

.rich-field__editor.is-empty > * {
  display: none;
}

.rich-field__editor :deep(h2) {
  margin: 0 0 0.45rem;
  font-size: 1.15rem;
}

.rich-field__editor :deep(h3) {
  margin: 0.15rem 0 0.4rem;
  font-size: 1rem;
}

.rich-field__editor :deep(p) {
  margin: 0 0 0.45rem;
}

.rich-field__editor :deep(ul),
.rich-field__editor :deep(ol) {
  margin: 0 0 0.45rem;
  padding-left: 1.3rem;
}
</style>
