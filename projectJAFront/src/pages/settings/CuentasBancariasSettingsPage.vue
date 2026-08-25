<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import MediaProfileUpload from '@/components/media/MediaProfileUpload.vue'
import { usePermission } from '@/composables/usePermission'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { cuentasBancariasService } from '@/services/cuentasBancariasService'
import type { CuentaBancaria, CuentaBancariaTipo } from '@/modules/settings/types'

const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()

const items = ref<CuentaBancaria[]>([])
const loading = ref(false)
const saving = ref(false)
const uploadingQr = ref(false)
const dialogVisible = ref(false)
const editing = ref<CuentaBancaria | null>(null)
const search = ref('')
const pendingQr = ref<File | null>(null)
const pendingQrPreview = ref<string | null>(null)

const canUpdate = computed(() => can('settings.update'))

const form = reactive({
  nombre: '',
  banco: '',
  tipo_cuenta: 'ahorros' as CuentaBancariaTipo,
  numero_cuenta: '',
  titular: '',
  identificacion_titular: '',
  activo: true,
})

const tipoOptions = computed(() => [
  { label: t('settings.bankAccounts.tipos.ahorros'), value: 'ahorros' },
  { label: t('settings.bankAccounts.tipos.corriente'), value: 'corriente' },
])

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter((item) => {
    const haystack = [
      item.nombre,
      item.banco,
      item.tipo_cuenta,
      item.numero_cuenta,
      item.titular,
      item.identificacion_titular,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const qrPreview = computed(
  () => pendingQrPreview.value || resolveFileUrl(editing.value?.qr_url) || editing.value?.qr_url || null,
)

function tipoLabel(tipo: string): string {
  const key = `settings.bankAccounts.tipos.${tipo}`
  const label = t(key)
  return label === key ? tipo : label
}

function resetForm(): void {
  form.nombre = ''
  form.banco = ''
  form.tipo_cuenta = 'ahorros'
  form.numero_cuenta = ''
  form.titular = ''
  form.identificacion_titular = ''
  form.activo = true
  pendingQr.value = null
  if (pendingQrPreview.value) URL.revokeObjectURL(pendingQrPreview.value)
  pendingQrPreview.value = null
}

function openCreate(): void {
  editing.value = null
  resetForm()
  dialogVisible.value = true
}

function openEdit(item: CuentaBancaria): void {
  editing.value = item
  form.nombre = item.nombre
  form.banco = item.banco ?? ''
  form.tipo_cuenta = (item.tipo_cuenta === 'corriente' ? 'corriente' : 'ahorros') as CuentaBancariaTipo
  form.numero_cuenta = item.numero_cuenta
  form.titular = item.titular ?? ''
  form.identificacion_titular = item.identificacion_titular ?? ''
  form.activo = item.activo !== false
  pendingQr.value = null
  if (pendingQrPreview.value) URL.revokeObjectURL(pendingQrPreview.value)
  pendingQrPreview.value = null
  dialogVisible.value = true
}

function onSelectQr(file: File): void {
  pendingQr.value = file
  if (pendingQrPreview.value) URL.revokeObjectURL(pendingQrPreview.value)
  pendingQrPreview.value = URL.createObjectURL(file)
}

async function load(): Promise<void> {
  loading.value = true
  try {
    items.value = await cuentasBancariasService.list()
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
  if (!form.nombre.trim() || !form.numero_cuenta.trim()) return
  saving.value = true
  try {
    const payload = {
      nombre: form.nombre.trim(),
      banco: form.banco.trim() || null,
      tipo_cuenta: form.tipo_cuenta,
      numero_cuenta: form.numero_cuenta.trim(),
      titular: form.titular.trim() || null,
      identificacion_titular: form.identificacion_titular.trim() || null,
      activo: form.activo,
    }
    let saved = editing.value
      ? await cuentasBancariasService.update(editing.value.id, payload)
      : await cuentasBancariasService.create(payload)
    if (pendingQr.value) {
      uploadingQr.value = true
      saved = await cuentasBancariasService.uploadQr(saved.id, pendingQr.value)
    }
    await load()
    dialogVisible.value = false
    resetForm()
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.bankAccounts.saved'),
      life: 2200,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    saving.value = false
    uploadingQr.value = false
  }
}

async function toggleActivo(item: CuentaBancaria): Promise<void> {
  try {
    await cuentasBancariasService.update(item.id, {
      nombre: item.nombre,
      banco: item.banco ?? null,
      tipo_cuenta: item.tipo_cuenta,
      numero_cuenta: item.numero_cuenta,
      titular: item.titular ?? null,
      identificacion_titular: item.identificacion_titular ?? null,
      activo: item.activo === false,
    })
    await load()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

async function removeQr(): Promise<void> {
  if (pendingQr.value) {
    pendingQr.value = null
    if (pendingQrPreview.value) URL.revokeObjectURL(pendingQrPreview.value)
    pendingQrPreview.value = null
    return
  }
  if (!editing.value?.qr_url) return
  uploadingQr.value = true
  try {
    editing.value = await cuentasBancariasService.deleteQr(editing.value.id)
    await load()
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.bankAccounts.qrRemoved'),
      life: 2200,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    uploadingQr.value = false
  }
}

async function remove(item: CuentaBancaria): Promise<void> {
  try {
    await cuentasBancariasService.remove(item.id)
    await load()
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.bankAccounts.deleted'),
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
  <section class="bank-embed">
    <header class="bank-embed__header">
      <div>
        <h2>{{ t('settings.bankAccounts.title') }}</h2>
        <p class="pj-muted">{{ t('settings.bankAccounts.subtitle') }}</p>
      </div>
      <Button
        v-if="canUpdate"
        icon="pi pi-plus"
        :label="t('settings.bankAccounts.new')"
        @click="openCreate"
      />
    </header>

    <div class="pj-toolbar">
      <AppSearchField v-model="search" :placeholder="t('common.search')" />
    </div>

    <PageLoader v-if="loading && !items.length" :label="t('common.loading')" />

    <DataTable v-else :value="filteredItems" data-key="id" striped-rows>
      <template #empty>
        <p class="pj-muted">{{ t('settings.bankAccounts.empty') }}</p>
      </template>

      <Column :header="t('settings.bankAccounts.nombre')" field="nombre">
        <template #body="{ data }">
          <div class="acct-name">
            <strong>{{ data.nombre }}</strong>
            <span v-if="data.banco" class="pj-muted">{{ data.banco }}</span>
          </div>
        </template>
      </Column>
      <Column :header="t('settings.bankAccounts.tipo')" style="width: 9rem">
        <template #body="{ data }">
          <Tag :value="tipoLabel(data.tipo_cuenta)" severity="info" />
        </template>
      </Column>
      <Column :header="t('settings.bankAccounts.numero')" field="numero_cuenta" />
      <Column :header="t('settings.bankAccounts.identificacion')" field="identificacion_titular">
        <template #body="{ data }">
          {{ data.identificacion_titular || '—' }}
        </template>
      </Column>
      <Column :header="t('settings.bankAccounts.qr')" style="width: 5rem">
        <template #body="{ data }">
          <img
            v-if="resolveFileUrl(data.qr_url) || data.qr_url"
            class="qr-thumb"
            :src="resolveFileUrl(data.qr_url) || data.qr_url"
            alt=""
          />
          <span v-else class="pj-muted">—</span>
        </template>
      </Column>
      <Column :header="t('settings.bankAccounts.estado')" style="width: 8rem">
        <template #body="{ data }">
          <Tag
            :value="data.activo !== false ? t('common.active') : t('common.inactive')"
            :severity="data.activo !== false ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column v-if="canUpdate" :header="t('common.actions')" style="width: 10rem">
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
                  ? t('settings.bankAccounts.deactivate')
                  : t('settings.bankAccounts.activate')
              "
              @click="toggleActivo(data)"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              :aria-label="t('common.delete')"
              @click="remove(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? t('settings.bankAccounts.edit') : t('settings.bankAccounts.new')"
      :style="{ width: 'min(36rem, 94vw)' }"
    >
      <div class="form-grid">
        <div class="field">
          <label for="acct-nombre">{{ t('settings.bankAccounts.nombre') }}</label>
          <InputText id="acct-nombre" v-model="form.nombre" class="w-full" />
        </div>
        <div class="field">
          <label for="acct-banco">{{ t('settings.bankAccounts.banco') }}</label>
          <InputText id="acct-banco" v-model="form.banco" class="w-full" />
        </div>
        <div class="field">
          <label for="acct-tipo">{{ t('settings.bankAccounts.tipo') }}</label>
          <Select
            input-id="acct-tipo"
            v-model="form.tipo_cuenta"
            :options="tipoOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="field">
          <label for="acct-numero">{{ t('settings.bankAccounts.numero') }}</label>
          <InputText id="acct-numero" v-model="form.numero_cuenta" class="w-full" />
        </div>
        <div class="field">
          <label for="acct-titular">{{ t('settings.bankAccounts.titular') }}</label>
          <InputText id="acct-titular" v-model="form.titular" class="w-full" />
        </div>
        <div class="field">
          <label for="acct-cedula">{{ t('settings.bankAccounts.identificacion') }}</label>
          <InputText id="acct-cedula" v-model="form.identificacion_titular" class="w-full" />
        </div>
        <div class="field field--row">
          <label for="acct-activo">{{ t('common.active') }}</label>
          <ToggleSwitch input-id="acct-activo" v-model="form.activo" />
        </div>
        <div class="field field--wide">
          <MediaProfileUpload
            :src="qrPreview"
            :busy="saving || uploadingQr"
            :show-remove="Boolean(qrPreview)"
            :title="t('settings.bankAccounts.qr')"
            :subtitle="t('settings.bankAccounts.qrHint')"
            @select="onSelectQr"
            @remove="removeQr"
          />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="dialogVisible = false" />
        <Button :label="t('common.save')" :loading="saving || uploadingQr" @click="save" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.bank-embed { display: flex; flex-direction: column; gap: 0.85rem; padding: 0.5rem 0 1rem; }
.bank-embed__header { display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: space-between; align-items: flex-start; }
.bank-embed h2 { margin: 0; font-size: 1.15rem; }
.pj-muted { color: var(--pj-text-muted); font-size: 0.85rem; }
.pj-toolbar { display: flex; }
.acct-name { display: flex; flex-direction: column; gap: 0.15rem; }
.qr-thumb { width: 2.4rem; height: 2.4rem; object-fit: contain; border-radius: 6px; background: #fff; border: 1px solid var(--pj-border); }
.actions { display: flex; gap: 0.1rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-weight: 600; font-size: 0.82rem; }
.field--row { flex-direction: row; align-items: center; justify-content: space-between; }
.field--wide { grid-column: 1 / -1; }
@media (max-width: 700px) {
  .form-grid { grid-template-columns: 1fr; }
}
</style>
