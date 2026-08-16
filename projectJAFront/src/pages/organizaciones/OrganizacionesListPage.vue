<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import OrgListTree from '@/components/organizaciones/OrgListTree.vue'
import OrganizacionFormDrawer from '@/components/organizaciones/OrganizacionFormDrawer.vue'
import type { OrgDrawerMode } from '@/components/organizaciones/OrganizacionFormDrawer.vue'
import { organizacionesService } from '@/services/organizacionesService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { useOrganizacionesRealtime } from '@/composables/useOrganizacionesRealtime'
import type { Organizacion, OrganizacionTreeNode, TipoOrganizacion } from '@/modules/organizaciones/types'

const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()

const tree = ref<OrganizacionTreeNode[]>([])
const tipos = ref<TipoOrganizacion[]>([])
const loading = ref(false)
const deleteTarget = ref<OrganizacionTreeNode | null>(null)
const deleting = ref(false)

const drawerVisible = ref(false)
const drawerMode = ref<OrgDrawerMode>('create')
const drawerOrgId = ref<number | null>(null)
const drawerParentId = ref<number | null>(null)
const drawerParentTipoId = ref<number | null>(null)
const drawerParentNombre = ref<string | null>(null)
const drawerLockParent = ref(false)

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (v: boolean) => {
    if (!v) deleteTarget.value = null
  },
})

const filters = reactive({
  search: '',
  tipo_organizacion_id: null as number | null,
  estado: null as boolean | null,
})

const hasActiveFilters = computed(
  () =>
    !!filters.search.trim() ||
    filters.tipo_organizacion_id !== null ||
    filters.estado !== null,
)

const estadoFilterOptions = computed(() => [
  { label: t('common.all'), value: null },
  { label: t('common.active'), value: true },
  { label: t('common.inactive'), value: false },
])

const tipoFilterOptions = computed(() => [
  { label: t('organizaciones.allTypes'), value: null },
  ...tipos.value.map((tipo) => ({ label: tipo.nombre, value: tipo.id })),
])

function canAddChild(node: OrganizacionTreeNode): boolean {
  return tipos.value.some((tipo) => tipo.tipo_organizacion_padre_id === node.tipo_organizacion_id)
}

function findNode(
  nodes: OrganizacionTreeNode[],
  id: number,
): OrganizacionTreeNode | null {
  for (const node of nodes) {
    if (node.id === id) return node
    const found = findNode(node.children ?? [], id)
    if (found) return found
  }
  return null
}

function toTreeNode(org: Organizacion, children: OrganizacionTreeNode[] = []): OrganizacionTreeNode {
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
    children,
  }
}

function patchNodeInTree(org: Organizacion): boolean {
  const node = findNode(tree.value, org.id)
  if (!node) return false
  node.nombre = org.nombre
  node.codigo = org.codigo
  node.estado = org.estado
  node.tipo_organizacion_id = org.tipo_organizacion_id
  node.organizacion_padre_id = org.organizacion_padre_id
  node.pais_nombre = org.pais?.nombre ?? node.pais_nombre ?? null
  node.departamento_nombre = org.departamento?.nombre ?? node.departamento_nombre ?? null
  node.ciudad_nombre = org.ciudad?.nombre ?? node.ciudad_nombre ?? null
  node.tipo_nombre =
    org.tipo?.nombre ??
    tipos.value.find((tipo) => tipo.id === org.tipo_organizacion_id)?.nombre ??
    node.tipo_nombre
  return true
}

function insertNodeInTree(org: Organizacion): boolean {
  if (findNode(tree.value, org.id)) {
    return patchNodeInTree(org)
  }
  const newNode = toTreeNode(org)
  const parentId = org.organizacion_padre_id
  if (parentId == null) {
    tree.value = [...tree.value, newNode]
    return true
  }
  const parent = findNode(tree.value, parentId)
  if (!parent) return false
  parent.children = [...(parent.children ?? []), newNode]
  return true
}

function upsertNodeInTree(org: Organizacion): boolean {
  const existing = findNode(tree.value, org.id)
  if (existing) {
    if (existing.organizacion_padre_id !== org.organizacion_padre_id) {
      removeNodeFromTree(org.id)
      return insertNodeInTree(org)
    }
    return patchNodeInTree(org)
  }
  return insertNodeInTree(org)
}

function matchesFilters(org: Organizacion): boolean {
  const q = filters.search.trim().toLowerCase()
  if (q) {
    const hay = `${org.nombre} ${org.codigo ?? ''} ${org.correo ?? ''}`.toLowerCase()
    if (!hay.includes(q)) return false
  }
  if (
    filters.tipo_organizacion_id != null &&
    org.tipo_organizacion_id !== filters.tipo_organizacion_id
  ) {
    return false
  }
  if (filters.estado != null && org.estado !== filters.estado) {
    return false
  }
  return true
}

/**
 * Aplica un cambio remoto sin recargar el árbol completo (preserva drawer / trabajo en curso).
 */
async function applyRemoteOrganizacionChange(payload: {
  action: 'created' | 'updated' | 'deleted'
  organizacion_id: number
}): Promise<void> {
  if (payload.action === 'deleted') {
    removeNodeFromTree(payload.organizacion_id)
    if (
      drawerVisible.value &&
      drawerMode.value === 'edit' &&
      drawerOrgId.value === payload.organizacion_id
    ) {
      drawerVisible.value = false
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('organizaciones.realtimeDeleted'),
        life: 4000,
      })
    }
    return
  }

  try {
    const org = await organizacionesService.get(payload.organizacion_id)
    if (!matchesFilters(org)) {
      removeNodeFromTree(org.id)
      return
    }
    upsertNodeInTree(org)
  } catch {
    /* org fuera de alcance o eliminada entre tanto */
    if (payload.action === 'updated') {
      removeNodeFromTree(payload.organizacion_id)
    }
  }
}

