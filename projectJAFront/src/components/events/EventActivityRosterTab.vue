<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type { ActivityRoster, ActivityRosterCandidato } from '@/modules/events/types'

const props = defineProps<{
  actividadId: number
  locked?: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const roster = ref<ActivityRoster | null>(null)
const selectedIds = ref<number[]>([])
const activeTab = ref<'inscritos' | 'seleccionar'>('inscritos')

const candidatos = computed(() => roster.value?.candidatos ?? [])
const config = computed(() => roster.value?.actividad ?? null)
const locked = computed(() => Boolean(props.locked || roster.value?.bloqueada))

const selectedMembers = computed(() =>
  candidatos.value.filter((row) => selectedIds.value.includes(row.id)),
)
const selectedM = computed(() => selectedMembers.value.filter((row) => row.sexo === 'M').length)
const selectedF = computed(() => selectedMembers.value.filter((row) => row.sexo === 'F').length)

const needM = computed(() => {
  if (config.value?.participantes_genero !== 'mixto') return 0
  return Math.max(0, (config.value.participantes_min_m ?? 0) - selectedM.value)
})
const needF = computed(() => {
  if (config.value?.participantes_genero !== 'mixto') return 0
  return Math.max(0, (config.value.participantes_min_f ?? 0) - selectedF.value)
})
const overM = computed(() => {
  if (config.value?.participantes_genero !== 'mixto' || config.value.participantes_max_m == null) {
    return 0
  }
  return Math.max(0, selectedM.value - config.value.participantes_max_m)
})
const overF = computed(() => {
  if (config.value?.participantes_genero !== 'mixto' || config.value.participantes_max_f == null) {
    return 0
  }
  return Math.max(0, selectedF.value - config.value.participantes_max_f)
})

const countLabel = computed(() => {
  const selected = selectedIds.value.length
  if (config.value?.participantes_genero === 'mixto') {
    const minM = config.value.participantes_min_m ?? 0
    const maxM = config.value.participantes_max_m
    const minF = config.value.participantes_min_f ?? 0
    const maxF = config.value.participantes_max_f
    const mRange = maxM != null ? `${minM}–${maxM}` : String(minM)
    const fRange = maxF != null ? `${minF}–${maxF}` : String(minF)
    return `${selected} · M ${selectedM.value}/${mRange} · F ${selectedF.value}/${fRange}`
  }
  const min = config.value?.participantes_min ?? 0
  const max = config.value?.participantes_max
  if (max != null) {
    return t('events.activityRosterCount', { selected, min, max })
  }
  return t('events.activityRosterCountMin', { selected, min })
})

function genderLabel(row: ActivityRosterCandidato): string {
  if (row.sexo === 'M') return t('events.activityRosterMale')
  if (row.sexo === 'F') return t('events.activityRosterFemale')
  return '—'
}

function toggle(row: ActivityRosterCandidato, value: boolean): void {
  if (locked.value) return
  if (value) {
    if (!selectedIds.value.includes(row.id)) selectedIds.value = [...selectedIds.value, row.id]
    return
  }
  selectedIds.value = selectedIds.value.filter((id) => id !== row.id)
}

async function load(): Promise<void> {
  loading.value = true
  try {
    roster.value = await eventsService.activityRoster(props.actividadId)
    selectedIds.value = [...(roster.value.seleccionados ?? [])]
    if (!selectedIds.value.length && !locked.value) {
      activeTab.value = 'seleccionar'
    } else {
      activeTab.value = 'inscritos'
    }
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

async function save(): Promise<void> {
  if (locked.value) return
  saving.value = true
  try {
    roster.value = await eventsService.syncActivityRoster(props.actividadId, selectedIds.value)
    selectedIds.value = [...(roster.value.seleccionados ?? [])]
    activeTab.value = 'inscritos'
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.activityRosterSaved'),
      life: 2500,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

watch(
  () => props.actividadId,
  () => {
    void load()
  },
)

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="roster">
    <p v-if="locked" class="roster__lock">{{ t('events.activityRosterLocked') }}</p>
    <p v-else class="pj-muted">{{ t('events.activityRosterHint') }}</p>
    <div class="roster__meta">
      <strong>{{ countLabel }}</strong>
      <span v-if="config?.participantes_genero === 'mixto'">
        {{ t('events.activityRosterGenderMix', { m: selectedM, f: selectedF }) }}
      </span>
      <span v-if="needM > 0" class="roster__warn">{{ t('events.activityRosterNeedM', { count: needM }) }}</span>
      <span v-if="needF > 0" class="roster__warn">{{ t('events.activityRosterNeedF', { count: needF }) }}</span>
      <span v-if="overM > 0" class="roster__warn">{{ t('events.activityRosterOverM', { count: overM }) }}</span>
      <span v-if="overF > 0" class="roster__warn">{{ t('events.activityRosterOverF', { count: overF }) }}</span>
    </div>

    <p v-if="loading" class="pj-muted">{{ t('common.loading') }}</p>

    <Tabs v-else v-model:value="activeTab" class="roster__tabs">
      <TabList>
        <Tab value="inscritos">
          <i class="pi pi-check-circle" />
          <span>{{ t('events.activityRosterTabEnrolled') }}</span>
          <em v-if="selectedMembers.length">{{ selectedMembers.length }}</em>
        </Tab>
        <Tab value="seleccionar" :disabled="locked">
          <i class="pi pi-users" />
          <span>{{ t('events.activityRosterTabSelect') }}</span>
        </Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="inscritos">
          <p v-if="!selectedMembers.length" class="pj-muted">{{ t('events.activityRosterEnrolledEmpty') }}</p>
          <ul v-else class="roster__list">
            <li v-for="row in selectedMembers" :key="row.id">
              <div class="roster__person">
                <strong>{{ row.nombre }}</strong>
                <small>
                  {{ genderLabel(row) }}
                  <em v-if="!row.inscrito_evento"> · {{ t('events.activityRosterNotInEvent') }}</em>
                </small>
              </div>
            </li>
          </ul>
        </TabPanel>
        <TabPanel value="seleccionar">
          <p v-if="!candidatos.length" class="pj-muted">{{ t('events.activityRosterEmpty') }}</p>
          <template v-else>
            <ul class="roster__list">
              <li v-for="row in candidatos" :key="row.id">
                <Checkbox
                  :model-value="selectedIds.includes(row.id)"
                  :binary="true"
                  :disabled="locked"
                  :input-id="`roster-${row.id}`"
                  @update:model-value="toggle(row, Boolean($event))"
                />
                <label :for="`roster-${row.id}`">
                  <strong>{{ row.nombre }}</strong>
                  <small>
                    {{ genderLabel(row) }}
                    <em v-if="!row.inscrito_evento"> · {{ t('events.activityRosterNotInEvent') }}</em>
                  </small>
                </label>
              </li>
            </ul>
            <Button
              type="button"
              :label="t('events.activityRosterSave')"
              icon="pi pi-save"
              :loading="saving"
              :disabled="locked || loading || !candidatos.length"
              @click="save"
            />
          </template>
        </TabPanel>
      </TabPanels>
    </Tabs>
  </div>
</template>

<style scoped>
.roster {
  display: grid;
  gap: 0.75rem;
}

.roster__lock {
  margin: 0;
  padding: 0.55rem 0.75rem;
  border-radius: 10px;
  background: color-mix(in srgb, #2563eb 10%, transparent);
  color: #1d4ed8;
  font-weight: 650;
  font-size: 0.9rem;
}

.roster__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem 1rem;
  align-items: center;
  font-size: 0.88rem;
}

.roster__warn {
  color: #b45309;
  font-weight: 650;
}

.roster__tabs :deep(.p-tablist-tab-list) {
  gap: 0.35rem;
}

.roster__tabs :deep(.p-tab) {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.roster__tabs :deep(.p-tab em) {
  font-style: normal;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 0.05rem 0.4rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--p-primary-color) 14%, transparent);
}

.roster__list {
  list-style: none;
  margin: 0 0 0.85rem;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.roster__list li {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  padding: 0.55rem 0.7rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
}

.roster__list label,
.roster__person {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.roster__list small {
  color: var(--pj-text-muted, #64748b);
}

.roster__list em {
  font-style: normal;
  font-weight: 650;
}
</style>
