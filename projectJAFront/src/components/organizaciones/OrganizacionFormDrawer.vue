<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import { organizacionesService } from '@/services/organizacionesService'
import { getApiErrorMessage } from '@/services/api'
import type {
  CiudadOption,
  DepartamentoOption,
  Organizacion,
  OrganizacionParentOption,
  PaisOption,
  TipoOrganizacion,
} from '@/modules/organizaciones/types'
import {
  TIPO_ASOCIACION,
  TIPO_CLUB,
  TIPO_DISTRITO,
  TIPO_IGLESIA,
  TIPO_UNION,
  TIPOS_HEREDAN_UBICACION_COMPLETA,
} from '@/modules/organizaciones/types'

export type OrgDrawerMode = 'create' | 'edit'

const props = defineProps<{
  visible: boolean
  mode: OrgDrawerMode
  orgId?: number | null
  /** Prefills al agregar desde el árbol */
  parentId?: number | null
  parentTipoId?: number | null
  parentNombre?: string | null
  lockParentAndTipo?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  saved: [payload: { mode: OrgDrawerMode; organizacion: Organizacion }]
}>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const tipos = ref<TipoOrganizacion[]>([])
const parentOptions = ref<OrganizacionParentOption[]>([])
const paises = ref<PaisOption[]>([])
const departamentos = ref<DepartamentoOption[]>([])
const ciudades = ref<CiudadOption[]>([])

const form = reactive({
  organizacion_padre_id: null as number | null,
  tipo_organizacion_id: null as number | null,
  pais_id: null as number | null,
  pais_nombre: '',
  departamento_id: null as number | null,
  departamento_nombre: '',
  departamento_ids: [] as number[],
  ciudad_ids: [] as number[],
  ciudad_id: null as number | null,
  ciudad_nombre: '',
  nombre: '',
  direccion: '',
  telefono: '',
  correo: '',
  estado: true,
})

const drawerVisible = computed({
  get: () => props.visible,
  set: (value: boolean) => emit('update:visible', value),
})

const selectedTipo = computed(() =>
  tipos.value.find((tipo) => tipo.id === form.tipo_organizacion_id) ?? null,
)

const childTipoOptions = computed(() => {
  if (!props.parentTipoId) return tipos.value
  return tipos.value.filter((tipo) => tipo.tipo_organizacion_padre_id === props.parentTipoId)
})

const tipoSelectOptions = computed(() => {
  const source = props.lockParentAndTipo ? childTipoOptions.value : tipos.value
  return source.map((tipo) => ({ label: tipo.nombre, value: tipo.id }))
})

const tipoLocked = computed(
  () => props.mode === 'edit' || (!!props.lockParentAndTipo && childTipoOptions.value.length === 1),
)

/** Padre bloqueado solo al crear hijo desde el árbol (padre prefijado). En edición se puede cambiar. */
const parentLocked = computed(() => !!props.lockParentAndTipo && props.mode !== 'edit')

const isRootTipo = computed(
  () => !!form.tipo_organizacion_id && selectedTipo.value?.tipo_organizacion_padre_id == null,
)

const selectedParent = computed(() =>
  parentOptions.value.find((o) => o.id === form.organizacion_padre_id) ?? null,
)

const showPaisField = computed(() => form.tipo_organizacion_id === TIPO_UNION)
const showDepartamentosMultiField = computed(
  () => form.tipo_organizacion_id === TIPO_ASOCIACION || form.tipo_organizacion_id === TIPO_DISTRITO,
)
const showCiudadesMultiField = computed(() => form.tipo_organizacion_id === TIPO_DISTRITO)
const showIglesiaUbicacionField = computed(() => form.tipo_organizacion_id === TIPO_IGLESIA)
const showDireccionField = computed(() => form.tipo_organizacion_id === TIPO_IGLESIA)

function isHijoDeClub(tipoId: number | null): boolean {
  if (!tipoId) return false
  const tipo = tipos.value.find((t) => t.id === tipoId)
  return tipo?.tipo_organizacion_padre_id === TIPO_CLUB
}

const showInheritedLocation = computed(() => {
  const tipoId = form.tipo_organizacion_id
  if (!tipoId) return false
  return (
    TIPOS_HEREDAN_UBICACION_COMPLETA.includes(tipoId as (typeof TIPOS_HEREDAN_UBICACION_COMPLETA)[number]) ||
    isHijoDeClub(tipoId)
  )
})