function removeNodeFromTree(id: number): void {
  const strip = (nodes: OrganizacionTreeNode[]): OrganizacionTreeNode[] =>
    nodes
      .filter((node) => node.id !== id)
      .map((node) => ({
        ...node,
        children: strip(node.children ?? []),
      }))
  tree.value = strip(tree.value)
}

async function loadTipos(): Promise<void> {
  tipos.value = await organizacionesService.tipos()
}

async function load(): Promise<void> {
  loading.value = true
  try {
    tree.value = await organizacionesService.tree(undefined, {
      search: filters.search || undefined,
      tipo_organizacion_id: filters.tipo_organizacion_id,
      estado: filters.estado,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loading.value = false
  }
}

function onSaved(payload: { mode: OrgDrawerMode; organizacion: Organizacion }): void {
  const { mode, organizacion } = payload
  if (mode === 'edit') {
    upsertNodeInTree(organizacion)
    return
  }
  upsertNodeInTree(organizacion)
}

function openCreateRoot(): void {
  drawerMode.value = 'create'
  drawerOrgId.value = null
  drawerParentId.value = null
  drawerParentTipoId.value = null
  drawerParentNombre.value = null
  drawerLockParent.value = false
  drawerVisible.value = true
}

function openEdit(id: number): void {
  drawerMode.value = 'edit'
  drawerOrgId.value = id
  drawerParentId.value = null
  drawerParentTipoId.value = null
  drawerParentNombre.value = null
  drawerLockParent.value = false
  drawerVisible.value = true
}

function openAddChild(node: OrganizacionTreeNode): void {
  if (!canAddChild(node)) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('organizaciones.noChildType'),
      life: 3000,
    })
    return
  }
  drawerMode.value = 'create'
  drawerOrgId.value = null
  drawerParentId.value = node.id
  drawerParentTipoId.value = node.tipo_organizacion_id
  drawerParentNombre.value = node.nombre
  drawerLockParent.value = true
  drawerVisible.value = true
}

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  const removedId = deleteTarget.value.id
  deleting.value = true
  try {
    await organizacionesService.remove(removedId)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('organizaciones.deleteSuccess'),
      life: 2500,
    })
    deleteTarget.value = null
    removeNodeFromTree(removedId)
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

let timer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [filters.search, filters.tipo_organizacion_id, filters.estado],
  () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
      void load()
    }, 300)
  },
)

onMounted(async () => {
  try {
    await loadTipos()
  } catch {
    /* tipos opcionales */
  }
  await load()
})

/** Sync en tiempo real: solo el registro afectado, sin recargar toda la vista. */
let realtimeTimer: ReturnType<typeof setTimeout> | undefined
useOrganizacionesRealtime((payload) => {
  clearTimeout(realtimeTimer)
  realtimeTimer = setTimeout(() => {
    void applyRemoteOrganizacionChange(payload)
  }, 250)
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('organizaciones.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('organizaciones.subtitle') }}</p>
      </div>
      <Button
        v-if="can('organizaciones.create')"
        icon="pi pi-plus"
        :label="t('organizaciones.new')"
        @click="openCreateRoot"
      />
    </header>

    <div class="pj-panel">
      <div class="pj-toolbar toolbar">
        <AppSearchField
          v-model="filters.search"
          :placeholder="t('organizaciones.searchPlaceholder')"
          class="search"
        />
        <Select
          v-model="filters.tipo_organizacion_id"
          :options="tipoFilterOptions"
          option-label="label"
          option-value="value"
          class="filter"
        />
        <Select
          v-model="filters.estado"
          :options="estadoFilterOptions"
          option-label="label"
          option-value="value"
          class="filter"
        />
      </div>

      <PageLoader v-if="loading && !tree.length" :label="t('common.loading')" />

      <template v-else>
        <p v-if="!tree.length" class="pj-muted empty">{{ t('organizaciones.empty') }}</p>
        <OrgListTree
          v-else
          :nodes="tree"
          :can-edit="can('organizaciones.update')"
          :can-delete="can('organizaciones.delete')"
          :can-create="can('organizaciones.create')"
          :expand-all="hasActiveFilters"
          @edit="openEdit"
          @add="openAddChild"
          @remove="deleteTarget = $event"
        />
      </template>
    </div>

    <OrganizacionFormDrawer
      v-model:visible="drawerVisible"
      :mode="drawerMode"
      :org-id="drawerOrgId"
      :parent-id="drawerParentId"
      :parent-tipo-id="drawerParentTipoId"
      :parent-nombre="drawerParentNombre"
      :lock-parent-and-tipo="drawerLockParent"
      @saved="onSaved"
    />

    <Dialog
      v-model:visible="deleteDialogVisible"
      modal
      :header="t('common.confirm')"
      :style="{ width: 'min(92vw, 26rem)' }"
    >
      <p>{{ t('organizaciones.deleteConfirm') }}</p>
      <strong v-if="deleteTarget">{{ deleteTarget.nombre }}</strong>
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
  </section>
</template>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  margin-bottom: 0.75rem;
}

.search {
  min-width: 0;
  flex: 1 0 100%;
}

.filter {
  min-width: 10rem;
}

.empty {
  margin: 0.5rem 0 0;
}
</style>
