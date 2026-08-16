<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Dialog from 'primevue/dialog'
import Avatar from 'primevue/avatar'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import { usersService } from '@/services/usersService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { User } from '@/modules/auth/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const users = ref<User[]>([])
const loading = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<User | null>(null)
const deleting = ref(false)

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
  },
})

const filters = reactive({
  search: '',
  is_active: null as boolean | null,
  page: 1,
  per_page: 10,
})

const statusOptions = [
  { label: t('common.all'), value: null },
  { label: t('common.active'), value: true },
  { label: t('common.inactive'), value: false },
]

async function loadUsers(): Promise<void> {
  loading.value = true
  try {
    const result = await usersService.list({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search || undefined,
      is_active: filters.is_active,
    })
    users.value = result.items
    pagination.value = result.pagination
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

function onPage(event: { page: number; rows: number }): void {
  filters.page = event.page + 1
  filters.per_page = event.rows
  void loadUsers()
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
    await loadUsers()
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

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [filters.search, filters.is_active] as const,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.page = 1
      void loadUsers()
    }, 300)
  },
)

onMounted(() => {
  void loadUsers()
})
</script>

<template>
  <section class="pj-page">
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

    <div class="pj-panel">
      <PageLoader v-if="loading && !users.length" :label="t('common.loading')" />

      <template v-else>
      <div class="pj-toolbar" style="margin-bottom: 1rem">
        <AppSearchField
          v-model="filters.search"
          :placeholder="t('users.searchPlaceholder')"
          class="users-search"
        />
        <Select
          v-model="filters.is_active"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('users.filterStatus')"
          class="users-filter"
        />
      </div>

      <DataTable
        :value="users"
        :loading="loading"
        data-key="id"
        striped-rows
        paginator
        lazy
        :rows="filters.per_page"
        :total-records="pagination?.total ?? 0"
        :first="((pagination?.current_page ?? 1) - 1) * (pagination?.per_page ?? filters.per_page)"
        :rows-per-page-options="[10, 20, 50]"
        @page="onPage"
      >
        <template #empty>
          <div class="pj-empty">{{ t('users.empty') }}</div>
        </template>

        <Column :header="t('users.name')">
          <template #body="{ data }">
            <div class="user-cell">
              <Avatar
                :image="data.avatar_url || undefined"
                :label="data.name?.charAt(0)?.toUpperCase()"
                shape="circle"
              />
              <div>
                <strong>{{ data.name }}</strong>
                <div class="pj-muted">{{ data.email }}</div>
              </div>
            </div>
          </template>
        </Column>

        <Column :header="t('users.roles')">
          <template #body="{ data }">
            <div class="roles-wrap">
              <Tag
                v-for="role in data.roles || []"
                :key="role.id"
                :value="role.label || role.name"
                severity="info"
              />
              <span v-if="!(data.roles || []).length" class="pj-muted">—</span>
            </div>
          </template>
        </Column>

        <Column :header="t('users.status')" style="width: 8rem">
          <template #body="{ data }">
            <div class="status-cell">
              <ToggleSwitch
                :model-value="data.is_active"
                :disabled="!can('users.update')"
                @update:model-value="(v: boolean) => onToggleActive(data, v)"
              />
              <span class="pj-muted">{{ data.is_active ? t('common.active') : t('common.inactive') }}</span>
            </div>
          </template>
        </Column>

        <Column :header="t('common.actions')" style="width: 8rem">
          <template #body="{ data }">
            <div class="actions-cell">
              <Button
                v-if="can('users.update')"
                text
                rounded
                icon="pi pi-pencil"
                :aria-label="t('common.edit')"
                @click="router.push({ name: 'users.edit', params: { id: data.id } })"
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
      </template>
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
  </section>
</template>

<style scoped>
.users-search {
  flex: 1 0 100%;
  min-width: 0;
}

.users-filter {
  min-width: 160px;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.roles-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.status-cell,
.actions-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
</style>
