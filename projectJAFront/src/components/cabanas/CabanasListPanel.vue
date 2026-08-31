<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import AppSearchField from '@/components/AppSearchField.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import PageLoader from '@/components/PageLoader.vue'
import { MediaCoverUpload } from '@/components/media'
import { cabanasService } from '@/services/cabanasService'
import { lugaresService } from '@/services/lugaresService'
import type { Lugar } from '@/modules/lugares/types'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import type { Cabana, CabanaEstado } from '@/modules/cabanas/types'
import type { PaginationMeta } from '@/types/api'

const props = withDefaults(
  defineProps<{
    lugarId?: number | null
    embedded?: boolean
  }>(),
  { lugarId: null, embedded: false },
)

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { canCatalog } = usePermission()
const items = ref<Cabana[]>([])
const pagination = ref<PaginationMeta | null>(null)
const loading = ref(true)
const saving = ref(false)
const deleting = ref(false)
const drawerVisible = ref(false)
const editTarget = ref<Cabana | null>(null)
const deleteTarget = ref<Cabana | null>(null)
const pendingImage = ref<File | null>(null)
const pendingPreview = ref<string | null>(null)
const filters = reactive({ search: '', page: 1, per_page: 10 })
const form = reactive({
  lugar_id: null as number | null,
  nombre: '',
  descripcion: '',
  image_url: '' as string | null,
  estado: 'activa' as CabanaEstado,
})
const lugares = ref<Lugar[]>([])
const scoped = computed(() => props.lugarId != null)
const lugarOptions = computed(() => lugares.value.map((item) => ({ label: item.nombre, value: item.id })))
const coverSrc = computed(() => pendingPreview.value || form.image_url || null)
const previewName = computed(() => form.nombre.trim() || t('cabanas.create'))
const deleteVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
  },
})

function revokePreview(): void {
  if (pendingPreview.value) URL.revokeObjectURL(pendingPreview.value)
  pendingPreview.value = null
}

function resetForm(): void {
  revokePreview()
  pendingImage.value = null
  Object.assign(form, {
    lugar_id: props.lugarId ?? null,
    nombre: '',
    descripcion: '',
    image_url: null,
    estado: 'activa',
  })
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await cabanasService.list({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search,
      lugar_id: props.lugarId ?? undefined,
    })
    items.value = result.items
    pagination.value = result.pagination
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editTarget.value = null
  resetForm()
  drawerVisible.value = true
}

if (!props.embedded) {
  usePageChrome(() => ({
    title: t('cabanas.title'),
    subtitle: t('cabanas.subtitle'),
    actions: canCatalog('cabanas', 'create')
      ? [
          {
            key: 'new',
            label: t('cabanas.create'),
            icon: 'pi pi-plus',
            onClick: openCreate,
          },
        ]
      : [],
  }))
}

function openEdit(item: Cabana): void {
  editTarget.value = item
  revokePreview()
  pendingImage.value = null
  Object.assign(form, {
    lugar_id: props.lugarId ?? item.lugar_id ?? item.lugar?.id ?? null,
    nombre: item.nombre,
    descripcion: item.descripcion ?? '',
    image_url: item.image_url ?? null,
    estado: item.estado,
  })
  drawerVisible.value = true
}

function onPickImage(file: File): void {
  pendingImage.value = file
  revokePreview()
  pendingPreview.value = URL.createObjectURL(file)
}

async function uploadPendingImage(id: number): Promise<void> {
  if (!pendingImage.value) return
  const updated = await cabanasService.uploadImage(id, pendingImage.value)
  form.image_url = updated.image_url ?? null
  pendingImage.value = null
  revokePreview()
}

async function save(): Promise<void> {
  const lugarId = props.lugarId ?? form.lugar_id
  if (!form.nombre.trim() || !lugarId) return
  saving.value = true
  try {
    const payload = {
      lugar_id: lugarId,
      nombre: form.nombre.trim(),
      descripcion: form.descripcion.trim() || null,
      estado: form.estado,
    }
    const saved = editTarget.value
      ? await cabanasService.update(editTarget.value.id, payload)
      : await cabanasService.create(payload)
    if (pendingImage.value) await uploadPendingImage(saved.id)
    drawerVisible.value = false
    resetForm()
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.saveSuccess'), life: 2500 })
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await cabanasService.remove(deleteTarget.value.id)
    deleteTarget.value = null
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.deleteSuccess'), life: 2500 })
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    deleting.value = false
  }
}

