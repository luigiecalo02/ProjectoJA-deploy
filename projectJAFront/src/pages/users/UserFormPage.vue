<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import MediaProfileUpload from '@/components/media/MediaProfileUpload.vue'
import Drawer from 'primevue/drawer'
import RadioButton from 'primevue/radiobutton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import PageLoader from '@/components/PageLoader.vue'
import { usersService } from '@/services/usersService'
import { clubsService, personasService } from '@/services/clubsService'
import { organizacionesService } from '@/services/organizacionesService'
import { storageService } from '@/services/storageService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { RoleOption } from '@/modules/users/types'
import type { Club, ClubMinistry, ClubPersona, Persona } from '@/modules/clubs/types'
import type { Organizacion } from '@/modules/organizaciones/types'
import Select from 'primevue/select'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const isEdit = computed(() => route.name === 'users.edit')
const userId = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const uploading = ref(false)
const errorMessage = ref('')
const roles = ref<RoleOption[]>([])
const clubs = ref<Club[]>([])
const personasSinUsuario = ref<Persona[]>([])
const organizaciones = ref<Organizacion[]>([])
const linkedPersona = ref<Persona | null>(null)
const personaMode = ref<'existing' | 'new'>('existing')

const idTipoOptions = [
  { label: 'CC', value: 'CC' },
  { label: 'TI', value: 'TI' },
  { label: 'CE', value: 'CE' },
  { label: 'PA', value: 'PA' },
]

const personaModeOptions = computed(() => [
  { label: t('users.personaModeExisting'), value: 'existing' as const },
  { label: t('users.personaModeNew'), value: 'new' as const },
])

const personaSelectOptions = computed(() =>
  personasSinUsuario.value.map((p) => ({
    id: p.id,
    label: [p.full_name || [p.nombre1, p.apellido1].filter(Boolean).join(' '), p.identificacion]
      .filter(Boolean)
      .join(' · '),
  })),
)

const orgSelectOptions = computed(() =>
  organizaciones.value.map((o) => ({
    id: o.id,
    label: o.tipo?.nombre ? `${o.nombre} (${o.tipo.nombre})` : o.nombre,
  })),
)

const needsPersonaLink = computed(() => !isEdit.value || !linkedPersona.value)

const clubDrawerOpen = ref(false)
const membersDrawerOpen = ref(false)
const savingClub = ref(false)
const loadingMembers = ref(false)
const membersClubName = ref('')
const members = ref<ClubPersona[]>([])

const roleOptions = computed(() =>
  roles.value
    .filter((role) => role.name !== 'super_admin')
    .map((role) => ({
      ...role,
      displayName: role.label || role.display_name || role.name,
    })),
)

const pastorRoleId = computed(() => roles.value.find((r) => r.name === 'pastor')?.id ?? null)

const hasPastorRole = computed(() => {
  const pastorId = pastorRoleId.value
  if (pastorId === null) return false
  return form.organizaciones.some((row) => row.rol_ids.includes(pastorId))
})

const clubOptions = computed(() =>
  clubs.value.map((club) => ({
    id: club.id,
    label: [club.nombre, club.distrito, club.ciudad].filter(Boolean).join(' · '),
  })),
)

const selectedClubs = computed(() =>
  clubs.value.filter((club) => form.club_ids.includes(club.id)),
)

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  changePassword: false,
  is_active: true,
  club_ids: [] as number[],
  avatar_url: null as string | null,
  persona_id: null as number | null,
  organizaciones: [{ organizacion_id: null, rol_ids: [] }] as Array<{
    organizacion_id: number | null
    rol_ids: number[]
  }>,
  persona: {
    tipo_identificacion: 'CC',
    identificacion: '',
    nombre1: '',
    nombre2: '',
    apellido1: '',
    apellido2: '',
    telefono: '',
  },
})

const clubForm = reactive({
  nombre: '',
  distrito: '',
  ciudad: '',
  tipo: null as ClubMinistry | null,
  is_active: true,
})

const ministryOptions = computed(() => [
  { label: t('clubs.typeConquistadores'), value: 'conquistadores' as ClubMinistry },
  { label: t('clubs.typeAventureros'), value: 'aventureros' as ClubMinistry },
  { label: t('clubs.typeGuias'), value: 'guias_mayores' as ClubMinistry },
])

