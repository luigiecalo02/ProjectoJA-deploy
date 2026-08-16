<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Password from 'primevue/password'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Avatar from 'primevue/avatar'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import { clubsService, personasService } from '@/services/clubsService'
import { getApiErrorMessage } from '@/services/api'
import { brandConfig } from '@/config/brand'
import type {
  BoardPosition,
  Club,
  DirectorsPayload,
  Persona,
} from '@/modules/clubs/types'

type DrawerTab = 'select' | 'create'

interface Assignment {
  persona_id: number
  persona: Persona
  email?: string
}

interface CreateForm {
  tipo_identificacion: string
  identificacion: string
  nombre1: string
  nombre2: string
  apellido1: string
  apellido2: string
  email: string
  password: string
  telefono: string
}

const props = defineProps<{
  clubId: number
}>()

const emit = defineEmits<{
  updated: [club: Club]
}>()

const { t } = useI18n()
const toast = useToast()

const clubId = computed(() => props.clubId)
const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const club = ref<Club | null>(null)
const personas = ref<Persona[]>([])
const personaSearch = ref('')
const loadingPersonas = ref(false)

const assignments = reactive<Record<BoardPosition, Assignment | null>>({
  director: null,
  subdirector: null,
  secretaria: null,
  tesorero: null,
})

const drawerOpen = ref(false)
const editDrawerOpen = ref(false)
const drawerTab = ref<DrawerTab>('select')
const activePosition = ref<BoardPosition | null>(null)
const creating = ref(false)
const createError = ref('')
const editingPersona = ref<Persona | null>(null)
const savingPersonaEdit = ref(false)
const editError = ref('')

const editForm = reactive({
  tipo_identificacion: 'CC',
  identificacion: '',
  nombre1: '',
  nombre2: '',
  apellido1: '',
  apellido2: '',
  email: '',
  telefono: '',
})

const idTypes = [
  { label: 'CC', value: 'CC' },
  { label: 'TI', value: 'TI' },
  { label: 'CE', value: 'CE' },
  { label: 'Pasaporte', value: 'Pasaporte' },
  { label: 'NIT', value: 'NIT' },
]

const createForm = reactive<CreateForm>({
  tipo_identificacion: 'CC',
  identificacion: '',
  nombre1: '',
  nombre2: '',
  apellido1: '',
  apellido2: '',
  email: '',
  password: '',
  telefono: '',
})

const positions: {
  key: BoardPosition
  labelKey: string
  icon: string
  descKey: string
}[] = [
  { key: 'director', labelKey: 'clubs.boardDirector', icon: 'pi pi-star', descKey: 'clubs.boardDescDirector' },
  { key: 'subdirector', labelKey: 'clubs.boardSubdirector', icon: 'pi pi-user', descKey: 'clubs.boardDescSubdirector' },
  { key: 'secretaria', labelKey: 'clubs.boardSecretaria', icon: 'pi pi-file', descKey: 'clubs.boardDescSecretaria' },
  { key: 'tesorero', labelKey: 'clubs.boardTesorero', icon: 'pi pi-wallet', descKey: 'clubs.boardDescTesorero' },
]

const filledCount = computed(() => positions.filter((p) => assignments[p.key]).length)
const availableCount = computed(() => 4 - filledCount.value)

const clubTypeLabel = computed(() => {
  const tipo = club.value?.tipos?.[0]
  if (tipo === 'conquistadores') return t('clubs.typeConquistadores')
  if (tipo === 'aventureros') return t('clubs.typeAventureros')
  if (tipo === 'guias_mayores') return t('clubs.typeGuias')
  return tipo || '—'
})

const drawerTitle = computed(() => {
  if (!activePosition.value) return t('personas.new')
  const pos = positions.find((p) => p.key === activePosition.value)
  return pos ? `${t('clubs.boardChange')} · ${t(pos.labelKey)}` : t('clubs.boardChange')
})

const filteredPersonas = computed(() => {
  const q = personaSearch.value.trim().toLowerCase()
  if (!q) return personas.value
  return personas.value.filter((p) =>
    [p.full_name, p.identificacion, p.correo, p.nombre1, p.apellido1]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  )
})

