<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import PageLoader from '@/components/PageLoader.vue'
import { rolesService } from '@/services/rolesService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import type { ManagedRole } from '@/modules/roles/types'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()
const isMobile = useMediaQuery('(max-width: 899px)')

usePageChrome(() => ({
  title: t('roles.title'),
  subtitle: t('roles.subtitle'),
  actions: can('roles.create')
    ? [
        {
          key: 'new',
          label: t('roles.new'),
          icon: 'pi pi-plus',
          onClick: () => void router.push({ name: 'roles.create' }),
        },
      ]
    : [],
}))

const roles = ref<ManagedRole[]>([])
const loading = ref(false)
const deleteTarget = ref<ManagedRole | null>(null)
const deleting = ref(false)

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
  },
})

async function loadRoles(): Promise<void> {
  loading.value = true
  try {
    roles.value = await rolesService.list()
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

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await rolesService.remove(deleteTarget.value.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('roles.deleteSuccess'),
      life: 2500,
    })
    deleteTarget.value = null
    await loadRoles()
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

onMounted(() => {
  void loadRoles()
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('roles.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('roles.subtitle') }}</p>
      </div>
      <Button
        v-if="can('roles.create')"
        icon="pi pi-plus"
        :label="t('roles.new')"
        @click="router.push({ name: 'roles.create' })"
      />
    </header>

    <div class="pj-panel">
      <PageLoader v-if="loading && !roles.length" :label="t('common.loading')" />

      <DataTable v-else :value="roles" data-key="id" striped-rows>
        <template #empty>
          <p class="pj-muted">{{ t('roles.empty') }}</p>
        </template>

        <Column :header="t('roles.name')" field="display_name">
          <template #body="{ data }">
            <div class="role-name">
              <span v-if="data.icon" class="role-name__icon" aria-hidden="true">
                <i :class="data.icon" />
              </span>
              <div>
                <strong>{{ data.display_name }}</strong>
                <span v-if="!isMobile" class="pj-muted">{{ data.name }}</span>
              </div>
            </div>
          </template>
        </Column>

        <Column v-if="!isMobile" :header="t('roles.description')">
          <template #body="{ data }">
            <span>{{ data.description || '—' }}</span>
          </template>
        </Column>

        <Column v-if="!isMobile" :header="t('roles.usersCount')" style="width: 7rem">
          <template #body="{ data }">
            {{ data.users_count }}
          </template>
        </Column>

        <Column v-if="!isMobile" :header="t('roles.permissionsCount')" style="width: 8rem">
          <template #body="{ data }">
            <span v-if="data.is_super">{{ t('roles.allPermissions') }}</span>
            <span v-else>{{ data.permissions_count }}</span>
          </template>
        </Column>

        <Column :header="t('roles.type')" :style="isMobile ? 'width: auto' : 'width: 10rem'">
          <template #body="{ data }">
            <Tag v-if="data.is_super" severity="danger" :value="t('roles.super')" />
            <Tag v-else-if="data.is_system" severity="info" :value="t('roles.system')" />
            <Tag v-else severity="secondary" :value="t('roles.custom')" />
          </template>
        </Column>

        <Column :header="t('common.actions')" :style="isMobile ? 'width: 5.5rem' : 'width: 9rem'">
          <template #body="{ data }">
            <div class="actions">
              <Button
                v-if="can('roles.update') || can('roles.view')"
                icon="pi pi-pencil"
                text
                rounded
                :disabled="data.is_super"
                :aria-label="t('common.edit')"
                @click="router.push({ name: 'roles.edit', params: { id: data.id } })"
              />
              <Button
                v-if="can('roles.delete') && !data.is_system && !data.is_super"
                icon="pi pi-trash"
                text
                rounded
                severity="danger"
                :aria-label="t('common.delete')"
                @click="deleteTarget = data"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>

    <Dialog
      v-model:visible="deleteDialogVisible"
      modal
      :header="t('common.confirm')"
      :style="{ width: '28rem' }"
    >
      <p>{{ t('roles.deleteConfirm') }}</p>
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
.role-name {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.65rem;
}

.role-name > div {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.role-name__icon {
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 0.55rem;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: color-mix(in srgb, var(--pj-navy) 10%, white);
  color: var(--pj-navy);
}

.actions {
  display: flex;
  gap: 0.15rem;
}

@media (max-width: 899px) {
  .role-name strong {
    overflow-wrap: anywhere;
  }
}
</style>
