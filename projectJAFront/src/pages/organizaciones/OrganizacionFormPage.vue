<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import OrgTreeNodes from '@/components/organizaciones/OrgTreeNodes.vue'
import { organizacionesService } from '@/services/organizacionesService'
import { getApiErrorMessage } from '@/services/api'
import { useOrganizacionesRealtime } from '@/composables/useOrganizacionesRealtime'
import type {
  CiudadOption,
  DepartamentoOption,
  OrganizacionParentOption,
  OrganizacionTreeNode,
  PaisOption,
  TipoOrganizacion,
} from '@/modules/organizaciones/types'
import {
  TIPO_ASOCIACION,
  TIPO_CLUB,
  TIPO_DISTRITO,
  TIPO_IGLESIA,
  TIPO_UNION,
  TIPOS_HEREDAN_UBICACION,
  TIPOS_HEREDAN_UBICACION_COMPLETA,
} from '@/modules/organizaciones/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const isEdit = computed(() => route.name === 'organizaciones.edit')
const orgId = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const tipos = ref<TipoOrganizacion[]>([])
const parentOptions = ref<OrganizacionParentOption[]>([])
const tree = ref<OrganizacionTreeNode[]>([])
const treePanelRef = ref<HTMLElement | null>(null)

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
  ciudad_id: null as number | null,
  ciudad_nombre: '',
  nombre: '',
  codigo: '',
  direccion: '',
  telefono: '',
  correo: '',
  estado: true,
})

const selectedTipo = computed(() =>
  tipos.value.find((tipo) => tipo.id === form.tipo_organizacion_id) ?? null,
)

const isRootTipo = computed(() =>
  !!form.tipo_organizacion_id && selectedTipo.value?.tipo_organizacion_padre_id == null,
)

const expectedParentTipoId = computed(() => selectedTipo.value?.tipo_organizacion_padre_id ?? null)

const selectedParent = computed(() =>
  parentOptions.value.find((o) => o.id === form.organizacion_padre_id) ?? null,
)

const inheritedPaisLabel = computed(() => selectedParent.value?.pais_nombre || '—')
const inheritedDepartamentoLabel = computed(() => selectedParent.value?.departamento_nombre || '—')
const inheritedCiudadLabel = computed(() => selectedParent.value?.ciudad_nombre || '—')
const inheritedDepartamentosLabel = computed(() => {
  const deps = selectedParent.value?.departamentos ?? []
  if (!deps.length) return inheritedDepartamentoLabel.value
  return deps.map((d) => d.nombre).join(', ')
})

const parentDepartamentos = computed<DepartamentoOption[]>(() => {
  const deps = selectedParent.value?.departamentos ?? []
  if (deps.length) {
    return deps.map((d) => ({
      ...d,
      label: d.label || (d.codigo ? `${d.codigo} — ${d.nombre}` : d.nombre),
    }))
  }
  if (selectedParent.value?.departamento_id && selectedParent.value.departamento_nombre) {
    return [{
      id: selectedParent.value.departamento_id,
      pais_id: selectedParent.value.pais_id ?? 0,
      nombre: selectedParent.value.departamento_nombre,
      label: selectedParent.value.departamento_nombre,
    }]
  }
  return []
})

const showPaisField = computed(() => form.tipo_organizacion_id === TIPO_UNION)
const showDepartamentosMultiField = computed(() => form.tipo_organizacion_id === TIPO_ASOCIACION)
const showDistritoDepartamentoField = computed(() => form.tipo_organizacion_id === TIPO_DISTRITO)
const showCiudadField = computed(() => form.tipo_organizacion_id === TIPO_DISTRITO)
const showDireccionField = computed(() => form.tipo_organizacion_id === TIPO_IGLESIA)
const showInheritedLocation = computed(() => {
  const tipoId = form.tipo_organizacion_id
  if (!tipoId) return false
  return (
    TIPOS_HEREDAN_UBICACION.includes(tipoId as (typeof TIPOS_HEREDAN_UBICACION)[number]) ||
    isHijoDeClub(tipoId)
  )
})

