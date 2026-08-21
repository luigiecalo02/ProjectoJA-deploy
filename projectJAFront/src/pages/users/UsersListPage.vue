<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import AppSearchField from '@/components/AppSearchField.vue'
import PageLoader from '@/components/PageLoader.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import { usersService } from '@/services/usersService'
import { organizacionesService } from '@/services/organizacionesService'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/modules/auth/types'
import type { PaginationMeta } from '@/types/api'
import {
  TIPO_ASOCIACION,
  TIPO_CLUB,
  TIPO_DISTRITO,
  TIPO_IGLESIA,
  TIPO_UNION,
  TIPOS_HIJO_CLUB,
  type OrganizacionTreeNode,
} from '@/modules/organizaciones/types'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()
const auth = useAuthStore()

const VIEW_STORAGE_KEY = 'pj.users.listView'
const TABLE_PAGE_SIZE = 20
const SEARCH_PAGE_SIZE = 9

function readViewMode(): 'search' | 'table' {
  try {
    return localStorage.getItem(VIEW_STORAGE_KEY) === 'table' ? 'table' : 'search'
  } catch {
    return 'search'
  }
}

usePageChrome(() => ({
  title: t('users.title'),
  subtitle: t('users.subtitle'),
  actions: can('users.create')
    ? [
        {
          key: 'new',
          label: t('users.new'),
          icon: 'pi pi-plus',
          onClick: () => void router.push({ name: 'users.create' }),
        },
      ]
    : [],
}))

const query = ref('')
const statusFilter = ref<boolean | null>(null)
const orgTree = ref<OrganizacionTreeNode[]>([])
const orgFilters = reactive({
  unionId: null as number | null,
  asociacionId: null as number | null,
  distritoId: null as number | null,
  iglesiaId: null as number | null,
  clubId: null as number | null,
})
const users = ref<User[]>([])
const failedPhotos = ref(new Set<number>())
const totalUsers = ref(0)
const loading = ref(false)
const loadingTotal = ref(true)
const searched = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<User | null>(null)
const impersonateTarget = ref<User | null>(null)
const deleting = ref(false)
const impersonating = ref(false)
const viewMode = ref<'search' | 'table'>(readViewMode())
const perPage = computed(() => (viewMode.value === 'table' ? TABLE_PAGE_SIZE : SEARCH_PAGE_SIZE))
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let requestSequence = 0

const statusOptions = [
  { label: t('common.all'), value: null },
  { label: t('common.active'), value: true },
  { label: t('common.inactive'), value: false },
]

const CLUB_TIPOS = [TIPO_CLUB, ...TIPOS_HIJO_CLUB] as readonly number[]

type OrgFilterLevel = 'union' | 'asociacion' | 'distrito' | 'iglesia' | 'club'

const LEVEL_RANK: Record<OrgFilterLevel, number> = {
  union: 1,
  asociacion: 2,
  distrito: 3,
  iglesia: 4,
  club: 5,
}

function rankOfTipo(tipoId: number | null | undefined): number | null {
  if (!tipoId) return null
  if (tipoId === TIPO_UNION) return LEVEL_RANK.union
  if (tipoId === TIPO_ASOCIACION) return LEVEL_RANK.asociacion
  if (tipoId === TIPO_DISTRITO) return LEVEL_RANK.distrito
  if (tipoId === TIPO_IGLESIA) return LEVEL_RANK.iglesia
  if (CLUB_TIPOS.includes(tipoId)) return LEVEL_RANK.club
  return null
}

const canUseOrgFilters = computed(() => {
  const user = auth.user
  if (!user) return false
  return Boolean(
    user.is_super ||
    user.is_admin ||
    (user.roles ?? []).includes('super_admin') ||
    (user.roles ?? []).includes('admin'),
  )
})

const isPlatformScope = computed(() => {
  if (!canUseOrgFilters.value) return false
  const ctx = auth.contexto
  return !ctx?.organizacion_id || Boolean(ctx.is_platform)
})

