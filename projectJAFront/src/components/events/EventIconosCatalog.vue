<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import AppSearchField from '@/components/AppSearchField.vue'
import IconMark from '@/components/IconMark.vue'
import PageLoader from '@/components/PageLoader.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import { useIconCatalog } from '@/composables/useIconCatalog'
import { iconBoxStyle } from '@/utils/iconVisual'
import { primeIconOptions } from '@/utils/primeIcons'
import type { IconoCatalogCategoria, IconoCatalogo } from '@/modules/events/types'

const props = defineProps<{
  canCreate?: boolean
  canUpdate?: boolean
  canDelete?: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const { items, loading, refresh, matches } = useIconCatalog()

const search = ref('')
const categoriaFilter = ref<string | null>(null)
const dialogVisible = ref(false)
const saving = ref(false)
const editing = ref<IconoCatalogo | null>(null)
const deleteTarget = ref<IconoCatalogo | null>(null)
const deleting = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const categorias: Array<{ value: IconoCatalogCategoria; labelKey: string }> = [
  { value: 'eventos', labelKey: 'events.catalogos.iconCatEventos' },
  { value: 'clubes', labelKey: 'events.catalogos.iconCatClubes' },
  { value: 'deportes', labelKey: 'events.catalogos.iconCatDeportes' },
  { value: 'naturaleza', labelKey: 'events.catalogos.iconCatNaturaleza' },
  { value: 'personas', labelKey: 'events.catalogos.iconCatPersonas' },
  { value: 'tiempo', labelKey: 'events.catalogos.iconCatTiempo' },
  { value: 'comunicacion', labelKey: 'events.catalogos.iconCatComunicacion' },
  { value: 'archivos', labelKey: 'events.catalogos.iconCatArchivos' },
  { value: 'orientacion', labelKey: 'events.catalogos.iconCatOrientacion' },
  { value: 'sistema', labelKey: 'events.catalogos.iconCatSistema' },
  { value: 'personalizado', labelKey: 'events.catalogos.iconCatPersonalizado' },
]

const categoriaOptions = computed(() =>
  categorias.map((c) => ({ label: t(c.labelKey), value: c.value })),
)

const form = reactive({
  nombre: '',
  categoria: 'personalizado' as IconoCatalogCategoria,
  etiquetas: '',
  tipo: 'imagen' as 'prime' | 'imagen',
  valor: 'pi pi-star',
  estado: true,
  archivo: null as File | null,
  preview: '' as string,
})

const filtered = computed(() => {
  return items.value.filter((item) => {
    if (categoriaFilter.value && item.categoria !== categoriaFilter.value) return false
    return matches(item, search.value)
  })
})

const grouped = computed(() => {
  const map = new Map<string, IconoCatalogo[]>()
  for (const item of filtered.value) {
    const key = item.categoria || 'personalizado'
    const list = map.get(key) ?? []
    list.push(item)
    map.set(key, list)
  }
  return categorias
    .map((c) => ({
      key: c.value,
      label: t(c.labelKey),
      items: map.get(c.value) ?? [],
    }))
    .filter((group) => group.items.length)
})

function categoriaLabel(key: string): string {
  return t(
    categorias.find((c) => c.value === key)?.labelKey ?? 'events.catalogos.iconCatPersonalizado',
  )
}

function resetForm(): void {
  form.nombre = ''
  form.categoria = 'personalizado'
  form.etiquetas = ''
  form.tipo = 'imagen'
  form.valor = 'pi pi-star'
  form.estado = true
  form.archivo = null
  form.preview = ''
}

function openCreate(): void {
  editing.value = null
  resetForm()
  dialogVisible.value = true
}

function openEdit(item: IconoCatalogo): void {
  editing.value = item
  form.nombre = item.nombre
  form.categoria = (item.categoria as IconoCatalogCategoria) || 'personalizado'
  form.etiquetas = item.etiquetas.join(', ')
  form.tipo = item.tipo
  form.valor = item.valor
  form.estado = item.estado !== false
  form.archivo = null
  form.preview = item.url || ''
  dialogVisible.value = true
}

function onFile(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  form.archivo = file
  form.tipo = 'imagen'
  form.preview = URL.createObjectURL(file)
  if (!form.nombre.trim()) {
    form.nombre = file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ')
  }
}

function pickPrime(value: string): void {
  form.tipo = 'prime'
  form.valor = value
  form.archivo = null
  form.preview = ''
}

async function save(): Promise<void> {
  if (!form.nombre.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.catalogos.iconName'),
      life: 2800,
    })
    return
  }
  if (form.tipo === 'imagen' && !form.archivo && !editing.value) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.catalogos.iconNeedFile'),
      life: 2800,
    })
    return
  }

  saving.value = true
  try {
    const body = new FormData()
    body.append('nombre', form.nombre.trim())
    body.append('categoria', form.categoria)
    body.append('etiquetas', form.etiquetas)
    body.append('tipo', form.tipo)
    body.append('valor', form.valor)
    body.append('estado', form.estado ? '1' : '0')
    if (form.archivo) body.append('archivo', form.archivo)

    if (editing.value) {
      await eventsService.updateIcono(editing.value.id, body)
    } else {
      await eventsService.createIcono(body)
    }
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.catalogos.iconSaved'),
      life: 2200,
    })
    dialogVisible.value = false
    await refresh(true)
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

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await eventsService.removeIcono(deleteTarget.value.id)
    deleteTarget.value = null
    await refresh(true)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.catalogos.iconDeleted'),
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
    deleting.value = false
  }
}

