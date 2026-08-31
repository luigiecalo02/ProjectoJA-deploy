<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import AppSearchField from '@/components/AppSearchField.vue'
import PageLoader from '@/components/PageLoader.vue'
import Select from 'primevue/select'
import { cabanasService } from '@/services/cabanasService'
import { usersService } from '@/services/usersService'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { Cabana, CabanaBed, AlojamientoCupo, EventoCabana } from '@/modules/cabanas/types'
import type { User } from '@/modules/auth/types'

const props = defineProps<{ eventId: number | null; lugarId?: number | null }>()
const emit = defineEmits<{ summary: [payload: { cabanas: number; capacidad: number }] }>()
const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()
const catalog = ref<Cabana[]>([])
const selectedIds = ref<number[]>([])
const configured = ref<EventoCabana[]>([])
const search = ref('')
const loading = ref(false)
const saving = ref(false)
const savingPrices = ref(false)

type PriceRow = {
  id: number
  cabana: string
  cuarto: string
  codigo: string
  tipo: string
  tipoKey: 'sencilla' | 'camarote' | string
  nivel: string | null
  nivelKey: 'arriba' | 'abajo' | null
  sugerido: number | null
  precio: number | null
}

const priceRows = ref<PriceRow[]>([])
const bulkTop = ref<number | null>(null)
const bulkBottom = ref<number | null>(null)
const bulkSingle = ref<number | null>(null)
const canManage = computed(() => can('events.update') || can('cabanas.assign'))
const quotaRows = ref<AlojamientoCupo[]>([])
const quotaPool = ref({ capacidad: 0, ocupadas: 0, reservados: 0, libres: 0 })
const userOptions = ref<User[]>([])
const newUserId = ref<number | null>(null)
const newCupos = ref<number | null>(1)
const savingQuotas = ref(false)
const reservedCupos = computed(() => quotaRows.value.reduce((sum, row) => sum + Number(row.cupos || 0), 0))
const quotaCapacity = computed(() => quotaPool.value.capacidad || totalCapacity.value)
const canAddQuota = computed(() => {
  if (!newUserId.value || !newCupos.value || newCupos.value < 1) return false
  if (quotaRows.value.some((row) => row.user_id === newUserId.value)) return false
  return reservedCupos.value + newCupos.value <= quotaCapacity.value
})
const filtered = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('es')
  return term ? catalog.value.filter((item) => item.nombre.toLocaleLowerCase('es').includes(term)) : catalog.value
})
const selectedCabanas = computed(() =>
  selectedIds.value.map((id) => catalog.value.find((item) => item.id === id)).filter((item): item is Cabana => !!item),
)
const totalCapacity = computed(() => selectedCabanas.value.reduce((sum, item) => sum + Number(item.capacidad_total ?? 0), 0))

function money(value: number): string {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value)
}