const title = computed(() =>
  props.mode === 'edit' ? t('organizaciones.edit') : t('organizaciones.createTitle'),
)

const subtitle = computed(() => {
  if (props.lockParentAndTipo && props.parentNombre) {
    return t('organizaciones.addChildSubtitle', { parent: props.parentNombre })
  }
  return t('organizaciones.createSubtitle')
})

const estadoOptions = computed(() => [
  { label: t('common.active'), value: true },
  { label: t('common.inactive'), value: false },
])

const parentDepartamentos = computed<DepartamentoOption[]>(() => {
  const deps = selectedParent.value?.departamentos ?? []
  if (deps.length) {
    return deps.map((d) => ({
      ...d,
      label: d.nombre,
    }))
  }
  if (selectedParent.value?.departamento_id && selectedParent.value.departamento_nombre) {
    return [
      {
        id: selectedParent.value.departamento_id,
        pais_id: selectedParent.value.pais_id ?? 0,
        nombre: selectedParent.value.departamento_nombre,
        label: selectedParent.value.departamento_nombre,
      },
    ]
  }
  return []
})

const parentCiudades = computed<CiudadOption[]>(() => {
  const items = selectedParent.value?.ciudades ?? []
  if (items.length) {
    return items.map((ciudad) => ({
      ...ciudad,
      label: ciudad.nombre,
    }))
  }
  if (selectedParent.value?.ciudad_id && selectedParent.value.ciudad_nombre) {
    return [
      {
        id: selectedParent.value.ciudad_id,
        departamento_id: selectedParent.value.departamento_id ?? 0,
        nombre: selectedParent.value.ciudad_nombre,
        label: selectedParent.value.ciudad_nombre,
      },
    ]
  }
  return []
})

const ciudadesIglesia = computed(() => {
  const allowed = parentCiudades.value.map((item) => item.id)
  if (!allowed.length) return ciudades.value
  return ciudades.value.filter((item) => allowed.includes(item.id))
})

const showIglesiaDepartamentoField = computed(
  () => showIglesiaUbicacionField.value && parentDepartamentos.value.length > 1,
)
const showIglesiaCiudadField = computed(
  () => showIglesiaUbicacionField.value && parentCiudades.value.length > 1,
)

function applyIglesiaInheritedLocation(): void {
  if (form.tipo_organizacion_id !== TIPO_IGLESIA) return
  if (parentDepartamentos.value.length === 1) {
    form.departamento_id = parentDepartamentos.value[0].id
  }
  if (parentCiudades.value.length === 1) {
    form.ciudad_id = parentCiudades.value[0].id
  }
}

function resetForm(): void {
  form.organizacion_padre_id = null
  form.tipo_organizacion_id = null
  form.pais_id = null
  form.pais_nombre = ''
  form.departamento_id = null
  form.departamento_nombre = ''
  form.departamento_ids = []
  form.ciudad_ids = []
  form.ciudad_id = null
  form.ciudad_nombre = ''
  form.nombre = ''
  form.direccion = ''
  form.telefono = ''
  form.correo = ''
  form.estado = true
  errorMessage.value = ''
}

async function loadDepartamentos(paisId: number | null | undefined): Promise<void> {
  if (!paisId) {
    departamentos.value = []
    return
  }
  departamentos.value = await organizacionesService.departamentos(paisId)
}

async function loadCiudades(departamentoId: number | null | undefined): Promise<void> {
  if (!departamentoId) {
    ciudades.value = []
    return
  }
  ciudades.value = await organizacionesService.ciudades(departamentoId)
}

async function loadCiudadesByDepartamentos(departamentoIds: number[]): Promise<void> {
  if (!departamentoIds.length) {
    ciudades.value = []
    return
  }
  ciudades.value = await organizacionesService.ciudades(null, departamentoIds)
}

async function refreshParentOptions(): Promise<void> {
  if (!form.tipo_organizacion_id || isRootTipo.value) {
    parentOptions.value = []
    if (!props.lockParentAndTipo) form.organizacion_padre_id = null
    return
  }
  parentOptions.value = await organizacionesService.parentOptions(
    props.mode === 'edit' ? props.orgId ?? undefined : undefined,
    form.tipo_organizacion_id,
  )
  if (
    form.organizacion_padre_id &&
    !parentOptions.value.some((o) => o.id === form.organizacion_padre_id)
  ) {
    form.organizacion_padre_id = null
  }
}

