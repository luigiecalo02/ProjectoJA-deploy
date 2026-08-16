<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Message from 'primevue/message'
import Paginator from 'primevue/paginator'
import Tag from 'primevue/tag'
import AppSearchField from '@/components/AppSearchField.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import type { SeguroConsultaResultado } from '@/modules/events/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const toast = useToast()
const query = ref('')
const loading = ref(false)
const searched = ref(false)
const results = ref<SeguroConsultaResultado[]>([])
const pagination = ref<PaginationMeta | null>(null)
const perPage = 9
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let requestSequence = 0

const rangeLabel = computed(() => {
  if (!pagination.value?.total) return ''
  const from = (pagination.value.current_page - 1) * pagination.value.per_page + 1
  const to = Math.min(
    pagination.value.current_page * pagination.value.per_page,
    pagination.value.total,
  )
  return t('segurosConsulta.resultsRange', { from, to, total: pagination.value.total })
})

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const [year, month, day] = value.slice(0, 10).split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString('es-CO')
}

async function search(page = 1, showWarning = false): Promise<void> {
  const term = query.value.trim()
  if (term.length < 2) {
    results.value = []
    pagination.value = null
    searched.value = false
    if (showWarning) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('segurosConsulta.minimumSearch'),
        life: 2500,
      })
    }
    return
  }

  const sequence = ++requestSequence
  loading.value = true
  try {
    const response = await eventsService.consultarSeguros(term, page, perPage)
    if (sequence !== requestSequence) return
    results.value = response.items
    pagination.value = response.pagination
    searched.value = true
  } catch (error) {
    if (sequence !== requestSequence) return
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 3500,
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
    results.value = []
    pagination.value = null
    searched.value = false
    return
  }
  debounceTimer = setTimeout(() => void search(1), 350)
}

function onPage(event: { page: number }): void {
  void search(event.page + 1)
}

watch(query, scheduleSearch)

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <section class="pj-page insurance-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('segurosConsulta.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('segurosConsulta.subtitle') }}</p>
      </div>
    </header>

    <div class="search-panel pj-panel">
      <div class="search-panel__icon"><i class="pi pi-shield" /></div>
      <div class="search-panel__content">
        <label for="insurance-search">{{ t('segurosConsulta.searchLabel') }}</label>
        <div class="search-panel__controls">
          <AppSearchField
            v-model="query"
            input-id="insurance-search"
            :placeholder="t('segurosConsulta.searchPlaceholder')"
            :aria-label="t('segurosConsulta.searchAction')"
            @search="search(1, true)"
          />
        </div>
        <small class="search-panel__hint">
          <i class="pi pi-info-circle" />
          {{ t('segurosConsulta.liveSearchHint') }}
        </small>
      </div>
    </div>

    <Message v-if="searched && !results.length" severity="info" :closable="false">
      {{ t('segurosConsulta.empty') }}
    </Message>

    <div v-if="results.length" class="insurance-grid">
      <article
        v-for="item in results"
        :key="item.persona_id"
        class="insurance-card pj-panel"
        :class="{ 'is-active': item.vigente, 'is-inactive': !item.vigente }"
      >
        <div class="insurance-card__header">
          <div class="person-avatar"><i class="pi pi-user" /></div>
          <div class="person-info">
            <h2>{{ item.nombre }}</h2>
            <span>{{ item.tipo_identificacion }} {{ item.identificacion }}</span>
          </div>
          <Tag
            :value="item.vigente ? t('segurosConsulta.valid') : t('segurosConsulta.notValid')"
            :severity="item.vigente ? 'success' : 'danger'"
          />
        </div>

        <div v-if="item.vigente" class="days-box">
          <strong>{{ item.dias_restantes }}</strong>
          <span>
            {{
              item.dias_restantes === 1
                ? t('segurosConsulta.dayRemaining')
                : t('segurosConsulta.daysRemaining')
            }}
          </span>
        </div>

        <dl v-if="item.seguro" class="insurance-detail">
          <div>
            <dt>{{ t('segurosConsulta.type') }}</dt>
            <dd>{{ item.seguro.tipo || '—' }}</dd>
          </div>
          <div>
            <dt>{{ t('segurosConsulta.startDate') }}</dt>
            <dd>{{ formatDate(item.seguro.fecha_inicio) }}</dd>
          </div>
          <div>
            <dt>{{ t('segurosConsulta.endDate') }}</dt>
            <dd>{{ formatDate(item.seguro.fecha_fin) }}</dd>
          </div>
          <div v-if="item.seguro.evento">
            <dt>{{ t('segurosConsulta.event') }}</dt>
            <dd>{{ item.seguro.evento }}</dd>
          </div>
        </dl>
        <p v-else class="no-insurance">{{ t('segurosConsulta.neverInsured') }}</p>
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
  </section>
</template>

<style scoped>
.insurance-page {
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

.search-panel__icon,
.person-avatar {
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

.search-panel__content,
.search-input {
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

.search-panel__hint {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.45rem;
  color: var(--pj-text-muted);
}

.insurance-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

.insurance-card {
  position: relative;
  overflow: hidden;
  padding: 1rem;
  border-left: 4px solid var(--p-red-500);
}

.insurance-card.is-active {
  border-left-color: var(--p-green-500);
}

.insurance-card__header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.person-info {
  min-width: 0;
  flex: 1;
}

.person-info h2 {
  margin: 0;
  font-size: 1rem;
}

.person-info span {
  color: var(--pj-text-muted);
  font-size: 0.82rem;
}

.days-box {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin: 1rem 0;
  padding: 0.8rem;
  border-radius: 10px;
  color: var(--p-green-700);
  background: color-mix(in srgb, var(--p-green-500) 10%, transparent);
}

.days-box strong {
  font-size: 2rem;
  line-height: 1;
}

.insurance-detail {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
  margin: 1rem 0 0;
}

.insurance-detail div {
  min-width: 0;
}

.insurance-detail dt {
  color: var(--pj-text-muted);
  font-size: 0.72rem;
  text-transform: uppercase;
}

.insurance-detail dd {
  margin: 0.15rem 0 0;
  font-weight: 650;
}

.no-insurance {
  margin: 1rem 0 0;
  color: var(--pj-text-muted);
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