function emitSummary(): void {
  emit('summary', { cabanas: selectedIds.value.length, capacidad: totalCapacity.value })
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [available, eventCabanas, users, cupos] = await Promise.all([
      cabanasService.list({ per_page: 200, estado: 'activa', lugar_id: props.lugarId ?? undefined }),
      props.eventId ? cabanasService.getEventCabanas(props.eventId) : Promise.resolve([] as EventoCabana[]),
      canManage.value ? usersService.list({ per_page: 100, is_active: true }).catch(() => ({ items: [] as User[] })) : Promise.resolve({ items: [] as User[] }),
      props.eventId && canManage.value
        ? cabanasService.getAlojamientoCupos(props.eventId).catch(() => null)
        : Promise.resolve(null),
    ])
    catalog.value = available.items
    configured.value = eventCabanas.sort((a, b) => a.orden - b.orden)
    selectedIds.value = configured.value.map((item) => item.cabana_id)
    userOptions.value = users.items
    if (cupos) {
      quotaRows.value = cupos.items
      quotaPool.value = {
        capacidad: cupos.capacidad,
        ocupadas: cupos.ocupadas,
        reservados: cupos.reservados,
        libres: cupos.libres,
      }
    }
    hydratePrices(configured.value)
    emitSummary()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

function toggle(id: number): void {
  if (!canManage.value) return
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((value) => value !== id)
    : [...selectedIds.value, id]
  emitSummary()
}

function move(id: number, direction: -1 | 1): void {
  const index = selectedIds.value.indexOf(id)
  const target = index + direction
  if (index < 0 || target < 0 || target >= selectedIds.value.length) return
  const next = [...selectedIds.value]
  ;[next[index], next[target]] = [next[target], next[index]]
  selectedIds.value = next
}

function nivelLabel(bed: CabanaBed): string | null {
  if (bed.tipo !== 'camarote') return null
  return bed.nivel_camarote === 'arriba' ? t('cabanas.bunkTop') : t('cabanas.bunkBottom')
}

function hydratePrices(items: EventoCabana[]): void {
  priceRows.value = items.flatMap((cabana) =>
    (cabana.pisos ?? []).flatMap((floor) =>
      (floor.cuartos ?? []).flatMap((room) =>
        (room.camas ?? []).map((bed) => ({
          id: bed.id,
          cabana: cabana.nombre,
          cuarto: room.nombre,
          codigo: bed.codigo,
          tipo: bed.tipo === 'camarote' ? t('cabanas.bedBunk') : t('cabanas.bedSingle'),
          tipoKey: bed.tipo === 'camarote' ? 'camarote' : 'sencilla',
          nivel: nivelLabel(bed),
          nivelKey: bed.tipo === 'camarote' ? (bed.nivel_camarote === 'arriba' ? 'arriba' : 'abajo') : null,
          sugerido: bed.precio_sugerido ?? null,
          precio: bed.precio ?? bed.precio_sugerido ?? null,
        })),
      ),
    ),
  )
}

function applyBulkPrice(kind: 'arriba' | 'abajo' | 'sencilla'): void {
  const value = kind === 'arriba' ? bulkTop.value : kind === 'abajo' ? bulkBottom.value : bulkSingle.value
  if (value == null || !canManage.value) return
  priceRows.value = priceRows.value.map((row) => {
    if (kind === 'sencilla' && row.tipoKey !== 'camarote') return { ...row, precio: value }
    if (kind !== 'sencilla' && row.nivelKey === kind) return { ...row, precio: value }
    return row
  })
}

async function savePrices(): Promise<void> {
  if (!props.eventId || !canManage.value || !priceRows.value.length) return
  savingPrices.value = true
  try {
    configured.value = await cabanasService.updateEventBedPrices(
      props.eventId,
      priceRows.value.map((row) => ({ id: row.id, precio: row.precio })),
    )
    hydratePrices(configured.value)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.eventPricesSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    savingPrices.value = false
  }
}

async function save(): Promise<void> {
  if (!props.eventId || !canManage.value) return
  saving.value = true
  try {
    configured.value = await cabanasService.syncEventCabanas(
      props.eventId,
      selectedIds.value.map((cabanaId, index) => ({ cabana_id: cabanaId, orden: index + 1 })),
    )
    hydratePrices(configured.value)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.eventSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

function userLabel(user: User): string {
  return user.persona?.full_name || user.name || user.email
}

function addQuota(): void {
  if (!canManage.value || !newUserId.value || !newCupos.value) return
  if (quotaRows.value.some((row) => row.user_id === newUserId.value)) return
  if (reservedCupos.value + newCupos.value > quotaCapacity.value) {
    toast.add({ severity: 'warn', summary: t('common.error'), detail: t('cabanas.quotasExceed'), life: 3500 })
    return
  }
  const user = userOptions.value.find((item) => item.id === newUserId.value)
  quotaRows.value = [
    ...quotaRows.value,
    {
      id: 0,
      user_id: newUserId.value,
      cupos: newCupos.value,
      usados: 0,
      restantes: newCupos.value,
      estado: 'abierto',
      user: user ? { id: user.id, name: userLabel(user), email: user.email } : null,
    },
  ]
  newUserId.value = null
  newCupos.value = 1
}

function removeQuota(userId: number): void {
  if (!canManage.value) return
  quotaRows.value = quotaRows.value.filter((row) => row.user_id !== userId)
}

async function saveQuotas(): Promise<void> {
  if (!props.eventId || !canManage.value) return
  if (reservedCupos.value > quotaCapacity.value) {
    toast.add({ severity: 'warn', summary: t('common.error'), detail: t('cabanas.quotasExceed'), life: 3500 })
    return
  }
  savingQuotas.value = true
  try {
    const pool = await cabanasService.syncAlojamientoCupos(
      props.eventId,
      quotaRows.value.map((row) => ({ user_id: row.user_id, cupos: row.cupos })),
    )
    quotaRows.value = pool.items
    quotaPool.value = {
      capacidad: pool.capacidad,
      ocupadas: pool.ocupadas,
      reservados: pool.reservados,
      libres: pool.libres,
    }
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.quotasSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    savingQuotas.value = false
  }
}

watch(() => props.eventId, () => void load())
watch(() => props.lugarId, () => void load())
onMounted(() => void load())
</script>

<template>
  <section class="event-cabanas-step">
    <div class="step-heading">
      <div>
        <h2>{{ t('cabanas.eventTitle') }}</h2>
        <p>{{ t('cabanas.eventHint') }}</p>
      </div>
      <Tag :value="t('cabanas.selectedCount', { count: selectedIds.length })" severity="info" />
    </div>
    <Message v-if="!lugarId" severity="info" :closable="false">{{ t('events.wizard.pickPlaceFirst') }}</Message>
    <Message v-else-if="!eventId" severity="info" :closable="false">{{ t('cabanas.saveEventFirst') }}</Message>
    <template v-else>
      <div class="summary">
        <span><i class="pi pi-building" /> {{ selectedIds.length }} {{ t('cabanas.cabins') }}</span>
        <span><i class="pi pi-users" /> {{ totalCapacity }} {{ t('cabanas.spaces') }}</span>
      </div>
      <AppSearchField v-model="search" :placeholder="t('cabanas.search')" />
      <PageLoader v-if="loading" />
      <div v-else class="cabana-cards">
        <article
          v-for="item in filtered"
          :key="item.id"
          class="cabana-card"
          :class="{ selected: selectedIds.includes(item.id) }"
          @click="toggle(item.id)"
        >
          <div class="cabana-icon" :class="{ 'has-photo': !!resolveFileUrl(item.image_url) }">
            <img v-if="resolveFileUrl(item.image_url)" :src="resolveFileUrl(item.image_url)!" :alt="item.nombre" />
            <i v-else class="pi pi-building" />
          </div>
          <div>
            <strong>{{ item.nombre }}</strong>
            <small>{{ item.pisos_count ?? item.pisos?.length ?? 0 }} {{ t('cabanas.floors').toLowerCase() }}</small>
          </div>
          <span class="capacity">{{ item.capacidad_total ?? 0 }} <i class="pi pi-user" /></span>
          <i :class="selectedIds.includes(item.id) ? 'pi pi-check-circle' : 'pi pi-circle'" />
        </article>
      </div>
      <div v-if="selectedCabanas.length" class="priority-list">
        <strong>{{ t('cabanas.priority') }}</strong>
        <div v-for="(item, index) in selectedCabanas" :key="item.id">
          <span>{{ index + 1 }}. {{ item.nombre }}</span>
          <span>
            <Button icon="pi pi-chevron-up" text rounded size="small" :disabled="index === 0" @click="move(item.id, -1)" />
            <Button icon="pi pi-chevron-down" text rounded size="small" :disabled="index === selectedCabanas.length - 1" @click="move(item.id, 1)" />
          </span>
        </div>
      </div>
      <div v-if="canManage" class="actions">
        <Button :label="t('common.save')" icon="pi pi-save" :loading="saving" @click="save" />
      </div>
      <div v-if="priceRows.length" class="price-panel">
        <div>
          <strong>{{ t('cabanas.eventPricesTitle') }}</strong>
          <p>{{ t('cabanas.eventPricesHint') }}</p>
        </div>
        <div v-if="canManage" class="price-bulk">
          <label>
            {{ t('cabanas.bunkTop') }}
            <span>
              <InputNumber v-model="bulkTop" :min="0" :max-fraction-digits="0" prefix="$ " />
              <Button :label="t('cabanas.applyBulk')" size="small" text @click="applyBulkPrice('arriba')" />
            </span>
          </label>
          <label>
            {{ t('cabanas.bunkBottom') }}
            <span>
              <InputNumber v-model="bulkBottom" :min="0" :max-fraction-digits="0" prefix="$ " />
              <Button :label="t('cabanas.applyBulk')" size="small" text @click="applyBulkPrice('abajo')" />
            </span>
          </label>
          <label>
            {{ t('cabanas.bedSingle') }}
            <span>
              <InputNumber v-model="bulkSingle" :min="0" :max-fraction-digits="0" prefix="$ " />
              <Button :label="t('cabanas.applyBulk')" size="small" text @click="applyBulkPrice('sencilla')" />
            </span>
          </label>
        </div>
        <div class="price-table">
          <div class="price-head">
            <span>{{ t('cabanas.bed') }}</span>
            <span>{{ t('cabanas.suggestedPrice') }}</span>
            <span>{{ t('cabanas.eventPrice') }}</span>
          </div>
          <label v-for="row in priceRows" :key="row.id" class="price-row">
            <span>
              <strong>{{ row.codigo }}</strong>
              <small>{{ row.tipo }}{{ row.nivel ? ` · ${row.nivel}` : '' }} · {{ row.cabana }} · {{ row.cuarto }}</small>
            </span>
            <em>{{ row.sugerido != null ? money(row.sugerido) : '—' }}</em>
            <InputNumber
              v-model="row.precio"
              :min="0"
              :max-fraction-digits="0"
              prefix="$ "
              :disabled="!canManage"
            />
          </label>
        </div>
        <div v-if="canManage" class="actions">
          <Button
            :label="t('cabanas.savePrices')"
            icon="pi pi-dollar"
            :loading="savingPrices"
            @click="savePrices"
          />
        </div>
      </div>
      <div class="quota-panel">
        <div>
          <strong>{{ t('cabanas.quotasTitle') }}</strong>
          <p>{{ t('cabanas.quotasHint') }}</p>
        </div>
        <div class="summary">
          <span>
            <i class="pi pi-bookmark" />
            {{ t('cabanas.quotasSummary', {
              reserved: reservedCupos,
              free: Math.max(0, quotaCapacity - reservedCupos),
              capacity: quotaCapacity,
            }) }}
          </span>
        </div>
        <div v-if="canManage" class="quota-add">
          <Select
            v-model="newUserId"
            :options="userOptions.filter((user) => !quotaRows.some((row) => row.user_id === user.id))"
            option-label="name"
            option-value="id"
            filter
            :filter-placeholder="t('cabanas.quotasPickUser')"
            :placeholder="t('cabanas.quotasUser')"
          >
            <template #option="{ option }">
              <span>{{ userLabel(option) }}</span>
              <small>{{ option.email }}</small>
            </template>
          </Select>
          <InputNumber v-model="newCupos" :min="1" :max="Math.max(1, quotaCapacity - reservedCupos)" />
          <Button
            :label="t('cabanas.quotasAdd')"
            icon="pi pi-plus"
            :disabled="!canAddQuota"
            @click="addQuota"
          />
        </div>
        <p v-if="!quotaRows.length" class="quota-empty">{{ t('cabanas.quotasEmpty') }}</p>
        <div v-else class="quota-table">
          <div class="quota-head">
            <span>{{ t('cabanas.quotasUser') }}</span>
            <span>{{ t('cabanas.quotasAmount') }}</span>
            <span>{{ t('cabanas.quotasUsed') }}</span>
            <span>{{ t('cabanas.quotasRemaining') }}</span>
            <span>{{ t('cabanas.quotasStatus') }}</span>
            <span />
          </div>
          <div v-for="row in quotaRows" :key="row.user_id" class="quota-row">
            <span>
              <strong>{{ row.user?.name || row.user_id }}</strong>
              <small>{{ row.user?.email }}</small>
            </span>
            <InputNumber
              v-model="row.cupos"
              :min="Math.max(1, row.usados)"
              :disabled="!canManage || row.estado === 'cerrado'"
            />
            <em>{{ row.usados }}</em>
            <em>{{ Math.max(0, row.cupos - row.usados) }}</em>
            <Tag
              :value="row.estado === 'cerrado' ? t('cabanas.quotasClosed') : t('cabanas.quotasOpen')"
              :severity="row.estado === 'cerrado' ? 'secondary' : 'success'"
            />
            <Button
              v-if="canManage"
              :label="t('cabanas.quotasRemove')"
              icon="pi pi-times"
              text
              severity="danger"
              size="small"
              @click="removeQuota(row.user_id)"
            />
          </div>
        </div>
        <div v-if="canManage" class="actions">
          <Button
            :label="t('cabanas.quotasSave')"
            icon="pi pi-users"
            :loading="savingQuotas"
            @click="saveQuotas"
          />
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.event-cabanas-step { display: grid; gap: 1rem; }
.step-heading { display: flex; justify-content: space-between; gap: 1rem; align-items: start; }
.step-heading h2, .step-heading p { margin: 0; }
.step-heading p { margin-top: .25rem; color: var(--pj-text-muted); }
.summary { display: flex; flex-wrap: wrap; gap: 1rem; padding: .75rem; border-radius: 10px; background: var(--pj-primary-soft); }
.summary span { display: inline-flex; align-items: center; gap: .4rem; font-weight: 600; }
.cabana-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: .65rem; }
.cabana-card { display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: .65rem; padding: .8rem; border: 1px solid var(--pj-border); border-radius: 12px; background: var(--pj-bg-elevated); cursor: pointer; }
.cabana-card.selected { border-color: var(--p-primary-color); background: var(--pj-primary-soft); }
.cabana-card strong, .cabana-card small { display: block; }
.cabana-card small { margin-top: .18rem; color: var(--pj-text-muted); }
.cabana-icon { display: grid; place-items: center; width: 2.6rem; height: 2.6rem; overflow: hidden; border-radius: 9px; background: color-mix(in srgb, var(--p-primary-color) 12%, white); color: var(--p-primary-color); }
.cabana-icon img { width: 100%; height: 100%; object-fit: cover; }
.cabana-icon.has-photo { background: #e2e8f0; }
.capacity { white-space: nowrap; font-weight: 700; }
.priority-list { display: grid; gap: .35rem; padding: .75rem; border: 1px solid var(--pj-border); border-radius: 10px; }
.priority-list > div { display: flex; justify-content: space-between; align-items: center; min-height: 2.2rem; border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent); }
.actions { display: flex; justify-content: flex-end; }
.price-panel { display: grid; gap: .65rem; padding: .85rem; border: 1px solid var(--pj-border); border-radius: 12px; }
.price-panel p { margin: .2rem 0 0; color: var(--pj-text-muted); font-size: .85rem; }
.price-table { display: grid; gap: .4rem; }
.price-head, .price-row {
  display: grid;
  grid-template-columns: minmax(10rem, 1.4fr) 8rem 10rem;
  gap: .65rem;
  align-items: center;
}
.price-head { font-size: .75rem; font-weight: 700; color: var(--pj-text-muted); text-transform: uppercase; }
.price-row { padding: .45rem 0; border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent); }
.price-row span { display: flex; flex-direction: column; gap: .1rem; }
.price-row small { color: var(--pj-text-muted); font-size: .75rem; }
.price-row em { font-style: normal; font-weight: 600; }
.price-bulk {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.65rem;
}
.price-bulk label { display: grid; gap: 0.3rem; font-size: 0.78rem; font-weight: 700; }
.price-bulk span { display: flex; gap: 0.35rem; align-items: center; }
.quota-panel { display: grid; gap: .65rem; padding: .85rem; border: 1px solid var(--pj-border); border-radius: 12px; }
.quota-panel p { margin: .2rem 0 0; color: var(--pj-text-muted); font-size: .85rem; }
.quota-add { display: grid; grid-template-columns: minmax(12rem, 1fr) 7rem auto; gap: .55rem; align-items: center; }
.quota-add small { display: block; color: var(--pj-text-muted); font-size: .75rem; }
.quota-empty { margin: 0; }
.quota-table { display: grid; gap: .35rem; }
.quota-head, .quota-row {
  display: grid;
  grid-template-columns: minmax(10rem, 1.4fr) 7rem 5rem 6rem 7rem auto;
  gap: .55rem;
  align-items: center;
}
.quota-head { font-size: .75rem; font-weight: 700; color: var(--pj-text-muted); text-transform: uppercase; }
.quota-row { padding: .4rem 0; border-top: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent); }
.quota-row span { display: flex; flex-direction: column; gap: .1rem; }
.quota-row small { color: var(--pj-text-muted); font-size: .75rem; }
.quota-row em { font-style: normal; font-weight: 600; }
@media (max-width: 720px) {
  .price-head, .price-row { grid-template-columns: 1fr; }
  .quota-add, .quota-head, .quota-row { grid-template-columns: 1fr; }
}
</style>
