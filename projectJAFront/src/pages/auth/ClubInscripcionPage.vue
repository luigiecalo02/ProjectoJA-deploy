<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import RadioButton from 'primevue/radiobutton'
import Message from 'primevue/message'
import { getApiErrorMessage } from '@/services/api'
import {
  clubInscripcionService,
  type CatalogClub,
  type CatalogOrg,
  type PublicFormOptions,
  type UbicacionOption,
} from '@/services/clubInscripcionService'
import { TIPO_ASOCIACION, TIPO_DISTRITO, TIPO_IGLESIA, TIPO_UNION } from '@/modules/organizaciones/types'
import { evaluatePasswordStrength, PASSWORD_MAX_LENGTH } from '@/utils/passwordStrength'

type Cargo = 'director' | 'subdirector' | 'secretaria' | 'tesorero'
type ClubTipo = 'conquistadores' | 'aventureros' | 'guias_mayores'

const { t } = useI18n()
const router = useRouter()

const step = ref(1)
const submitted = ref(false)
const loading = ref(false)
const errorMessage = ref('')

const flags = ref<PublicFormOptions>({
  enabled: true,
  allow_request_asociacion: true,
  allow_request_distrito: true,
  allow_request_iglesia: true,
  allow_request_club: true,
})
const optionsReady = ref(false)

const uniones = ref<CatalogOrg[]>([])
const asociaciones = ref<CatalogOrg[]>([])
const distritos = ref<CatalogOrg[]>([])
const iglesias = ref<CatalogOrg[]>([])
const clubes = ref<CatalogClub[]>([])
const departamentosUnion = ref<UbicacionOption[]>([])
const ciudades = ref<UbicacionOption[]>([])
const ciudadesDistrito = ref<UbicacionOption[]>([])

const form = reactive({
  asociacion_id: null as number | null,
  distrito_id: null as number | null,
  iglesia_id: null as number | null,
  club_id: null as number | null,
  solicitarAsociacion: false,
  solicitarDistrito: false,
  solicitarIglesia: false,
  solicitarClub: false,
  solicitud_asociacion: {
    union_id: null as number | null,
    nombre: '',
    departamento_ids: [] as number[],
  },
  solicitud_distrito: {
    nombre: '',
    departamento_ids: [] as number[],
    ciudad_ids: [] as number[],
  },
  solicitud_iglesia: {
    nombre: '',
    direccion: '',
    departamento_id: null as number | null,
    ciudad_id: null as number | null,
    telefono: '',
    correo: '',
  },
  club: {
    nombre: '',
    nombre_corto: '',
    tipo: null as ClubTipo | null,
  },
  usuario: {
    cargo: null as Cargo | null,
    email: '',
    password: '',
    password_confirmation: '',
    persona: {
      tipo_identificacion: 'CC',
      identificacion: '',
      nombre1: '',
      nombre2: '',
      apellido1: '',
      apellido2: '',
      telefono: '',
    },
  },
})

const clubTipos = computed(() => [
  { value: 'conquistadores' as ClubTipo, label: t('clubs.typeConquistadores') },
  { value: 'aventureros' as ClubTipo, label: t('clubs.typeAventureros') },
  { value: 'guias_mayores' as ClubTipo, label: t('clubs.typeGuias') },
])

const cargos = computed(() => [
  { value: 'director' as Cargo, label: t('clubs.boardDirector') },
  { value: 'subdirector' as Cargo, label: t('clubs.boardSubdirector') },
  { value: 'secretaria' as Cargo, label: t('clubs.boardSecretaria') },
  { value: 'tesorero' as Cargo, label: t('clubs.boardTesorero') },
])

const passwordStrength = computed(() => evaluatePasswordStrength(form.usuario.password))
const passwordLevelLabel = computed(() => {
  const labels = {
    mala: t('validation.passwordLevelMala'),
    facil: t('validation.passwordLevelFacil'),
    media: t('validation.passwordLevelMedia'),
    dificil: t('validation.passwordLevelDificil'),
  }
  return labels[passwordStrength.value.level]
})
const selectedAsociacion = computed(() => asociaciones.value.find((o) => o.id === form.asociacion_id) ?? null)
const selectedDistrito = computed(() => distritos.value.find((o) => o.id === form.distrito_id) ?? null)
const selectedClub = computed(() => clubes.value.find((o) => o.id === form.club_id) ?? null)
const selectedUnion = computed(
  () => uniones.value.find((o) => o.id === form.solicitud_asociacion.union_id) ?? uniones.value[0] ?? null,
)