function ministryLabel(tipo: string): string {
  return ministryOptions.value.find((o) => o.value === tipo)?.label || tipo
}

const pageTitle = computed(() => (isEdit.value ? t('users.edit') : t('users.new')))

watch(hasPastorRole, (enabled) => {
  if (!enabled) {
    form.club_ids = []
  }
})

function addOrganizacionRow(): void {
  form.organizaciones.push({ organizacion_id: null, rol_ids: [] })
}

function onChangePasswordToggle(enabled: boolean): void {
  if (!enabled) {
    form.password = ''
    form.password_confirmation = ''
  }
}

function removeOrganizacionRow(index: number): void {
  form.organizaciones.splice(index, 1)
  if (form.organizaciones.length === 0) {
    form.organizaciones.push({ organizacion_id: null, rol_ids: [] })
  }
}

function orgOptionsForRow(index: number) {
  const selectedElsewhere = new Set(
    form.organizaciones
      .map((row, i) => (i === index ? null : row.organizacion_id))
      .filter((id): id is number => id != null),
  )
  return orgSelectOptions.value.filter((o) => !selectedElsewhere.has(o.id))
}

const shouldValidatePassword = computed(
  () => !isEdit.value || form.changePassword,
)

function validate(): string | null {
  if (!form.name.trim() || !form.email.trim()) {
    return t('validation.required')
  }
  if (shouldValidatePassword.value) {
    if (!form.password) {
      return t('validation.required')
    }
    const password = form.password
    const strong =
      password.length >= 8 &&
      /[a-z]/.test(password) &&
      /[A-Z]/.test(password) &&
      /\d/.test(password) &&
      /[^A-Za-z0-9]/.test(password)
    if (!strong) {
      return t('validation.passwordStrong')
    }
    if (password !== form.password_confirmation) {
      return t('validation.passwordMatch')
    }
  }
  if (needsPersonaLink.value) {
    if (personaMode.value === 'existing' && !form.persona_id) {
      return t('users.personaRequired')
    }
    if (
      personaMode.value === 'new' &&
      (!form.persona.identificacion.trim() ||
        !form.persona.nombre1.trim() ||
        !form.persona.apellido1.trim())
    ) {
      return t('users.personaRequired')
    }
  }
  for (const row of form.organizaciones) {
    if (!row.organizacion_id && row.rol_ids.length === 0) {
      continue
    }
    if (!row.organizacion_id || row.rol_ids.length === 0) {
      return t('users.organizacionRequired')
    }
  }
  const orgIds = form.organizaciones
    .map((row) => row.organizacion_id)
    .filter((id): id is number => id != null)
  if (new Set(orgIds).size !== orgIds.length) {
    return t('users.organizacionDuplicate')
  }
  return null
}

async function loadRoles(): Promise<void> {
  roles.value = await usersService.roles()
}

async function loadClubs(): Promise<void> {
  clubs.value = await clubsService.availableForAccount(isEdit.value ? userId.value : null)
}

async function loadPersonasSinUsuario(): Promise<void> {
  const result = await personasService.list({ per_page: 100, sin_usuario: true })
  personasSinUsuario.value = result.items
}

async function loadOrganizaciones(): Promise<void> {
  const result = await organizacionesService.list({ per_page: 200 })
  organizaciones.value = result.items
}

