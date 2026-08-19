<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
import { usersService } from '@/services/usersService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { User } from '@/modules/auth/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const query = ref('')
const statusFilter = ref<boolean | null>(null)
const users = ref<User[]>([])
const totalUsers = ref(0)
const loading = ref(false)
const loadingTotal = ref(true)
const searched = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<User | null>(null)
const deleting = ref(false)
const perPage = 9
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let requestSequence = 0

const statusOptions = [
  { label: t('common.all'), value: null },
  { label: t('common.active'), value: true },
  { label: t('common.inactive'), value: false },
]

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
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
    const result = await usersService.list({ page: 1, per_page: 1 })
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

async function search(page = 1, showWarning = false): Promise<void> {
  const term = query.value.trim()
  if (term.length < 2) {
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
      per_page: perPage,
      search: term,
      is_active: statusFilter.value,
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
  if (query.value.trim().length < 2) {
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

watch(query, scheduleSearch)
watch(statusFilter, () => {
  if (query.value.trim().length >= 2) void search(1)
})

onMounted(() => {
  void loadTotal()
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

    <div class="search-panel pj-panel">
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
          />
        </div>
        <small class="search-panel__hint">
          <i class="pi pi-info-circle" />
          {{ t('users.liveSearchHint') }}
        </small>
      </div>
    </div>

    <PageLoader v-if="loadingTotal && !searched" :label="t('common.loading')" />

    <article v-else-if="!searched" class="total-card pj-panel">
      <div class="search-panel__icon"><i class="pi pi-id-card" /></div>
      <div>
        <strong>{{ t('users.totalUsers', { count: totalUsers }) }}</strong>
        <p>{{ t('users.totalUsersHint') }}</p>
      </div>
    </article>

    <Message v-if="searched && !users.length && !loading" severity="info" :closable="false">
      {{ t('users.empty') }}
    </Message>

    <div v-if="users.length" class="users-grid">
      <article
        v-for="user in users"
        :key="user.id"
        class="user-card pj-panel"
        :class="{ 'is-active': user.is_active, 'is-inactive': !user.is_active }"
      >
        <div class="user-card__header">
          <Avatar
            :image="user.avatar_url || undefined"
            :label="user.name?.charAt(0)?.toUpperCase()"
            shape="circle"
            size="large"
          />
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
  </section>
</template>

<style scoped>
.users-page {
  max-width: 72rem;
  margin: 0 auto;
}

.search-panel {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
  padding: 1.15rem;
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
  gap: 0.65rem;
}

.users-filter {
  min-width: 10rem;
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
  grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr));
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

@media (max-width: 640px) {
  .search-panel {
    align-items: flex-start;
  }

  .search-panel__controls {
    flex-direction: column;
  }

  .results-footer {
    flex-direction: column;
  }
}
</style>