const scopeOrgId = computed(() => {
  if (isPlatformScope.value) return null
  return auth.contexto?.organizacion_id ?? null
})

function findOrgNode(nodes: OrganizacionTreeNode[], id: number | null): OrganizacionTreeNode | null {
  if (!id) return null
  for (const node of nodes) {
    if (node.id === id) return node
    const found = findOrgNode(node.children || [], id)
    if (found) return found
  }
  return null
}

function optionsFromNodes(
  nodes: OrganizacionTreeNode[],
  tipoIds: readonly number[],
): Array<{ id: number; nombre: string }> {
  return nodes
    .filter((node) => tipoIds.includes(node.tipo_organizacion_id))
    .map((node) => ({ id: node.id, nombre: node.nombre }))
    .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'))
}

const unionOptions = computed(() => optionsFromNodes(orgTree.value, [TIPO_UNION]))

const asociacionOptions = computed(() => {
  const parent = findOrgNode(orgTree.value, orgFilters.unionId)
  return parent ? optionsFromNodes(parent.children || [], [TIPO_ASOCIACION]) : []
})

const distritoOptions = computed(() => {
  const parent = findOrgNode(orgTree.value, orgFilters.asociacionId)
  return parent ? optionsFromNodes(parent.children || [], [TIPO_DISTRITO]) : []
})

const iglesiaOptions = computed(() => {
  const parent = findOrgNode(orgTree.value, orgFilters.distritoId)
  return parent ? optionsFromNodes(parent.children || [], [TIPO_IGLESIA]) : []
})

const clubOptions = computed(() => {
  const parent = findOrgNode(orgTree.value, orgFilters.iglesiaId)
  return parent ? optionsFromNodes(parent.children || [], CLUB_TIPOS) : []
})

const scopeTipoId = computed(() => {
  if (isPlatformScope.value) return null
  return (
    auth.contexto?.tipo_organizacion_id
    ?? findOrgNode(orgTree.value, scopeOrgId.value)?.tipo_organizacion_id
    ?? null
  )
})

function isLevelImplicit(level: OrgFilterLevel): boolean {
  if (isPlatformScope.value) return false
  const scopeRank = rankOfTipo(scopeTipoId.value)
  if (scopeRank === null) return false
  return LEVEL_RANK[level] <= scopeRank
}

function applyScopeLocks(): void {
  const id = scopeOrgId.value
  const tipo = scopeTipoId.value
  if (!id || !tipo) return
  if (tipo === TIPO_UNION) orgFilters.unionId = id
  else if (tipo === TIPO_ASOCIACION) orgFilters.asociacionId = id
  else if (tipo === TIPO_DISTRITO) orgFilters.distritoId = id
  else if (tipo === TIPO_IGLESIA) orgFilters.iglesiaId = id
  else if (CLUB_TIPOS.includes(tipo)) orgFilters.clubId = id
}

const selectedOrganizacionId = computed(
  () =>
    orgFilters.clubId
    ?? orgFilters.iglesiaId
    ?? orgFilters.distritoId
    ?? orgFilters.asociacionId
    ?? orgFilters.unionId
    ?? scopeOrgId.value,
)

const showUnionFilter = computed(
  () => canUseOrgFilters.value && !isLevelImplicit('union') && unionOptions.value.length > 0,
)
const showAsociacionFilter = computed(
  () =>
    canUseOrgFilters.value &&
    !isLevelImplicit('asociacion') &&
    Boolean(orgFilters.unionId) &&
    asociacionOptions.value.length > 0,
)
const showDistritoFilter = computed(
  () =>
    canUseOrgFilters.value &&
    !isLevelImplicit('distrito') &&
    Boolean(orgFilters.asociacionId) &&
    distritoOptions.value.length > 0,
)
const showIglesiaFilter = computed(
  () =>
    canUseOrgFilters.value &&
    !isLevelImplicit('iglesia') &&
    Boolean(orgFilters.distritoId) &&
    iglesiaOptions.value.length > 0,
)
const showClubFilter = computed(
  () =>
    canUseOrgFilters.value &&
    !isLevelImplicit('club') &&
    Boolean(orgFilters.iglesiaId) &&
    clubOptions.value.length > 0,
)

