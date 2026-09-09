<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import OrgHierarchyFilters from '@/components/organizaciones/OrgHierarchyFilters.vue'
import { clubsService } from '@/services/clubsService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import { clubPageScope } from '@/modules/clubs/pageScope'
import type { Club } from '@/modules/clubs/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()
const scope = computed(() => clubPageScope(route.name))

usePageChrome(() => ({
  title: scope.value.isMiClub ? t('miClub.title') : t('clubs.title'),
  subtitle: scope.value.isMiClub ? t('miClub.listSubtitle') : t('clubs.subtitle'),
  actions: can(scope.value.createPerm)
    ? [
        {
          key: 'new',
          label: t('clubs.new'),
          icon: 'pi pi-plus',
          onClick: () => void router.push({ name: scope.value.createRoute }),
        },
      ]
    : [],
}))

const clubs = ref<Club[]>([])
const loading = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<Club | null>(null)
const deleting = ref(false)

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (v: boolean) => {
    if (!v) deleteTarget.value = null
  },
})

const filters = reactive({
  search: '',
  page: 1,
  per_page: 10,
  organizacion_id: null as number | null,
  tipo_club: null as Club['tipos'][number] | null,
})

function tipoLabel(tipo: string): string {
  if (tipo === 'conquistadores') return t('clubs.typeConquistadores')
  if (tipo === 'aventureros') return t('clubs.typeAventureros')
  if (tipo === 'guias_mayores') return t('clubs.typeGuias')
  return tipo
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await clubsService.list({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search || undefined,
      organizacion_id: filters.organizacion_id,
      tipo_club: filters.tipo_club,
    })
    clubs.value = result.items
    pagination.value = result.pagination
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

function onPage(event: { page: number; rows: number }): void {
  filters.page = event.page + 1
  filters.per_page = event.rows
  void load()
}

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await clubsService.remove(deleteTarget.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('clubs.deleteSuccess'), life: 2500 })
    deleteTarget.value = null
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    deleting.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(
  () => filters.search,
  () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
      filters.page = 1
      void load()
    }, 300)
  },
)

watch(
  () => [filters.organizacion_id, filters.tipo_club] as const,
  () => {
    filters.page = 1
    void load()
  },
)

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ scope.isMiClub ? t('miClub.title') : t('clubs.title') }}</h1>
        <p class="pj-page__subtitle">{{ scope.isMiClub ? t('miClub.listSubtitle') : t('clubs.subtitle') }}</p>
      </div>
      <Button
        v-if="can(scope.createPerm)"
        icon="pi pi-plus"
        :label="t('clubs.new')"
        @click="router.push({ name: scope.createRoute })"
      />
    </header>

    <div class="clubs-layout">
      <aside class="search-panel pj-panel">
        <div class="search-panel__icon"><i class="pi pi-flag" /></div>
        <div class="search-panel__content">
          <label for="clubs-search">{{ t('clubs.searchLabel') }}</label>
          <div class="search-panel__controls">
            <AppSearchField
              v-model="filters.search"
              input-id="clubs-search"
              :placeholder="t('clubs.searchPlaceholder')"
            />
            <OrgHierarchyFilters
              v-model:organizacion-id="filters.organizacion_id"
              v-model:tipo-club="filters.tipo_club"
            />
          </div>
          <small class="search-panel__hint">
            <i class="pi pi-info-circle" />
            {{ t('clubs.filterHint') }}
          </small>
        </div>
      </aside>

      <div class="pj-panel clubs-main">
      <PageLoader v-if="loading && !clubs.length" :label="t('common.loading')" />
      <template v-else>
        <DataTable
          :value="clubs"
          data-key="id"
          striped-rows
          lazy
          paginator
          :rows="filters.per_page"
          :total-records="pagination?.total ?? clubs.length"
          :first="((pagination?.current_page ?? 1) - 1) * (pagination?.per_page ?? filters.per_page)"
          :loading="loading"
          @page="onPage"
        >
          <template #empty>
            <p class="pj-muted">{{ t('clubs.empty') }}</p>
          </template>

          <Column :header="t('clubs.logo')" style="width: 4.5rem">
            <template #body="{ data }">
              <img v-if="data.logo_url" :src="data.logo_url" :alt="data.nombre" class="logo" />
              <div v-else class="logo logo--empty"><i class="pi pi-image" /></div>
            </template>
          </Column>
          <Column field="nombre" :header="t('clubs.name')" />
          <Column :header="t('clubs.types')">
            <template #body="{ data }">
              <Tag
                v-if="data.tipos?.[0]"
                severity="info"
                :value="tipoLabel(data.tipos[0])"
              />
              <span v-else class="pj-muted">—</span>
            </template>
          </Column>
          <Column field="distrito" :header="t('clubs.district')" />
          <Column field="ciudad" :header="t('clubs.city')" />
          <Column :header="t('clubs.members')">
            <template #body="{ data }">{{ data.personas_count ?? 0 }}</template>
          </Column>
          <Column :header="t('common.actions')" style="width: 11rem">
            <template #body="{ data }">
              <div class="actions">
                <Button
                  v-if="can(scope.updatePerm)"
                  icon="pi pi-pencil"
                  text
                  rounded
                  @click="router.push({ name: scope.editRoute, params: { id: data.id } })"
                />
                <Button
                  v-if="can(scope.deletePerm)"
                  icon="pi pi-trash"
                  text
                  rounded
                  severity="danger"
                  @click="deleteTarget = data"
                />
              </div>
            </template>
          </Column>
        </DataTable>
      </template>
      </div>
    </div>

    <Dialog v-model:visible="deleteDialogVisible" modal :header="t('common.confirm')" :style="{ width: '28rem' }">
      <p>{{ t('clubs.deleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="confirmDelete" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.clubs-layout {
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
  font-family: var(--pj-font-sans);
  font-weight: 700;
  color: var(--pj-text);
}

.search-panel__controls {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.search-panel__hint {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.45rem;
  color: var(--pj-text-muted);
}

.clubs-main {
  min-width: 0;
}

.logo {
  width: 2.75rem;
  height: 2.75rem;
  object-fit: cover;
  border-radius: 8px;
  display: block;
}

.logo--empty {
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  color: color-mix(in srgb, var(--pj-navy) 45%, transparent);
}

.actions { display: flex; gap: 0.1rem; }

@media (max-width: 960px) {
  .clubs-layout {
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
}
</style>