async function loadUser(): Promise<void> {
  if (!isEdit.value) return
  loading.value = true
  try {
    const user = await usersService.get(userId.value)
    form.name = user.name
    form.email = user.email
    form.is_active = user.is_active
    form.avatar_url = user.avatar_url
    form.club_ids = [...(user.club_ids || [])]
    linkedPersona.value = (user.persona as Persona | null | undefined) ?? null
    form.persona_id = user.persona_id ?? null
    form.organizaciones = (user.organizaciones || []).map((org) => ({
      organizacion_id: org.organizacion_id,
      rol_ids: (org.roles || []).map((r) => r.rol_id),
    }))
    if (form.organizaciones.length === 0) {
      form.organizaciones = [{ organizacion_id: null, rol_ids: [] }]
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}

async function onAvatarSelect(file: File): Promise<void> {
  if (!file || !isEdit.value) return

  uploading.value = true
  try {
    const url = await storageService.uploadUserAvatar(userId.value, file)
    form.avatar_url = url
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('users.avatarSuccess'),
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
    uploading.value = false
  }
}

function openClubDrawer(): void {
  clubForm.nombre = ''
  clubForm.distrito = ''
  clubForm.ciudad = ''
  clubForm.tipo = null
  clubForm.is_active = true
  clubDrawerOpen.value = true
}

async function createClubFromDrawer(): Promise<void> {
  if (!clubForm.nombre.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('validation.required'),
      life: 2500,
    })
    return
  }
  if (!clubForm.tipo) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('clubs.typesRequired'),
      life: 2500,
    })
    return
  }

  savingClub.value = true
  try {
    const created = await clubsService.create({
      nombre: clubForm.nombre.trim(),
      distrito: clubForm.distrito.trim() || null,
      ciudad: clubForm.ciudad.trim() || null,
      tipos: [clubForm.tipo],
      is_active: clubForm.is_active,
    })
    clubs.value = [created, ...clubs.value.filter((c) => c.id !== created.id)]
    if (!form.club_ids.includes(created.id)) {
      form.club_ids.push(created.id)
    }
    clubDrawerOpen.value = false
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('clubs.createSuccess'),
      life: 2500,
    })
    await loadClubs()
    if (!form.club_ids.includes(created.id)) {
      form.club_ids.push(created.id)
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    savingClub.value = false
  }
}

async function openMembersDrawer(club: Club): Promise<void> {
  membersDrawerOpen.value = true
  membersClubName.value = club.nombre
  loadingMembers.value = true
  members.value = []
  try {
    const detail = await clubsService.get(club.id)
    members.value = detail.personas || []
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loadingMembers.value = false
  }
}