const coberturaAsociacionIds = computed(() => {
  if (form.solicitarAsociacion) return form.solicitud_asociacion.departamento_ids
  return selectedAsociacion.value?.departamento_ids ?? []
})

const departamentosAsociacion = computed(() =>
  departamentosUnion.value.filter((item) => coberturaAsociacionIds.value.includes(item.id)),
)

const coberturaDistritoIds = computed(() => {
  if (form.solicitarDistrito) return form.solicitud_distrito.departamento_ids
  const ids = selectedDistrito.value?.departamento_ids ?? []
  if (ids.length) return ids
  return selectedDistrito.value?.departamento_id ? [selectedDistrito.value.departamento_id] : []
})

const departamentosDistrito = computed(() =>
  departamentosUnion.value.filter((item) => coberturaDistritoIds.value.includes(item.id)),
)

const coberturaCiudadIds = computed(() => {
  if (form.solicitarDistrito) return form.solicitud_distrito.ciudad_ids
  const ids = selectedDistrito.value?.ciudad_ids ?? []
  if (ids.length) return ids
  return selectedDistrito.value?.ciudad_id ? [selectedDistrito.value.ciudad_id] : []
})

const ciudadesIglesia = computed(() => {
  if (!coberturaCiudadIds.value.length) return ciudades.value
  return ciudades.value.filter((item) => coberturaCiudadIds.value.includes(item.id))
})

const showIglesiaDepartamento = computed(() => coberturaDistritoIds.value.length > 1)
const showIglesiaCiudad = computed(() => coberturaCiudadIds.value.length > 1)

const cargosOcupados = computed(() => selectedClub.value?.cargos_ocupados ?? [])
const cargosOcupadosIds = computed(() => cargosOcupados.value.map((item) => item.cargo))

function nombreCargoOcupado(cargo: Cargo): string {
  return cargosOcupados.value.find((item) => item.cargo === cargo)?.nombre?.trim() || ''
}

const asociacionOk = computed(() =>
  Boolean(
    form.asociacion_id || (
      form.solicitarAsociacion
      && form.solicitud_asociacion.nombre.trim()
      && form.solicitud_asociacion.departamento_ids.length > 0
    ),
  ),
)

const distritoOk = computed(() =>
  Boolean(
    form.distrito_id || (
      form.solicitarDistrito
      && form.solicitud_distrito.nombre.trim()
      && form.solicitud_distrito.departamento_ids.length > 0
      && form.solicitud_distrito.ciudad_ids.length > 0
    ),
  ),
)

const iglesiaOk = computed(() =>
  Boolean(
    form.iglesia_id || (
      form.solicitarIglesia
      && form.solicitud_iglesia.nombre.trim()
      && form.solicitud_iglesia.direccion.trim()
      && (!showIglesiaDepartamento.value || form.solicitud_iglesia.departamento_id)
      && (!showIglesiaCiudad.value || form.solicitud_iglesia.ciudad_id)
    ),
  ),
)

const canStep1 = computed(() => asociacionOk.value && distritoOk.value && iglesiaOk.value)

const step1ErrorMessage = computed(() => {
  if (!asociacionOk.value) return t('clubInscripcion.step1AsociacionError')
  if (!distritoOk.value) return t('clubInscripcion.step1DistritoError')
  if (form.solicitarIglesia) {
    if (!form.solicitud_iglesia.nombre.trim()) return t('clubInscripcion.step1IglesiaNombreError')
    if (showIglesiaDepartamento.value && !form.solicitud_iglesia.departamento_id) {
      return t('clubInscripcion.step1IglesiaDepartamentoError')
    }
    if (showIglesiaCiudad.value && !form.solicitud_iglesia.ciudad_id) {
      return t('clubInscripcion.step1IglesiaCiudadError')
    }
    if (!form.solicitud_iglesia.direccion.trim()) return t('clubInscripcion.step1IglesiaDireccionError')
  }
  if (!form.iglesia_id) return t('clubInscripcion.step1IglesiaError')
  return t('clubInscripcion.step1Error')
})

