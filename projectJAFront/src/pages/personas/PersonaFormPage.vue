<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import PageLoader from '@/components/PageLoader.vue'
import { personasService } from '@/services/clubsService'
import { getApiErrorMessage } from '@/services/api'
import type { PersonaOrganizacionOption, PersonaOrganizacionOptions } from '@/modules/clubs/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const isIntegrantes = computed(
  () => String(route.name ?? '').startsWith('integrantes'),
)
const isEdit = computed(
  () => route.name === 'personas.edit' || route.name === 'integrantes.edit',
)
const personaId = computed(() => Number(route.params.id))
const listRouteName = computed(() => (isIntegrantes.value ? 'integrantes' : 'personas'))
const pageTitle = computed(() => {
  if (isEdit.value) {
    return isIntegrantes.value ? t('integrantes.edit') : t('personas.edit')
  }
  return isIntegrantes.value ? t('integrantes.new') : t('personas.new')
})
const pageSubtitle = computed(() =>
  isIntegrantes.value ? t('integrantes.formSubtitle') : t('personas.subtitle'),
)

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const orgOptionsMeta = ref<PersonaOrganizacionOptions>({
  mode: 'admin',
  locked: false,
  default_ids: [],
  options: [],
})

const idTypes = [
  { label: 'CC', value: 'CC' },
  { label: 'TI', value: 'TI' },
  { label: 'CE', value: 'CE' },
  { label: 'Pasaporte', value: 'Pasaporte' },
  { label: 'NIT', value: 'NIT' },
]

const sexOptions = [
  { label: 'Masculino', value: 'M' },
  { label: 'Femenino', value: 'F' },
  { label: 'Otro', value: 'Otro' },
]

const form = reactive({
  tipo_identificacion: 'CC',
  identificacion: '',
  nombre1: '',
  nombre2: '',
  apellido1: '',
  apellido2: '',
  fecha_nacimiento: null as Date | null,
  sexo: null as string | null,
  telefono: '',
  correo: '',
  direccion_actual: '',
  organizacion_ids: [] as number[],
})

/** Orgs activas fuera del alcance: visibles pero no editables. */
const lockedOrganizations = ref<Array<{ id: number; label: string }>>([])

const clubOrgOptions = computed(() =>
  orgOptionsMeta.value.options.map((org: PersonaOrganizacionOption) => ({
    id: org.id,
    label: [org.nombre, org.padre_nombre, org.abuelo_nombre].filter(Boolean).join(' · '),
  })),
)

const clubFieldLocked = computed(
  () => orgOptionsMeta.value.locked || (!isEdit.value && orgOptionsMeta.value.options.length === 1),
)

const lockedOrgIds = computed(() => lockedOrganizations.value.map((o) => o.id))

function toDateString(value: Date | null): string | null {
  if (!value) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
}

function parseDate(value: string | null): Date | null {
  if (!value) return null
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime()) ? null : date
}

function validate(): string | null {
  if (!form.tipo_identificacion || !form.identificacion.trim()) return t('validation.required')
  if (!form.nombre1.trim() || !form.apellido1.trim()) return t('validation.required')
  if (form.correo.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.correo.trim())) {
    return t('validation.email')
  }
  if (form.organizacion_ids.length === 0 && lockedOrgIds.value.length === 0) {
    return t('personas.organizacionRequired')
  }
  return null
}

const orgFieldLabel = computed(() =>
  isIntegrantes.value ? t('integrantes.clubesAsociados') : t('personas.organizaciones'),
)
const orgFieldPlaceholder = computed(() =>
  isIntegrantes.value
    ? t('integrantes.clubesAsociadosPlaceholder')
    : t('personas.organizacionPlaceholder'),
)
const orgFieldHint = computed(() => {
  if (clubFieldLocked.value) {
    return isIntegrantes.value
      ? t('integrantes.clubesAsociadosAuto')
      : t('personas.organizacionLeafHint')
  }
  if (orgOptionsMeta.value.mode === 'admin') {
    return isIntegrantes.value
      ? t('integrantes.clubesAsociadosHint')
      : t('personas.organizacionAdminHint')
  }
  return isIntegrantes.value
    ? t('integrantes.clubesAsociadosHint')
    : t('personas.organizacionParentHint')
})

async function loadOrgOptions(): Promise<void> {
  orgOptionsMeta.value = await personasService.organizacionOptions({
    solo_tipo_club: isIntegrantes.value,
  })
  if (!isEdit.value) {
    if (orgOptionsMeta.value.default_ids.length) {
      form.organizacion_ids = [...orgOptionsMeta.value.default_ids]
    } else if (orgOptionsMeta.value.options.length === 1) {
      form.organizacion_ids = [orgOptionsMeta.value.options[0].id]
    }
  }
}

