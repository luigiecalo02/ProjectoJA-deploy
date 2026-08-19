<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Select from 'primevue/select'
import type { CabanaBed, CabanaFloor, CabanaRoom, EventoCabana } from '@/modules/cabanas/types'
import { bedState, cabanaCapacity, cabanaFloors, cabanaLabel, occupancyOf, roomState, rotateTransform } from '@/modules/cabanas/layout'

const props = defineProps<{
  cabanas: EventoCabana[]
  modelValue: number | null
  assignedBedId?: number | null
  disabled?: boolean
}>()
const emit = defineEmits<{
  'update:modelValue': [id: number | null]
  select: [bed: CabanaBed, room: CabanaRoom, floor: CabanaFloor, eventCabana: EventoCabana]
}>()
const activeCabanaId = ref<number | null>(null)
const activeFloorId = ref<number | null>(null)

const activeCabana = computed(() =>
  props.cabanas.find((item) => item.id === activeCabanaId.value) ?? props.cabanas[0] ?? null,
)
const floors = computed(() => (activeCabana.value ? cabanaFloors(activeCabana.value) : []))
const activeFloor = computed(() =>
  floors.value.find((floor) => floor.id === activeFloorId.value) ?? floors.value[0] ?? null,
)
const floorOptions = computed(() => floors.value.map((floor) => ({ label: floor.nombre, value: floor.id })))
const occupied = computed(() => props.cabanas.reduce((sum, item) => sum + occupancyOf(item), 0))
const capacity = computed(() => props.cabanas.reduce((sum, item) => sum + cabanaCapacity(item), 0))
const stateOptions = computed(() => ({ selectedId: props.modelValue, assignedId: props.assignedBedId }))

watch(
  () => props.cabanas,
  (items) => {
    if (!items.some((item) => item.id === activeCabanaId.value)) activeCabanaId.value = items[0]?.id ?? null
  },
  { immediate: true },
)
watch(activeCabanaId, () => {
  activeFloorId.value = floors.value[0]?.id ?? null
}, { immediate: true })

function selectBed(bed: CabanaBed, room: CabanaRoom): void {
  if (!activeCabana.value || !activeFloor.value || props.disabled) return
  const state = bedState(bed, stateOptions.value)
  if (state === 'bloqueada' || state === 'completa') return
  emit('update:modelValue', bed.id)
  emit('select', bed, room, activeFloor.value, activeCabana.value)
}

function bedAriaLabel(bed: CabanaBed, room: CabanaRoom): string {
  return `${bed.nombre || bed.codigo}, ${room.nombre}, ${occupancyOf(bed)} de ${bed.capacidad} ocupadas, ${bedState(bed, stateOptions.value)}`
}
</script>

<template>
  <section class="bed-selector">
    <header class="selector-header">
      <div class="cabana-tabs" role="tablist" aria-label="Cabañas">
        <button
          v-for="item in cabanas"
          :key="item.id"
          type="button"
          role="tab"
          :aria-selected="activeCabana?.id === item.id"
          :class="{ active: activeCabana?.id === item.id }"
          @click="activeCabanaId = item.id"
        >
          <i class="pi pi-building" />
          {{ cabanaLabel(item) }}
          <small>{{ occupancyOf(item) }}/{{ cabanaCapacity(item) }}</small>
        </button>
      </div>
      <Select
        v-if="floorOptions.length > 1"
        v-model="activeFloorId"
        :options="floorOptions"
        option-label="label"
        option-value="value"
        aria-label="Piso"
      />
    </header>

    <div class="global-counter">
      <span><strong>{{ occupied }}</strong> ocupadas</span>
      <span>/</span>
      <span><strong>{{ capacity }}</strong> capacidad total</span>
      <progress :value="occupied" :max="capacity || 1">{{ occupied }}/{{ capacity }}</progress>
    </div>

    <div v-if="activeFloor" class="layout-scroll">
      <svg
        class="bed-layout"
        :viewBox="`0 0 ${activeFloor.ancho || 1000} ${activeFloor.alto || 650}`"
        role="group"
        :aria-label="`${activeCabana ? cabanaLabel(activeCabana) : ''}, ${activeFloor.nombre}`"
      >
        <defs>
          <pattern id="bed-grid-pattern" width="25" height="25" patternUnits="userSpaceOnUse">
            <path d="M 25 0 L 0 0 0 25" fill="none" stroke="#64748b" stroke-width=".7" opacity=".12" />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#bed-grid-pattern)" />
        <g
          v-for="room in activeFloor.cuartos"
          :key="room.id"
          class="room"
          :class="`state-${roomState(room, stateOptions)}`"
        >
          <ellipse
            v-if="room.forma === 'circle'"
            :cx="room.x + room.ancho / 2"
            :cy="room.y + room.alto / 2"
            :rx="room.ancho / 2"
            :ry="room.alto / 2"
          />
          <path
            v-else-if="room.forma === 'polygon' && room.vertices?.length"
            :d="`M ${room.vertices.map((point) => `${point.x} ${point.y}`).join(' L ')} Z`"
          />
          <rect v-else :x="room.x" :y="room.y" :width="room.ancho" :height="room.alto" rx="11" />
          <text class="room-name" :x="room.x + 12" :y="room.y + 23">{{ room.nombre }}</text>
          <text class="room-counter" :x="room.x + 12" :y="room.y + 42">
            {{ occupancyOf(room) }}/{{ room.capacidad }}
          </text>
          <g
            v-for="bed in room.camas"
            :key="bed.id"
            class="bed"
            :class="`state-${bedState(bed, stateOptions)}`"
            :transform="rotateTransform({ x: bed.x - 18, y: bed.y - 13, ancho: 36, alto: 26, rotacion: bed.rotacion })"
            role="button"
            tabindex="0"
            :aria-label="bedAriaLabel(bed, room)"
            :aria-disabled="disabled || ['bloqueada', 'completa'].includes(bedState(bed, stateOptions))"
            @click.stop="selectBed(bed, room)"
            @keydown.enter.stop="selectBed(bed, room)"
            @keydown.space.prevent.stop="selectBed(bed, room)"
          >
            <rect :x="bed.x - 18" :y="bed.y - 13" width="36" height="26" rx="6" />
            <path :d="`M ${bed.x - 12} ${bed.y - 5} h 24 M ${bed.x - 12} ${bed.y + 5} h 24`" />
            <text :x="bed.x" :y="bed.y + 3">{{ bed.codigo }}</text>
          </g>
        </g>
      </svg>
    </div>

    <div class="legend" aria-label="Leyenda">
      <span class="state-disponible"><i /> Disponible</span>
      <span class="state-parcial"><i /> Parcial</span>
      <span class="state-completa"><i /> Completa</span>
      <span class="state-seleccionada"><i /> Seleccionada</span>
      <span class="state-bloqueada"><i /> Bloqueada</span>
    </div>
  </section>