async function onParentChange(parentId: number | null): Promise<void> {
  form.organizacion_padre_id = parentId
  const padre = parentOptions.value.find((o) => o.id === parentId) ?? null
  if (form.tipo_organizacion_id === TIPO_ASOCIACION && padre?.pais_id) {
    form.departamento_ids = []
    await loadDepartamentos(padre.pais_id)
  }
  if (form.tipo_organizacion_id === TIPO_DISTRITO) {
    form.departamento_ids = []
    form.ciudad_ids = []
    form.departamento_id = null
    form.ciudad_id = null
    ciudades.value = []
  }
  if (form.tipo_organizacion_id === TIPO_IGLESIA) {
    form.departamento_id = null
    form.ciudad_id = null
    applyIglesiaInheritedLocation()
    if (form.departamento_id) await loadCiudades(form.departamento_id)
  }
}

async function openCreate(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    resetForm()
    const [tiposData, paisesData] = await Promise.all([
      organizacionesService.tipos(),
      organizacionesService.paises(),
    ])
    tipos.value = tiposData
    paises.value = paisesData

    if (props.lockParentAndTipo && props.parentId) {
      form.organizacion_padre_id = props.parentId
      const children = childTipoOptions.value
      form.tipo_organizacion_id = children.length === 1 ? children[0].id : null
    }

    await refreshParentOptions()
    if (form.organizacion_padre_id && form.tipo_organizacion_id === TIPO_ASOCIACION) {
      const padre = parentOptions.value.find((o) => o.id === form.organizacion_padre_id)
      if (padre?.pais_id) await loadDepartamentos(padre.pais_id)
    }
    applyIglesiaInheritedLocation()
    if (form.tipo_organizacion_id === TIPO_IGLESIA && form.departamento_id) {
      await loadCiudades(form.departamento_id)
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}

async function openEdit(): Promise<void> {
  if (!props.orgId) return
  loading.value = true
  errorMessage.value = ''
  try {
    const [tiposData, paisesData, org] = await Promise.all([
      organizacionesService.tipos(),
      organizacionesService.paises(),
      organizacionesService.get(props.orgId),
    ])
    tipos.value = tiposData
    paises.value = paisesData
    form.organizacion_padre_id = org.organizacion_padre_id
    form.tipo_organizacion_id = org.tipo_organizacion_id
    form.pais_id = org.pais_id
    form.departamento_id = org.departamento_id
    form.ciudad_id = org.ciudad_id
    form.departamento_ids = (org.departamentos ?? []).map((d) => d.id)
    if (!form.departamento_ids.length && org.departamento_id) {
      form.departamento_ids = [org.departamento_id]
    }
    form.ciudad_ids = (org.ciudades ?? []).map((ciudad) => ciudad.id)
    if (!form.ciudad_ids.length && org.ciudad_id) {
      form.ciudad_ids = [org.ciudad_id]
    }
    form.nombre = org.nombre
    form.direccion = org.direccion ?? ''
    form.telefono = org.telefono ?? ''
    form.correo = org.correo ?? ''
    form.estado = org.estado

    await Promise.all([
      refreshParentOptions(),
      org.pais_id ? loadDepartamentos(org.pais_id) : Promise.resolve(),
      org.tipo_organizacion_id === TIPO_DISTRITO
        ? loadCiudadesByDepartamentos(form.departamento_ids)
        : org.departamento_id
          ? loadCiudades(org.departamento_id)
          : Promise.resolve(),
    ])
    applyIglesiaInheritedLocation()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) return
    if (props.mode === 'edit') await openEdit()
    else await openCreate()
  },
)

watch(
  () => [...form.departamento_ids],
  async (ids) => {
    if (form.tipo_organizacion_id !== TIPO_DISTRITO || loading.value) return
    await loadCiudadesByDepartamentos(ids)
    const valid = new Set(ciudades.value.map((item) => item.id))
    form.ciudad_ids = form.ciudad_ids.filter((id) => valid.has(id))
  },
)

watch(
  () => form.departamento_id,
  async (departamentoId) => {
    if (form.tipo_organizacion_id !== TIPO_IGLESIA || loading.value) return
    if (parentCiudades.value.length !== 1) {
      form.ciudad_id = null
    }
    await loadCiudades(departamentoId)
    applyIglesiaInheritedLocation()
  },
)