async function submit(): Promise<void> {
  errorMessage.value = ''
  const validationError = validate()
  if (validationError) {
    errorMessage.value = validationError
    return
  }

  saving.value = true
  try {
    const payload = {
      name: form.name.trim(),
      email: form.email.trim().toLowerCase(),
      is_active: form.is_active,
      club_ids: hasPastorRole.value ? [...form.club_ids] : [],
      ...(form.avatar_url ? { avatar_url: form.avatar_url } : {}),
      ...(shouldValidatePassword.value && form.password
        ? {
            password: form.password,
            password_confirmation: form.password_confirmation,
          }
        : {}),
      ...(needsPersonaLink.value
        ? personaMode.value === 'existing'
          ? { persona_id: form.persona_id }
          : {
              persona: {
                tipo_identificacion: form.persona.tipo_identificacion,
                identificacion: form.persona.identificacion.trim(),
                nombre1: form.persona.nombre1.trim(),
                nombre2: form.persona.nombre2.trim() || null,
                apellido1: form.persona.apellido1.trim(),
                apellido2: form.persona.apellido2.trim() || null,
                telefono: form.persona.telefono.trim() || null,
                correo: form.email.trim().toLowerCase(),
              },
            }
        : {}),
      ...(form.organizaciones.some((row) => row.organizacion_id)
        ? {
            organizaciones: form.organizaciones
              .filter((row) => row.organizacion_id)
              .map((row) => ({
                organizacion_id: row.organizacion_id as number,
                rol_ids: [...row.rol_ids],
              })),
          }
        : {}),
    }

    if (isEdit.value) {
      await usersService.update(userId.value, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('users.updateSuccess'),
        life: 2500,
      })
    } else {
      const created = await usersService.create(payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('users.createSuccess'),
        life: 2500,
      })
      await router.replace({ name: 'users.edit', params: { id: created.id } })
      return
    }

    await router.push({ name: 'users' })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    await Promise.all([loadRoles(), loadClubs(), loadPersonasSinUsuario(), loadOrganizaciones()])
    await loadUser()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ pageTitle }}</h1>
        <p class="pj-page__subtitle">{{ t('users.subtitle') }}</p>
      </div>
      <Button :label="t('common.back')" text @click="router.push({ name: 'users' })" />
    </header>

    <form class="pj-panel" @submit.prevent="submit">
      <Message v-if="errorMessage" severity="error" :closable="false" class="pj-span-2">
        {{ errorMessage }}
      </Message>

      <PageLoader v-if="loading" :label="t('common.loading')" />

      <div v-else class="pj-form-grid">
        <div class="pj-field pj-span-2">
          <MediaProfileUpload
            :src="form.avatar_url"
            :busy="uploading"
            :disabled="!isEdit"
            :hint="isEdit ? undefined : `${t('users.uploadAvatar')} — ${t('common.save')}`"
            @select="onAvatarSelect"
          />
        </div>

        <div class="pj-field">
          <label for="name">{{ t('users.name') }}</label>
          <InputText id="name" v-model="form.name" required fluid />
        </div>

        <div class="pj-field">
          <label for="email">{{ t('users.email') }}</label>
          <InputText id="email" v-model="form.email" type="email" required fluid />
        </div>

        <div class="pj-field pj-span-2 persona-block">
          <h3 class="persona-block__title">{{ t('users.personaSection') }}</h3>
          <p class="pj-muted persona-block__hint">{{ t('users.personaHint') }}</p>

          <div v-if="linkedPersona" class="persona-linked">
            <strong>{{ t('users.personaLinked') }}:</strong>
            <span>
              {{ linkedPersona.full_name || [linkedPersona.nombre1, linkedPersona.apellido1].filter(Boolean).join(' ') }}
              · {{ linkedPersona.tipo_identificacion }} {{ linkedPersona.identificacion }}
            </span>
          </div>

          <template v-else>
            <div class="pj-field">
              <Select
                v-model="personaMode"
                :options="personaModeOptions"
                option-label="label"
                option-value="value"
                fluid
              />
            </div>

            <div v-if="personaMode === 'existing'" class="pj-field">
              <label for="persona_id">{{ t('users.personaSelect') }}</label>
              <Select
                id="persona_id"
                v-model="form.persona_id"
                :options="personaSelectOptions"
                option-label="label"
                option-value="id"
                filter
                show-clear
                fluid
                :placeholder="t('users.personaSelectPlaceholder')"
              />
            </div>

            <div v-else class="persona-new-grid">
              <div class="pj-field">
                <label>{{ t('users.personaTipoId') }}</label>
                <Select
                  v-model="form.persona.tipo_identificacion"
                  :options="idTipoOptions"
                  option-label="label"
                  option-value="value"
                  fluid
                />
              </div>
              <div class="pj-field">
                <label>{{ t('users.personaIdentificacion') }}</label>
                <InputText v-model="form.persona.identificacion" fluid />
              </div>
              <div class="pj-field">
                <label>{{ t('users.personaNombre1') }}</label>
                <InputText v-model="form.persona.nombre1" fluid />
              </div>
              <div class="pj-field">
                <label>{{ t('users.personaNombre2') }}</label>
                <InputText v-model="form.persona.nombre2" fluid />
              </div>
              <div class="pj-field">
                <label>{{ t('users.personaApellido1') }}</label>
                <InputText v-model="form.persona.apellido1" fluid />
              </div>
              <div class="pj-field">
                <label>{{ t('users.personaApellido2') }}</label>
                <InputText v-model="form.persona.apellido2" fluid />
              </div>
              <div class="pj-field">
                <label>{{ t('users.personaTelefono') }}</label>
                <InputText v-model="form.persona.telefono" fluid />
              </div>
            </div>
          </template>
        </div>

        <div class="pj-field pj-span-2 persona-block">
          <div class="org-block__header">
            <div>
              <h3 class="persona-block__title">{{ t('users.organizacionSection') }}</h3>
              <p class="pj-muted persona-block__hint">{{ t('users.organizacionHint') }}</p>
            </div>
            <Button
              type="button"
              :label="t('users.organizacionAdd')"
              icon="pi pi-plus"
              size="small"
              outlined
              @click="addOrganizacionRow"
            />
          </div>

          <div
            v-for="(row, index) in form.organizaciones"
            :key="index"
            class="org-row"
          >
            <div class="pj-field">
              <label :for="`organizacion_id_${index}`">{{ t('users.organizacion') }}</label>
              <Select
                :id="`organizacion_id_${index}`"
                v-model="row.organizacion_id"
                :options="orgOptionsForRow(index)"
                option-label="label"
                option-value="id"
                filter
                show-clear
                fluid
                :placeholder="t('users.organizacionPlaceholder')"
                @update:model-value="row.rol_ids = []"
              />
            </div>
            <div class="pj-field">
              <label :for="`organizacion_rol_ids_${index}`">{{ t('users.organizacionRoles') }}</label>
              <MultiSelect
                :id="`organizacion_rol_ids_${index}`"
                v-model="row.rol_ids"
                :options="roleOptions"
                option-label="displayName"
                option-value="id"
                filter
                display="chip"
                fluid
                :disabled="!row.organizacion_id"
                :placeholder="t('users.organizacionRolesPlaceholder')"
              />
            </div>
            <Button
              type="button"
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              :aria-label="t('common.delete')"
              :disabled="form.organizaciones.length === 1 && !row.organizacion_id"
              @click="removeOrganizacionRow(index)"
            />
          </div>
        </div>

        <div v-if="isEdit" class="pj-field pj-span-2">
          <div class="password-toggle">
            <Checkbox
              v-model="form.changePassword"
              input-id="change_password"
              binary
              @update:model-value="onChangePasswordToggle"
            />
            <label for="change_password">{{ t('users.changePassword') }}</label>
          </div>
          <small class="pj-muted">{{ t('users.changePasswordHint') }}</small>
        </div>

        <div v-if="shouldValidatePassword" class="pj-field">
          <label for="password">{{ t('users.password') }}</label>
          <Password
            id="password"
            v-model="form.password"
            :feedback="false"
            toggle-mask
            fluid
          />
        </div>

        <div v-if="shouldValidatePassword" class="pj-field">
          <label for="password_confirmation">{{ t('users.passwordConfirm') }}</label>
          <Password
            id="password_confirmation"
            v-model="form.password_confirmation"
            :feedback="false"
            toggle-mask
            fluid
          />
        </div>

        <div v-if="hasPastorRole" class="pj-field pj-span-2 clubs-block">
          <div class="clubs-block__head">
            <label for="clubs">{{ t('users.associatedClubs') }}</label>
            <Button
              v-if="can('clubs.create')"
              type="button"
              icon="pi pi-plus"
              size="small"
              :label="t('users.newClubDrawer')"
              text
              @click="openClubDrawer"
            />
          </div>
          <MultiSelect
            id="clubs"
            v-model="form.club_ids"
            :options="clubOptions"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            fluid
            :placeholder="t('users.clubsPlaceholder')"
          />
          <small class="pj-muted">{{ t('users.clubsHint') }} {{ t('users.clubsExclusive') }}</small>

          <div v-if="selectedClubs.length" class="selected-clubs">
            <article v-for="club in selectedClubs" :key="club.id" class="selected-club">
              <div class="selected-club__info">
                <img v-if="club.logo_url" :src="club.logo_url" :alt="club.nombre" class="selected-club__logo" />
                <div>
                  <strong>{{ club.nombre }}</strong>
                  <span class="pj-muted">{{ [club.distrito, club.ciudad].filter(Boolean).join(' · ') || '—' }}</span>
                  <span v-if="club.tipos?.[0]" class="pj-muted">
                    {{ ministryLabel(club.tipos[0]) }}
                  </span>
                </div>
              </div>
              <Button
                type="button"
                icon="pi pi-users"
                size="small"
                text
                :label="t('users.viewMembers')"
                @click="openMembersDrawer(club)"
              />
            </article>
          </div>
        </div>

        <div class="pj-field">
          <label>{{ t('users.status') }}</label>
          <div class="status-row">
            <ToggleSwitch v-model="form.is_active" />
            <span>{{ form.is_active ? t('common.active') : t('common.inactive') }}</span>
          </div>
        </div>

        <div class="pj-span-2 form-actions">
          <Button type="button" :label="t('common.cancel')" text @click="router.push({ name: 'users' })" />
          <Button type="submit" :label="t('common.save')" :loading="saving || uploading" />
        </div>
      </div>
    </form>

    <Drawer
      v-model:visible="clubDrawerOpen"
      position="right"
      class="wide-drawer"
      :header="t('users.newClubDrawer')"
      :style="{ width: '90vw', maxWidth: '90vw' }"
    >
      <div class="drawer-form">
        <p class="pj-muted">{{ t('users.newClubDrawerHint') }}</p>
        <div class="pj-field">
          <label for="club_nombre">{{ t('clubs.name') }}</label>
          <InputText id="club_nombre" v-model="clubForm.nombre" fluid required />
        </div>
        <div class="drawer-grid">
          <div class="pj-field">
            <label for="club_distrito">{{ t('clubs.district') }}</label>
            <InputText id="club_distrito" v-model="clubForm.distrito" fluid />
          </div>
          <div class="pj-field">
            <label for="club_ciudad">{{ t('clubs.city') }}</label>
            <InputText id="club_ciudad" v-model="clubForm.ciudad" fluid />
          </div>
        </div>
        <div class="pj-field">
          <label>{{ t('clubs.types') }}</label>
          <div class="types-row">
            <label v-for="opt in ministryOptions" :key="opt.value" class="type-check">
              <RadioButton v-model="clubForm.tipo" :input-id="`drawer-tipo-${opt.value}`" :value="opt.value" />
              <span>{{ opt.label }}</span>
            </label>
          </div>
          <small class="pj-muted">{{ t('clubs.typesHint') }}</small>
        </div>
        <div class="status-row">
          <ToggleSwitch v-model="clubForm.is_active" />
          <span>{{ clubForm.is_active ? t('common.active') : t('common.inactive') }}</span>
        </div>
        <div class="form-actions">
          <Button type="button" :label="t('common.cancel')" text @click="clubDrawerOpen = false" />
          <Button type="button" :label="t('common.create')" :loading="savingClub" @click="createClubFromDrawer" />
        </div>
      </div>
    </Drawer>

    <Drawer
      v-model:visible="membersDrawerOpen"
      position="right"
      class="wide-drawer members-drawer"
      :header="`${t('users.clubMembers')}: ${membersClubName}`"
      :style="{ width: '90vw', maxWidth: '90vw' }"
    >
      <PageLoader v-if="loadingMembers" :label="t('common.loading')" />
      <DataTable v-else :value="members" data-key="id" striped-rows size="small">
        <template #empty>
          <p class="pj-muted">{{ t('users.noMembers') }}</p>
        </template>
        <Column field="full_name" :header="t('personas.firstName')" />
        <Column :header="t('personas.idNumber')">
          <template #body="{ data }">
            {{ data.tipo_identificacion }} {{ data.identificacion }}
          </template>
        </Column>
        <Column field="correo" :header="t('personas.email')" />
        <Column field="telefono" :header="t('personas.phone')" />
      </DataTable>
    </Drawer>
  </section>