</template>

<style scoped>
.bed-selector { display: grid; gap: .8rem; min-width: 0; }
.selector-header { display: flex; justify-content: space-between; align-items: center; gap: .75rem; }
.cabana-tabs { display: flex; gap: .4rem; overflow-x: auto; padding-bottom: .2rem; }
.cabana-tabs button { display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap; padding: .55rem .7rem; border: 1px solid var(--pj-border); border-radius: 9px; background: var(--pj-bg-elevated); cursor: pointer; color: var(--pj-text); }
.cabana-tabs button.active { border-color: var(--p-primary-color); background: var(--pj-primary-soft); color: var(--pj-navy); font-weight: 700; }
.cabana-tabs small { padding: .12rem .35rem; border-radius: 999px; background: rgb(15 23 42 / 8%); }
.global-counter { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; color: var(--pj-text-muted); font-size: .88rem; }
.global-counter strong { color: var(--pj-text); }
.global-counter progress { flex: 1 1 12rem; height: .55rem; accent-color: var(--p-primary-color); }
.layout-scroll { overflow: auto; border: 1px solid var(--pj-border); border-radius: 14px; background: var(--pj-bg-elevated); }
.bed-layout { display: block; width: 100%; min-width: 600px; min-height: 380px; aspect-ratio: 1000 / 650; }
.room > rect, .room > ellipse, .room > path { stroke-width: 2; }
.room.state-disponible > rect, .room.state-disponible > ellipse, .room.state-disponible > path { fill: #ecfdf5; stroke: #10b981; }
.room.state-parcial > rect, .room.state-parcial > ellipse, .room.state-parcial > path { fill: #fffbeb; stroke: #f59e0b; }
.room.state-completa > rect, .room.state-completa > ellipse, .room.state-completa > path { fill: #fef2f2; stroke: #ef4444; }
.room.state-bloqueada > rect, .room.state-bloqueada > ellipse, .room.state-bloqueada > path { fill: #f1f5f9; stroke: #64748b; }
.room-name, .room-counter { pointer-events: none; fill: #172033; font-weight: 700; font-size: 17px; }
.room-counter { fill: #475569; font-size: 13px; font-weight: 500; }
.bed { cursor: pointer; outline: none; }
.bed rect { stroke-width: 2; transition: filter .15s, transform .15s; transform-box: fill-box; transform-origin: center; }
.bed:hover rect, .bed:focus rect { filter: drop-shadow(0 3px 3px rgb(15 23 42 / 25%)); transform: scale(1.08); }
.bed path { fill: none; stroke-width: 1; pointer-events: none; }
.bed text { text-anchor: middle; font-size: 9px; font-weight: 800; pointer-events: none; }
.bed.state-disponible rect { fill: #d1fae5; stroke: #059669; }
.bed.state-disponible path { stroke: #047857; }
.bed.state-parcial rect { fill: #fef3c7; stroke: #d97706; }
.bed.state-parcial path { stroke: #b45309; }
.bed.state-completa rect { fill: #fee2e2; stroke: #dc2626; }
.bed.state-completa path { stroke: #b91c1c; }
.bed.state-seleccionada rect { fill: #dbeafe; stroke: #2563eb; stroke-width: 4; }
.bed.state-seleccionada path { stroke: #1d4ed8; }
.bed.state-bloqueada { cursor: not-allowed; opacity: .65; }
.bed.state-bloqueada rect { fill: #e2e8f0; stroke: #64748b; }
.bed.state-bloqueada path { stroke: #475569; }
.legend { display: flex; flex-wrap: wrap; gap: .75rem; font-size: .78rem; color: var(--pj-text-muted); }
.legend span { display: inline-flex; align-items: center; gap: .3rem; }
.legend i { width: .75rem; height: .75rem; border-radius: 3px; border: 2px solid; }
.legend .state-disponible i { background: #d1fae5; border-color: #059669; }
.legend .state-parcial i { background: #fef3c7; border-color: #d97706; }
.legend .state-completa i { background: #fee2e2; border-color: #dc2626; }
.legend .state-seleccionada i { background: #dbeafe; border-color: #2563eb; }
.legend .state-bloqueada i { background: #e2e8f0; border-color: #64748b; }
@media (max-width: 720px) {
  .selector-header { align-items: stretch; flex-direction: column; }
  .selector-header :deep(.p-select) { width: 100%; }
}
</style>