const showDepartamentoHeredado = computed(() => {
  const tipoId = form.tipo_organizacion_id
  if (!tipoId || tipoId === TIPO_ASOCIACION || tipoId === TIPO_DISTRITO) return false
  return tipoId === TIPO_IGLESIA || TIPOS_HEREDAN_UBICACION_COMPLETA.includes(tipoId as (typeof TIPOS_HEREDAN_UBICACION_COMPLETA)[number]) || isHijoDeClub(tipoId)
})

const showCiudadHeredada = computed(() => {
  const tipoId = form.tipo_organizacion_id
  if (!tipoId) return false
  return (
    tipoId === TIPO_IGLESIA ||
    TIPOS_HEREDAN_UBICACION_COMPLETA.includes(tipoId as (typeof TIPOS_HEREDAN_UBICACION_COMPLETA)[number]) ||
    isHijoDeClub(tipoId)
  )
})

const locationHint = computed(() => {
  switch (form.tipo_organizacion_id) {
    case TIPO_UNION:
      return t('organizaciones.locationHintUnion')
    case TIPO_ASOCIACION:
      return t('organizaciones.locationHintAsociacion')
    case TIPO_DISTRITO:
      return t('organizaciones.locationHintDistrito')
    case TIPO_IGLESIA:
      return t('organizaciones.locationHintIglesia')
    case TIPO_CLUB:
      return t('organizaciones.locationHintClub')
    default:
      if (isHijoDeClub(form.tipo_organizacion_id)) {
        return t('organizaciones.locationHintClubChild')
      }
      return ''
  }
})

const parentSelectOptions = computed(() => {
  if (!form.tipo_organizacion_id) {
    return []
  }

  if (isRootTipo.value || expectedParentTipoId.value == null) {
    return [{ label: t('organizaciones.noParent'), value: null as number | null }]
  }

  return parentOptions.value.map((o) => ({
    label: o.tipo_nombre ? `${o.nombre} (${o.tipo_nombre})` : o.nombre,
    value: o.id as number | null,
  }))
})

const estadoOptions = computed(() => [
  { label: t('common.active'), value: true },
  { label: t('common.inactive'), value: false },
])

async function refreshParentOptions(): Promise<void> {
  const exclude = isEdit.value ? orgId.value : undefined
  if (!form.tipo_organizacion_id || isRootTipo.value || expectedParentTipoId.value == null) {
    parentOptions.value = []
    form.organizacion_padre_id = null
    return
  }
  parentOptions.value = await organizacionesService.parentOptions(exclude, form.tipo_organizacion_id)
  if (
    form.organizacion_padre_id &&
    !parentOptions.value.some((o) => o.id === form.organizacion_padre_id)
  ) {
    form.organizacion_padre_id = null
  }
}

function selectParent(id: number): void {
  if (isRootTipo.value) return
  if (!parentOptions.value.some((o) => o.id === id)) return
  form.organizacion_padre_id = id
}

function isHijoDeClub(tipoId: number | null): boolean {
  if (!tipoId) return false
  const tipo = tipos.value.find((t) => t.id === tipoId)
  return tipo?.tipo_organizacion_padre_id === TIPO_CLUB
}

