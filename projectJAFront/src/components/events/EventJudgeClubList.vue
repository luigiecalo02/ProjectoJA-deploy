<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import EventSearchPanel from '@/components/events/EventSearchPanel.vue'
import type { JudgeClubResumen } from '@/modules/events/types'

type ClubFilter = 'todos' | 'pendientes' | 'evaluados'
type ClubSort = 'nombre_asc' | 'nombre_desc' | 'fecha_asc' | 'fecha_desc'

const search = defineModel<string>('search', { required: true })
const clubFilter = defineModel<ClubFilter>('clubFilter', { required: true })

const props = withDefaults(
  defineProps<{
    clubs: JudgeClubResumen[]
    selectedOrgId: number | null
    isEventFirst: boolean
    hasEventSelection: boolean
    filterCounts: { todos: number; pendientes: number; evaluados: number }
    clubSort: ClubSort
    clubSortOptions: { value: ClubSort; label: string }[]
    showPhaseChip?: boolean
    clubTurn: (index: number) => number
    clubLogoSrc: (url?: string | null) => string | null
    clubEnrolledLabel: (club: JudgeClubResumen) => string
    clubPendingCount: (club: JudgeClubResumen) => number
    clubStatusMeta: (estado: string) => { label: string; css: string }
  }>(),
  {
    showPhaseChip: true,
  },
)

const emit = defineEmits<{
  persistSort: [value: ClubSort]
  select: [club: JudgeClubResumen]
}>()

const { t } = useI18n()

const stepNumber = computed(() => (props.isEventFirst ? 2 : 1))
const stepReady = computed(() => (props.isEventFirst ? props.hasEventSelection : true))
const stepTitle = computed(() =>
  props.isEventFirst ? t('events.judgePhaseClubsOrder') : t('events.judgePhaseClub'),
)
const stepHint = computed(() => {
  if (!props.isEventFirst) return t('events.judgePhaseClubHint')
  return props.hasEventSelection
    ? t('events.judgePhaseClubsOrderHint')
    : t('events.judgePhaseClubsOrderLocked')
})
</script>

<template>
  <div class="judge-club-list" :class="{ 'judge-club-list--wrap-names': isEventFirst }">
    <div class="list-head">
      <div v-if="showPhaseChip" class="phase-chip">
        <span class="phase-chip__step" :class="{ 'is-ready': stepReady }">
          {{ stepNumber }}
        </span>
        <div>
          <strong>{{ stepTitle }}</strong>
          <p class="pj-muted">{{ stepHint }}</p>
        </div>
      </div>
      <EventSearchPanel
        v-model="search"
        input-id="judge-club-search"
        icon="pi pi-users"
        :label="t('events.standingsSearchLabel')"
        :placeholder="t('events.judgeSearchClub')"
        :hint="t('segurosConsulta.liveSearchHint')"
      />
      <div v-if="isEventFirst" class="club-sort">
        <label for="judge-club-sort">{{ t('events.judgeClubSort') }}</label>
        <Select
          input-id="judge-club-sort"
          :model-value="clubSort"
          :options="clubSortOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          :disabled="!hasEventSelection"
          @update:model-value="emit('persistSort', $event)"
        />
      </div>
      <div class="filter-tabs">
        <button
          type="button"
          :class="{ active: clubFilter === 'todos' }"
          @click="clubFilter = 'todos'"
        >
          {{ t('events.judgeFilterAll') }} ({{ filterCounts.todos }})
        </button>
        <button
          type="button"
          :class="{ active: clubFilter === 'pendientes' }"
          @click="clubFilter = 'pendientes'"
        >
          {{ t('events.judgeFilterPending') }} ({{ filterCounts.pendientes }})
        </button>
        <button
          type="button"
          :class="{ active: clubFilter === 'evaluados' }"
          @click="clubFilter = 'evaluados'"
        >
          {{ t('events.judgeFilterScored') }} ({{ filterCounts.evaluados }})
        </button>
      </div>
    </div>

    <div v-if="isEventFirst && !hasEventSelection" class="tree-lock">
      <i class="pi pi-lock" />
      <p>{{ t('events.judgeSelectEventFirst') }}</p>
    </div>

    <button
      v-for="(club, index) in clubs"
      v-show="!isEventFirst || hasEventSelection"
      :key="club.organizacion_id"
      type="button"
      class="club-item"
      :class="{ active: selectedOrgId === club.organizacion_id }"
      @click="emit('select', club)"
    >
      <span
        v-if="isEventFirst"
        class="club-item__turn"
        :title="t('events.judgeClubTurn', { n: clubTurn(index) })"
      >
        {{ clubTurn(index) }}
      </span>
      <div class="club-item__avatar">
        <img
          v-if="clubLogoSrc(club.logo_url)"
          :src="clubLogoSrc(club.logo_url) || ''"
          :alt="club.nombre"
        />
        <i v-else class="pi pi-building" />
      </div>
      <div class="club-item__body">
        <strong>{{ club.nombre }}</strong>
        <span class="pj-muted">
          {{ t('events.judgeEvidencesCount', { count: club.evidencias_count }) }}
          <template v-if="isEventFirst && clubEnrolledLabel(club)">
            · {{ clubEnrolledLabel(club) }}
          </template>
        </span>
        <span class="status-badge" :class="clubStatusMeta(club.estado).css">
          {{ clubStatusMeta(club.estado).label }}
        </span>
      </div>
      <div class="club-item__pending">
        <span
          v-if="clubPendingCount(club) > 0"
          class="pending-badge"
          :title="t('events.judgeClubPendingEvents', { count: clubPendingCount(club) })"
        >
          {{ clubPendingCount(club) > 99 ? '99+' : clubPendingCount(club) }}
        </span>
        <small v-else class="pj-muted">{{ t('events.judgeClubNoPending') }}</small>
      </div>
    </button>

    <p v-if="(!isEventFirst || hasEventSelection) && !clubs.length" class="pj-muted empty">
      {{ t('events.judgeClubsEmpty') }}
    </p>
  </div>
