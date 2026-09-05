<script setup lang="ts">
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import RadioButton from 'primevue/radiobutton'
import type { JuezConflictAction, JuezPropagateConflict } from '@/modules/events/types'

const props = defineProps<{
  visible: boolean
  conflicts: JuezPropagateConflict[]
  applying?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  apply: [decisions: Array<{ event_id: number; action: JuezConflictAction }>]
  dismiss: []
}>()

const { t } = useI18n()
const actions = reactive<Record<number, JuezConflictAction>>({})

const options: Array<{ value: JuezConflictAction; labelKey: string; hintKey: string }> = [
  { value: 'replace', labelKey: 'events.wizard.juezPropagateReplace', hintKey: 'events.wizard.juezPropagateReplaceHint' },
  { value: 'keep_both', labelKey: 'events.wizard.juezPropagateBoth', hintKey: 'events.wizard.juezPropagateBothHint' },
  { value: 'keep_existing', labelKey: 'events.wizard.juezPropagateKeep', hintKey: 'events.wizard.juezPropagateKeepHint' },
]

watch(
  () => props.conflicts,
  (items) => {
    for (const item of items) {
      if (!actions[item.id]) actions[item.id] = 'keep_both'
    }
  },
  { immediate: true },
)

function applyAll(action: JuezConflictAction): void {
  for (const item of props.conflicts) actions[item.id] = action
}

function names(people: Array<{ name: string }>): string {
  return people.map((person) => person.name).join(', ') || '—'
}

function confirm(): void {
  emit(
    'apply',
    props.conflicts.map((item) => ({
      event_id: item.id,
      action: actions[item.id] || 'keep_existing',
    })),
  )
}

function hide(): void {
  emit('update:visible', false)
  emit('dismiss')
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('events.wizard.juezPropagateTitle')"
    :style="{ width: 'min(40rem, 94vw)' }"
    @update:visible="hide"
  >
    <p class="juez-prop__lead">{{ t('events.wizard.juezPropagateLead') }}</p>
    <div class="juez-prop__bulk">
      <span>{{ t('events.wizard.juezPropagateApplyAll') }}</span>
      <Button
        v-for="option in options"
        :key="option.value"
        type="button"
        size="small"
        text
        :label="t(option.labelKey)"
        @click="applyAll(option.value)"
      />
    </div>
    <ul class="juez-prop__list">
      <li v-for="item in conflicts" :key="item.id" class="juez-prop__item">
        <strong>{{ item.name }}</strong>
        <small>
          {{ t('events.wizard.juezPropagateCurrent', { names: names(item.jueces) }) }}
        </small>
        <small>
          {{ t('events.wizard.juezPropagateIncoming', { names: names(item.incoming_jueces) }) }}
        </small>
        <div class="juez-prop__actions">
          <label v-for="option in options" :key="option.value">
            <RadioButton v-model="actions[item.id]" :value="option.value" :name="`juez-prop-${item.id}`" />
            <span>
              {{ t(option.labelKey) }}
              <em>{{ t(option.hintKey) }}</em>
            </span>
          </label>
        </div>
      </li>
    </ul>
    <template #footer>
      <Button :label="t('events.wizard.juezPropagateKeep')" text :disabled="applying" @click="hide" />
      <Button
        :label="t('common.save')"
        icon="pi pi-check"
        :loading="applying"
        @click="confirm"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.juez-prop__lead {
  margin: 0 0 0.75rem;
  color: var(--pj-text-muted);
  font-size: 0.9rem;
}

.juez-prop__bulk {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.35rem;
  margin-bottom: 0.75rem;
  font-size: 0.78rem;
  color: var(--pj-text-muted);
}

.juez-prop__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  max-height: min(24rem, 60vh);
  overflow: auto;
}

.juez-prop__item {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.7rem 0.8rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.juez-prop__item small {
  color: var(--pj-text-muted);
}

.juez-prop__actions {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-top: 0.45rem;
}

.juez-prop__actions label {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  font-size: 0.85rem;
}

.juez-prop__actions em {
  display: block;
  font-style: normal;
  font-size: 0.75rem;
  color: var(--pj-text-muted);
}
</style>