async function focusTree(): Promise<void> {
  await nextTick()
  treePanelRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
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

watch(
  () => form.tipo_organizacion_id,
  async (tipo) => {
    if (tipo === TIPO_UNION) {
      form.organizacion_padre_id = null
      form.departamento_id = null
      form.departamento_nombre = ''
      form.ciudad_id = null
      form.ciudad_nombre = ''
      form.direccion = ''
    } else if (tipo === TIPO_ASOCIACION) {
      form.pais_id = null
      form.pais_nombre = ''
      form.departamento_id = null
      form.departamento_nombre = ''
      form.departamento_ids = []
      form.ciudad_id = null
      form.ciudad_nombre = ''
      form.direccion = ''
    } else if (tipo === TIPO_DISTRITO) {
      form.pais_id = null
      form.pais_nombre = ''
      form.departamento_id = null
      form.departamento_nombre = ''
      form.departamento_ids = []
      form.ciudad_id = null
      form.ciudad_nombre = ''
      form.direccion = ''
    } else if (tipo === TIPO_IGLESIA) {
      form.pais_id = null
      form.pais_nombre = ''
      form.departamento_id = null
      form.departamento_nombre = ''
      form.departamento_ids = []
      form.ciudad_id = null
      form.ciudad_nombre = ''
    } else if (tipo === TIPO_CLUB || isHijoDeClub(tipo)) {
      form.pais_id = null
      form.pais_nombre = ''
      form.departamento_id = null
      form.departamento_nombre = ''
      form.departamento_ids = []
      form.ciudad_id = null
      form.ciudad_nombre = ''
      form.direccion = ''
    }

    await refreshParentOptions()
  },
)

watch(
  () => form.organizacion_padre_id,
  async (padreId) => {
    const padre = parentOptions.value.find((o) => o.id === padreId)
    if (!padre) return

    if (form.tipo_organizacion_id === TIPO_ASOCIACION && padre.pais_id) {
      await loadDepartamentos(padre.pais_id)
    }
    if (form.tipo_organizacion_id === TIPO_DISTRITO) {
      form.departamento_id = null
      form.ciudad_id = null
      form.ciudad_nombre = ''
      ciudades.value = []
    }
  },
)

watch(
  () => form.departamento_id,
  async (departamentoId) => {
    if (form.tipo_organizacion_id !== TIPO_DISTRITO) return
    form.ciudad_id = null
    form.ciudad_nombre = ''
    await loadCiudades(departamentoId)
  },
)

async function loadCatalogs(): Promise<void> {
  const [tiposData, treeData, paisesData] = await Promise.all([
    organizacionesService.tipos(),
    organizacionesService.tree(),
    organizacionesService.paises(),
  ])
  tipos.value = tiposData
  tree.value = treeData
  paises.value = paisesData
  await refreshParentOptions()
}

async function submit(): Promise<void> {
  if (!form.nombre.trim()) {
    errorMessage.value = t('validation.required')
    return
  }
  if (!form.tipo_organizacion_id) {
    errorMessage.value = t('organizaciones.typeRequired')
    return
  }

  if (form.tipo_organizacion_id === TIPO_UNION && !form.pais_id && !form.pais_nombre.trim()) {
    errorMessage.value = t('organizaciones.paisRequired')
    return
  }
  if (form.tipo_organizacion_id === TIPO_ASOCIACION && form.departamento_ids.length === 0) {
    errorMessage.value = t('organizaciones.departamentosRequired')
    return
  }
  if (form.tipo_organizacion_id === TIPO_DISTRITO && !form.departamento_id) {
    errorMessage.value = t('organizaciones.departamentoRequired')
    return
  }
  if (form.tipo_organizacion_id === TIPO_DISTRITO && !form.ciudad_id && !form.ciudad_nombre.trim()) {
    errorMessage.value = t('organizaciones.ciudadRequired')
    return
  }
  if (form.tipo_organizacion_id === TIPO_IGLESIA && !form.direccion.trim()) {
    errorMessage.value = t('organizaciones.addressRequired')
    return
  }
  if (form.tipo_organizacion_id !== TIPO_UNION && !isRootTipo.value && !form.organizacion_padre_id) {
    errorMessage.value = t('organizaciones.parentRequired')
    return
  }

  saving.value = true
  errorMessage.value = ''
  try {
    const payload = {
      organizacion_padre_id: form.organizacion_padre_id,
      tipo_organizacion_id: form.tipo_organizacion_id,
      pais_id: showPaisField.value ? form.pais_id : null,
      pais_nombre: showPaisField.value && !form.pais_id ? form.pais_nombre.trim() || null : null,
      departamento_ids: showDepartamentosMultiField.value ? form.departamento_ids : undefined,
      departamento_id: showDistritoDepartamentoField.value ? form.departamento_id : null,
      departamento_nombre: null,
      ciudad_id: showCiudadField.value ? form.ciudad_id : null,
      ciudad_nombre:
        showCiudadField.value && !form.ciudad_id ? form.ciudad_nombre.trim() || null : null,
      nombre: form.nombre.trim(),
      direccion: showDireccionField.value ? form.direccion.trim() || null : null,
      telefono: form.telefono.trim() || null,
      correo: form.correo.trim() || null,
      estado: form.estado,
    }

    if (isEdit.value) {
      await organizacionesService.update(orgId.value, payload)
    } else {
      await organizacionesService.create(payload)
    }

    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: isEdit.value ? t('organizaciones.updateSuccess') : t('organizaciones.createSuccess'),
      life: 2500,
    })
    await router.push({ name: 'organizaciones' })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loading.value = true
  try {
    if (isEdit.value) {
      const [tiposData, treeData, paisesData, org] = await Promise.all([
        organizacionesService.tipos(),
        organizacionesService.tree(),
        organizacionesService.paises(),
        organizacionesService.get(orgId.value),
      ])
      tipos.value = tiposData
      tree.value = treeData
      paises.value = paisesData

      form.tipo_organizacion_id = org.tipo_organizacion_id
      form.pais_id = org.pais_id
      form.departamento_id = org.departamento_id
      form.departamento_ids = (org.departamentos ?? []).map((d) => d.id)
      form.ciudad_id = org.ciudad_id
      form.nombre = org.nombre
      form.codigo = org.codigo || ''
      form.direccion = org.direccion || ''
      form.telefono = org.telefono || ''
      form.correo = org.correo || ''
      form.estado = org.estado
      form.organizacion_padre_id = org.organizacion_padre_id

      await Promise.all([
        refreshParentOptions(),
        org.tipo_organizacion_id === TIPO_ASOCIACION && org.pais_id
          ? loadDepartamentos(org.pais_id)
          : Promise.resolve(),
        org.departamento_id ? loadCiudades(org.departamento_id) : Promise.resolve(),
      ])
    } else {
      await loadCatalogs()
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
})

function findTreeNode(
  nodes: OrganizacionTreeNode[],
  id: number,
): OrganizacionTreeNode | null {
  for (const node of nodes) {
    if (node.id === id) return node
    const found = findTreeNode(node.children ?? [], id)
    if (found) return found
  }
  return null
}

function toFormTreeNode(org: {
  id: number
  nombre: string
  codigo: string | null
  tipo_organizacion_id: number
  organizacion_padre_id: number | null
  estado: boolean
  tipo?: { nombre: string } | null
  pais?: { nombre: string } | null
  departamento?: { nombre: string } | null
  ciudad?: { nombre: string } | null
}): OrganizacionTreeNode {
  return {
    id: org.id,
    nombre: org.nombre,
    codigo: org.codigo,
    tipo_organizacion_id: org.tipo_organizacion_id,
    tipo_nombre:
      org.tipo?.nombre ??
      tipos.value.find((tipo) => tipo.id === org.tipo_organizacion_id)?.nombre ??
      null,
    organizacion_padre_id: org.organizacion_padre_id,
    estado: org.estado,
    pais_nombre: org.pais?.nombre ?? null,
    departamento_nombre: org.departamento?.nombre ?? null,
    ciudad_nombre: org.ciudad?.nombre ?? null,
    children: [],
  }
}

function removeFromFormTree(id: number): void {
  const strip = (nodes: OrganizacionTreeNode[]): OrganizacionTreeNode[] =>
    nodes
      .filter((node) => node.id !== id)
      .map((node) => ({
        ...node,
        children: strip(node.children ?? []),
      }))
  tree.value = strip(tree.value)
}

function upsertFormTree(org: Parameters<typeof toFormTreeNode>[0]): void {
  const existing = findTreeNode(tree.value, org.id)
  if (existing) {
    if (existing.organizacion_padre_id !== org.organizacion_padre_id) {
      removeFromFormTree(org.id)
    } else {
      existing.nombre = org.nombre
      existing.codigo = org.codigo
      existing.estado = org.estado
      existing.tipo_organizacion_id = org.tipo_organizacion_id
      existing.organizacion_padre_id = org.organizacion_padre_id
      existing.tipo_nombre =
        org.tipo?.nombre ??
        tipos.value.find((tipo) => tipo.id === org.tipo_organizacion_id)?.nombre ??
        existing.tipo_nombre
      existing.pais_nombre = org.pais?.nombre ?? existing.pais_nombre ?? null
      existing.departamento_nombre =
        org.departamento?.nombre ?? existing.departamento_nombre ?? null
      existing.ciudad_nombre = org.ciudad?.nombre ?? existing.ciudad_nombre ?? null
      return
    }
  }

  const newNode = toFormTreeNode(org)
  const parentId = org.organizacion_padre_id
  if (parentId == null) {
    tree.value = [...tree.value, newNode]
    return
  }
  const parent = findTreeNode(tree.value, parentId)
  if (!parent) return
  parent.children = [...(parent.children ?? []), newNode]
}

async function syncParentOptionFromOrg(org: Awaited<ReturnType<typeof organizacionesService.get>>): Promise<void> {
  if (expectedParentTipoId.value == null) return
  if (org.tipo_organizacion_id !== expectedParentTipoId.value) {
    parentOptions.value = parentOptions.value.filter((o) => o.id !== org.id)
    return
  }
  if (isEdit.value && org.id === orgId.value) return

  const option = {
    id: org.id,
    nombre: org.nombre,
    codigo: org.codigo,
    tipo_organizacion_id: org.tipo_organizacion_id,
    tipo_nombre: org.tipo?.nombre ?? null,
    organizacion_padre_id: org.organizacion_padre_id,
    pais_id: org.pais_id,
    pais_nombre: org.pais?.nombre ?? null,
    departamento_id: org.departamento_id,
    departamento_nombre: org.departamento?.nombre ?? null,
    ciudad_id: org.ciudad_id,
    ciudad_nombre: org.ciudad?.nombre ?? null,
    departamentos: org.departamentos ?? [],
  } satisfies (typeof parentOptions.value)[number]

  const idx = parentOptions.value.findIndex((o) => o.id === org.id)
  if (idx >= 0) {
    parentOptions.value[idx] = option
  } else {
    parentOptions.value = [...parentOptions.value, option]
  }
}

/**
 * Sync remoto sin recargar el formulario (no pisa cambios en curso).
 * Solo actualiza árbol / opciones de padre; si borran la org que editas, sale.
 */
let formRealtimeTimer: ReturnType<typeof setTimeout> | undefined
useOrganizacionesRealtime((payload) => {
  clearTimeout(formRealtimeTimer)
  formRealtimeTimer = setTimeout(async () => {
    if (payload.action === 'deleted') {
      removeFromFormTree(payload.organizacion_id)
      parentOptions.value = parentOptions.value.filter((o) => o.id !== payload.organizacion_id)
      if (isEdit.value && payload.organizacion_id === orgId.value) {
        toast.add({
          severity: 'warn',
          summary: t('common.warning'),
          detail: t('organizaciones.realtimeDeleted'),
          life: 4000,
        })
        await router.push({ name: 'organizaciones' })
      }
      return
    }

    try {
      const org = await organizacionesService.get(payload.organizacion_id)
      upsertFormTree(org)
      await syncParentOptionFromOrg(org)
      // Si otra sesión actualizó la misma org que editas, no pisamos el formulario.
      if (isEdit.value && payload.organizacion_id === orgId.value && payload.action === 'updated') {
        toast.add({
          severity: 'info',
          summary: t('common.info'),
          detail: t('organizaciones.realtimeUpdatedElsewhere'),
          life: 3500,
        })
      }
    } catch {
      removeFromFormTree(payload.organizacion_id)
      parentOptions.value = parentOptions.value.filter((o) => o.id !== payload.organizacion_id)
    }
  }, 250)
})
</script>

<template>
  <section class="pj-page org-form-page">
    <header class="org-form-page__header">
      <div class="org-form-page__title-block">
        <span class="org-form-page__icon" aria-hidden="true">
          <i class="pi pi-building" />
        </span>
        <div>
          <h1 class="pj-page__title">{{ isEdit ? t('organizaciones.edit') : t('organizaciones.createTitle') }}</h1>
          <p class="pj-page__subtitle">{{ t('organizaciones.createSubtitle') }}</p>
        </div>
      </div>
      <Button
        type="button"
        :label="t('organizaciones.backToList')"
        icon="pi pi-arrow-left"
        outlined
        @click="router.push({ name: 'organizaciones' })"
      />
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <div v-else class="org-form-layout">
      <form class="org-card" @submit.prevent="submit">
        <h2 class="org-card__title">{{ t('organizaciones.generalInfo') }}</h2>

        <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

        <div class="org-form-grid">
          <div class="field">
            <label for="tipo">{{ t('organizaciones.type') }} <span class="req">*</span></label>
            <Select
              id="tipo"
              v-model="form.tipo_organizacion_id"
              :options="tipos"
              option-label="nombre"
              option-value="id"
              :placeholder="t('organizaciones.typePlaceholder')"
              :disabled="isEdit"
              class="w-full"
            />
            <small v-if="isEdit" class="pj-muted">{{ t('organizaciones.typeLockedHint') }}</small>
          </div>

          <div class="field">
            <div class="label-row">
              <label for="padre">{{ t('organizaciones.parent') }}</label>
              <i class="pi pi-info-circle info-tip" :title="t('organizaciones.parentHint')" />
            </div>
            <div class="parent-row">
              <Select
                id="padre"
                v-model="form.organizacion_padre_id"
                :options="parentSelectOptions"
                option-label="label"
                option-value="value"
                show-clear
                filter
                :disabled="isRootTipo || !form.tipo_organizacion_id"
                :placeholder="t('organizaciones.parentPlaceholder')"
                class="w-full parent-select"
              />
              <Button
                type="button"
                :label="t('organizaciones.viewTree')"
                icon="pi pi-sitemap"
                outlined
                @click="focusTree"
              />
            </div>
            <Message severity="info" :closable="false" class="parent-hint">
              {{ isEdit ? t('organizaciones.parentEditHint') : t('organizaciones.parentAlert') }}
            </Message>
          </div>

          <div class="field">
            <label for="nombre">{{ t('organizaciones.name') }} <span class="req">*</span></label>
            <InputText id="nombre" v-model="form.nombre" class="w-full" required />
          </div>

          <div v-if="isEdit && form.codigo" class="field">
            <label for="codigo">{{ t('organizaciones.code') }}</label>
            <InputText id="codigo" :model-value="form.codigo" class="w-full" readonly disabled />
            <small class="pj-muted">{{ t('organizaciones.codeAutoHint') }}</small>
          </div>

          <div v-else class="field">
            <label>{{ t('organizaciones.code') }}</label>
            <InputText :model-value="t('organizaciones.codeAutoPlaceholder')" class="w-full" readonly disabled />
            <small class="pj-muted">{{ t('organizaciones.codeAutoHint') }}</small>
          </div>

          <div class="field">
            <label for="correo">{{ t('organizaciones.email') }}</label>
            <span class="input-icon">
              <i class="pi pi-envelope" />
              <InputText id="correo" v-model="form.correo" type="email" class="w-full" />
            </span>
          </div>

          <div class="field">
            <label for="telefono">{{ t('organizaciones.phone') }}</label>
            <span class="input-icon">
              <i class="pi pi-phone" />
              <InputText id="telefono" v-model="form.telefono" class="w-full" />
            </span>
          </div>

          <div class="field">
            <label for="estado">{{ t('organizaciones.status') }} <span class="req">*</span></label>
            <Select
              id="estado"
              v-model="form.estado"
              :options="estadoOptions"
              option-label="label"
              option-value="value"
              class="w-full"
            >
              <template #value="{ value }">
                <span class="estado-value">
                  <span class="estado-dot" :class="{ 'estado-dot--on': value }" />
                  {{ value ? t('common.active') : t('common.inactive') }}
                </span>
              </template>
              <template #option="{ option }">
                <span class="estado-value">
                  <span class="estado-dot" :class="{ 'estado-dot--on': option.value }" />
                  {{ option.label }}
                </span>
              </template>
            </Select>
          </div>

          <div v-if="form.tipo_organizacion_id" class="field field--full">
            <Message severity="info" :closable="false">{{ locationHint }}</Message>
          </div>

          <div v-if="showInheritedLocation && selectedParent" class="field field--full inherited-box">
            <p class="inherited-title">{{ t('organizaciones.inheritedLocation') }}</p>
            <div class="inherited-grid">
              <div>
                <span class="pj-muted">{{ t('organizaciones.pais') }}</span>
                <strong>{{ inheritedPaisLabel }}</strong>
              </div>
              <div v-if="form.tipo_organizacion_id === TIPO_DISTRITO">
                <span class="pj-muted">{{ t('organizaciones.departamentosAsociacion') }}</span>
                <strong>{{ inheritedDepartamentosLabel }}</strong>
              </div>
              <div v-if="showDepartamentoHeredado">
                <span class="pj-muted">{{ t('organizaciones.departamento') }}</span>
                <strong>{{ inheritedDepartamentoLabel }}</strong>
              </div>
              <div v-if="showCiudadHeredada">
                <span class="pj-muted">{{ t('organizaciones.ciudad') }}</span>
                <strong>{{ inheritedCiudadLabel }}</strong>
              </div>
            </div>
          </div>

          <div v-if="showPaisField" class="field">
            <label for="pais">{{ t('organizaciones.pais') }} <span class="req">*</span></label>
            <Select
              id="pais"
              v-model="form.pais_id"
              :options="paises"
              option-label="nombre"
              option-value="id"
              filter
              show-clear
              :placeholder="t('organizaciones.paisPlaceholder')"
              class="w-full"
              @update:model-value="() => { form.pais_nombre = '' }"
            />
            <InputText
              v-model="form.pais_nombre"
              class="w-full"
              :placeholder="t('organizaciones.paisNewPlaceholder')"
              :disabled="!!form.pais_id"
            />
            <small class="pj-muted">{{ t('organizaciones.paisHint') }}</small>
          </div>

          <div v-if="showDepartamentosMultiField" class="field field--full">
            <label for="departamentos">{{ t('organizaciones.departamentos') }} <span class="req">*</span></label>
            <MultiSelect
              id="departamentos"
              v-model="form.departamento_ids"
              :options="departamentos"
              option-label="label"
              option-value="id"
              filter
              display="chip"
              :placeholder="t('organizaciones.departamentosPlaceholder')"
              class="w-full"
            />
            <small class="pj-muted">{{ t('organizaciones.departamentosHint') }}</small>
          </div>

          <div v-if="showDistritoDepartamentoField" class="field">
            <label for="departamento">{{ t('organizaciones.departamento') }} <span class="req">*</span></label>
            <Select
              id="departamento"
              v-model="form.departamento_id"
              :options="parentDepartamentos"
              option-label="label"
              option-value="id"
              filter
              show-clear
              :placeholder="t('organizaciones.departamentoFromParentPlaceholder')"
              class="w-full"
            />
            <small class="pj-muted">{{ t('organizaciones.departamentoFromParentHint') }}</small>
          </div>

          <div v-if="showCiudadField" class="field">
            <label for="ciudad">{{ t('organizaciones.ciudad') }} <span class="req">*</span></label>
            <Select
              id="ciudad"
              v-model="form.ciudad_id"
              :options="ciudades"
              option-label="label"
              option-value="id"
              filter
              show-clear
              :placeholder="t('organizaciones.ciudadPlaceholder')"
              class="w-full"
              :disabled="!form.departamento_id"
              @update:model-value="() => { form.ciudad_nombre = '' }"
            />
            <InputText
              v-model="form.ciudad_nombre"
              class="w-full"
              :placeholder="t('organizaciones.ciudadNewPlaceholder')"
              :disabled="!!form.ciudad_id || !form.departamento_id"
            />
            <small class="pj-muted">{{ t('organizaciones.ciudadHint') }}</small>
          </div>

          <div v-if="showDireccionField" class="field field--address">
            <label for="direccion">{{ t('organizaciones.address') }} <span class="req">*</span></label>
            <Textarea id="direccion" v-model="form.direccion" rows="3" class="w-full" auto-resize />
          </div>
        </div>

        <div class="org-form-actions">
          <Button
            type="button"
            :label="t('common.cancel')"
            outlined
            @click="router.push({ name: 'organizaciones' })"
          />
          <Button
            type="submit"
            :label="t('organizaciones.saveOrg')"
            icon="pi pi-save"
            :loading="saving"
          />
        </div>
      </form>

      <aside ref="treePanelRef" class="org-card org-tree-card">
        <h2 class="org-card__title">{{ t('organizaciones.treeView') }}</h2>
        <p v-if="!tree.length" class="pj-muted tree-empty">{{ t('organizaciones.treeEmpty') }}</p>
        <OrgTreeNodes
          v-else
          :nodes="tree"
          :selected-id="form.organizacion_padre_id"
          @select="selectParent"
        />
      </aside>
    </div>
  </section>
</template>

<style scoped>
.org-form-page__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.org-form-page__title-block {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.org-form-page__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 10px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--pj-navy) 10%, transparent);
  color: var(--pj-navy);
  font-size: 1.1rem;
  flex-shrink: 0;
}

