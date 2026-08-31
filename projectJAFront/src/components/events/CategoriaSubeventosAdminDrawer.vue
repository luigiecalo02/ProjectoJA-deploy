<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import ColorAlphaPicker from '@/components/terrenos/ColorAlphaPicker.vue'
import { colorToCss } from '@/utils/color'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type { CategoriaSubevento } from '@/modules/events/types'

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
const items = ref<CategoriaSubevento[]>([])
const formVisible = ref(false)
const editingId = ref<number | null>(null)

const form = reactive({
  nombre: '',
  color: '#2563eb',
  icono: 'pi pi-tag',
  orden: 0,
  estado: true,
  maneja_puntos: true,
  maneja_fecha_inicio: false,
  maneja_fecha_fin: false,
})

const drawerVisible = computed({
  get: () => props.visible,
  set: (value: boolean) => emit('update:visible', value),
})

const formTitle = computed(() =>
  editingId.value ? t('events.wizard.catEdit') : t('events.wizard.catAdd'),
)

function resetForm(): void {
  form.nombre = ''
  form.color = '#2563eb'
  form.icono = 'pi pi-tag'
  form.orden = (items.value.at(-1)?.orden ?? 0) + 1
  form.estado = true
  form.maneja_puntos = true
  form.maneja_fecha_inicio = false
  form.maneja_fecha_fin = false
}

function openCreate(): void {
  editingId.value = null
  resetForm()
  formVisible.value = true
}

function openEdit(item: CategoriaSubevento): void {
  editingId.value = item.id
  form.nombre = item.nombre
  form.color = item.color || '#2563eb'
  form.icono = item.icono || 'pi pi-tag'
  form.orden = item.orden ?? 0
  form.estado = item.estado !== false
  form.maneja_puntos = item.maneja_puntos !== false
  form.maneja_fecha_inicio = !!item.maneja_fecha_inicio
  form.maneja_fecha_fin = !!item.maneja_fecha_fin
  formVisible.value = true
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    items.value = await eventsService.categoriasSubevento({ todos: true })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    items.value = []
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!form.nombre.trim()) {
    errorMessage.value = t('events.wizard.catNameRequired')
    return
  }
  saving.value = true
  errorMessage.value = ''
  try {
    const payload = {
      nombre: form.nombre.trim(),
      color: form.color || null,
      icono: form.icono.trim() || null,
      orden: form.orden,
      estado: form.estado,
      maneja_puntos: form.maneja_puntos,
      maneja_fecha_inicio: form.maneja_fecha_inicio,
      maneja_fecha_fin: form.maneja_fecha_fin,
    }
    if (editingId.value) {
      await eventsService.updateCategoriaSubevento(editingId.value, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.catUpdateSuccess'),
        life: 2000,
      })
    } else {
      await eventsService.createCategoriaSubevento(payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.catCreateSuccess'),
        life: 2000,
      })
    }
    formVisible.value = false
    await load()
    emit('changed')
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

async function removeItem(item: CategoriaSubevento): Promise<void> {
  if (!confirm(t('events.wizard.catDeleteConfirm'))) return
  try {
    await eventsService.removeCategoriaSubevento(item.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.catDeleteSuccess'),
      life: 2000,
    })
    await load()
    emit('changed')
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) void load()
    else formVisible.value = false
  },
)
</script>