</template>

<style scoped>
.judge-club-list {
  display: grid;
  gap: 0.55rem;
  align-content: start;
}

.list-head {
  display: grid;
  gap: 0.55rem;
  position: sticky;
  top: 0;
  background: var(--pj-bg-elevated, var(--pj-surface, #fff));
  z-index: 1;
  padding-bottom: 0.35rem;
}

.filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.filter-tabs button {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  border-radius: 999px;
  padding: 0.28rem 0.65rem;
  font-size: 0.75rem;
  font-weight: 650;
  color: #071e48;
  cursor: pointer;
}

.filter-tabs button.active {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-color: #2563eb;
  color: #1d4ed8;
}

.club-item__turn {
  width: 1.7rem;
  height: 1.7rem;
  border-radius: 999px;
  display: grid;
  place-items: center;
  font-size: 0.75rem;
  font-weight: 800;
  color: #1d4ed8;
  background: color-mix(in srgb, #2563eb 14%, transparent);
}

.club-item {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.65rem;
  align-items: center;
  width: 100%;
  text-align: left;
  padding: 0.65rem;
  border-radius: 12px;
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
}

.club-item:has(.club-item__turn) {
  grid-template-columns: auto auto 1fr auto;
}

.club-item:hover,
.club-item.active {
  background: color-mix(in srgb, #2563eb 8%, transparent);
  border-color: color-mix(in srgb, #2563eb 25%, transparent);
}

.club-item__avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 10px;
  overflow: hidden;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
  flex-shrink: 0;
}

.club-item__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.club-item__body {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.club-item__body strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #071e48;
}

.club-sort {
  display: grid;
  gap: 0.3rem;
}

.club-sort label {
  font-size: 0.75rem;
  font-weight: 650;
  color: #5b6b82;
}

.club-sort :deep(.p-select) {
  width: 100%;
}

.club-item__pending {
  display: grid;
  justify-items: end;
  align-content: center;
  gap: 0.2rem;
  min-width: 3.2rem;
}

.pending-badge {
  display: inline-grid;
  place-items: center;
  min-width: 1.7rem;
  height: 1.7rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 800;
  background: color-mix(in srgb, #ea580c 18%, transparent);
  color: #c2410c;
  border: 1px solid color-mix(in srgb, #ea580c 35%, transparent);
}

.phase-chip {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  margin-bottom: 0.35rem;
}

.phase-chip__step {
  width: 1.55rem;
  height: 1.55rem;
  border-radius: 999px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  font-size: 0.78rem;
  font-weight: 800;
  background: color-mix(in srgb, #94a3b8 20%, transparent);
  color: #475569;
}

.phase-chip__step.is-ready {
  background: color-mix(in srgb, #0f766e 20%, transparent);
  color: #0f766e;
}

.phase-chip strong {
  display: block;
  font-family: var(--pj-font-sans);
  font-size: 0.95rem;
  font-weight: 700;
  letter-spacing: 0;
  line-height: 1.25;
  color: var(--pj-text);
}

.phase-chip .pj-muted {
  margin: 0.1rem 0 0;
  font-size: 0.75rem;
  line-height: 1.3;
  color: var(--pj-text-muted);
}

.tree-lock {
  display: grid;
  place-items: center;
  gap: 0.55rem;
  min-height: 12rem;
  padding: 1.5rem 1rem;
  text-align: center;
  color: var(--pj-text-muted, #64748b);
  border: 1px dashed color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 12px;
}

.tree-lock i {
  font-size: 1.4rem;
}

.status-badge {
  display: inline-flex;
  align-self: flex-start;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
}

.status-badge.is-scored {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.status-badge.is-pending {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.empty {
  margin: 1rem 0;
}

@media (max-width: 900px) {
  .club-item__body strong {
    white-space: normal;
    overflow: visible;
    text-overflow: unset;
    line-height: 1.3;
  }
}

@media (min-width: 901px) {
  .judge-club-list--wrap-names .club-item__body strong {
    white-space: normal;
    overflow: visible;
    text-overflow: unset;
    line-height: 1.3;
  }
}
</style>