const canStep2 = computed(() => {
  if (form.solicitarClub) {
    return Boolean(form.club.nombre.trim() && form.club.tipo)
  }
  return Boolean(form.club_id)
})

const passwordError = computed(() => {
  if (!form.usuario.password) return t('clubInscripcion.passwordRequired')
  if (!passwordStrength.value.canSave) return t('validation.passwordIncomplete')
  if (form.usuario.password !== form.usuario.password_confirmation) return t('validation.passwordMatch')
  return ''
})

const canStep3 = computed(() =>
  Boolean(
    form.usuario.cargo
    && !cargosOcupadosIds.value.includes(form.usuario.cargo)
    && form.usuario.email.trim()
    && !passwordError.value
    && form.usuario.persona.identificacion.trim()
    && form.usuario.persona.nombre1.trim()
    && form.usuario.persona.apellido1.trim(),
  ),
)

async function loadDepartamentosUnion(paisId?: number | null): Promise<void> {
  departamentosUnion.value = paisId ? await clubInscripcionService.departamentos(paisId) : []
}

async function loadAsociaciones(): Promise<void> {
  asociaciones.value = await clubInscripcionService.catalog(TIPO_ASOCIACION)
}

async function loadDistritos(padreId: number): Promise<void> {
  distritos.value = await clubInscripcionService.catalog(TIPO_DISTRITO, padreId)
}

async function loadIglesias(padreId: number): Promise<void> {
  iglesias.value = await clubInscripcionService.catalog(TIPO_IGLESIA, padreId)
}

async function loadClubes(iglesiaId: number): Promise<void> {
  clubes.value = await clubInscripcionService.catalogClubes(iglesiaId)
}

onMounted(async () => {
  try {
    flags.value = await clubInscripcionService.options()
    optionsReady.value = true
    if (!flags.value.enabled) {
      return
    }
    uniones.value = await clubInscripcionService.catalog(TIPO_UNION)
    if (uniones.value[0] && !form.solicitud_asociacion.union_id) {
      form.solicitud_asociacion.union_id = uniones.value[0].id
    }
    await loadDepartamentosUnion(selectedUnion.value?.pais_id)
    await loadAsociaciones()
    form.solicitarClub = false
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    optionsReady.value = true
  }
})

watch(
  () => form.asociacion_id,
  async (id) => {
    form.distrito_id = null
    form.iglesia_id = null
    form.club_id = null
    distritos.value = []
    iglesias.value = []
    clubes.value = []
    if (id) await loadDistritos(id)
  },
)

watch(
  () => form.distrito_id,
  async (id) => {
    form.iglesia_id = null
    form.club_id = null
    iglesias.value = []
    clubes.value = []
    if (id) await loadIglesias(id)
  },
)

watch(
  () => form.iglesia_id,
  async (id) => {
    form.club_id = null
    clubes.value = []
    if (!id) {
      form.solicitarClub = flags.value.allow_request_club
      return
    }
    await loadClubes(id)
    if (clubes.value.length) {
      form.solicitarClub = false
      if (clubes.value.length === 1) form.club_id = clubes.value[0].id
      return
    }
    form.solicitarClub = flags.value.allow_request_club
  },
)

watch(
  () => form.solicitud_asociacion.union_id,
  async (id) => {
    const union = uniones.value.find((o) => o.id === id)
    form.solicitud_asociacion.departamento_ids = []
    await loadDepartamentosUnion(union?.pais_id)
  },
)

watch(
  () => form.solicitarIglesia,
  (solicitar) => {
    if (solicitar) {
      form.solicitarClub = flags.value.allow_request_club
      form.club_id = null
      clubes.value = []
    }
  },
)

watch(
  () => [...form.solicitud_distrito.departamento_ids],
  async (ids) => {
    ciudadesDistrito.value = ids.length ? await clubInscripcionService.ciudades(null, ids) : []
    const valid = new Set(ciudadesDistrito.value.map((item) => item.id))
    form.solicitud_distrito.ciudad_ids = form.solicitud_distrito.ciudad_ids.filter((id) => valid.has(id))
  },
)

watch(
  () => [...coberturaDistritoIds.value],
  (ids) => {
    if (ids.length === 1) {
      form.solicitud_iglesia.departamento_id = ids[0]
    } else if (!ids.includes(form.solicitud_iglesia.departamento_id ?? 0)) {
      form.solicitud_iglesia.departamento_id = null
    }
  },
)