function resetCreateForm(): void {
  createForm.tipo_identificacion = 'CC'
  createForm.identificacion = ''
  createForm.nombre1 = ''
  createForm.nombre2 = ''
  createForm.apellido1 = ''
  createForm.apellido2 = ''
  createForm.email = ''
  createForm.password = ''
  createForm.telefono = ''
  createError.value = ''
}

function toPersonaFromDirector(clubData: Club, position: BoardPosition): Persona | null {
  const dir = clubData.directors?.find((d) => d.ministry === position)
  if (!dir?.persona) {
    const byUser = clubData.personas?.find((p) => p.user_id === dir?.user_id || p.cargo === position)
    if (!byUser || !dir?.user_id) return null
    return {
      id: byUser.id,
      user_id: dir.user_id,
      tipo_identificacion: byUser.tipo_identificacion,
      identificacion: byUser.identificacion,
      nombre1: byUser.nombre1,
      nombre2: byUser.nombre2 ?? null,
      apellido1: byUser.apellido1,
      apellido2: byUser.apellido2 ?? null,
      fecha_nacimiento: null,
      sexo: null,
      telefono: byUser.telefono ?? null,
      correo: byUser.correo ?? null,
      direccion_actual: null,
      full_name: byUser.full_name,
    }
  }
  return {
    id: dir.persona.id,
    user_id: dir.persona.user_id ?? dir.user_id,
    tipo_identificacion: dir.persona.tipo_identificacion,
    identificacion: dir.persona.identificacion,
    nombre1: dir.persona.nombre1,
    nombre2: null,
    apellido1: dir.persona.apellido1,
    apellido2: null,
    fecha_nacimiento: null,
    sexo: null,
    telefono: null,
    correo: dir.persona.correo,
    direccion_actual: null,
    full_name: dir.persona.full_name,
  }
}

function applyAssignmentsFromClub(clubData: Club): void {
  for (const p of positions) {
    const persona = toPersonaFromDirector(clubData, p.key)
    assignments[p.key] = persona
      ? { persona_id: persona.id, persona }
      : null
  }
}

async function loadPersonas(search = ''): Promise<void> {
  loadingPersonas.value = true
  try {
    const padreId =
      club.value?.iglesia_organizacion_id ?? club.value?.organizacion?.padre?.id ?? null
    const result = await personasService.list({
      page: 1,
      per_page: 50,
      search: search.trim() || undefined,
      organizacion_padre_id: padreId || undefined,
    })
    personas.value = result.items
  } finally {
    loadingPersonas.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const data = await clubsService.get(clubId.value)
    club.value = data
    applyAssignmentsFromClub(data)
    await loadPersonas()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}

function openChange(position: BoardPosition): void {
  activePosition.value = position
  drawerTab.value = 'select'
  createError.value = ''
  resetCreateForm()
  drawerOpen.value = true
  void loadPersonas(personaSearch.value)
}

function openCreatePerson(): void {
  activePosition.value = null
  drawerTab.value = 'create'
  resetCreateForm()
  drawerOpen.value = true
}

function needsEmail(persona: Persona): boolean {
  return !persona.user_id && !persona.correo
}

function openEditPersonaDrawer(persona: Persona): void {
  editingPersona.value = persona
  editForm.tipo_identificacion = persona.tipo_identificacion || 'CC'
  editForm.identificacion = persona.identificacion || ''
  editForm.nombre1 = persona.nombre1 || ''
  editForm.nombre2 = persona.nombre2 || ''
  editForm.apellido1 = persona.apellido1 || ''
  editForm.apellido2 = persona.apellido2 || ''
  editForm.email = persona.correo || ''
  editForm.telefono = persona.telefono || ''
  editError.value = ''
  createError.value = t('clubs.boardEmailHint')
  editDrawerOpen.value = true
}

function assignPersona(persona: Persona): void {
  if (!activePosition.value) return

  if (needsEmail(persona)) {
    openEditPersonaDrawer(persona)
    return
  }

  createError.value = ''
  const key = activePosition.value
  delete pendingCreates[key]
  assignments[key] = {
    persona_id: persona.id,
    persona,
  }
  editDrawerOpen.value = false
  drawerOpen.value = false
}

async function savePersonaEditAndAssign(): Promise<void> {
  if (!editingPersona.value || !activePosition.value) return

  if (!editForm.email.trim()) {
    editError.value = t('clubs.boardEmailHint')
    return
  }
  if (!editForm.identificacion.trim() || !editForm.nombre1.trim() || !editForm.apellido1.trim()) {
    editError.value = t('validation.required')
    return
  }

  savingPersonaEdit.value = true
  editError.value = ''
  try {
    const updated = await personasService.update(editingPersona.value.id, {
      tipo_identificacion: editForm.tipo_identificacion,
      identificacion: editForm.identificacion.trim(),
      nombre1: editForm.nombre1.trim(),
      nombre2: editForm.nombre2.trim() || null,
      apellido1: editForm.apellido1.trim(),
      apellido2: editForm.apellido2.trim() || null,
      correo: editForm.email.trim(),
      telefono: editForm.telefono.trim() || null,
      club_ids: [clubId.value],
    })

    personas.value = [updated, ...personas.value.filter((p) => p.id !== updated.id)]
    editingPersona.value = updated

    const key = activePosition.value
    delete pendingCreates[key]
    assignments[key] = {
      persona_id: updated.id,
      persona: updated,
      email: updated.correo || editForm.email.trim(),
    }

    editDrawerOpen.value = false
    drawerOpen.value = false
    createError.value = ''
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('personas.updateSuccess'),
      life: 2500,
    })
  } catch (error) {
    editError.value = getApiErrorMessage(error)
  } finally {
    savingPersonaEdit.value = false
  }
}

