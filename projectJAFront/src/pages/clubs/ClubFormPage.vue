<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import ToggleSwitch from 'primevue/toggleswitch'
import Select from 'primevue/select'
import RadioButton from 'primevue/radiobutton'
import DatePicker from 'primevue/datepicker'
import FileUpload from 'primevue/fileupload'
import type { FileUploadSelectEvent } from 'primevue/fileupload'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import PageLoader from '@/components/PageLoader.vue'
import ClubBoardPanel from '@/components/clubs/ClubBoardPanel.vue'
import ClubMembersPanel from '@/components/clubs/ClubMembersPanel.vue'
import { clubsService } from '@/services/clubsService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type {
  Club,
  ClubDirector,
  ClubMinistry,
  ClubPersona,
} from '@/modules/clubs/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const isEdit = computed(() => route.name === 'clubs.edit')
const clubId = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const iglesiaOptions = ref<
  Array<{
    id: number
    nombre: string
    codigo?: string | null
    tipo_nombre?: string | null
    distrito?: string | null
    ciudad?: string | null
  }>
>([])
const clubPersonas = ref<ClubPersona[]>([])
const directors = ref<ClubDirector[]>([])
const createdAt = ref<string | null>(null)
const pendingLogo = ref<File | null>(null)
const pendingPreview = ref<string | null>(null)
const fundacionDate = ref<Date | null>(null)
/** Tab activo en edición: directiva | integrantes */
const activeTab = ref<'board' | 'members'>('board')

const ministryOptions = computed(() => [
  { label: t('clubs.typeConquistadores'), value: 'conquistadores' as ClubMinistry },
  { label: t('clubs.typeAventureros'), value: 'aventureros' as ClubMinistry },
  { label: t('clubs.typeGuias'), value: 'guias_mayores' as ClubMinistry },
])

const orgSelectOptions = computed(() =>
  iglesiaOptions.value.map((o) => ({
    id: o.id,
    label: o.tipo_nombre ? `${o.nombre} (${o.tipo_nombre})` : o.nombre,
  })),
)

/** Una sola iglesia disponible: se muestra como información (sin selector). */
const iglesiaLocked = computed(() => iglesiaOptions.value.length === 1)

const selectedIglesia = computed(
  () => iglesiaOptions.value.find((o) => o.id === form.organizacion_id) ?? null,
)

const locationDistrito = computed(
  () => selectedIglesia.value?.distrito || form.distrito || '—',
)

const locationCiudad = computed(
  () => selectedIglesia.value?.ciudad || form.ciudad || '—',
)

/** Organización tipo Club del registro (para asociar personas). */
const clubOrganizacionId = ref<number | null>(null)

const form = reactive({
  organizacion_id: null as number | null,
  nombre: '',
  nombre_corto: '',
  lema: '',
  distrito: '',
  ciudad: '',
  descripcion: '',
  color_principal: '#1e3a5f',
  color_secundario: '#c4a35a',
  sitio_web: '',
  tipo: null as ClubMinistry | null,
  is_active: true,
  persona_ids: [] as number[],
  logo_url: null as string | null,
})

const imagePreview = computed(() => pendingPreview.value || form.logo_url)

const primaryDirector = computed(() => {
  return directors.value.find((d) => d.ministry === 'director') || directors.value[0] || null
})