function onPage(event: { page: number; rows: number }): void {
  filters.page = event.page + 1
  filters.per_page = event.rows
  void load()
}

function goToLayout(id: number): void {
  drawerVisible.value = false
  void router.push({ name: 'cabanas.layout', params: { id } })
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.page = 1
    void load()
  }, 300)
})

watch(
  () => props.lugarId,
  () => {
    filters.page = 1
    void load()
  },
)

onMounted(() => {
  void load()
  if (!scoped.value) {
    void lugaresService
      .list({ per_page: 200, estado: 'activo' })
      .then((result) => {
        lugares.value = result.items
      })
      .catch(() => {
        lugares.value = []
      })
  }
})
onBeforeUnmount(() => {
  clearTimeout(searchTimer)
  revokePreview()
})
</script>

<template>
  <section class="pj-page cabanas-panel" :class="{ 'is-embedded': embedded }">
    <header v-if="!embedded" class="pj-page__header">
      <div>
        <h1 class="pj-display">{{ t('cabanas.title') }}</h1>
        <p>{{ t('cabanas.subtitle') }}</p>
      </div>
      <Button v-if="canCatalog('cabanas', 'create')" :label="t('cabanas.create')" icon="pi pi-plus" @click="openCreate" />
    </header>

    <div class="pj-toolbar">
      <AppSearchField v-model="filters.search" :placeholder="t('cabanas.search')" />
      <Button
        v-if="embedded && canCatalog('cabanas', 'create')"
        :label="t('cabanas.create')"
        icon="pi pi-plus"
        @click="openCreate"
      />
    </div>

    <PageLoader v-if="loading && !items.length" />
    <DataTable
      v-else
      :value="items"
      lazy
      paginator
      :rows="filters.per_page"
      :total-records="pagination?.total ?? items.length"
      :first="(filters.page - 1) * filters.per_page"
      data-key="id"
      @page="onPage"
    >
      <Column :header="t('cabanas.formSectionPhoto')" style="width: 5.2rem">
        <template #body="{ data }">
          <span class="thumb" :class="{ 'has-photo': !!resolveFileUrl(data.image_url) }">
            <img v-if="resolveFileUrl(data.image_url)" :src="resolveFileUrl(data.image_url)!" :alt="data.nombre" />
            <i v-else class="pi pi-home" />
          </span>
        </template>
      </Column>
      <Column field="nombre" :header="t('cabanas.name')">
        <template #body="{ data }">
          <strong>{{ data.nombre }}</strong>
          <small class="description">{{ data.descripcion || '—' }}</small>
        </template>
      </Column>
      <Column v-if="!scoped" :header="t('cabanas.lugar')">
        <template #body="{ data }">{{ data.lugar?.nombre || '—' }}</template>
      </Column>
      <Column :header="t('cabanas.floors')">
        <template #body="{ data }">{{ data.pisos_count ?? data.pisos?.length ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.rooms')">
        <template #body="{ data }">{{ data.cuartos_count ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.capacity')">
        <template #body="{ data }">{{ data.capacidad_total ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.status')">
        <template #body="{ data }">
          <Tag :value="data.estado" :severity="data.estado === 'activa' ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              v-if="canCatalog('cabanas', 'view')"
              icon="pi pi-map"
              text
              rounded
              :aria-label="t('cabanas.openLayout')"
              @click="router.push({ name: 'cabanas.layout', params: { id: data.id } })"
            />
            <Button v-if="canCatalog('cabanas', 'update')" icon="pi pi-pencil" text rounded @click="openEdit(data)" />
            <Button
              v-if="canCatalog('cabanas', 'delete')"
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              @click="deleteTarget = data"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <AppStackDrawer
      v-model:visible="drawerVisible"
      :title="editTarget ? t('cabanas.edit') : t('cabanas.create')"
      :subtitle="t('cabanas.formHint')"
      :level="1"
    >
      <div class="cabana-form">
        <aside class="preview-card">
          <span class="preview-card__photo" :class="{ 'has-photo': !!coverSrc }">
            <img v-if="coverSrc" :src="coverSrc" alt="" />
            <i v-else class="pi pi-home" />
          </span>
          <div>
            <em>{{ editTarget ? t('cabanas.edit') : t('cabanas.create') }}</em>
            <strong>{{ previewName }}</strong>
            <small>{{ form.descripcion.trim() || t('cabanas.formPhotoHint') }}</small>
          </div>
          <Tag
            :value="form.estado === 'activa' ? t('cabanas.active') : t('cabanas.inactive')"
            :severity="form.estado === 'activa' ? 'success' : 'secondary'"
          />
        </aside>

        <div class="form-grid">
          <section class="panel">
            <header>
              <i class="pi pi-image" />
              <div>
                <strong>{{ t('cabanas.formSectionPhoto') }}</strong>
                <p>{{ t('cabanas.formPhotoHint') }}</p>
              </div>
            </header>
            <MediaCoverUpload
              :src="coverSrc"
              :title="t('cabanas.formSectionPhoto')"
              :subtitle="t('media.cabanaCoverSubtitle')"
              :busy="saving"
              @select="onPickImage"
            />
          </section>

          <div class="form-stack">
            <section class="panel">
              <header>
                <i class="pi pi-home" />
                <div>
                  <strong>{{ t('cabanas.formSectionInfo') }}</strong>
                  <p>{{ t('cabanas.formHint') }}</p>
                </div>
              </header>
              <label v-if="!scoped">
                {{ t('cabanas.lugar') }} *
                <Select
                  v-model="form.lugar_id"
                  :options="lugarOptions"
                  option-label="label"
                  option-value="value"
                  filter
                  class="w-full"
                  :placeholder="t('cabanas.lugar')"
                />
              </label>
              <label>
                {{ t('cabanas.name') }} *
                <InputText
                  v-model="form.nombre"
                  autofocus
                  :placeholder="t('cabanas.formNamePlaceholder')"
                />
              </label>
              <label>
                {{ t('cabanas.description') }}
                <Textarea
                  v-model="form.descripcion"
                  rows="5"
                  :placeholder="t('cabanas.formDescriptionPlaceholder')"
                />
              </label>
            </section>

            <section class="panel">
              <header>
                <i class="pi pi-flag" />
                <div>
                  <strong>{{ t('cabanas.formSectionStatus') }}</strong>
                </div>
              </header>
              <div class="status-cards">
                <button
                  type="button"
                  class="status-card"
                  :class="{ active: form.estado === 'activa' }"
                  @click="form.estado = 'activa'"
                >
                  <i class="pi pi-check-circle" />
                  <span>
                    <strong>{{ t('cabanas.active') }}</strong>
                    <small>{{ t('cabanas.formStatusActiveHint') }}</small>
                  </span>
                </button>
                <button
                  type="button"
                  class="status-card"
                  :class="{ active: form.estado === 'inactiva' }"
                  @click="form.estado = 'inactiva'"
                >
                  <i class="pi pi-ban" />
                  <span>
                    <strong>{{ t('cabanas.inactive') }}</strong>
                    <small>{{ t('cabanas.formStatusInactiveHint') }}</small>
                  </span>
                </button>
              </div>
            </section>

            <section v-if="editTarget" class="panel stats-panel">
              <div class="stat">
                <strong>{{ editTarget.pisos_count ?? editTarget.pisos?.length ?? 0 }}</strong>
                <small>{{ t('cabanas.floors') }}</small>
              </div>
              <div class="stat">
                <strong>{{ editTarget.cuartos_count ?? 0 }}</strong>
                <small>{{ t('cabanas.rooms') }}</small>
              </div>
              <div class="stat">
                <strong>{{ editTarget.capacidad_total ?? 0 }}</strong>
                <small>{{ t('cabanas.capacity') }}</small>
              </div>
              <Button
                v-if="canCatalog('cabanas', 'view')"
                :label="t('cabanas.formOpenLayout')"
                icon="pi pi-map"
                outlined
                @click="goToLayout(editTarget.id)"
              />
            </section>

            <section v-else class="next-card">
              <i class="pi pi-map" />
              <div>
                <strong>{{ t('cabanas.formNextTitle') }}</strong>
                <p>{{ t('cabanas.formNextHint') }}</p>
              </div>
            </section>
          </div>
        </div>
      </div>

      <template #footer>
        <Button :label="t('common.cancel')" text @click="drawerVisible = false" />
        <Button :label="t('common.save')" icon="pi pi-save" :loading="saving" :disabled="!form.nombre.trim() || !(lugarId || form.lugar_id)" @click="save" />
      </template>
    </AppStackDrawer>

    <Dialog v-model:visible="deleteVisible" modal :header="t('common.confirm')" :style="{ width: 'min(420px, 95vw)' }">
      <p>{{ t('cabanas.deleteConfirm', { name: deleteTarget?.nombre }) }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="remove" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.cabanas-panel.is-embedded { padding: 0; }
.cabanas-panel.is-embedded .pj-toolbar { margin-bottom: 0.75rem; }
.description { display: block; max-width: 30rem; margin-top: .2rem; color: var(--pj-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.row-actions { display: flex; gap: .2rem; }
.thumb {
  display: grid;
  place-items: center;
  width: 3.4rem;
  height: 2.4rem;
  overflow: hidden;
  border-radius: 8px;
  background: color-mix(in srgb, var(--p-primary-color) 12%, white);
  color: var(--p-primary-color);
}
.thumb img { width: 100%; height: 100%; object-fit: cover; }
.thumb.has-photo { background: #e2e8f0; }

.cabana-form { display: grid; gap: 1.1rem; }
.preview-card {
  display: grid;
  grid-template-columns: 5.6rem 1fr auto;
  gap: 1rem;
  align-items: center;
  padding: 1rem 1.1rem;
  border-radius: 16px;
  background:
    linear-gradient(135deg, color-mix(in srgb, var(--p-primary-color) 16%, white), #fff 62%),
    var(--pj-bg-elevated, #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border, #e2e8f0) 80%, transparent);
}
.preview-card__photo {
  display: grid;
  place-items: center;
  width: 5.6rem;
  height: 4rem;
  overflow: hidden;
  border-radius: 12px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 1.3rem;
}
.preview-card__photo img { width: 100%; height: 100%; object-fit: cover; }
.preview-card em, .preview-card strong, .preview-card small { display: block; }
.preview-card em { font-style: normal; font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--pj-text-muted); }
.preview-card strong { margin-top: .15rem; font-size: 1.15rem; }
.preview-card small { margin-top: .28rem; color: var(--pj-text-muted); line-height: 1.35; }

.form-grid {
  display: grid;
  grid-template-columns: minmax(18rem, 0.95fr) minmax(22rem, 1.15fr);
  gap: 1rem;
  align-items: start;
}
.form-stack { display: grid; gap: 1rem; }
.panel, .next-card {
  display: grid;
  gap: .85rem;
  padding: 1rem 1.05rem;
  border: 1px solid var(--pj-border, #e2e8f0);
  border-radius: 16px;
  background: var(--pj-bg-elevated, #fff);
}
.panel > header { display: flex; gap: .7rem; align-items: flex-start; }
.panel > header i {
  display: grid;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 10px;
  background: color-mix(in srgb, var(--p-primary-color) 12%, white);
  color: var(--p-primary-color);
}
.panel > header p, .next-card p { margin: .2rem 0 0; color: var(--pj-text-muted); font-size: .84rem; line-height: 1.4; }
.panel label { display: grid; gap: .35rem; font-size: .86rem; font-weight: 600; }
.panel :deep(.p-inputtext), .panel :deep(.p-textarea) { width: 100%; }

.status-cards { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; }
.status-card {
  display: flex;
  gap: .65rem;
  align-items: flex-start;
  padding: .8rem .85rem;
  border: 1px solid var(--pj-border, #e2e8f0);
  border-radius: 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
}
.status-card i { margin-top: .1rem; color: var(--pj-text-muted); }
.status-card strong, .status-card small { display: block; }
.status-card small { margin-top: .2rem; color: var(--pj-text-muted); font-size: .75rem; line-height: 1.35; }
.status-card.active {
  border-color: var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 8%, white);
}
.status-card.active i { color: var(--p-primary-color); }

.stats-panel {
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  align-items: center;
  gap: .75rem;
}
.stat { display: grid; gap: .15rem; }
.stat strong { font-size: 1.25rem; }
.stat small { color: var(--pj-text-muted); font-size: .78rem; }

.next-card {
  grid-template-columns: auto 1fr;
  align-items: start;
  background: color-mix(in srgb, var(--p-primary-color) 7%, white);
}
.next-card i {
  display: grid;
  place-items: center;
  width: 2.3rem;
  height: 2.3rem;
  border-radius: 10px;
  background: var(--p-primary-color);
  color: #fff;
}

@media (max-width: 920px) {
  .form-grid, .stats-panel, .status-cards { grid-template-columns: 1fr; }
  .preview-card { grid-template-columns: 4.4rem 1fr; }
}
</style>