function applyPersona(persona: Awaited<ReturnType<typeof personasService.get>>): void {
  form.tipo_identificacion = persona.tipo_identificacion || 'CC'
  form.identificacion = persona.identificacion || ''
  form.nombre1 = persona.nombre1 || ''
  form.nombre2 = persona.nombre2 || ''
  form.apellido1 = persona.apellido1 || ''
  form.apellido2 = persona.apellido2 || ''
  form.fecha_nacimiento = parseDate(persona.fecha_nacimiento)
  form.sexo = persona.sexo
  form.telefono = persona.telefono || ''
  form.correo = persona.correo || ''
  form.direccion_actual = persona.direccion_actual || ''

  const allowedIds = new Set(orgOptionsMeta.value.options.map((o) => o.id))
  const activeOrgs = (persona.organizaciones ?? []).filter((o) => o.estado !== false)
  const activeIds =
    activeOrgs.length > 0
      ? activeOrgs.map((o) => o.organizacion_id)
      : [...(persona.organizacion_ids || [])]

  lockedOrganizations.value = activeIds
    .filter((id) => !allowedIds.has(id))
    .map((id) => {
      const row = activeOrgs.find((o) => o.organizacion_id === id)
      return {
        id,
        label: row?.organizacion_nombre || `#${id}`,
      }
    })

  form.organizacion_ids = activeIds.filter((id) => allowedIds.has(id))
}

async function submit(): Promise<void> {
  const validationError = validate()
  if (validationError) {
    errorMessage.value = validationError
    return
  }

  saving.value = true
  errorMessage.value = ''
  try {
    const payload = {
      tipo_identificacion: form.tipo_identificacion,
      identificacion: form.identificacion.trim(),
      nombre1: form.nombre1.trim(),
      nombre2: form.nombre2.trim() || null,
      apellido1: form.apellido1.trim(),
      apellido2: form.apellido2.trim() || null,
      fecha_nacimiento: toDateString(form.fecha_nacimiento),
      sexo: form.sexo,
      telefono: form.telefono.trim() || null,
      correo: form.correo.trim() || null,
      direccion_actual: form.direccion_actual.trim() || null,
      // Incluye orgs bloqueadas para que el backend las preserve.
      organizacion_ids: [...new Set([...lockedOrgIds.value, ...form.organizacion_ids])],
    }

    if (isEdit.value) {
      await personasService.update(personaId.value, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: isIntegrantes.value ? t('integrantes.updateSuccess') : t('personas.updateSuccess'),
        life: 2500,
      })
    } else {
      await personasService.create(payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: isIntegrantes.value ? t('integrantes.createSuccess') : t('personas.createSuccess'),
        life: 2500,
      })
    }
    await router.push({ name: listRouteName.value })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    if (isEdit.value) {
      const [orgMeta, persona] = await Promise.all([
        personasService.organizacionOptions({ solo_tipo_club: isIntegrantes.value }),
        personasService.get(personaId.value),
      ])
      orgOptionsMeta.value = orgMeta
      applyPersona(persona)
    } else {
      await loadOrgOptions()
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ pageTitle }}</h1>
        <p class="pj-page__subtitle">{{ pageSubtitle }}</p>
      </div>
      <Button
        :label="t('common.back')"
        icon="pi pi-arrow-left"
        text
        @click="router.push({ name: listRouteName })"
      />
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <form v-else class="pj-panel form-panel" @submit.prevent="submit">
      <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

      <div class="form-grid">
        <div class="field">
          <label>{{ t('personas.idType') }}</label>
          <Select
            v-model="form.tipo_identificacion"
            :options="idTypes"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="field">
          <label>{{ t('personas.idNumber') }}</label>
          <InputText v-model="form.identificacion" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.firstName') }}</label>
          <InputText v-model="form.nombre1" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.secondName') }}</label>
          <InputText v-model="form.nombre2" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.lastName') }}</label>
          <InputText v-model="form.apellido1" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.secondLastName') }}</label>
          <InputText v-model="form.apellido2" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.birthDate') }}</label>
          <DatePicker v-model="form.fecha_nacimiento" date-format="dd/mm/yy" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.sex') }}</label>
          <Select
            v-model="form.sexo"
            :options="sexOptions"
            option-label="label"
            option-value="value"
            show-clear
            class="w-full"
          />
        </div>
        <div class="field">
          <label>{{ t('personas.phone') }}</label>
          <InputText v-model="form.telefono" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('personas.email') }}</label>
          <InputText v-model="form.correo" type="email" class="w-full" />
        </div>
        <div class="field span-2">
          <label>{{ t('personas.address') }}</label>
          <InputText v-model="form.direccion_actual" class="w-full" />
        </div>
        <div class="field span-2">
          <label>{{ orgFieldLabel }}</label>

          <div v-if="lockedOrganizations.length" class="locked-orgs">
            <Tag
              v-for="org in lockedOrganizations"
              :key="org.id"
              severity="secondary"
              :value="org.label"
              :title="t('personas.organizacionFueraAlcance')"
            />
            <small class="pj-muted">{{ t('personas.organizacionFueraAlcanceHint') }}</small>
          </div>

          <MultiSelect
            v-model="form.organizacion_ids"
            :options="clubOrgOptions"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            :disabled="clubFieldLocked"
            :placeholder="orgFieldPlaceholder"
            class="w-full"
          />
          <small class="pj-muted">{{ orgFieldHint }}</small>
        </div>
      </div>

      <div class="form-actions">
        <Button
          type="button"
          :label="t('common.cancel')"
          text
          @click="router.push({ name: listRouteName })"
        />
        <Button type="submit" :label="t('common.save')" :loading="saving" />
      </div>
    </form>
  </section>
</template>

<style scoped>
.form-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.span-2 {
  grid-column: span 2;
}

.locked-orgs {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.35rem;
}

.locked-orgs small {
  flex-basis: 100%;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

@media (max-width: 720px) {
  .form-grid {
    grid-template-columns: 1fr;
  }

  .span-2 {
    grid-column: span 1;
  }
}
</style>