const createdAtLabel = computed(() => {
  if (!createdAt.value) return '—'
  const date = new Date(createdAt.value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' })
})

function toDateString(value: Date | null): string | null {
  if (!value) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
}

function applyClub(club: Club): void {
  form.organizacion_id = club.iglesia_organizacion_id ?? club.organizacion?.padre?.id ?? null
  clubOrganizacionId.value = club.organizacion_id ?? null
  form.nombre = club.nombre
  form.nombre_corto = club.nombre_corto || ''
  form.lema = club.lema || ''
  form.distrito = club.distrito || ''
  form.ciudad = club.ciudad || ''
  form.descripcion = club.descripcion || ''
  form.color_principal = club.color_principal || '#1e3a5f'
  form.color_secundario = club.color_secundario || '#c4a35a'
  form.sitio_web = club.sitio_web || ''
  form.tipo = (club.tipos?.[0] as ClubMinistry) || null
  form.is_active = club.is_active
  form.persona_ids = [...(club.persona_ids || [])]
  form.logo_url = club.logo || club.logo_url
  fundacionDate.value = club.fecha_fundacion ? new Date(`${club.fecha_fundacion}T00:00:00`) : null
  clubPersonas.value = [...(club.personas || [])]
  directors.value = [...(club.directors || [])]
  createdAt.value = club.created_at || null
  syncLocationFromIglesia()
}

function syncLocationFromIglesia(): void {
  const iglesia = selectedIglesia.value
  if (!iglesia) return
  form.distrito = iglesia.distrito || ''
  form.ciudad = iglesia.ciudad || ''
}

function onIglesiaChange(id: number | null): void {
  form.organizacion_id = id
  syncLocationFromIglesia()
}

async function loadOrganizaciones(): Promise<void> {
  iglesiaOptions.value = await clubsService.iglesiaOptions()
  if (iglesiaOptions.value.length === 1) {
    form.organizacion_id = iglesiaOptions.value[0].id
  }
  syncLocationFromIglesia()
}

async function loadClub(): Promise<void> {
  if (!isEdit.value) return
  const club = await clubsService.get(clubId.value)
  applyClub(club)
  ensureCurrentIglesiaInOptions(club)
  syncLocationFromIglesia()
}

function ensureCurrentIglesiaInOptions(club: Club): void {
  const id = club.iglesia_organizacion_id ?? club.organizacion?.padre?.id ?? null
  if (!id || iglesiaOptions.value.some((o) => o.id === id)) return
  iglesiaOptions.value = [
    {
      id,
      nombre: club.organizacion?.padre?.nombre || `Iglesia #${id}`,
      distrito: club.distrito,
      ciudad: club.ciudad,
    },
    ...iglesiaOptions.value,
  ]
}

function onLogoSelect(event: FileUploadSelectEvent): void {
  const file = Array.isArray(event.files) ? event.files[0] : event.files
  if (!file) return
  pendingLogo.value = file as File
  if (pendingPreview.value) URL.revokeObjectURL(pendingPreview.value)
  pendingPreview.value = URL.createObjectURL(file as File)
}

async function refreshClub(): Promise<void> {
  const club = await clubsService.get(clubId.value)
  applyClub(club)
}

async function submit(): Promise<void> {
  if (!form.organizacion_id) {
    errorMessage.value = t('clubs.organizacionRequired')
    return
  }
  if (!form.nombre.trim()) {
    errorMessage.value = t('validation.required')
    return
  }
  if (!form.tipo) {
    errorMessage.value = t('clubs.typesRequired')
    return
  }
  saving.value = true
  errorMessage.value = ''
  try {
    const payload = {
      organizacion_id: form.organizacion_id,
      nombre: form.nombre.trim(),
      nombre_corto: form.nombre_corto.trim() || null,
      lema: form.lema.trim() || null,
      fecha_fundacion: toDateString(fundacionDate.value),
      descripcion: form.descripcion.trim() || null,
      color_principal: form.color_principal || null,
      color_secundario: form.color_secundario || null,
      sitio_web: form.sitio_web.trim() || null,
      distrito: form.distrito.trim() || null,
      ciudad: form.ciudad.trim() || null,
      tipos: [form.tipo],
      is_active: form.is_active,
      persona_ids: [...form.persona_ids],
    }

    let id = clubId.value
    if (isEdit.value) {
      const updated = await clubsService.update(id, payload)
      applyClub(updated)
    } else {
      const created = await clubsService.create(payload)
      id = created.id
      applyClub(created)
    }

    if (pendingLogo.value) {
      const updated = await clubsService.uploadLogo(id, pendingLogo.value)
      form.logo_url = updated.logo || updated.logo_url
      pendingLogo.value = null
      if (pendingPreview.value) {
        URL.revokeObjectURL(pendingPreview.value)
        pendingPreview.value = null
      }
    }

    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: isEdit.value ? t('clubs.updateSuccess') : t('clubs.createSuccess'),
      life: 2500,
    })

    if (!isEdit.value) {
      await router.replace({ name: 'clubs.edit', params: { id } })
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

function onBoardUpdated(club: Club): void {
  applyClub(club)
}

onMounted(async () => {
  loading.value = true
  try {
    await Promise.all([loadOrganizaciones(), loadClub()])
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="pj-page club-edit">
    <header class="club-edit__header">
      <div>
        <p class="breadcrumb">{{ t('nav.clubs') }} › {{ isEdit ? t('clubs.edit') : t('clubs.new') }}</p>
        <h1 class="pj-page__title">{{ isEdit ? t('clubs.edit') : t('clubs.new') }}</h1>
        <p class="pj-page__subtitle">{{ t('clubs.formHint') }}</p>
      </div>
      <div class="header-actions">
        <Button :label="t('common.back')" icon="pi pi-arrow-left" text @click="router.push({ name: 'clubs' })" />
      </div>
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <form v-else class="club-edit__body" @submit.prevent="submit">
      <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

      <section v-if="!isEdit" class="club-card">
        <h2>{{ t('clubs.infoTitle') }}</h2>
        <div class="info-grid">
          <div class="logo-col">
            <div class="logo-preview">
              <img v-if="imagePreview" :src="imagePreview" :alt="form.nombre || t('clubs.logo')" />
              <div v-else class="logo-preview__empty"><i class="pi pi-image" /></div>
            </div>
            <FileUpload
              mode="basic"
              accept="image/*"
              :choose-label="t('clubs.changeLogo')"
              :auto="true"
              custom-upload
              @select="onLogoSelect"
            />
          </div>

          <div class="fields-col">
            <div class="field">
              <label for="organizacion_id">{{ t('clubs.organizacion') }}</label>
              <template v-if="iglesiaLocked && selectedIglesia">
                <p class="info-label">{{ selectedIglesia.nombre }}</p>
                <small class="pj-muted">{{ t('clubs.iglesiaAutoSelected') }}</small>
              </template>
              <Select
                v-else
                id="organizacion_id"
                :model-value="form.organizacion_id"
                :options="orgSelectOptions"
                option-label="label"
                option-value="id"
                filter
                fluid
                :placeholder="t('clubs.organizacionPlaceholder')"
                @update:model-value="onIglesiaChange"
              />
            </div>
            <div class="field">
              <label for="nombre">{{ t('clubs.name') }}</label>
              <InputText id="nombre" v-model="form.nombre" class="w-full" required />
            </div>
            <div class="grid-2">
              <div class="field">
                <label for="nombre_corto">{{ t('clubs.nombreCorto') }}</label>
                <InputText id="nombre_corto" v-model="form.nombre_corto" class="w-full" />
              </div>
              <div class="field">
                <label for="fecha_fundacion">{{ t('clubs.fechaFundacion') }}</label>
                <DatePicker
                  id="fecha_fundacion"
                  v-model="fundacionDate"
                  date-format="yy-mm-dd"
                  show-icon
                  fluid
                />
              </div>
            </div>
            <div class="field">
              <label for="lema">{{ t('clubs.lema') }}</label>
              <InputText id="lema" v-model="form.lema" class="w-full" />
            </div>
            <div class="grid-2">
              <div class="field">
                <label>{{ t('clubs.district') }}</label>
                <p class="info-label">{{ locationDistrito }}</p>
              </div>
              <div class="field">
                <label>{{ t('clubs.city') }}</label>
                <p class="info-label">{{ locationCiudad }}</p>
              </div>
            </div>
            <small class="pj-muted location-hint">{{ t('clubs.locationFromIglesiaHint') }}</small>
            <div class="grid-2">
              <div class="field">
                <label for="color_principal">{{ t('clubs.colorPrincipal') }}</label>
                <InputText id="color_principal" v-model="form.color_principal" class="w-full" />
              </div>
              <div class="field">
                <label for="color_secundario">{{ t('clubs.colorSecundario') }}</label>
                <InputText id="color_secundario" v-model="form.color_secundario" class="w-full" />
              </div>
            </div>
            <div class="field">
              <label for="sitio_web">{{ t('clubs.sitioWeb') }}</label>
              <InputText id="sitio_web" v-model="form.sitio_web" class="w-full" />
            </div>
            <div class="field">
              <label for="descripcion">{{ t('clubs.descripcion') }}</label>
              <InputText id="descripcion" v-model="form.descripcion" class="w-full" />
            </div>
            <div class="field">
              <label>{{ t('clubs.types') }}</label>
              <div class="types-row">
                <label v-for="opt in ministryOptions" :key="opt.value" class="type-radio">
                  <RadioButton v-model="form.tipo" :input-id="`tipo-${opt.value}`" :value="opt.value" />
                  <span>{{ opt.label }}</span>
                </label>
              </div>
              <small class="pj-muted">{{ t('clubs.typesHint') }}</small>
            </div>
          </div>

          <aside class="meta-col">
            <div class="meta-item">
              <span class="meta-label">{{ t('clubs.status') }}</span>
              <div class="status-row">
                <span class="status-dot" :class="{ 'status-dot--on': form.is_active }" />
                <ToggleSwitch v-model="form.is_active" />
                <strong>{{ form.is_active ? t('common.active') : t('common.inactive') }}</strong>
              </div>
            </div>
          </aside>
        </div>
      </section>

      <template v-if="isEdit">
        <div class="club-tabs">
          <Button
            type="button"
            :label="t('clubs.tabBoard')"
            icon="pi pi-id-card"
            :outlined="activeTab !== 'board'"
            size="small"
            @click="activeTab = 'board'"
          />
          <Button
            type="button"
            :label="t('clubs.tabMembers')"
            icon="pi pi-users"
            :outlined="activeTab !== 'members'"
            size="small"
            @click="activeTab = 'members'"
          />
          <Tag
            v-if="clubPersonas.length"
            severity="info"
            :value="String(clubPersonas.length)"
            class="club-tabs__count"
          />
        </div>

        <section
          v-show="activeTab === 'board'"
          v-if="can('clubs.update') || can('clubs.manage_directors')"
          class="club-card club-card--board"
        >
        <ClubBoardPanel :club-id="clubId" @updated="onBoardUpdated">
          <template #info>
            <div class="hero-form">
              <div class="hero-form__logo">
                <div class="logo-preview logo-preview--sm">
                  <img v-if="imagePreview" :src="imagePreview" :alt="form.nombre || t('clubs.logo')" />
                  <div v-else class="logo-preview__empty"><i class="pi pi-image" /></div>
                </div>
                <FileUpload
                  mode="basic"
                  accept="image/*"
                  :choose-label="t('clubs.changeLogo')"
                  :auto="true"
                  custom-upload
                  class="logo-upload-sm"
                  @select="onLogoSelect"
                />
              </div>

              <div class="hero-form__fields">
                <div class="field">
                  <label for="organizacion_id_edit">{{ t('clubs.organizacion') }}</label>
                  <template v-if="iglesiaLocked && selectedIglesia">
                    <p class="info-label">{{ selectedIglesia.nombre }}</p>
                    <small class="pj-muted">{{ t('clubs.iglesiaAutoSelected') }}</small>
                  </template>
                  <Select
                    v-else
                    id="organizacion_id_edit"
                    :model-value="form.organizacion_id"
                    :options="orgSelectOptions"
                    option-label="label"
                    option-value="id"
                    filter
                    fluid
                    :placeholder="t('clubs.organizacionPlaceholder')"
                    @update:model-value="onIglesiaChange"
                  />
                </div>
                <div class="field">
                  <label for="nombre">{{ t('clubs.name') }}</label>
                  <InputText id="nombre" v-model="form.nombre" class="w-full" required />
                </div>
                <div class="hero-form__row">
                  <div class="field">
                    <label>{{ t('clubs.district') }}</label>
                    <p class="info-label">{{ locationDistrito }}</p>
                  </div>
                  <div class="field">
                    <label>{{ t('clubs.city') }}</label>
                    <p class="info-label">{{ locationCiudad }}</p>
                  </div>
                </div>
                <small class="pj-muted location-hint">{{ t('clubs.locationFromIglesiaHint') }}</small>
                <div class="field">
                  <label>{{ t('clubs.types') }}</label>
                  <div class="types-row types-row--compact">
                    <label v-for="opt in ministryOptions" :key="opt.value" class="type-radio">
                      <RadioButton v-model="form.tipo" :input-id="`tipo-edit-${opt.value}`" :value="opt.value" />
                      <span>{{ opt.label }}</span>
                    </label>
                  </div>
                </div>
              </div>

              <aside class="hero-form__meta">
                <div class="meta-item">
                  <span class="meta-label">{{ t('clubs.status') }}</span>
                  <div class="status-row">
                    <span class="status-dot" :class="{ 'status-dot--on': form.is_active }" />
                    <ToggleSwitch v-model="form.is_active" />
                    <strong>{{ form.is_active ? t('common.active') : t('common.inactive') }}</strong>
                  </div>
                </div>
                <div class="meta-item">
                  <span class="meta-label">{{ t('clubs.createdAt') }}</span>
                  <strong>{{ createdAtLabel }}</strong>
                </div>
                <div class="meta-item">
                  <span class="meta-label">{{ t('clubs.directorLabel') }}</span>
                  <strong>{{ primaryDirector?.user?.name || '—' }}</strong>
                </div>
                <div class="meta-item">
                  <span class="meta-label">{{ t('clubs.totalMembers') }}</span>
                  <strong>{{ clubPersonas.length }} personas</strong>
                </div>
              </aside>
            </div>
          </template>
        </ClubBoardPanel>
        </section>

        <ClubMembersPanel
          v-show="activeTab === 'members'"
          v-model:persona-ids="form.persona_ids"
          v-model:personas="clubPersonas"
          :club-id="clubId"
          :club-organizacion-id="clubOrganizacionId"
          :iglesia-organizacion-id="form.organizacion_id"
          @refreshed="refreshClub"
        />
      </template>

      <div class="form-actions">
        <Button type="button" :label="t('common.cancel')" text @click="router.push({ name: 'clubs' })" />
        <Button type="submit" :label="t('common.save')" :loading="saving" />
      </div>
    </form>
  </section>
</template>

<style scoped>
.club-edit__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.breadcrumb {
  margin: 0 0 0.2rem;
  font-size: 0.78rem;
  color: var(--pj-text-muted);
}

.header-actions {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.club-edit__body {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.club-tabs {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}

.club-tabs__count {
  margin-left: 0.15rem;
}

.club-card {
  background: color-mix(in srgb, var(--pj-bg-elevated) 94%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 14px;
  padding: 1rem;
  box-shadow: var(--pj-shadow);
  backdrop-filter: blur(6px);
}

.club-card--board {
  padding: 0.75rem 0.9rem;
}

.club-card h2 {
  margin: 0 0 0.85rem;
  font-size: 1.05rem;
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
  flex-wrap: wrap;
}

.card-head h2 {
  margin: 0 0 0.15rem;
}

.card-head p {
  margin: 0;
  font-size: 0.82rem;
}

.info-grid {
  display: grid;
  grid-template-columns: 8rem minmax(0, 1fr) 12rem;
  gap: 1rem;
}

.hero-form {
  display: grid;
  grid-template-columns: 5.5rem minmax(0, 1fr) 10.5rem;
  gap: 0.65rem;
  align-items: start;
}

.hero-form__logo {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.logo-preview--sm {
  width: 5.5rem;
  aspect-ratio: 1;
  border-radius: 10px;
  background: color-mix(in srgb, #fff 85%, transparent);
}

.hero-form__fields {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  min-width: 0;
}

.hero-form__fields .field {
  gap: 0.15rem;
}

.hero-form__fields label {
  font-size: 0.72rem;
}

.hero-form__row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
}

.hero-form__meta {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.5rem 0.6rem;
  border-radius: 10px;
  background: color-mix(in srgb, #fff 88%, var(--pj-navy) 5%);
  border: 1px solid color-mix(in srgb, var(--pj-border) 50%, transparent);
  backdrop-filter: blur(4px);
}

.hero-form__meta .meta-item {
  gap: 0.1rem;
}

.hero-form__meta strong {
  font-size: 0.82rem;
  line-height: 1.2;
}

.types-row--compact {
  gap: 0.55rem;
  font-size: 0.82rem;
}

.hero-form__fields :deep(.p-inputtext) {
  padding: 0.35rem 0.55rem;
  font-size: 0.88rem;
}

:deep(.logo-upload-sm .p-button) {
  font-size: 0.7rem;
  padding: 0.25rem 0.4rem;
  width: 100%;
}

.logo-col {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  align-items: stretch;
}

.logo-preview {
  width: 100%;
  aspect-ratio: 1;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--pj-navy) 14%, transparent);
  background: color-mix(in srgb, var(--pj-navy) 4%, transparent);
}

.logo-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.logo-preview__empty {
  height: 100%;
  display: grid;
  place-items: center;
  color: color-mix(in srgb, var(--pj-navy) 45%, transparent);
  font-size: 1.5rem;
}

.fields-col,
.meta-col {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.meta-col {
  padding: 0.75rem;
  border-radius: 12px;
  background: color-mix(in srgb, var(--pj-navy) 5%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 50%, transparent);
}

.meta-item {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.meta-label {
  font-size: 0.72rem;
  color: var(--pj-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.info-label {
  margin: 0;
  min-height: 2.4rem;
  display: flex;
  align-items: center;
  padding: 0.55rem 0.75rem;
  border-radius: 0.65rem;
  background: color-mix(in srgb, var(--pj-bg-muted, #f1f5f9) 88%, white);
  border: 1px solid color-mix(in srgb, var(--pj-border, #e2e8f0) 80%, transparent);
  color: var(--pj-text, #0f172a);
  font-weight: 600;
  font-size: 0.92rem;
}

.location-hint {
  display: block;
  margin: -0.15rem 0 0.35rem;
}

.grid-2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.w-full {
  width: 100%;
}

.types-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem;
}

.type-radio {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  cursor: pointer;
}

.status-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.status-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: #94a3b8;
}

.status-dot--on {
  background: var(--pj-success);
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.45rem;
}

@media (max-width: 960px) {
  .info-grid,
  .hero-form {
    grid-template-columns: 1fr;
  }

  .logo-col,
  .hero-form__logo {
    max-width: 10rem;
  }
}

@media (max-width: 640px) {
  .grid-2,
  .create-grid {
    grid-template-columns: 1fr;
  }

  .span-2 {
    grid-column: auto;
  }
}
</style>