function clearPosition(position: BoardPosition): void {
  assignments[position] = null
  delete pendingCreates[position]
}

const pendingCreates = reactive<Partial<Record<BoardPosition, {
  password: string
  email: string
  name: string
  persona: Persona
}>>>({})

function fullNameFromCreate(): string {
  return [createForm.nombre1, createForm.nombre2, createForm.apellido1, createForm.apellido2]
    .map((v) => v.trim())
    .filter(Boolean)
    .join(' ')
}

async function createAndAssign(): Promise<void> {
  if (
    !createForm.identificacion.trim()
    || !createForm.nombre1.trim()
    || !createForm.apellido1.trim()
    || !createForm.email.trim()
    || (activePosition.value && !createForm.password)
  ) {
    createError.value = t('clubs.directorCreateRequired', {
      ministry: activePosition.value
        ? t(positions.find((p) => p.key === activePosition.value)!.labelKey)
        : t('personas.new'),
    })
    return
  }

  creating.value = true
  createError.value = ''
  try {
    const persona = await personasService.create({
      tipo_identificacion: createForm.tipo_identificacion,
      identificacion: createForm.identificacion.trim(),
      nombre1: createForm.nombre1.trim(),
      nombre2: createForm.nombre2.trim() || null,
      apellido1: createForm.apellido1.trim(),
      apellido2: createForm.apellido2.trim() || null,
      correo: createForm.email.trim(),
      telefono: createForm.telefono.trim() || null,
      club_ids: [clubId.value],
    })

    personas.value = [persona, ...personas.value.filter((p) => p.id !== persona.id)]

    if (activePosition.value) {
      const key = activePosition.value
      assignments[key] = {
        persona_id: persona.id,
        persona,
        email: createForm.email.trim(),
      }
      pendingCreates[key] = {
        password: createForm.password,
        email: createForm.email.trim(),
        name: fullNameFromCreate(),
        persona,
      }
    } else {
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('personas.createSuccess'),
        life: 2500,
      })
    }
    drawerOpen.value = false
  } catch (error) {
    createError.value = getApiErrorMessage(error)
  } finally {
    creating.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  errorMessage.value = ''
  try {
    const directors: DirectorsPayload['directors'] = {}
    for (const p of positions) {
      const pending = pendingCreates[p.key]
      const assigned = assignments[p.key]

      if (pending) {
        directors[p.key] = {
          mode: 'select',
          persona_id: pending.persona.id,
          user: {
            name: pending.name,
            email: pending.email,
            password: pending.password,
          },
        }
      } else if (assigned) {
        directors[p.key] = {
          mode: 'select',
          persona_id: assigned.persona_id,
          ...(assigned.email
            ? { user: { email: assigned.email, name: assigned.persona.full_name } }
            : {}),
        }
      } else {
        directors[p.key] = { clear: true }
      }
    }

    const updated = await clubsService.syncDirectors(clubId.value, { directors })
    club.value = updated
    applyAssignmentsFromClub(updated)
    for (const key of Object.keys(pendingCreates) as BoardPosition[]) {
      delete pendingCreates[key]
    }
    emit('updated', updated)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('clubs.directorsSuccess'),
      life: 2500,
    })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