</template>

<style scoped>
.avatar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
}

.avatar-placeholder {
  display: grid;
  place-items: center;
  font-family: var(--pj-font-display);
  font-weight: 700;
  font-size: 1.5rem;
  color: var(--pj-text-muted);
}

.status-row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-height: 2.5rem;
}

.password-toggle {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.password-toggle label {
  margin: 0;
  cursor: pointer;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.clubs-block__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.selected-clubs {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-top: 0.65rem;
}

.selected-club {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.7rem;
  border-radius: 8px;
  border: 1px solid color-mix(in srgb, var(--pj-navy) 12%, transparent);
  background: color-mix(in srgb, var(--pj-navy) 3%, transparent);
}

.selected-club__info {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.selected-club__info > div {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.selected-club__logo {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 6px;
  object-fit: cover;
}

.drawer-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding-bottom: 1rem;
}

.drawer-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.types-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem;
}

.type-check {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  cursor: pointer;
}

@media (max-width: 640px) {
  .drawer-grid {
    grid-template-columns: 1fr;
  }

  .selected-club {
    flex-direction: column;
    align-items: stretch;
  }
}

.persona-block {
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 12px;
  padding: 0.85rem 1rem;
  background: color-mix(in srgb, var(--pj-navy) 3%, transparent);
}

.persona-block__title {
  margin: 0 0 0.25rem;
  font-size: 1rem;
  color: var(--pj-navy);
}

.persona-block__hint {
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
}

.org-block__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.org-block__header .persona-block__hint {
  margin-bottom: 0;
}

.org-row {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr) auto;
  gap: 0.75rem;
  align-items: end;
  margin-bottom: 0.75rem;
}

@media (max-width: 720px) {
  .org-row {
    grid-template-columns: 1fr;
  }

  .org-block__header {
    flex-direction: column;
  }
}

.persona-linked {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.55rem;
  font-size: 0.92rem;
}

.persona-new-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

@media (max-width: 720px) {
  .persona-new-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<style>
.wide-drawer.p-drawer {
  width: 90vw !important;
  max-width: 90vw !important;
}

.members-drawer.p-drawer {
  z-index: 1201;
}
</style>
