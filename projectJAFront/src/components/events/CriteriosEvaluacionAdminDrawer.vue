<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type { CriterioEvaluacion } from '@/modules/events/types'

const props = defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  changed: []
}>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const items = ref<CriterioEvaluacion[]>([])
const formVisible = ref(false)
const editingId = ref<number | null>(null)

const form = reactive({
  nombre: '',
  descripcion: '',
  orden: 0,
  estado: true,
})

const drawerVisible = computed({
  get: () => props.visible,
  set: (value: boolean) => emit('update:visible', value),
})

function resetForm(): void {
  form.nombre = ''
  form.descripcion = ''
  form.orden = (items.value.at(-1)?.orden ?? 0) + 1
  form.estado = true
}

function openCreate(): void {
  editingId.value = null
  resetForm()
  formVisible.value = true
}

function openEdit(item: CriterioEvaluacion): void {
  editingId.value = item.id
  form.nombre = item.nombre
  form.descripcion = item.descripcion || ''
  form.orden = item.orden ?? 0
  form.estado = item.estado !== false
  formVisible.value = true
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    items.value = await eventsService.criteriosEvaluacion({ todos: true })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    items.value = []
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!form.nombre.trim()) {
    errorMessage.value = t('events.wizard.criteriaName')
    return
  }
  saving.value = true
  errorMessage.value = ''
  try {
    const payload = {
      nombre: form.nombre.trim(),
      descripcion: form.descripcion.trim() || null,
      orden: form.orden,
      estado: form.estado,
    }
    if (editingId.value) {
      await eventsService.updateCriterioEvaluacion(editingId.value, payload)
    } else {
      await eventsService.createCriterioEvaluacion(payload)
    }
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.criteriaSaved'),
      life: 2500,
    })
    formVisible.value = false
    await load()
    emit('changed')
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

async function remove(item: CriterioEvaluacion): Promise<void> {
  try {
    await eventsService.removeCriterioEvaluacion(item.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.criteriaDeleted'),
      life: 2500,
    })
    await load()
    emit('changed')
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) void load()
  },
)
</script>

<template>
  <AppStackDrawer
    v-model:visible="drawerVisible"
    :header="t('events.wizard.criteriaAdminTitle')"
    width="28rem"
  >
    <p class="pj-muted lead">{{ t('events.wizard.criteriaAdminLead') }}</p>
    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

    <div class="toolbar">
      <Button type="button" icon="pi pi-plus" :label="t('events.wizard.criteriaCreate')" @click="openCreate" />
    </div>

    <PageLoader v-if="loading" :label="t('common.loading')" />
    <ul v-else class="list">
      <li v-for="item in items" :key="item.id">
        <div>
          <strong>{{ item.nombre }}</strong>
          <p v-if="item.descripcion" class="pj-muted">{{ item.descripcion }}</p>
        </div>
        <div class="list__actions">
          <Button type="button" icon="pi pi-pencil" text rounded @click="openEdit(item)" />
          <Button type="button" icon="pi pi-trash" text rounded severity="danger" @click="remove(item)" />
        </div>
      </li>
    </ul>

    <div v-if="formVisible" class="form">
      <div class="field">
        <label>{{ t('events.wizard.criteriaName') }}</label>
        <InputText v-model="form.nombre" class="w-full" />
      </div>
      <div class="field">
        <label>{{ t('events.wizard.criteriaDesc') }}</label>
        <Textarea v-model="form.descripcion" rows="3" class="w-full" />
      </div>
      <div class="field">
        <label>Orden</label>
        <InputNumber v-model="form.orden" class="w-full" :min="0" />
      </div>
      <div class="field field--row">
        <label>Activo</label>
        <ToggleSwitch v-model="form.estado" />
      </div>
      <div class="form__actions">
        <Button type="button" text :label="t('common.cancel')" @click="formVisible = false" />
        <Button type="button" :label="t('common.save')" :loading="saving" @click="save" />
      </div>
    </div>
  </AppStackDrawer>
</template>

<style scoped>
.lead {
  margin: 0 0 0.85rem;
}
.toolbar {
  margin-bottom: 0.75rem;
}
.list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.list li {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.65rem 0.7rem;
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-bg) 80%, transparent);
}
.list__actions {
  display: flex;
  gap: 0.15rem;
}
.form {
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  display: grid;
  gap: 0.65rem;
}
.field label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.82rem;
  font-weight: 600;
}
.field--row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
}
</style>