async function save(): Promise<void> {
  if (!form.tipo_organizacion_id) {
    errorMessage.value = t('organizaciones.typeRequired')
    return
  }
  if (!form.nombre.trim()) {
    errorMessage.value = t('organizaciones.nameRequired')
    return
  }
  if (!isRootTipo.value && !form.organizacion_padre_id) {
    errorMessage.value = t('organizaciones.parentRequired')
    return
  }
  if (showPaisField.value && !form.pais_id && !form.pais_nombre.trim()) {
    errorMessage.value = t('organizaciones.paisRequired')
    return
  }
  if (showDepartamentosMultiField.value && form.departamento_ids.length === 0) {
    errorMessage.value = t('organizaciones.departamentoRequired')
    return
  }
  if (showCiudadesMultiField.value && form.ciudad_ids.length === 0) {
    errorMessage.value = t('organizaciones.ciudadesRequired')
    return
  }
  applyIglesiaInheritedLocation()
  if (showIglesiaDepartamentoField.value && !form.departamento_id) {
    errorMessage.value = t('organizaciones.iglesiaUbicacionRequired')
    return
  }
  if (showIglesiaCiudadField.value && !form.ciudad_id) {
    errorMessage.value = t('organizaciones.iglesiaUbicacionRequired')
    return
  }
  if (showDireccionField.value && !form.direccion.trim()) {
    errorMessage.value = t('organizaciones.addressRequired')
    return
  }

  saving.value = true
  errorMessage.value = ''
  try {
    const payload = {
      organizacion_padre_id: form.organizacion_padre_id,
      tipo_organizacion_id: form.tipo_organizacion_id,
      pais_id: form.pais_id,
      pais_nombre: form.pais_nombre.trim() || null,
      departamento_id: form.departamento_id,
      departamento_nombre: form.departamento_nombre.trim() || null,
      departamento_ids: form.departamento_ids,
      ciudad_ids: form.ciudad_ids,
      ciudad_id: form.ciudad_id,
      ciudad_nombre: form.ciudad_nombre.trim() || null,
      nombre: form.nombre.trim(),
      direccion: showDireccionField.value ? form.direccion.trim() || null : null,
      telefono: form.telefono.trim() || null,
      correo: form.correo.trim() || null,
      estado: form.estado,
    }

    let organizacion: Organizacion
    if (props.mode === 'edit' && props.orgId) {
      organizacion = await organizacionesService.update(props.orgId, payload)
    } else {
      organizacion = await organizacionesService.create(payload)
    }

    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail:
        props.mode === 'edit'
          ? t('organizaciones.updateSuccess')
          : t('organizaciones.createSuccess'),
      life: 2500,
    })
    emit('saved', { mode: props.mode, organizacion })
    drawerVisible.value = false
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <AppStackDrawer
    v-model:visible="drawerVisible"
    :title="title"
    :subtitle="subtitle"
    :level="1"
  >
    <PageLoader v-if="loading" :label="t('common.loading')" />
    <template v-else>
      <Message v-if="errorMessage" severity="error" class="drawer-msg" :closable="false">
        {{ errorMessage }}
      </Message>

      <div class="org-drawer-form">
        <div class="field">
          <label>{{ t('organizaciones.type') }}</label>
          <Select
            v-model="form.tipo_organizacion_id"
            :options="tipoSelectOptions"
            option-label="label"
            option-value="value"
            fluid
            :disabled="tipoLocked"
            :placeholder="t('organizaciones.typePlaceholder')"
            @update:model-value="refreshParentOptions"
          />
          <small v-if="mode === 'edit'" class="hint">{{ t('organizaciones.typeLockedHint') }}</small>
        </div>

        <div v-if="!isRootTipo" class="field">
          <label>{{ t('organizaciones.parent') }}</label>
          <Select
            v-model="form.organizacion_padre_id"
            :options="parentOptions.map((o) => ({ label: o.nombre, value: o.id }))"
            option-label="label"
            option-value="value"
            fluid
            filter
            :disabled="parentLocked || isRootTipo"
            :placeholder="t('organizaciones.parentPlaceholder')"
            @update:model-value="onParentChange"
          />
          <small v-if="mode === 'edit' && !parentLocked" class="hint">
            {{ t('organizaciones.parentEditHint') }}
          </small>
        </div>

        <div class="field">
          <label>{{ t('organizaciones.name') }} <span class="req">*</span></label>
          <InputText v-model="form.nombre" class="w-full" />
        </div>

        <div v-if="showPaisField" class="field">
          <label>{{ t('organizaciones.pais') }} <span class="req">*</span></label>
          <Select
            v-model="form.pais_id"
            :options="paises.map((p) => ({ label: p.nombre, value: p.id }))"
            option-label="label"
            option-value="value"
            fluid
            filter
            show-clear
            :placeholder="t('organizaciones.paisPlaceholder')"
          />
          <InputText
            v-model="form.pais_nombre"
            class="w-full mt"
            :placeholder="t('organizaciones.paisNewPlaceholder')"
          />
        </div>

        <div v-if="showDepartamentosMultiField" class="field">
          <label>{{ t('organizaciones.departamentos') }}</label>
          <MultiSelect
            v-model="form.departamento_ids"
            :options="form.tipo_organizacion_id === TIPO_DISTRITO && parentDepartamentos.length ? parentDepartamentos : (departamentos.length ? departamentos : parentDepartamentos)"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            fluid
            :placeholder="t('organizaciones.departamentosPlaceholder')"
          />
          <small v-if="form.tipo_organizacion_id === TIPO_DISTRITO" class="hint">
            {{ t('organizaciones.departamentosDistritoHint') }}
          </small>
        </div>

        <div v-if="showCiudadesMultiField" class="field">
          <label>{{ t('organizaciones.ciudades') }}</label>
          <MultiSelect
            v-model="form.ciudad_ids"
            :options="ciudades"
            option-label="label"
            option-value="id"
            display="chip"
            filter
            fluid
            :disabled="!form.departamento_ids.length"
            :placeholder="t('organizaciones.ciudadesPlaceholder')"
          />
          <small class="hint">{{ t('organizaciones.ciudadesHint') }}</small>
        </div>

        <div v-if="showIglesiaDepartamentoField" class="field">
          <label>{{ t('organizaciones.departamento') }}</label>
          <Select
            v-model="form.departamento_id"
            :options="parentDepartamentos.length ? parentDepartamentos : departamentos"
            option-label="label"
            option-value="id"
            fluid
            filter
            :placeholder="t('organizaciones.departamentoPlaceholder')"
          />
        </div>

        <div v-if="showIglesiaCiudadField" class="field">
          <label>{{ t('organizaciones.ciudad') }}</label>
          <Select
            v-model="form.ciudad_id"
            :options="ciudadesIglesia"
            option-label="label"
            option-value="id"
            fluid
            filter
            :disabled="!form.departamento_id && showIglesiaDepartamentoField"
            :placeholder="t('organizaciones.ciudadPlaceholder')"
          />
        </div>

        <p v-if="showIglesiaUbicacionField && (!showIglesiaDepartamentoField || !showIglesiaCiudadField)" class="hint">
          {{ t('organizaciones.locationHintIglesia') }}
        </p>

        <div v-if="showDireccionField" class="field">
          <label>{{ t('organizaciones.address') }} <span class="req">*</span></label>
          <InputText v-model="form.direccion" class="w-full" />
        </div>

        <p v-if="showInheritedLocation" class="hint">
          {{ t('organizaciones.inheritedLocationHint') }}
        </p>

        <div class="grid-2">
          <div class="field">
            <label>{{ t('organizaciones.phone') }}</label>
            <InputText v-model="form.telefono" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('organizaciones.email') }}</label>
            <InputText v-model="form.correo" class="w-full" />
          </div>
        </div>

        <div class="field">
          <label>{{ t('organizaciones.status') }}</label>
          <Select
            v-model="form.estado"
            :options="estadoOptions"
            option-label="label"
            option-value="value"
            fluid
          />
        </div>
      </div>
    </template>

    <template #footer>
      <Button :label="t('common.cancel')" text :disabled="saving" @click="drawerVisible = false" />
      <Button :label="t('common.save')" icon="pi pi-check" :loading="saving" @click="save" />
    </template>
  </AppStackDrawer>
</template>

<style scoped>
.org-drawer-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.85rem;
  font-weight: 600;
}

.grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.req {
  color: var(--p-red-500, #ef4444);
}

.hint {
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color, #64748b);
}

.mt {
  margin-top: 0.45rem;
}

.drawer-msg {
  margin-bottom: 0.75rem;
}

@media (max-width: 640px) {
  .grid-2 {
    grid-template-columns: 1fr;
  }
}
</style>