onMounted(() => {
  void refresh(true)
})

defineExpose({ openCreate })
</script>

<template>
  <div class="icon-cat">
    <p class="pj-muted lead">{{ t('events.catalogos.iconLead') }}</p>
    <div class="icon-cat__toolbar">
      <AppSearchField
        v-model="search"
        class="icon-cat__search"
        :placeholder="t('events.catalogos.iconSearch')"
      />
      <Select
        v-model="categoriaFilter"
        :options="[{ label: t('common.all'), value: null }, ...categoriaOptions]"
        option-label="label"
        option-value="value"
        class="icon-cat__filter"
        :placeholder="t('events.catalogos.iconType')"
        show-clear
      />
      <Button
        v-if="canCreate"
        icon="pi pi-plus"
        :label="t('events.catalogos.iconCreate')"
        @click="openCreate"
      />
    </div>

    <PageLoader v-if="loading && !items.length" :label="t('common.loading')" />
    <p v-else-if="!filtered.length" class="pj-muted">{{ t('events.catalogos.iconEmpty') }}</p>

    <section v-for="group in grouped" :key="group.key" class="icon-group">
      <h3>{{ group.label }} <small>{{ group.items.length }}</small></h3>
      <div class="icon-grid">
        <article v-for="item in group.items" :key="item.id" class="icon-card">
          <span class="icon-card__glyph" :style="iconBoxStyle('#1e3a5f')">
            <IconMark :icono="item.url || item.valor" />
          </span>
          <strong>{{ item.nombre }}</strong>
          <div class="icon-card__tags">
            <span v-for="tag in item.etiquetas.slice(0, 3)" :key="tag">{{ tag }}</span>
          </div>
          <div class="icon-card__actions">
            <Button
              v-if="canUpdate"
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              @click="openEdit(item)"
            />
            <Button
              v-if="canDelete && !item.es_sistema"
              icon="pi pi-trash"
              text
              rounded
              size="small"
              severity="danger"
              @click="deleteTarget = item"
            />
          </div>
        </article>
      </div>
    </section>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing ? t('events.catalogos.iconEdit') : t('events.catalogos.iconCreate')"
      :style="{ width: 'min(34rem, 94vw)' }"
    >
      <div class="icon-form">
        <label class="field">
          <span>{{ t('events.catalogos.iconName') }}</span>
          <InputText v-model="form.nombre" class="w-full" />
        </label>
        <label class="field">
          <span>{{ t('events.catalogos.iconType') }}</span>
          <Select
            v-model="form.categoria"
            :options="categoriaOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </label>
        <label class="field">
          <span>{{ t('events.catalogos.iconTags') }}</span>
          <InputText
            v-model="form.etiquetas"
            class="w-full"
            :placeholder="t('events.catalogos.iconTagsHint')"
          />
        </label>
        <div class="visual-toggle" role="tablist">
          <button type="button" :class="{ 'is-active': form.tipo === 'imagen' }" @click="form.tipo = 'imagen'">
            {{ t('events.catalogos.iconFromFile') }}
          </button>
          <button type="button" :class="{ 'is-active': form.tipo === 'prime' }" @click="form.tipo = 'prime'">
            {{ t('events.catalogos.iconFromPrime') }}
          </button>
        </div>
        <div v-if="form.tipo === 'imagen'" class="upload-box" @click="fileInput?.click()">
          <img v-if="form.preview" :src="form.preview" alt="" />
          <div v-else>
            <i class="pi pi-image" />
            <strong>{{ t('events.catalogos.iconDrop') }}</strong>
            <small>PNG, JPG, WEBP, SVG o GIF</small>
          </div>
          <input
            ref="fileInput"
            type="file"
            accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
            class="sr-only"
            @change="onFile"
          />
        </div>
        <div v-else class="prime-grid">
          <button
            v-for="opt in primeIconOptions"
            :key="opt.value"
            type="button"
            :class="{ 'is-selected': form.valor === opt.value }"
            :title="opt.name"
            @click="pickPrime(opt.value)"
          >
            <i :class="opt.value" />
          </button>
        </div>
        <label class="toggle">
          <ToggleSwitch v-model="form.estado" />
          <span>{{ t('common.active') }}</span>
        </label>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="dialogVisible = false" />
        <Button :label="t('common.save')" :loading="saving" @click="save" />
      </template>
    </Dialog>

    <Dialog
      :visible="!!deleteTarget"
      modal
      :header="t('common.delete')"
      :style="{ width: 'min(24rem, 92vw)' }"
      @update:visible="(open) => { if (!open) deleteTarget = null }"
    >
      <p>{{ t('events.catalogos.iconDeleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button
          :label="t('common.delete')"
          severity="danger"
          :loading="deleting"
          @click="confirmDelete"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.lead {
  margin: 0 0 0.85rem;
}

.icon-cat__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  align-items: center;
  margin-bottom: 1rem;
}

.icon-cat__search {
  flex: 1 1 14rem;
}

.icon-cat__filter {
  min-width: 11rem;
}

.icon-group {
  margin-bottom: 1.25rem;
}

.icon-group h3 {
  margin: 0 0 0.55rem;
  font-size: 0.95rem;
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
}

.icon-group small {
  color: var(--pj-text-muted);
  font-weight: 500;
}

.icon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(8.4rem, 1fr));
  gap: 0.55rem;
}