function cancelChanges(): void {
  if (club.value) applyAssignmentsFromClub(club.value)
  for (const key of Object.keys(pendingCreates) as BoardPosition[]) {
    delete pendingCreates[key]
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
function onSearchInput(): void {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    void loadPersonas(personaSearch.value)
  }, 300)
}

watch(clubId, () => {
  if (clubId.value) void load()
})

onMounted(() => {
  if (clubId.value) void load()
})
</script>

<template>
  <section class="board-panel">
    <header class="board-panel__header">
      <div>
        <h2>{{ t('clubs.infoTitle') }}</h2>
        <p class="pj-muted">{{ t('clubs.directorsPageSubtitle') }}</p>
      </div>
      <Button type="button"
        icon="pi pi-user-plus"
        :label="t('clubs.registerNewPerson')"
        outlined
        size="small"
        @click="openCreatePerson"
      />
    </header>

    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

    <section
      class="club-hero"
      :class="{ 'club-hero--form': $slots.info }"
      :style="{ '--club-hero-art': `url(${brandConfig.directivaHero})` }"
    >
      <div class="club-hero__info">
        <slot name="info">
          <PageLoader v-if="loading" :label="t('common.loading')" />
          <div v-else-if="club" class="club-hero__brand">
            <img
              v-if="club.logo_url"
              :src="club.logo_url"
              :alt="club.nombre"
              class="club-hero__logo"
            />
            <div v-else class="club-hero__logo club-hero__logo--empty">
              <i class="pi pi-shield" />
            </div>
            <div>
              <h3>{{ club.nombre }}</h3>
              <p class="pj-muted club-hero__meta">
                {{ [club.distrito, club.ciudad].filter(Boolean).join(' · ') || '—' }}
                · {{ clubTypeLabel }}
              </p>
              <div class="club-hero__stats">
                <span><strong>{{ club.personas_count ?? club.personas?.length ?? 0 }}</strong> {{ t('clubs.totalMembers') }}</span>
                <span><strong>{{ availableCount }}</strong> {{ t('clubs.boardAvailable') }}</span>
                <Tag
                  :value="club.is_active ? t('common.active') : t('common.inactive')"
                  :severity="club.is_active ? 'success' : 'secondary'"
                />
              </div>
            </div>
          </div>
        </slot>
      </div>
    </section>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <template v-else>
      <section class="board-section">
        <h3>{{ t('clubs.boardPositionsTitle') }}</h3>
        <div class="board-grid">
          <article
            v-for="p in positions"
            :key="p.key"
            class="role-card"
            :class="`role-card--${p.key}`"
          >
            <header class="role-card__head">
              <div class="role-card__title">
                <span class="role-card__icon"><i :class="p.icon" /></span>
                <strong>{{ t(p.labelKey) }}</strong>
              </div>
              <Button type="button"
                :label="assignments[p.key] ? t('clubs.boardChange') : t('clubs.boardAssign')"
                size="small"
                text
                @click="openChange(p.key)"
              />
            </header>

            <div v-if="assignments[p.key]" class="role-card__person">
              <Avatar
                :label="assignments[p.key]!.persona.full_name.charAt(0).toUpperCase()"
                shape="circle"
                style="background: var(--pj-primary-soft); color: var(--pj-navy)"
              />
              <div class="role-card__person-info">
                <div class="role-card__name-row">
                  <strong>{{ assignments[p.key]!.persona.full_name }}</strong>
                  <Tag :value="t('clubs.boardAssigned')" severity="success" />
                </div>
                <span class="pj-muted">
                  {{ assignments[p.key]!.persona.tipo_identificacion }}
                  {{ assignments[p.key]!.persona.identificacion }}
                </span>
                <span class="pj-muted">{{ assignments[p.key]!.persona.correo || '—' }}</span>
              </div>
            </div>
            <div v-else class="role-card__empty">
              <i class="pi pi-user-plus" />
              <p>{{ t('clubs.boardVacant') }}</p>
            </div>

            <p class="role-card__desc">{{ t(p.descKey) }}</p>
            <Button type="button"
              v-if="assignments[p.key]"
              :label="t('clubs.boardClear')"
              severity="secondary"
              text
              size="small"
              class="role-card__clear"
              @click="clearPosition(p.key)"
            />
          </article>
        </div>
      </section>

      <footer class="board-panel__footer">
        <Button type="button"
          :label="t('common.cancel')"
          text
          size="small"
          @click="cancelChanges"
        />
        <Button type="button"
          :label="t('clubs.saveBoard')"
          icon="pi pi-check"
          size="small"
          :loading="saving"
          @click="save"
        />
      </footer>
    </template>

    <AppStackDrawer
      v-model:visible="drawerOpen"
      :level="1"
      :title="drawerTitle"
      :subtitle="t('clubs.boardDrawerHint')"
    >
      <div class="drawer-tabs" v-if="activePosition">
        <Button type="button"
          :label="t('clubs.boardTabSelect')"
          :outlined="drawerTab !== 'select'"
          size="small"
          @click="drawerTab = 'select'"
        />
        <Button type="button"
          :label="t('clubs.boardTabCreate')"
          :outlined="drawerTab !== 'create'"
          size="small"
          @click="drawerTab = 'create'"
        />
      </div>

      <Message v-if="createError" severity="error" :closable="false">
        {{ createError }}
      </Message>

      <template v-if="drawerTab === 'select' && activePosition">
        <AppSearchField
          v-model="personaSearch"
          :placeholder="t('personas.searchPlaceholder')"
          @update:model-value="onSearchInput"
        />

        <PageLoader v-if="loadingPersonas" :label="t('common.loading')" />
        <DataTable
          v-else
          :value="filteredPersonas"
          data-key="id"
          size="small"
          striped-rows
          class="personas-table"
        >
          <template #empty>
            <p class="pj-muted">{{ t('personas.empty') }}</p>
          </template>
          <Column :header="t('personas.fullName')">
            <template #body="{ data }">
              <div class="persona-cell">
                <Avatar
                  :label="(data.full_name || '?').charAt(0).toUpperCase()"
                  shape="circle"
                  style="background: var(--pj-primary-soft); color: var(--pj-navy)"
                />
                <div>
                  <strong>{{ data.full_name }}</strong>
                  <small class="pj-muted">{{ data.tipo_identificacion }} {{ data.identificacion }}</small>
                </div>
              </div>
            </template>
          </Column>
          <Column :header="t('clubs.boardHasUserCol')" style="width: 5rem">
            <template #body="{ data }">
              <Tag
                :value="data.user_id ? t('common.yes') : t('common.no')"
                :severity="data.user_id ? 'success' : 'warn'"
              />
            </template>
          </Column>
          <Column style="width: 6.5rem">
            <template #body="{ data }">
              <Button type="button"
                :label="t('clubs.boardPick')"
                size="small"
                @click="assignPersona(data)"
              />
            </template>
          </Column>
        </DataTable>
      </template>

      <form v-else class="create-form" @submit.prevent="createAndAssign">
        <div class="create-grid">
          <div class="field">
            <label>{{ t('personas.idType') }}</label>
            <Select
              v-model="createForm.tipo_identificacion"
              :options="idTypes"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
          <div class="field">
            <label>{{ t('personas.idNumber') }} *</label>
            <InputText v-model="createForm.identificacion" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.firstName') }} *</label>
            <InputText v-model="createForm.nombre1" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.secondName') }}</label>
            <InputText v-model="createForm.nombre2" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.lastName') }} *</label>
            <InputText v-model="createForm.apellido1" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.secondLastName') }}</label>
            <InputText v-model="createForm.apellido2" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.email') }} *</label>
            <InputText v-model="createForm.email" type="email" class="w-full" />
          </div>
          <div v-if="activePosition" class="field">
            <label>{{ t('users.password') }} *</label>
            <Password
              v-model="createForm.password"
              toggle-mask
              :feedback="false"
              class="w-full"
              input-class="w-full"
            />
          </div>
          <div class="field" :class="{ 'span-2': !activePosition }">
            <label>{{ t('personas.phone') }}</label>
            <InputText v-model="createForm.telefono" class="w-full" />
          </div>
        </div>
        <div class="drawer-actions">
          <Button type="button" :label="t('common.cancel')" text @click="drawerOpen = false" />
          <Button
            type="submit"
            :label="activePosition ? t('clubs.boardCreateAndAssign') : t('common.create')"
            :loading="creating"
          />
        </div>
      </form>
    </AppStackDrawer>

    <AppStackDrawer
      v-model:visible="editDrawerOpen"
      :level="2"
      :title="t('clubs.boardEditMissingTitle')"
      :subtitle="editingPersona?.full_name || t('clubs.boardEditMissingSubtitle')"
    >
      <Message severity="warn" :closable="false">
        {{ t('clubs.boardEmailHint') }}
      </Message>
      <Message v-if="editError" severity="error" :closable="false">{{ editError }}</Message>

      <form class="create-form" @submit.prevent="savePersonaEditAndAssign">
        <div class="create-grid">
          <div class="field">
            <label>{{ t('personas.idType') }}</label>
            <Select
              v-model="editForm.tipo_identificacion"
              :options="idTypes"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
          <div class="field">
            <label>{{ t('personas.idNumber') }} *</label>
            <InputText v-model="editForm.identificacion" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.firstName') }} *</label>
            <InputText v-model="editForm.nombre1" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.secondName') }}</label>
            <InputText v-model="editForm.nombre2" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.lastName') }} *</label>
            <InputText v-model="editForm.apellido1" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.secondLastName') }}</label>
            <InputText v-model="editForm.apellido2" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.email') }} *</label>
            <InputText v-model="editForm.email" type="email" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.phone') }}</label>
            <InputText v-model="editForm.telefono" class="w-full" />
          </div>
        </div>
        <div class="drawer-actions">
          <Button type="button" :label="t('common.cancel')" text @click="editDrawerOpen = false" />
          <Button
            type="submit"
            :label="t('clubs.boardSaveAndAssign')"
            :loading="savingPersonaEdit"
          />
        </div>
      </form>
    </AppStackDrawer>
  </section>