.org-form-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(16rem, 20rem);
  gap: 1rem;
  align-items: start;
}

.org-card {
  background: color-mix(in srgb, var(--pj-bg-elevated) 96%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 14px;
  padding: 1.1rem 1.15rem;
  box-shadow: var(--pj-shadow);
}

.org-card__title {
  margin: 0 0 1rem;
  font-size: 1.05rem;
  color: var(--pj-navy);
}

.org-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.9rem 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.field--address,
.field--full {
  grid-column: 1 / -1;
}

.inherited-box {
  background: color-mix(in srgb, var(--pj-navy) 4%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  border-radius: 10px;
  padding: 0.75rem 0.85rem;
}

.inherited-title {
  margin: 0 0 0.5rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--pj-navy);
}

.inherited-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: 0.55rem;
}

.inherited-grid > div {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.9rem;
}

.label-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.req {
  color: #dc2626;
}

.info-tip {
  color: var(--pj-text-muted);
  font-size: 0.85rem;
}

.parent-row {
  display: flex;
  gap: 0.45rem;
  align-items: stretch;
}

.parent-select {
  flex: 1;
  min-width: 0;
}

.parent-hint {
  margin-top: 0.35rem;
}

.input-icon {
  position: relative;
  display: block;
}

.input-icon > i {
  position: absolute;
  left: 0.7rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--pj-text-muted);
  z-index: 1;
  pointer-events: none;
}

.input-icon :deep(.p-inputtext) {
  padding-left: 2.1rem;
}

.estado-value {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.estado-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: #94a3b8;
}

.estado-dot--on {
  background: #16a34a;
}

.org-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 1.15rem;
  padding-top: 0.95rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
}

.org-tree-card {
  position: sticky;
  top: 0.75rem;
  max-height: calc(100vh - 7rem);
  overflow: auto;
}

.tree-empty {
  margin: 0;
  font-size: 0.85rem;
}

.w-full {
  width: 100%;
}

@media (max-width: 980px) {
  .org-form-layout {
    grid-template-columns: 1fr;
  }

  .org-tree-card {
    position: static;
    max-height: none;
  }
}

@media (max-width: 720px) {
  .org-form-grid {
    grid-template-columns: 1fr;
  }

  .parent-row {
    flex-direction: column;
  }
}
</style>