watch(
  () => [...coberturaCiudadIds.value],
  (ids) => {
    if (ids.length === 1) {
      form.solicitud_iglesia.ciudad_id = ids[0]
    } else if (!ids.includes(form.solicitud_iglesia.ciudad_id ?? 0)) {
      form.solicitud_iglesia.ciudad_id = null
    }
  },
)

watch(
  () => form.solicitud_iglesia.departamento_id,
  async (id) => {
    if (coberturaCiudadIds.value.length !== 1) {
      form.solicitud_iglesia.ciudad_id = null
    }
    ciudades.value = id ? await clubInscripcionService.ciudades(id) : []
    if (coberturaCiudadIds.value.length === 1) {
      form.solicitud_iglesia.ciudad_id = coberturaCiudadIds.value[0]
    }
  },
)

async function toggleSolicitud(kind: 'asociacion' | 'distrito' | 'iglesia' | 'club'): Promise<void> {
  if (kind === 'asociacion' && flags.value.allow_request_asociacion) {
    form.solicitarAsociacion = !form.solicitarAsociacion
    form.asociacion_id = null
    form.distrito_id = null
    form.iglesia_id = null
    form.club_id = null
    form.solicitud_asociacion.nombre = ''
    form.solicitud_asociacion.departamento_ids = []
    form.solicitarDistrito = form.solicitarAsociacion && flags.value.allow_request_distrito ? true : form.solicitarDistrito
    form.solicitarIglesia = form.solicitarAsociacion && flags.value.allow_request_iglesia ? true : form.solicitarIglesia
    await loadDepartamentosUnion(selectedUnion.value?.pais_id)
  }
  if (kind === 'distrito' && flags.value.allow_request_distrito) {
    form.solicitarDistrito = !form.solicitarDistrito
    form.distrito_id = null
    form.iglesia_id = null
    form.club_id = null
    form.solicitud_distrito.nombre = ''
    form.solicitud_distrito.departamento_ids = []
    form.solicitud_distrito.ciudad_ids = []
    ciudadesDistrito.value = []
    if (form.solicitarDistrito && flags.value.allow_request_iglesia) form.solicitarIglesia = true
  }
  if (kind === 'iglesia' && flags.value.allow_request_iglesia) {
    form.solicitarIglesia = !form.solicitarIglesia
    form.iglesia_id = null
    form.club_id = null
    form.solicitud_iglesia.nombre = ''
    form.solicitud_iglesia.direccion = ''
    form.solicitud_iglesia.departamento_id = null
    form.solicitud_iglesia.ciudad_id = null
  }
  if (kind === 'club' && flags.value.allow_request_club) {
    form.solicitarClub = !form.solicitarClub
    form.club_id = null
    form.usuario.cargo = null
    if (!form.solicitarClub && form.iglesia_id) {
      await loadClubes(form.iglesia_id)
    }
  }
}

function goNext(): void {
  errorMessage.value = ''
  if (step.value === 1 && !canStep1.value) {
    errorMessage.value = step1ErrorMessage.value
    return
  }
  if (step.value === 2 && !canStep2.value) {
    errorMessage.value = (!form.solicitarClub && !clubes.value.length)
      ? t('clubInscripcion.step2NoClubs')
      : t('clubInscripcion.step2Error')
    return
  }
  step.value += 1
}