</template>

<style scoped>
.board-panel__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 0.65rem;
}

.board-panel__header h2 {
  margin: 0;
  font-size: 1.05rem;
  color: var(--pj-navy);
}

.board-panel__header .pj-muted {
  margin: 0.15rem 0 0;
  font-size: 0.82rem;
}

.club-hero {
  position: relative;
  display: block;
  isolation: isolate;
  background-color: #eef6ff;
  background-image: var(--club-hero-art);
  background-repeat: no-repeat;
  background-position: right center;
  background-size: auto 100%;
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 0.75rem;
  box-shadow: 0 4px 14px color-mix(in srgb, var(--pj-navy) 5%, transparent);
  min-height: 0;
}

.club-hero--form {
  min-height: 0;
}

.club-hero__info {
  position: relative;
  z-index: 1;
  padding: 0.65rem 0.9rem;
  min-width: 0;
  background: linear-gradient(
    90deg,
    color-mix(in srgb, #fff 94%, transparent) 0%,
    color-mix(in srgb, #fff 82%, transparent) 48%,
    color-mix(in srgb, #fff 55%, transparent) 100%
  );
}

.club-hero--form .club-hero__info {
  padding: 0.55rem 0.75rem;
}

.club-hero__brand {
  display: flex;
  gap: 0.65rem;
  align-items: center;
}

.club-hero__logo {
  width: 2.85rem;
  height: 2.85rem;
  object-fit: cover;
  border-radius: 10px;
  flex-shrink: 0;
}

.club-hero__logo--empty {
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  color: var(--pj-navy);
  font-size: 1.1rem;
}

.club-hero__info h3 {
  margin: 0;
  font-size: 1rem;
  line-height: 1.25;
  color: var(--pj-navy);
}

.club-hero__meta {
  margin: 0.1rem 0 0;
  font-size: 0.78rem;
}

.club-hero__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  margin-top: 0.4rem;
  align-items: center;
  font-size: 0.78rem;
}

.board-section h3 {
  margin: 0 0 0.55rem;
  font-size: 0.95rem;
  color: var(--pj-navy);
}

.board-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.55rem;
}

.role-card {
  background: #fff;
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 12px;
  padding: 0.65rem;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-height: 0;
}

.role-card__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.3rem;
}