<template>
  <AppStackDrawer
    v-model:visible="drawerVisible"
    :title="t('events.wizard.catAdminTitle')"
    :subtitle="t('events.wizard.catAdminSubtitle')"
    :level="1"
  >
    <Message v-if="errorMessage" severity="error" :closable="true" @close="errorMessage = ''">
      {{ errorMessage }}
    </Message>

    <div class="cat-toolbar">
      <Button type="button" icon="pi pi-plus" :label="t('events.wizard.catAdd')" @click="openCreate" />
    </div>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <div v-else-if="!items.length" class="pj-muted">
      {{ t('events.wizard.catEmpty') }}
    </div>

    <ul v-else class="cat-list">
      <li v-for="item in items" :key="item.id" class="cat-list__item">
        <span class="cat-list__icon" :style="{ color: colorToCss(item.color) }">
          <i :class="item.icono || 'pi pi-tag'" />
        </span>
        <div class="cat-list__body">
          <strong>{{ item.nombre }}</strong>
          <div class="cat-list__flags">
            <span v-if="item.maneja_puntos" class="flag">{{ t('events.wizard.catFlagPoints') }}</span>
            <span v-if="item.maneja_fecha_inicio" class="flag">{{ t('events.wizard.catFlagStart') }}</span>
            <span v-if="item.maneja_fecha_fin" class="flag">{{ t('events.wizard.catFlagEnd') }}</span>
            <span v-if="!item.estado" class="flag flag--off">{{ t('common.inactive') }}</span>
          </div>
        </div>
        <div class="cat-list__actions">
          <Button type="button" icon="pi pi-pencil" text rounded size="small" @click="openEdit(item)" />
          <Button
            type="button"
            icon="pi pi-trash"
            text
            rounded
            size="small"
            severity="danger"
            @click="removeItem(item)"
          />
        </div>
      </li>
    </ul>

    <template #footer>
      <Button :label="t('common.close')" text @click="drawerVisible = false" />
    </template>
  </AppStackDrawer>

  <AppStackDrawer
    v-model:visible="formVisible"
    :title="formTitle"
    :subtitle="t('events.wizard.catFormSubtitle')"
    :level="2"
  >
    <div class="cat-form">
      <div class="field">
        <label>{{ t('events.wizard.catName') }}</label>
        <InputText v-model="form.nombre" class="w-full" />
      </div>
      <div class="field-grid">
        <div class="field">
          <label>{{ t('events.wizard.catColor') }}</label>
          <ColorAlphaPicker v-model="form.color" />
        </div>
        <div class="field">
          <label>{{ t('events.wizard.catIcon') }}</label>
          <InputText v-model="form.icono" class="w-full" placeholder="pi pi-tag" />
        </div>
        <div class="field">
          <label>{{ t('events.wizard.catOrder') }}</label>
          <InputNumber v-model="form.orden" class="w-full" :min="0" />
        </div>
      </div>

      <div class="field field--row">
        <label>{{ t('events.wizard.catActive') }}</label>
        <ToggleSwitch v-model="form.estado" />
      </div>

      <div class="cat-caps">
        <h4>{{ t('events.wizard.catCapsTitle') }}</h4>
        <p class="pj-muted">{{ t('events.wizard.catCapsLead') }}</p>
        <label class="cap-row">
          <ToggleSwitch v-model="form.maneja_puntos" />
          <span>{{ t('events.wizard.catFlagPoints') }}</span>
        </label>
        <label class="cap-row">
          <ToggleSwitch v-model="form.maneja_fecha_inicio" />
          <span>{{ t('events.wizard.catFlagStart') }}</span>
        </label>
        <label class="cap-row">
          <ToggleSwitch v-model="form.maneja_fecha_fin" />
          <span>{{ t('events.wizard.catFlagEnd') }}</span>
        </label>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" text :disabled="saving" @click="formVisible = false" />
      <Button :label="t('common.save')" icon="pi pi-check" :loading="saving" @click="save" />
    </template>
  </AppStackDrawer>
</template>

<style scoped>
.cat-toolbar {
  margin-bottom: 0.85rem;
}

.cat-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.cat-list__item {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.7rem 0.75rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  background: #fff;
}

.cat-list__icon {
  width: 2rem;
  height: 2rem;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: color-mix(in srgb, currentColor 12%, transparent);
  flex-shrink: 0;
}

.cat-list__body {
  flex: 1;
  min-width: 0;
}

.cat-list__body strong {
  display: block;
}

.cat-list__flags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-top: 0.25rem;
}

.flag {
  font-size: 0.72rem;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  color: var(--pj-text-muted);
}

.flag--off {
  color: #b45309;
  border-color: #f59e0b;
}

.cat-list__actions {
  display: flex;
  flex-shrink: 0;
}

.cat-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field--row {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.cat-caps {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.cat-caps h4 {
  margin: 0;
  font-size: 0.95rem;
}

.cat-caps .pj-muted {
  margin: 0;
  font-size: 0.85rem;
}

.cap-row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  cursor: pointer;
}

@media (max-width: 640px) {
  .field-grid {
    grid-template-columns: 1fr;
  }
}
</style>
