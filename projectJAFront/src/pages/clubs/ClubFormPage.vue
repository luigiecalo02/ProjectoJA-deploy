<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import ToggleSwitch from 'primevue/toggleswitch'
import Select from 'primevue/select'
import RadioButton from 'primevue/radiobutton'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import ColorAlphaPicker from '@/components/terrenos/ColorAlphaPicker.vue'
import MediaProfileUpload from '@/components/media/MediaProfileUpload.vue'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import PageLoader from '@/components/PageLoader.vue'
import ClubBoardPanel from '@/components/clubs/ClubBoardPanel.vue'
import ClubMembersPanel from '@/components/clubs/ClubMembersPanel.vue'
import { clubsService } from '@/services/clubsService'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import { useAuthStore } from '@/stores/auth'
import { clubPageScope } from '@/modules/clubs/pageScope'
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
const auth = useAuthStore()
const scope = computed(() => clubPageScope(route.name))
const resolvedClubId = ref<number | null>(null)

const isSessionClub = computed(() => scope.value.isSessionClub && Boolean(auth.contexto?.is_club))
const isEdit = computed(
  () => route.name === 'clubs.edit' || route.name === 'mi-club.edit' || isSessionClub.value,
)
const clubId = computed(() => {
  if (resolvedClubId.value) return resolvedClubId.value
  if (isSessionClub.value && auth.contexto?.club_id) return Number(auth.contexto.club_id)
  return Number(route.params.id) || 0
})
const canEditBoard = computed(
  () =>
    can(scope.value.updatePerm) ||
    can(scope.value.directorsPerm) ||
    can('clubs.update') ||
    can('clubs.manage_directors'),
)
const pageTitle = computed(() => {
  if (isSessionClub.value) return t('miClub.title')
  return isEdit.value ? t('clubs.edit') : t('clubs.new')
})
const pageSubtitle = computed(() =>
  isSessionClub.value ? t('miClub.subtitle') : t('clubs.formHint'),
)
const lockIdentity = computed(() => scope.value.isMiClub && isEdit.value)
const iglesiaReadOnly = computed(() => lockIdentity.value || iglesiaLocked.value)
const selectedTipoLabel = computed(
  () => ministryOptions.value.find((opt) => opt.value === form.tipo)?.label || '—',
)

function goList(): void {
  if (isSessionClub.value) return
  void router.push({ name: scope.value.listRoute })
}

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const iglesiaOptions = ref<
  Array<{
    id: number
    nombre: string
    codigo?: string | null
    tipo_nombre?: string | null
    zona?: string | null
    distrito?: string | null
    ciudad?: string | null
  }>
>([])
const clubZona = ref<string | null>(null)
const clubPersonas = ref<ClubPersona[]>([])
const directors = ref<ClubDirector[]>([])
const createdAt = ref<string | null>(null)
const pendingLogo = ref<File | null>(null)
const pendingPreview = ref<string | null>(null)
const uploadingLogo = ref(false)
const fundacionDate = ref<Date | null>(null)
/** Tab activo en edición: información | directiva | integrantes */
const activeTab = ref<'info' | 'board' | 'members'>('info')

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

const locationZona = computed(
  () => selectedIglesia.value?.zona || clubZona.value || '—',
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

const imagePreview = computed(() => pendingPreview.value || resolveFileUrl(form.logo_url))

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
  clubZona.value = club.zona || null
  form.descripcion = club.descripcion || ''
  form.color_principal = club.color_principal || '#1e3a5f'
  form.color_secundario = club.color_secundario || '#c4a35a'
  form.sitio_web = club.sitio_web || ''
  form.tipo = (club.tipos?.[0] as ClubMinistry) || null
  form.is_active = club.is_active
  form.persona_ids = [...(club.persona_ids || [])]
  if (!pendingLogo.value) {
    form.logo_url = resolveFileUrl(club.logo || club.logo_url)
  }
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
  if (isSessionClub.value) {
    const club = auth.contexto?.club_id
      ? await clubsService.get(auth.contexto.club_id)
      : await clubsService.current()
    resolvedClubId.value = club.id
    applyClub(club)
    ensureCurrentIglesiaInOptions(club)
    syncLocationFromIglesia()
    return
  }
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
      zona: club.zona,
      distrito: club.distrito,
      ciudad: club.ciudad,
    },
    ...iglesiaOptions.value,
  ]
}

function persistSessionClubLogo(url: string | null): void {
  if (!auth.user?.contexto?.is_club || auth.contexto?.club_id !== clubId.value) return
  auth.persistUser({
    ...auth.user,
    contexto: { ...auth.user.contexto, club_logo_url: url },
  })
}

function clearPendingLogo(): void {
  pendingLogo.value = null
  if (pendingPreview.value) {
    URL.revokeObjectURL(pendingPreview.value)
    pendingPreview.value = null
  }
}

async function persistLogo(id: number, file: File): Promise<string | null> {
  const updated = await clubsService.uploadLogo(id, file)
  const url = resolveFileUrl(updated.logo || updated.logo_url)
  form.logo_url = url
  pendingLogo.value = null
  persistSessionClubLogo(url)
  return url
}