.role-card__title {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.88rem;
}

.role-card__icon {
  width: 1.55rem;
  height: 1.55rem;
  border-radius: 7px;
  display: grid;
  place-items: center;
  font-size: 0.75rem;
}

.role-card--director .role-card__icon { background: #fff3d6; color: #b45309; }
.role-card--subdirector .role-card__icon { background: #e8f0ff; color: #1d4ed8; }
.role-card--secretaria .role-card__icon { background: #e6f7ef; color: #047857; }
.role-card--tesorero .role-card__icon { background: #f1e9ff; color: #6d28d9; }

.role-card__person {
  display: flex;
  gap: 0.5rem;
  align-items: flex-start;
}

.role-card__person-info {
  display: flex;
  flex-direction: column;
  gap: 0.08rem;
  min-width: 0;
  font-size: 0.75rem;
}

.role-card__name-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  align-items: center;
}

.role-card__empty {
  display: grid;
  place-items: center;
  gap: 0.2rem;
  padding: 0.55rem 0.4rem;
  color: var(--pj-text-muted);
  border: 1px dashed color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 8px;
}

.role-card__empty i {
  font-size: 1.1rem;
}

.role-card__empty p {
  margin: 0;
  font-size: 0.78rem;
}

.role-card__desc {
  margin: auto 0 0;
  font-size: 0.7rem;
  line-height: 1.3;
  color: var(--pj-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.role-card--director .role-card__desc { background: #fff8e8; padding: 0.35rem 0.45rem; border-radius: 6px; color: #92400e; }
.role-card--subdirector .role-card__desc { background: #eff4ff; padding: 0.35rem 0.45rem; border-radius: 6px; color: #1e3a8a; }
.role-card--secretaria .role-card__desc { background: #ecfdf5; padding: 0.35rem 0.45rem; border-radius: 6px; color: #065f46; }
.role-card--tesorero .role-card__desc { background: #f5f3ff; padding: 0.35rem 0.45rem; border-radius: 6px; color: #5b21b6; }

.role-card__clear {
  align-self: flex-start;
  margin-top: -0.15rem;
}

.board-panel__footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  margin-top: 0.75rem;
}

.drawer-tabs {
  display: flex;
  gap: 0.4rem;
}

.persona-cell {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.persona-cell small {
  display: block;
}

.create-form,
.create-grid {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.create-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.span-2 { grid-column: span 2; }
.w-full { width: 100%; }

.drawer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
}

@media (max-width: 1100px) {
  .board-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 800px) {
  .board-grid { grid-template-columns: 1fr; }
  .create-grid { grid-template-columns: 1fr; }
  .span-2 { grid-column: auto; }
}
</style>