const scopeLabel = computed(() => {
  if (!canUseOrgFilters.value || isPlatformScope.value) return ''
  const name = auth.contexto?.organizacion_nombre?.trim()
  return name ? t('users.filterScope', { org: name }) : ''
})

function clearBelow(level: Exclude<OrgFilterLevel, 'club'>): void {
  const below: Record<Exclude<OrgFilterLevel, 'club'>, OrgFilterLevel[]> = {
    union: ['asociacion', 'distrito', 'iglesia', 'club'],
    asociacion: ['distrito', 'iglesia', 'club'],
    distrito: ['iglesia', 'club'],
    iglesia: ['club'],
  }
  for (const next of below[level]) {
    if (isLevelImplicit(next)) continue
    if (next === 'asociacion') orgFilters.asociacionId = null
    if (next === 'distrito') orgFilters.distritoId = null
    if (next === 'iglesia') orgFilters.iglesiaId = null
    if (next === 'club') orgFilters.clubId = null
  }
}

async function loadOrgTree(): Promise<void> {
  try {
    orgTree.value = await organizacionesService.tree()
    applyScopeLocks()
  } catch {
    orgTree.value = []
  }
}

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
  },
})

const impersonateDialogVisible = computed({
  get: () => impersonateTarget.value !== null,
  set: (value: boolean) => {
    if (!value) impersonateTarget.value = null
  },
})

const rangeLabel = computed(() => {
  if (!pagination.value?.total) return ''
  const from = (pagination.value.current_page - 1) * pagination.value.per_page + 1
  const to = Math.min(
    pagination.value.current_page * pagination.value.per_page,
    pagination.value.total,
  )
  return t('users.resultsRange', { from, to, total: pagination.value.total })
})

function roleLabel(role: User['roles'][number]): string {
  return role.label || role.display_name || role.name
}

function photoOf(user: User): string | null {
  if (failedPhotos.value.has(user.id)) {
    return null
  }
  return resolveFileUrl(user.avatar_url)
}

function onPhotoError(userId: number): void {
  const next = new Set(failedPhotos.value)
  next.add(userId)
  failedPhotos.value = next
}

function identityOf(user: User): string {
  const persona = user.persona
  if (persona?.identificacion) {
    return `${persona.tipo_identificacion || ''} ${persona.identificacion}`.trim()
  }
  return user.email
}

async function loadTotal(): Promise<void> {
  loadingTotal.value = true
  try {
    const result = await usersService.list({
      page: 1,
      per_page: 1,
      organizacion_id: selectedOrganizacionId.value,
    })
    totalUsers.value = result.pagination?.total ?? result.items.length
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loadingTotal.value = false
  }
}

function canLoginAs(user: User): boolean {
  if (auth.isImpersonating || !user.is_active || user.id === auth.user?.id) {
    return false
  }
  if (user.is_super && !auth.user?.is_super) {
    return false
  }
  return auth.canImpersonate || can('users.view') || can('users.update')
}

