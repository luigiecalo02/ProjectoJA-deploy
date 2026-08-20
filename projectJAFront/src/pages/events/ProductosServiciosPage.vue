<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import type { ProductoServicio, ProductoServicioPayload } from '@/modules/events/types'

const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()

const items = ref<ProductoServicio[]>([])
const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const editing = ref<ProductoServicio | null>(null)
const search = ref('')

const form = reactive({
  nombre: '',
  tipo: 'PASADIA',
  descripcion: '',
  precio: 0 as number | null,
  unidad: 'UNIDAD',
  activo: true,
})

const canManage = computed(() => can('events.update'))

const tipoOptions = computed(() => [
  { label: t('productosServicios.tipos.PASADIA'), value: 'PASADIA' },
  { label: t('productosServicios.tipos.CABANA'), value: 'CABANA' },
  { label: t('productosServicios.tipos.ALIMENTACION'), value: 'ALIMENTACION' },
  { label: t('productosServicios.tipos.PARQUEADERO'), value: 'PARQUEADERO' },
  { label: t('productosServicios.tipos.OTRO'), value: 'OTRO' },
])

const unidadOptions = computed(() => [
  { label: t('productosServicios.unidades.UNIDAD'), value: 'UNIDAD' },
  { label: t('productosServicios.unidades.DIA'), value: 'DIA' },
  { label: t('productosServicios.unidades.PERSONA'), value: 'PERSONA' },
])

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter((item) => {
    const haystack = `${item.nombre} ${item.tipo} ${item.descripcion ?? ''}`.toLowerCase()
    return haystack.includes(q)
  })
})

function resetForm(): void {
  form.nombre = ''
  form.tipo = 'PASADIA'
  form.descripcion = ''
  form.precio = 0
  form.unidad = 'UNIDAD'
  form.activo = true
}

function openCreate(): void {
  editing.value = null
  resetForm()
  dialogVisible.value = true
}

usePageChrome(() => ({
  title: t('productosServicios.title'),
  subtitle: t('productosServicios.subtitle'),
  actions: canManage.value
    ? [
        {
          key: 'new',
          label: t('productosServicios.new'),
          icon: 'pi pi-plus',
          onClick: openCreate,
        },
      ]
    : [],
}))

function openEdit(item: ProductoServicio): void {
  editing.value = item
  form.nombre = item.nombre
  form.tipo = item.tipo || 'OTRO'
  form.descripcion = item.descripcion ?? ''
  form.precio = item.precio ?? 0
  form.unidad = item.unidad || 'UNIDAD'
  form.activo = item.activo !== false
  dialogVisible.value = true
}

function tipoLabel(tipo: string): string {
  const key = `productosServicios.tipos.${tipo}`
  const label = t(key)
  return label === key ? tipo : label
}