async function submit(): Promise<void> {
  if (passwordError.value) {
    errorMessage.value = passwordError.value
    return
  }
  if (form.usuario.cargo && cargosOcupadosIds.value.includes(form.usuario.cargo)) {
    errorMessage.value = t('clubInscripcion.cargoTaken')
    return
  }
  if (!canStep3.value || !form.usuario.cargo) {
    errorMessage.value = t('clubInscripcion.step3Error')
    return
  }
  loading.value = true
  errorMessage.value = ''
  try {
    await clubInscripcionService.register({
      asociacion_id: form.solicitarAsociacion ? null : form.asociacion_id,
      distrito_id: form.solicitarDistrito ? null : form.distrito_id,
      iglesia_id: form.solicitarIglesia ? null : form.iglesia_id,
      club_id: form.solicitarClub ? null : form.club_id,
      solicitud_asociacion: form.solicitarAsociacion
        ? {
            union_id: form.solicitud_asociacion.union_id,
            nombre: form.solicitud_asociacion.nombre.trim(),
            departamento_ids: form.solicitud_asociacion.departamento_ids,
          }
        : null,
      solicitud_distrito: form.solicitarDistrito
        ? {
            nombre: form.solicitud_distrito.nombre.trim(),
            departamento_ids: form.solicitud_distrito.departamento_ids,
            ciudad_ids: form.solicitud_distrito.ciudad_ids,
          }
        : null,
      solicitud_iglesia: form.solicitarIglesia
        ? {
            nombre: form.solicitud_iglesia.nombre.trim(),
            direccion: form.solicitud_iglesia.direccion.trim(),
            departamento_id: form.solicitud_iglesia.departamento_id
              ?? (coberturaDistritoIds.value.length === 1 ? coberturaDistritoIds.value[0] : null),
            ciudad_id: form.solicitud_iglesia.ciudad_id
              ?? (coberturaCiudadIds.value.length === 1 ? coberturaCiudadIds.value[0] : null),
            telefono: form.solicitud_iglesia.telefono.trim() || null,
            correo: form.solicitud_iglesia.correo.trim() || null,
          }
        : null,
      club: form.solicitarClub && form.club.tipo
        ? {
            nombre: form.club.nombre.trim(),
            nombre_corto: form.club.nombre_corto.trim() || null,
            tipo: form.club.tipo,
          }
        : null,
      usuario: {
        cargo: form.usuario.cargo,
        email: form.usuario.email.trim(),
        password: form.usuario.password,
        password_confirmation: form.usuario.password_confirmation,
        persona: {
          tipo_identificacion: form.usuario.persona.tipo_identificacion,
          identificacion: form.usuario.persona.identificacion.trim(),
          nombre1: form.usuario.persona.nombre1.trim(),
          nombre2: form.usuario.persona.nombre2.trim() || null,
          apellido1: form.usuario.persona.apellido1.trim(),
          apellido2: form.usuario.persona.apellido2.trim() || null,
          telefono: form.usuario.persona.telefono.trim() || null,
        },
      },
    })
    await router.push({ name: 'auth.verify', query: { email: form.usuario.email.trim() } })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div v-if="optionsReady && !flags.enabled" class="inscripcion">
    <header class="inscripcion__head">
      <p class="inscripcion__kicker">{{ t('clubInscripcion.kicker') }}</p>
      <h1>{{ t('clubInscripcion.disabledTitle') }}</h1>
      <p>{{ t('clubInscripcion.disabledSubtitle') }}</p>
    </header>
    <Message severity="warn" :closable="false">{{ t('clubInscripcion.disabledMessage') }}</Message>
    <Button type="button" :label="t('clubInscripcion.backLogin')" @click="router.push({ name: 'login' })" />
  </div>
  <div v-else-if="submitted" class="inscripcion">
    <header class="inscripcion__head">
      <p class="inscripcion__kicker">{{ t('clubInscripcion.kicker') }}</p>
      <h1>{{ t('clubInscripcion.pendingTitle') }}</h1>
      <p>{{ t('clubInscripcion.pendingSubtitle') }}</p>
    </header>
    <Button type="button" :label="t('clubInscripcion.backLogin')" @click="router.push({ name: 'login' })" />
  </div>
  <form v-else-if="optionsReady" class="inscripcion" @submit.prevent="step === 3 ? submit() : goNext()">
    <header class="inscripcion__head">
      <p class="inscripcion__kicker">{{ t('clubInscripcion.kicker') }}</p>
      <h1>{{ t('clubInscripcion.title') }}</h1>
      <p>{{ t('clubInscripcion.subtitle') }}</p>
      <ol class="inscripcion__steps">
        <li :class="{ 'is-active': step === 1, 'is-done': step > 1 }">{{ t('clubInscripcion.stepOrg') }}</li>
        <li :class="{ 'is-active': step === 2, 'is-done': step > 2 }">{{ t('clubInscripcion.stepClub') }}</li>
        <li :class="{ 'is-active': step === 3 }">{{ t('clubInscripcion.stepUser') }}</li>
      </ol>
    </header>

    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

    <section v-if="step === 1" class="inscripcion__block">
      <div class="field">
        <div class="field__head">
          <label>{{ t('clubInscripcion.asociacion') }}</label>
          <Button
            v-if="flags.allow_request_asociacion"
            type="button"
            text
            size="small"
            :label="form.solicitarAsociacion ? t('clubInscripcion.useExisting') : t('clubInscripcion.requestMissing')"
            @click="toggleSolicitud('asociacion')"
          />
        </div>
        <Select
          v-if="!form.solicitarAsociacion"
          v-model="form.asociacion_id"
          :options="asociaciones"
          option-label="nombre"
          option-value="id"
          filter
          fluid
          :placeholder="t('clubInscripcion.asociacionPlaceholder')"
        />
        <div v-else class="solicitud">
          <p class="pj-muted">{{ t('clubInscripcion.requestAsociacionHint') }}</p>
          <Select
            v-if="uniones.length > 1"
            v-model="form.solicitud_asociacion.union_id"
            :options="uniones"
            option-label="nombre"
            option-value="id"
            fluid
            :placeholder="t('clubInscripcion.unionPlaceholder')"
          />
          <InputText v-model="form.solicitud_asociacion.nombre" :placeholder="t('clubInscripcion.asociacionNombre')" fluid />
          <MultiSelect
            v-model="form.solicitud_asociacion.departamento_ids"
            :options="departamentosUnion"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            fluid
            :placeholder="t('clubInscripcion.departamentos')"
          />
        </div>
      </div>

      <div class="field">
        <div class="field__head">
          <label>{{ t('clubInscripcion.distrito') }}</label>
          <Button
            v-if="flags.allow_request_distrito"
            type="button"
            text
            size="small"
            :label="form.solicitarDistrito ? t('clubInscripcion.useExisting') : t('clubInscripcion.requestMissing')"
            @click="toggleSolicitud('distrito')"
          />
        </div>
        <Select
          v-if="!form.solicitarDistrito"
          v-model="form.distrito_id"
          :options="distritos"
          option-label="nombre"
          option-value="id"
          filter
          fluid
          :disabled="!form.asociacion_id && !form.solicitarAsociacion"
          :placeholder="t('clubInscripcion.distritoPlaceholder')"
        />
        <div v-else class="solicitud">
          <p class="pj-muted">{{ t('clubInscripcion.requestDistritoHint') }}</p>
          <InputText v-model="form.solicitud_distrito.nombre" :placeholder="t('clubInscripcion.distritoNombre')" fluid />
          <MultiSelect
            v-model="form.solicitud_distrito.departamento_ids"
            :options="departamentosAsociacion"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            fluid
            :disabled="!coberturaAsociacionIds.length"
            :placeholder="t('clubInscripcion.departamentos')"
          />
          <MultiSelect
            v-model="form.solicitud_distrito.ciudad_ids"
            :options="ciudadesDistrito"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            fluid
            :disabled="!form.solicitud_distrito.departamento_ids.length"
            :placeholder="t('clubInscripcion.ciudades')"
          />
        </div>
      </div>

      <div class="field">
        <div class="field__head">
          <label>{{ t('clubInscripcion.iglesia') }}</label>
          <Button
            v-if="flags.allow_request_iglesia"
            type="button"
            text
            size="small"
            :label="form.solicitarIglesia ? t('clubInscripcion.useExisting') : t('clubInscripcion.requestMissing')"
            @click="toggleSolicitud('iglesia')"
          />
        </div>
        <Select
          v-if="!form.solicitarIglesia"
          v-model="form.iglesia_id"
          :options="iglesias"
          option-label="nombre"
          option-value="id"
          filter
          fluid
          :disabled="!form.distrito_id && !form.solicitarDistrito"
          :placeholder="t('clubInscripcion.iglesiaPlaceholder')"
        />
        <div v-else class="solicitud">
          <p class="pj-muted">{{ t('clubInscripcion.requestIglesiaHint') }}</p>
          <InputText v-model="form.solicitud_iglesia.nombre" :placeholder="t('clubInscripcion.iglesiaNombre')" fluid />
          <Select
            v-if="showIglesiaDepartamento"
            v-model="form.solicitud_iglesia.departamento_id"
            :options="departamentosDistrito"
            option-label="label"
            option-value="id"
            fluid
            :disabled="!coberturaDistritoIds.length"
            :placeholder="t('clubInscripcion.departamento')"
          />
          <Select
            v-if="showIglesiaCiudad"
            v-model="form.solicitud_iglesia.ciudad_id"
            :options="ciudadesIglesia"
            option-label="label"
            option-value="id"
            fluid
            :disabled="!form.solicitud_iglesia.departamento_id && showIglesiaDepartamento"
            :placeholder="t('clubInscripcion.ciudad')"
          />
          <InputText v-model="form.solicitud_iglesia.direccion" :placeholder="t('clubInscripcion.direccion')" fluid required />
          <InputText v-model="form.solicitud_iglesia.telefono" :placeholder="t('clubInscripcion.telefono')" fluid />
        </div>
      </div>
    </section>

    <section v-else-if="step === 2" class="inscripcion__block">
      <div class="field">
        <div class="field__head">
          <label>{{ t('clubs.name') }}</label>
          <Button
            v-if="flags.allow_request_club && !form.solicitarIglesia && (clubes.length > 0 || form.solicitarClub)"
            type="button"
            text
            size="small"
            :label="form.solicitarClub ? t('clubInscripcion.useExistingClub') : t('clubInscripcion.requestClub')"
            @click="toggleSolicitud('club')"
          />
        </div>
        <Select
          v-if="!form.solicitarClub"
          v-model="form.club_id"
          :options="clubes"
          option-label="nombre"
          option-value="id"
          filter
          fluid
          :disabled="!form.iglesia_id || !clubes.length"
          :placeholder="clubes.length ? t('clubInscripcion.clubPlaceholder') : t('clubInscripcion.step2NoClubs')"
        />
        <div v-else class="solicitud">
          <InputText v-model="form.club.nombre" :placeholder="t('clubs.name')" fluid required />
          <InputText v-model="form.club.nombre_corto" :placeholder="t('clubs.nombreCorto')" fluid />
          <label>{{ t('clubs.types') }}</label>
          <div class="tipos">
            <label v-for="opt in clubTipos" :key="opt.value" class="tipo">
              <RadioButton v-model="form.club.tipo" :value="opt.value" :input-id="`tipo-${opt.value}`" />
              <span>{{ opt.label }}</span>
            </label>
          </div>
        </div>
      </div>
    </section>

    <section v-else class="inscripcion__block">
      <div class="field">
        <label>{{ t('clubInscripcion.cargo') }}</label>
        <div class="tipos">
          <label v-for="opt in cargos" :key="opt.value" class="tipo">
            <RadioButton
              v-model="form.usuario.cargo"
              :value="opt.value"
              :input-id="`cargo-${opt.value}`"
              :disabled="cargosOcupadosIds.includes(opt.value)"
            />
            <span>
              {{ opt.label }}
              <small v-if="cargosOcupadosIds.includes(opt.value)" class="taken">
                {{ nombreCargoOcupado(opt.value)
                  ? t('clubInscripcion.cargoTakenBy', { name: nombreCargoOcupado(opt.value) })
                  : t('clubInscripcion.cargoTakenShort') }}
              </small>
            </span>
          </label>
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label>{{ t('auth.registrationIdType') }}</label>
          <select v-model="form.usuario.persona.tipo_identificacion" class="native-select">
            <option value="CC">CC</option>
            <option value="TI">TI</option>
            <option value="CE">CE</option>
            <option value="PASAPORTE">Pasaporte</option>
          </select>
        </div>
        <div class="field">
          <label>{{ t('auth.registrationIdNumber') }}</label>
          <InputText v-model="form.usuario.persona.identificacion" fluid required />
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label>{{ t('clubInscripcion.nombre1') }}</label>
          <InputText v-model="form.usuario.persona.nombre1" fluid required />
        </div>
        <div class="field">
          <label>{{ t('clubInscripcion.apellido1') }}</label>
          <InputText v-model="form.usuario.persona.apellido1" fluid required />
        </div>
      </div>
      <div class="field">
        <label>{{ t('auth.email') }}</label>
        <InputText v-model="form.usuario.email" type="email" fluid required />
      </div>
      <div class="grid-2">
        <div class="field">
          <label>{{ t('auth.password') }}</label>
          <Password v-model="form.usuario.password" :feedback="false" toggle-mask fluid :maxlength="PASSWORD_MAX_LENGTH" />
        </div>
        <div class="field">
          <label>{{ t('clubInscripcion.passwordConfirm') }}</label>
          <Password v-model="form.usuario.password_confirmation" :feedback="false" toggle-mask fluid />
        </div>
      </div>
      <div class="password-strength" aria-live="polite">
        <div class="password-strength__track">
          <span class="password-strength__fill" :class="`is-${passwordStrength.level}`" :style="{ width: form.usuario.password ? (passwordStrength.level === 'dificil' ? '100%' : passwordStrength.level === 'media' ? '70%' : passwordStrength.level === 'facil' ? '45%' : '25%') : '0' }" />
        </div>
        <strong :class="`is-${passwordStrength.level}`">{{ passwordLevelLabel }}</strong>
        <small class="pj-muted">{{ t('validation.passwordStrong') }}</small>
        <small v-if="form.usuario.password_confirmation" :class="form.usuario.password === form.usuario.password_confirmation ? 'ok' : 'bad'">
          {{ form.usuario.password === form.usuario.password_confirmation ? t('validation.passwordRuleMatch') : t('validation.passwordMatch') }}
        </small>
      </div>
    </section>

    <footer class="inscripcion__actions">
      <Button v-if="step > 1" type="button" text :label="t('common.back')" @click="step -= 1" />
      <Button v-else type="button" text :label="t('clubInscripcion.backLogin')" @click="router.push({ name: 'login' })" />
      <Button
        type="submit"
        :label="step === 3 ? t('clubInscripcion.submit') : t('clubInscripcion.next')"
        :loading="loading"
      />
    </footer>
  </form>
</template>

<style scoped>
.inscripcion { display: flex; flex-direction: column; gap: 1rem; }
.inscripcion__head h1 { margin: 0.15rem 0; font-size: 1.45rem; }
.inscripcion__head p { margin: 0; color: var(--pj-text-muted); font-size: 0.88rem; }
.inscripcion__kicker { font-weight: 700; color: var(--pj-navy); font-size: 0.75rem; letter-spacing: 0.04em; text-transform: uppercase; }
.inscripcion__steps {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.35rem;
  padding: 0;
  margin: 0.85rem 0 0;
}
.inscripcion__steps li {
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.4rem 0.45rem;
  border-radius: 8px;
  background: var(--pj-bg-muted);
  color: var(--pj-text-muted);
  text-align: center;
}
.inscripcion__steps li.is-active { background: var(--pj-primary-soft); color: var(--pj-navy); }
.inscripcion__steps li.is-done { color: var(--pj-success); }
.inscripcion__block,
.solicitud { display: flex; flex-direction: column; gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field__head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.field label { font-weight: 600; font-size: 0.82rem; }
.tipos { display: flex; flex-direction: column; gap: 0.4rem; }
.tipo { display: flex; align-items: center; gap: 0.5rem; }
.taken { color: var(--pj-danger, #ed1c24); margin-left: 0.35rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; }
.native-select {
  width: 100%;
  min-height: 2.6rem;
  border-radius: 8px;
  border: 1px solid var(--pj-border);
  background: var(--pj-bg-elevated);
  color: inherit;
  padding: 0 0.7rem;
}
.password-strength { display: flex; flex-direction: column; gap: 0.25rem; }
.password-strength__track {
  height: 0.4rem;
  border-radius: 999px;
  background: var(--pj-bg-muted);
  overflow: hidden;
}
.password-strength__fill { display: block; height: 100%; transition: width 0.2s ease; }
.password-strength__fill.is-mala { background: #dc2626; }
.password-strength__fill.is-facil { background: #ea580c; }
.password-strength__fill.is-media { background: #ca8a04; }
.password-strength__fill.is-dificil { background: #15803d; }
.password-strength strong.is-mala { color: #dc2626; }
.password-strength strong.is-facil { color: #ea580c; }
.password-strength strong.is-media { color: #ca8a04; }
.password-strength strong.is-dificil { color: #15803d; }
.ok { color: #15803d; }
.bad { color: #dc2626; }
.inscripcion__actions { display: flex; justify-content: space-between; gap: 0.6rem; }
@media (max-width: 640px) {
  .grid-2 { grid-template-columns: 1fr; }
}
</style>
