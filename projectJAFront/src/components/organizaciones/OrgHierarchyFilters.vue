<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import { useOrgHierarchyFilters } from '@/composables/useOrgHierarchyFilters'
import type { ClubMinistry } from '@/modules/clubs/types'

const organizacionId = defineModel<number | null>('organizacionId', { default: null })
const tipoClub = defineModel<ClubMinistry | null>('tipoClub', { default: null })

const { t } = useI18n()
const {
  orgFilters,
  tipoClub: tipoClubFilter,
  tipoClubOptions,
  unionOptions,
  asociacionOptions,
  zonaOptions,
  distritoOptions,
  iglesiaOptions,
  selectedOrganizacionId,
  showUnionFilter,
  showAsociacionFilter,
  showZonaFilter,
  showDistritoFilter,
  showIglesiaFilter,
  showTipoClubFilter,
  scopeLabel,
  clearBelow,
  loadOrgTree,
} = useOrgHierarchyFilters({ alwaysShowTipoClub: true })

watch(selectedOrganizacionId, (id) => {
  organizacionId.value = id
}, { immediate: true })

watch(tipoClubFilter, (value) => {
  tipoClub.value = value
}, { immediate: true })

watch(tipoClub, (value) => {
  if (tipoClubFilter.value !== value) {
    tipoClubFilter.value = value
  }
})

onMounted(() => {
  void loadOrgTree()
})
</script>

<template>
  <div class="org-filter-stack">
    <p v-if="scopeLabel" class="org-filter-scope">{{ scopeLabel }}</p>
    <Select
      v-if="showUnionFilter"
      v-model="orgFilters.unionId"
      :options="unionOptions"
      option-label="nombre"
      option-value="id"
      show-clear
      :placeholder="t('users.filterUnion')"
      class="org-filter"
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
      class="org-filter"
      fluid
      @update:model-value="clearBelow('asociacion')"
    />
    <Select
      v-if="showZonaFilter"
      v-model="orgFilters.zonaId"
      :options="zonaOptions"
      option-label="nombre"
      option-value="id"
      show-clear
      :placeholder="t('users.filterZona')"
      class="org-filter"
      fluid
      @update:model-value="clearBelow('zona')"
    />
    <Select
      v-if="showDistritoFilter"
      v-model="orgFilters.distritoId"
      :options="distritoOptions"
      option-label="nombre"
      option-value="id"
      show-clear
      :placeholder="t('users.filterDistrito')"
      class="org-filter"
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
      class="org-filter"
      fluid
      @update:model-value="clearBelow('iglesia')"
    />
    <Select
      v-if="showTipoClubFilter"
      v-model="tipoClubFilter"
      :options="tipoClubOptions"
      option-label="label"
      option-value="value"
      :placeholder="t('users.filterTipoClub')"
      class="org-filter"
      fluid
    />
  </div>
</template>

<style scoped>
.org-filter-stack {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.org-filter {
  min-width: 0;
  width: 100%;
}

.org-filter :deep(.p-select-label),
.org-filter :deep(.p-select-label.p-placeholder) {
  color: #071e48;
}

.org-filter :deep(.p-select-label.p-placeholder) {
  color: #5b6b82;
}

.org-filter-scope {
  margin: 0;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}
</style>