async function search(page = 1, showWarning = false): Promise<void> {
  const term = query.value.trim()
  if (viewMode.value === 'search' && term.length < 2) {
    users.value = []
    pagination.value = null
    searched.value = false
    if (showWarning) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('users.minimumSearch'),
        life: 2500,
      })
    }
    return
  }

  const sequence = ++requestSequence
  loading.value = true
  try {
    const result = await usersService.list({
      page,
      per_page: perPage.value,
      search: term || undefined,
      is_active: statusFilter.value,
      organizacion_id: selectedOrganizacionId.value,
    })
    if (sequence !== requestSequence) return
    users.value = result.items
    pagination.value = result.pagination
    searched.value = true
  } catch (error) {
    if (sequence !== requestSequence) return
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function scheduleSearch(): void {
  if (debounceTimer) clearTimeout(debounceTimer)
  if (viewMode.value === 'search' && query.value.trim().length < 2) {
    requestSequence += 1
    loading.value = false
    users.value = []
    pagination.value = null
    searched.value = false
    return
  }
  debounceTimer = setTimeout(() => void search(1), 350)
}

function onPage(event: { page: number }): void {
  void search(event.page + 1)
}

async function onToggleActive(user: User, value: boolean): Promise<void> {
  const previous = user.is_active
  user.is_active = value
  try {
    await usersService.toggleActive(user.id, value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('users.toggleSuccess'),
      life: 2500,
    })
  } catch (error) {
    user.is_active = previous
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  }
}

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await usersService.remove(deleteTarget.value.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('users.deleteSuccess'),
      life: 2500,
    })
    deleteTarget.value = null
    await Promise.all([loadTotal(), searched.value ? search(pagination.value?.current_page ?? 1) : Promise.resolve()])
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

async function confirmImpersonate(): Promise<void> {
  if (!impersonateTarget.value) return
  impersonating.value = true
  try {
    await auth.impersonate(impersonateTarget.value.id)
    impersonateTarget.value = null
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('users.impersonateSuccess'),
      life: 2500,
    })
    await router.push({ name: 'dashboard' })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    impersonating.value = false
  }
}

watch(query, scheduleSearch)
watch(statusFilter, () => {
  if (viewMode.value === 'table' || query.value.trim().length >= 2) void search(1)
})
watch(selectedOrganizacionId, () => {
  if (viewMode.value === 'table' || query.value.trim().length >= 2) {
    void search(1)
    return
  }
  void loadTotal()
})
watch(viewMode, (mode) => {
  try {
    localStorage.setItem(VIEW_STORAGE_KEY, mode)
  } catch {
    /* ignore */
  }
  if (mode === 'table') {
    void search(1)
    return
  }
  if (query.value.trim().length >= 2) {
    void search(1)
    return
  }
  users.value = []
  pagination.value = null
  searched.value = false
})