.icon-card {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.65rem 0.55rem 0.45rem;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.3rem;
  text-align: center;
}

.icon-card__glyph {
  width: 2.6rem;
  height: 2.6rem;
  display: grid;
  place-items: center;
  border-radius: 10px;
  font-size: 1.2rem;
}

.icon-card strong {
  font-size: 0.78rem;
  line-height: 1.25;
}

.icon-card__tags {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.2rem;
}

.icon-card__tags span {
  font-size: 0.62rem;
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--pj-navy) 8%, #fff);
  color: var(--pj-text-muted);
}

.icon-card__actions {
  display: flex;
}

.icon-form {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.82rem;
}

.w-full {
  width: 100%;
}

.visual-toggle {
  display: flex;
  padding: 0.15rem;
  border-radius: 9px;
  background: color-mix(in srgb, var(--pj-navy) 6%, #fff);
}

.visual-toggle button {
  flex: 1;
  border: 0;
  background: transparent;
  border-radius: 7px;
  padding: 0.35rem;
  font: inherit;
  font-size: 0.78rem;
  font-weight: 650;
  cursor: pointer;
  color: var(--pj-text-muted);
}

.visual-toggle button.is-active {
  background: #fff;
  color: var(--pj-navy);
}

.upload-box {
  border: 1.5px dashed color-mix(in srgb, var(--pj-border) 85%, transparent);
  border-radius: 12px;
  min-height: 7rem;
  display: grid;
  place-items: center;
  text-align: center;
  cursor: pointer;
  overflow: hidden;
}

.upload-box img {
  width: 100%;
  height: 7rem;
  object-fit: contain;
  background: #f8fafc;
}

.upload-box i {
  font-size: 1.3rem;
  color: #2563eb;
}

.prime-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(2.1rem, 1fr));
  gap: 0.25rem;
  max-height: 12rem;
  overflow: auto;
  padding: 0.35rem;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 10px;
}

.prime-grid button {
  width: 2.1rem;
  height: 2.1rem;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
}

.prime-grid button.is-selected,
.prime-grid button:hover {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.86rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}

@media (max-width: 640px) {
  .icon-cat__toolbar {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