async function onLogoSelect(file: File): Promise<void> {
  if (!file) return
  pendingLogo.value = file
  if (pendingPreview.value) URL.revokeObjectURL(pendingPreview.value)
  pendingPreview.value = URL.createObjectURL(file)

  if (!isEdit.value || !clubId.value) return

  uploadingLogo.value = true
  try {
    await persistLogo(clubId.value, file)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('clubs.logoSuccess'),
      life: 2500,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    uploadingLogo.value = false
  }
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
      await persistLogo(id, pendingLogo.value)
    }

    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: isEdit.value ? t('clubs.updateSuccess') : t('clubs.createSuccess'),
      life: 2500,
    })

    if (!isEdit.value) {
      await router.replace({ name: scope.value.editRoute, params: { id } })
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

usePageChrome(() => ({
  title: pageTitle.value,
  subtitle: pageSubtitle.value,
  backTo: isSessionClub.value ? null : { name: scope.value.listRoute },
  actions: [
    {
      key: 'save',
      label: t('common.save'),
      icon: 'pi pi-save',
      loading: saving.value,
      onClick: () => void submit(),
    },
  ],
}))

function onBoardUpdated(club: Club): void {
  applyClub(club)
}

onMounted(async () => {
  loading.value = true
  try {
    await Promise.all([loadOrganizaciones(), loadClub()])
  } catch (error) {
    errorMessage.value = isSessionClub.value ? t('miClub.missing') : getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  clearPendingLogo()
})
</script>

<template>
  <section class="pj-page club-edit">
    <header class="club-edit__header">
      <div>
        <p class="breadcrumb">
          {{ isSessionClub ? t('nav.miClub') : t('nav.clubs') }} › {{ pageTitle }}
        </p>
        <h1 class="pj-page__title">{{ pageTitle }}</h1>
        <p class="pj-page__subtitle">{{ pageSubtitle }}</p>
      </div>
      <div v-if="!isSessionClub" class="header-actions">
        <Button :label="t('common.back')" icon="pi pi-arrow-left" text @click="goList" />
      </div>
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <form v-else class="club-edit__body" @submit.prevent="submit">
      <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

      <Tabs v-if="isEdit" v-model:value="activeTab" class="club-tabs">
        <TabList>
          <Tab value="info">
            <i class="pi pi-building" />
            <span>{{ t('clubs.tabInfo') }}</span>
          </Tab>
          <Tab value="board">
            <i class="pi pi-id-card" />
            <span>{{ t('clubs.tabBoard') }}</span>
          </Tab>
          <Tab value="members">
            <i class="pi pi-users" />
            <span>{{ t('clubs.tabMembers') }}</span>
            <Tag
              v-if="clubPersonas.length"
              severity="info"
              :value="String(clubPersonas.length)"
              class="club-tabs__count"
            />
          </Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="info" />
          <TabPanel value="board" />
          <TabPanel value="members" />
        </TabPanels>
      </Tabs>

      <section v-show="!isEdit || activeTab === 'info'" class="club-card">
        <h2>{{ t('clubs.infoTitle') }}</h2>
        <div class="info-grid">
          <div class="logo-col">
            <MediaProfileUpload
              compact
              dense
              :src="imagePreview"
              :busy="uploadingLogo || saving"
              :title="t('clubs.logo')"
              :subtitle="t('media.clubProfileSubtitle')"
              @select="onLogoSelect"
            />
          </div>

          <div class="fields-col">
            <div class="field">
              <label for="organizacion_id">{{ t('clubs.organizacion') }}</label>
              <template v-if="iglesiaReadOnly && selectedIglesia">
                <p class="info-label">{{ selectedIglesia.nombre }}</p>
                <small class="pj-muted">{{
                  lockIdentity ? t('miClub.iglesiaLocked') : t('clubs.iglesiaAutoSelected')
                }}</small>
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
            <div class="grid-3">
              <div class="field">
                <label>{{ t('clubs.zone') }}</label>
                <p class="info-label">{{ locationZona }}</p>
              </div>
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
                <ColorAlphaPicker id="color_principal" v-model="form.color_principal" default-hex="#1e3a5f" />
              </div>
              <div class="field">
                <label for="color_secundario">{{ t('clubs.colorSecundario') }}</label>
                <ColorAlphaPicker id="color_secundario" v-model="form.color_secundario" default-hex="#c4a35a" />
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
              <template v-if="lockIdentity">
                <p class="info-label">{{ selectedTipoLabel }}</p>
                <small class="pj-muted">{{ t('miClub.tipoLocked') }}</small>
              </template>
              <template v-else>
                <div class="types-row">
                  <label v-for="opt in ministryOptions" :key="opt.value" class="type-radio">
                    <RadioButton v-model="form.tipo" :input-id="`tipo-${opt.value}`" :value="opt.value" />
                    <span>{{ opt.label }}</span>
                  </label>
                </div>
                <small class="pj-muted">{{ t('clubs.typesHint') }}</small>
              </template>
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
            <div v-if="isEdit" class="meta-item">
              <span class="meta-label">{{ t('clubs.createdAt') }}</span>
              <strong>{{ createdAtLabel }}</strong>
            </div>
            <div v-if="isEdit" class="meta-item">
              <span class="meta-label">{{ t('clubs.directorLabel') }}</span>
              <strong>{{ primaryDirector?.user?.name || '—' }}</strong>
            </div>
            <div v-if="isEdit" class="meta-item">
              <span class="meta-label">{{ t('clubs.totalMembers') }}</span>
              <strong>{{ clubPersonas.length }} personas</strong>
            </div>
          </aside>
        </div>
      </section>

      <section v-if="isEdit && activeTab === 'board' && canEditBoard" class="club-card club-card--board">
        <ClubBoardPanel
          hide-hero
          :club-id="clubId"
          :lock-director="lockIdentity"
          @updated="onBoardUpdated"
        />
      </section>

      <div v-show="isEdit && activeTab === 'members'">
        <ClubMembersPanel
          v-model:persona-ids="form.persona_ids"
          v-model:personas="clubPersonas"
          :club-id="clubId"
          :club-organizacion-id="clubOrganizacionId"
          :iglesia-organizacion-id="form.organizacion_id"
          @refreshed="refreshClub"
        />
      </div>

      <div v-if="!isEdit || activeTab === 'info' || activeTab === 'members'" class="form-actions">
        <Button v-if="!isSessionClub" type="button" :label="t('common.cancel')" text @click="goList" />
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
  background: transparent;
  border: none;
  overflow: visible;
}

.club-tabs :deep(.p-tablist-tab-list) {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  padding: 0.28rem;
  background: var(--pj-bg-elevated);
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  box-shadow: var(--pj-shadow);
}

.club-tabs :deep(.p-tablist-active-bar) {
  display: none;
}

.club-tabs :deep(.p-tab) {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  padding: 0.58rem 0.95rem;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: var(--pj-text-muted);
  font-family: var(--pj-font-sans);
  font-size: 0.88rem;
  font-weight: 650;
  letter-spacing: 0.01em;
  transition: background 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
}

.club-tabs :deep(.p-tab i) {
  font-size: 0.92rem;
  color: inherit;
}

.club-tabs :deep(.p-tab:not(.p-tab-active):not([data-p-active='true']):hover) {
  background: var(--pj-primary-soft);
  color: var(--pj-navy);
}

.club-tabs :deep(.p-tab.p-tab-active),
.club-tabs :deep(.p-tab[data-p-active='true']) {
  background: var(--pj-navy);
  color: #fff;
  box-shadow: inset 0 -2px 0 var(--pj-gold);
}

.club-tabs :deep(.p-tab:focus-visible) {
  outline: 2px solid var(--pj-gold);
  outline-offset: 1px;
}

.club-tabs :deep(.p-tabpanels) {
  display: none;
}

.club-tabs__count {
  margin-left: 0.05rem;
  min-width: 1.35rem;
  justify-content: center;
  background: color-mix(in srgb, var(--pj-sky) 18%, transparent) !important;
  color: var(--pj-navy) !important;
  border: 0 !important;
}

.club-tabs :deep(.p-tab.p-tab-active) .club-tabs__count,
.club-tabs :deep(.p-tab[data-p-active='true']) .club-tabs__count {
  background: color-mix(in srgb, var(--pj-gold) 88%, #fff) !important;
  color: var(--pj-navy-dark) !important;
}

html:not(.dark) .club-tabs :deep(.p-tab:not(.p-tab-active):not([data-p-active='true'])) {
  color: #5b6b82;
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
  font-family: var(--pj-font-sans);
  font-size: 1.05rem;
  font-weight: 700;
  letter-spacing: 0;
  color: var(--pj-text);
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
  font-family: var(--pj-font-sans);
  font-weight: 700;
  letter-spacing: 0;
  color: var(--pj-text);
}

.card-head p {
  margin: 0;
  font-size: 0.82rem;
}

.info-grid {
  display: grid;
  grid-template-columns: minmax(16rem, 18rem) minmax(0, 1fr) 12rem;
  gap: 1.1rem;
  align-items: start;
}

.logo-preview--sm {
  width: 5.5rem;
  aspect-ratio: 1;
  border-radius: 10px;
  background: color-mix(in srgb, #fff 85%, transparent);
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

.grid-3 {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
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
  gap: 0.45rem;
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0.2rem 0;
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

@media (max-width: 1100px) {
  .info-grid {
    grid-template-columns: minmax(16rem, 1fr) minmax(0, 1.4fr);
  }

  .meta-col {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  }
}

@media (max-width: 720px) {
  .info-grid {
    grid-template-columns: 1fr;
  }

  .logo-col {
    max-width: 20rem;
  }
}

@media (max-width: 640px) {
  .grid-2,
  .grid-3,
  .create-grid {
    grid-template-columns: 1fr;
  }

  .span-2 {
    grid-column: auto;
  }
}
</style>