onMounted(async () => {
  await loadOrgTree()
  void loadTotal()
  if (viewMode.value === 'table') {
    void search(1)
  }
})

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <section class="pj-page users-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('users.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('users.subtitle') }}</p>
      </div>
      <Button
        v-if="can('users.create')"
        icon="pi pi-plus"
        :label="t('users.new')"
        @click="router.push({ name: 'users.create' })"
      />
    </header>

    <div class="users-layout">
    <aside class="search-panel pj-panel">
      <div class="search-panel__icon"><i class="pi pi-users" /></div>
      <div class="search-panel__content">
        <label for="users-search">{{ t('users.searchLabel') }}</label>
        <div class="search-panel__controls">
          <AppSearchField
            v-model="query"
            input-id="users-search"
            :placeholder="t('users.searchPlaceholder')"
            :aria-label="t('users.searchAction')"
            @search="search(1, true)"
          />
          <Select
            v-model="statusFilter"
            :options="statusOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('users.filterStatus')"
            class="users-filter"
            fluid
          />
          <p v-if="scopeLabel" class="users-filter-scope">{{ scopeLabel }}</p>
          <Select
            v-if="showUnionFilter"
            v-model="orgFilters.unionId"
            :options="unionOptions"
            option-label="nombre"
            option-value="id"
            show-clear
            :placeholder="t('users.filterUnion')"
            class="users-filter"
            fluid
            @update:model-value="clearBelow('union')"
          />
          <Select
            v-if="showAsociacionFilter"
            v-model="orgFilters.asociacionId"
            :options="asociacionOptions"
            option-label="nombre"
            option-value="id"
            show-clear
            :placeholder="t('users.filterAsociacion')"
            class="users-filter"
            fluid
            @update:model-value="clearBelow('asociacion')"
          />
          <Select
            v-if="showDistritoFilter"
            v-model="orgFilters.distritoId"
            :options="distritoOptions"
            option-label="nombre"
            option-value="id"
            show-clear
            :placeholder="t('users.filterDistrito')"
            class="users-filter"
            fluid
            @update:model-value="clearBelow('distrito')"
          />
          <Select
            v-if="showIglesiaFilter"
            v-model="orgFilters.iglesiaId"
            :options="iglesiaOptions"
            option-label="nombre"
            option-value="id"
            show-clear
            :placeholder="t('users.filterIglesia')"
            class="users-filter"
            fluid
            @update:model-value="clearBelow('iglesia')"
          />
          <Select
            v-if="showClubFilter"
            v-model="orgFilters.clubId"
            :options="clubOptions"
            option-label="nombre"
            option-value="id"
            show-clear
            :placeholder="t('users.filterClub')"
            class="users-filter"
            fluid
          />
          <div class="users-views" role="group" :aria-label="t('users.viewMode')">
            <Button
              type="button"
              size="small"
              icon="pi pi-search"
              :outlined="viewMode !== 'search'"
              :label="t('users.viewSearch')"
              @click="viewMode = 'search'"
            />
            <Button
              type="button"
              size="small"
              icon="pi pi-th-large"
              :outlined="viewMode !== 'table'"
              :label="t('users.viewTable')"
              @click="viewMode = 'table'"
            />
          </div>
        </div>
        <small class="search-panel__hint">
          <i class="pi pi-info-circle" />
          {{ viewMode === 'table' ? t('users.tableHint') : t('users.liveSearchHint') }}
        </small>
      </div>
    </aside>

    <div class="users-main">
    <PageLoader v-if="(loadingTotal && !searched && viewMode === 'search') || (loading && viewMode === 'table' && !users.length)" :label="t('common.loading')" />

    <article v-else-if="viewMode === 'search' && !searched" class="total-card pj-panel">
      <div class="search-panel__icon"><i class="pi pi-id-card" /></div>
      <div>
        <strong>{{ t('users.totalUsers', { count: totalUsers }) }}</strong>
        <p>{{ t('users.totalUsersHint') }}</p>
      </div>
    </article>

    <Message v-if="searched && !users.length && !loading" severity="info" :closable="false">
      {{ t('users.empty') }}
    </Message>

    <div v-if="users.length && viewMode === 'search'" class="users-grid">
      <article
        v-for="user in users"
        :key="user.id"
        class="user-card pj-panel"
        :class="{ 'is-active': user.is_active, 'is-inactive': !user.is_active }"
      >
        <div class="user-card__header">
          <div class="user-card__photo-wrap">
            <img
              v-if="photoOf(user)"
              :src="photoOf(user)!"
              :alt="user.name"
              class="user-card__photo"
              @error="onPhotoError(user.id)"
            />
            <Avatar
              v-else
              :label="user.name?.charAt(0)?.toUpperCase()"
              shape="circle"
              size="large"
            />
          </div>
          <div class="user-card__info">
            <h2>{{ user.name }}</h2>
            <span>{{ identityOf(user) }}</span>
            <small>{{ user.email }}</small>
          </div>
          <Tag
            :value="user.is_active ? t('common.active') : t('common.inactive')"
            :severity="user.is_active ? 'success' : 'secondary'"
          />
        </div>

        <dl class="user-card__meta">
          <div>
            <dt>{{ t('users.roles') }}</dt>
            <dd>
              <div v-if="user.roles?.length" class="roles-wrap">
                <Tag v-for="role in user.roles" :key="role.id" :value="roleLabel(role)" severity="info" />
              </div>
              <span v-else class="pj-muted">—</span>
            </dd>
          </div>
        </dl>

        <footer class="user-card__actions">
          <label class="status-toggle">
            <ToggleSwitch
              :model-value="user.is_active"
              :disabled="!can('users.update')"
              @update:model-value="(value: boolean) => onToggleActive(user, value)"
            />
            <span>{{ user.is_active ? t('common.active') : t('common.inactive') }}</span>
          </label>
          <div>
            <Button
              v-if="can('users.update')"
              text
              rounded
              icon="pi pi-pencil"
              :aria-label="t('common.edit')"
              @click="router.push({ name: 'users.edit', params: { id: user.id } })"
            />
            <Button
              v-if="canLoginAs(user)"
              size="small"
              outlined
              icon="pi pi-sign-in"
              :label="t('users.impersonate')"
              @click="impersonateTarget = user"
            />
            <Button
              v-if="can('users.delete')"
              text
              rounded
              severity="danger"
              icon="pi pi-trash"
              :aria-label="t('common.delete')"
              @click="deleteTarget = user"
            />
          </div>
        </footer>
      </article>
    </div>

    <div v-if="viewMode === 'table' && (users.length || searched)" class="pj-panel users-table">
      <DataTable :value="users" data-key="id" striped-rows>
        <template #empty>
          <p class="pj-muted">{{ t('users.empty') }}</p>
        </template>
        <Column :header="t('users.name')" style="min-width: 16rem">
          <template #body="{ data }">
            <div class="table-user">
              <img
                v-if="photoOf(data)"
                :src="photoOf(data)!"
                :alt="data.name"
                class="user-card__photo"
                @error="onPhotoError(data.id)"
              />
              <Avatar
                v-else
                :label="data.name?.charAt(0)?.toUpperCase()"
                shape="circle"
              />
              <div>
                <strong>{{ data.name }}</strong>
                <small>{{ identityOf(data) }}</small>
              </div>
            </div>
          </template>
        </Column>
        <Column :header="t('users.email')" field="email" />
        <Column :header="t('users.roles')">
          <template #body="{ data }">
            <div v-if="data.roles?.length" class="roles-wrap">
              <Tag v-for="role in data.roles" :key="role.id" :value="roleLabel(role)" severity="info" />
            </div>
            <span v-else class="pj-muted">—</span>
          </template>
        </Column>
        <Column :header="t('users.status')" style="width: 8rem">
          <template #body="{ data }">
            <Tag
              :value="data.is_active ? t('common.active') : t('common.inactive')"
              :severity="data.is_active ? 'success' : 'secondary'"
            />
          </template>
        </Column>
        <Column :header="t('common.actions')" style="width: 16rem">
          <template #body="{ data }">
            <div class="actions">
              <Button
                v-if="can('users.update')"
                text
                rounded
                icon="pi pi-pencil"
                :aria-label="t('common.edit')"
                @click="router.push({ name: 'users.edit', params: { id: data.id } })"
              />
              <Button
                v-if="canLoginAs(data)"
                size="small"
                outlined
                icon="pi pi-sign-in"
                :label="t('users.impersonate')"
                @click="impersonateTarget = data"
              />
              <Button
                v-if="can('users.delete')"
                text
                rounded
                severity="danger"
                icon="pi pi-trash"
                :aria-label="t('common.delete')"
                @click="deleteTarget = data"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>

    <footer v-if="pagination && pagination.total > 0" class="results-footer">
      <span class="pj-muted">{{ rangeLabel }}</span>
      <Paginator
        :rows="pagination.per_page"
        :total-records="pagination.total"
        :first="(pagination.current_page - 1) * pagination.per_page"
        template="PrevPageLink PageLinks NextPageLink"
        @page="onPage"
      />
    </footer>
    </div>
    </div>

    <Dialog
      v-model:visible="deleteDialogVisible"
      modal
      :header="t('common.confirm')"
      :style="{ width: 'min(92vw, 420px)' }"
      :closable="!deleting"
    >
      <p>{{ t('users.deleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text :disabled="deleting" @click="deleteTarget = null" />
        <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="confirmDelete" />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="impersonateDialogVisible"
      modal
      :header="t('users.impersonate')"
      :style="{ width: 'min(92vw, 460px)' }"
      :closable="!impersonating"
    >
      <p>{{ t('users.impersonateConfirm', { name: impersonateTarget?.name || '' }) }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text :disabled="impersonating" @click="impersonateTarget = null" />
        <Button
          :label="t('users.impersonate')"
          icon="pi pi-sign-in"
          :loading="impersonating"
          @click="confirmImpersonate"
        />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.users-page {
  max-width: 92rem;
  margin: 0 auto;
}

.users-layout {
  display: grid;
  grid-template-columns: minmax(16.5rem, 20rem) minmax(0, 1fr);
  align-items: start;
  gap: 1rem;
}

.search-panel {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 1rem;
  margin-bottom: 0;
  padding: 1.15rem;
  position: sticky;
  top: 1rem;
}

.search-panel__icon {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 3rem;
  height: 3rem;
  border-radius: 12px;
  color: var(--p-primary-color);
  background: color-mix(in srgb, var(--p-primary-color) 12%, transparent);
  font-size: 1.25rem;
}

.search-panel__content {
  width: 100%;
}

.search-panel__content label {
  display: block;
  margin-bottom: 0.45rem;
  font-weight: 700;
}

.search-panel__controls {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.users-filter {
  min-width: 0;
  width: 100%;
}

.users-filter-scope {
  margin: 0;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.users-views {
  display: flex;
  gap: 0.4rem;
  flex-shrink: 0;
}

.users-table {
  margin-top: 0;
  padding: 0.5rem;
}

.users-main {
  min-width: 0;
}

.table-user {
  display: flex;
  align-items: center;
  gap: 0.7rem;
}

.table-user strong,
.table-user small {
  display: block;
}

.table-user small {
  color: var(--pj-text-muted);
}

.actions {
  display: flex;
  justify-content: flex-end;
}

.search-panel__hint {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.45rem;
  color: var(--pj-text-muted);
}

.total-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.15rem;
}

.total-card strong {
  display: block;
  font-size: 1.65rem;
  line-height: 1.15;
}

.total-card p {
  margin: 0.25rem 0 0;
  color: var(--pj-text-muted);
}

.users-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

.user-card {
  display: grid;
  gap: 0.85rem;
  padding: 1rem;
  border-left: 4px solid var(--pj-border);
}

.user-card.is-active {
  border-left-color: var(--p-green-500);
}

.user-card.is-inactive {
  border-left-color: var(--pj-text-muted);
}

.user-card__header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-card__photo-wrap {
  flex: 0 0 auto;
}

.user-card__photo {
  width: 3.25rem;
  height: 3.25rem;
  border-radius: 50%;
  object-fit: cover;
  display: block;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
}

.user-card__info {
  min-width: 0;
  flex: 1;
}

.user-card__info h2,
.user-card__info span,
.user-card__info small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-card__info h2 {
  margin: 0;
  font-size: 1rem;
}

.user-card__info span,
.user-card__info small {
  color: var(--pj-text-muted);
  font-size: 0.82rem;
}

.user-card__meta {
  margin: 0;
}

.user-card__meta dt {
  color: var(--pj-text-muted);
  font-size: 0.72rem;
  text-transform: uppercase;
}

.user-card__meta dd {
  margin: 0.25rem 0 0;
}

.roles-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.user-card__actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
}

.status-toggle {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--pj-text-muted);
  font-size: 0.82rem;
}

.results-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  margin-top: 1rem;
  padding: 0.5rem 0;
}

@media (max-width: 960px) {
  .users-layout {
    grid-template-columns: 1fr;
  }

  .search-panel {
    position: static;
  }
}

@media (max-width: 640px) {
  .search-panel__icon {
    display: none;
  }

  .user-card__header {
    flex-wrap: wrap;
  }

  .user-card__actions {
    flex-wrap: wrap;
  }

  .results-footer {
    flex-direction: column;
  }
}
</style>