async function load(): Promise<void> {
  loading.value = true
  try {
    items.value = await eventsService.productosServicios({ all: true })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!form.nombre.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('productosServicios.nameRequired'),
      life: 3000,
    })
    return
  }

  const payload: ProductoServicioPayload = {
    nombre: form.nombre.trim(),
    tipo: form.tipo.trim().toUpperCase() || 'OTRO',
    descripcion: form.descripcion.trim() || null,
    precio: form.precio ?? 0,
    unidad: form.unidad || 'UNIDAD',
    activo: form.activo,
  }

  saving.value = true
  try {
    if (editing.value) {
      await eventsService.updateProductoServicio(editing.value.id, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('productosServicios.updateSuccess'),
        life: 2500,
      })
    } else {
      await eventsService.createProductoServicio(payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('productosServicios.createSuccess'),
        life: 2500,
      })
    }
    dialogVisible.value = false
    await load()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function toggleActivo(item: ProductoServicio): Promise<void> {
  if (!canManage.value) return
  const next = !(item.activo !== false)
  try {
    await eventsService.updateProductoServicio(item.id, {
      nombre: item.nombre,
      tipo: item.tipo,
      descripcion: item.descripcion,
      precio: item.precio,
      unidad: item.unidad,
      activo: next,
    })
    item.activo = next
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: next ? t('productosServicios.activated') : t('productosServicios.deactivated'),
      life: 2200,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('productosServicios.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('productosServicios.subtitle') }}</p>
      </div>
      <Button
        v-if="canManage"
        icon="pi pi-plus"
        :label="t('productosServicios.new')"
        @click="openCreate"
      />
    </header>

    <div class="pj-toolbar">
      <AppSearchField v-model="search" :placeholder="t('common.search')" />
    </div>

    <div class="pj-panel">
      <PageLoader v-if="loading && !items.length" :label="t('common.loading')" />

      <DataTable v-else :value="filteredItems" data-key="id" striped-rows>
        <template #empty>
          <p class="pj-muted">{{ t('productosServicios.empty') }}</p>
        </template>

        <Column :header="t('productosServicios.nombre')" field="nombre">
          <template #body="{ data }">
            <div class="svc-name">
              <strong>{{ data.nombre }}</strong>
              <span v-if="data.descripcion" class="pj-muted">{{ data.descripcion }}</span>
            </div>
          </template>
        </Column>

        <Column :header="t('productosServicios.tipo')" style="width: 10rem">
          <template #body="{ data }">
            <Tag :value="tipoLabel(data.tipo)" severity="info" />
          </template>
        </Column>

        <Column :header="t('productosServicios.precio')" style="width: 9rem">
          <template #body="{ data }">
            {{ Number(data.precio ?? 0).toLocaleString() }}
          </template>
        </Column>

        <Column :header="t('productosServicios.unidad')" style="width: 8rem">
          <template #body="{ data }">
            {{ data.unidad || '—' }}
          </template>
        </Column>

        <Column :header="t('productosServicios.estado')" style="width: 8rem">
          <template #body="{ data }">
            <Tag
              :value="data.activo !== false ? t('common.active') : t('common.inactive')"
              :severity="data.activo !== false ? 'success' : 'secondary'"
            />
          </template>
        </Column>

        <Column v-if="canManage" :header="t('common.actions')" style="width: 9rem">
          <template #body="{ data }">
            <div class="actions">
              <Button
                icon="pi pi-pencil"
                text
                rounded
                :aria-label="t('common.edit')"
                @click="openEdit(data)"
              />
              <Button
                :icon="data.activo !== false ? 'pi pi-eye-slash' : 'pi pi-eye'"
                text
                rounded
                :aria-label="
                  data.activo !== false
                    ? t('productosServicios.deactivate')
                    : t('productosServicios.activate')
                "
                @click="toggleActivo(data)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? t('productosServicios.edit') : t('productosServicios.new')"
      :style="{ width: 'min(32rem, 94vw)' }"
    >
      <div class="form-grid">
        <div class="field">
          <label for="svc-nombre">{{ t('productosServicios.nombre') }}</label>
          <InputText id="svc-nombre" v-model="form.nombre" class="w-full" />
        </div>
        <div class="field">
          <label for="svc-tipo">{{ t('productosServicios.tipo') }}</label>
          <Select
            input-id="svc-tipo"
            v-model="form.tipo"
            :options="tipoOptions"
            option-label="label"
            option-value="value"
            class="w-full"
            editable
          />
        </div>
        <div class="field">
          <label for="svc-precio">{{ t('productosServicios.precioReferencial') }}</label>
          <InputNumber
            id="svc-precio"
            v-model="form.precio"
            class="w-full"
            :min="0"
            mode="currency"
            currency="COP"
            locale="es-CO"
          />
        </div>
        <div class="field">
          <label for="svc-unidad">{{ t('productosServicios.unidad') }}</label>
          <Select
            input-id="svc-unidad"
            v-model="form.unidad"
            :options="unidadOptions"
            option-label="label"
            option-value="value"
            class="w-full"
            editable
          />
        </div>
        <div class="field field--full">
          <label for="svc-desc">{{ t('productosServicios.descripcion') }}</label>
          <Textarea id="svc-desc" v-model="form.descripcion" rows="3" class="w-full" auto-resize />
        </div>
        <div class="field field--row field--full">
          <label for="svc-activo">{{ t('common.active') }}</label>
          <ToggleSwitch input-id="svc-activo" v-model="form.activo" />
        </div>
      </div>

      <template #footer>
        <Button :label="t('common.cancel')" text @click="dialogVisible = false" />
        <Button :label="t('common.save')" :loading="saving" @click="save" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.svc-name {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.actions {
  display: flex;
  gap: 0.15rem;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field--full {
  grid-column: 1 / -1;
}

.field--row {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
}

.w-full {
  width: 100%;
}

@media (max-width: 640px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
