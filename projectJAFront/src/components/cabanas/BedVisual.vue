<script setup lang="ts">
import type { VisualBedStatus } from '@/modules/cabanas/layout'
import camaSimple from '@/assets/cabanas/cama-simple.png'
import camaCamarote from '@/assets/cabanas/cama-camarote.png'

const props = defineProps<{
  x: number
  y: number
  width?: number
  height?: number
  codigo: string
  mode: 'single' | 'bunk' | 'double'
  status: VisualBedStatus
  statusTop?: VisualBedStatus | null
  statusBottom?: VisualBedStatus | null
  selected?: boolean
  interactive?: boolean
  flipped?: boolean
}>()

const emit = defineEmits<{
  pick: [level: 'single' | 'arriba' | 'abajo', event: PointerEvent]
}>()

const palette: Record<VisualBedStatus, { fill: string; stroke: string; text: string }> = {
  disponible: { fill: '#bbf7d0', stroke: '#16a34a', text: '#15803d' },
  reservada: { fill: '#fde68a', stroke: '#d97706', text: '#b45309' },
  ocupada: { fill: '#fecaca', stroke: '#dc2626', text: '#b91c1c' },
  seleccionada: { fill: '#bfdbfe', stroke: '#2563eb', text: '#1d4ed8' },
  mantenimiento: { fill: '#e2e8f0', stroke: '#94a3b8', text: '#64748b' },
  bloqueada: { fill: '#cbd5e1', stroke: '#64748b', text: '#475569' },
}

function tone(status: VisualBedStatus | null | undefined, fallback: VisualBedStatus = props.status) {
  return palette[status ?? fallback]
}

function label(status: VisualBedStatus | null | undefined): string {
  if (status === 'ocupada') return 'Ocupada'
  if (status === 'reservada') return 'Reservada'
  if (status === 'seleccionada') return 'Seleccionada'
  if (status === 'mantenimiento') return 'Mantenimiento'
  if (status === 'bloqueada') return 'No disponible'
  return 'Disponible'
}

function onPick(level: 'single' | 'arriba' | 'abajo', event: PointerEvent): void {
  if (!props.interactive) return
  emit('pick', level, event)
}

const boxWidth = () => props.width ?? 120
const artHeight = () => props.height ?? (props.mode === 'single' ? 72 : 100)
const artHref = () => (props.mode === 'single' ? camaSimple : camaCamarote)
const flipTransform = () => {
  const width = boxWidth()
  const height = artHeight()
  const cx = width / 2
  const cy = height / 2
  return props.flipped ? `translate(${cx} ${cy}) scale(-1 1) translate(${-cx} ${-cy})` : undefined
}
</script>

<template>
  <g class="bed-visual" :class="[`is-${status}`, { selected, flipped }]" :transform="`translate(${x} ${y})`">
    <g :transform="flipTransform()">
      <image
        class="art"
        :href="artHref()"
        x="4"
        y="0"
        :width="boxWidth() - 8"
        :height="artHeight()"
        preserveAspectRatio="xMidYMid meet"
      />
      <template v-if="mode === 'bunk'">
        <rect
          class="tint"
          :class="`is-${statusTop ?? status}`"
          x="8"
          y="2"
          :width="boxWidth() - 16"
          height="46"
          rx="6"
          :fill="tone(statusTop).fill"
          @pointerdown.stop="onPick('arriba', $event)"
        />
        <rect
          class="tint"
          :class="`is-${statusBottom ?? status}`"
          x="8"
          y="50"
          :width="boxWidth() - 16"
          height="48"
          rx="6"
          :fill="tone(statusBottom).fill"
          @pointerdown.stop="onPick('abajo', $event)"
        />
      </template>
      <rect
        v-else
        class="tint"
        :class="`is-${status}`"
        x="6"
        y="2"
        :width="boxWidth() - 12"
        :height="artHeight() - 4"
        rx="8"
        :fill="tone(status).fill"
        @pointerdown.stop="onPick('single', $event)"
      />
    </g>

    <rect class="card" x="0" :y="artHeight() + 4" :width="boxWidth()" height="34" rx="7" />
    <rect class="card-line" x="0" :y="artHeight() + 4" :width="boxWidth()" height="3" rx="2" :fill="tone(status).stroke" />
    <text class="code" x="8" :y="artHeight() + 18">{{ codigo }}</text>
    <text class="state" x="8" :y="artHeight() + 31" :fill="tone(status).text">{{ label(status) }}</text>

    <rect
      v-if="selected"
      class="outline"
      x="-4"
      y="-4"
      :width="boxWidth() + 8"
      :height="artHeight() + 46"
      rx="10"
    />
  </g>
</template>

<style scoped>
.bed-visual { cursor: grab; }
.card { fill: #fff; stroke: #e2e8f0; stroke-width: 1; }
.code { font-size: 11px; font-weight: 800; fill: #0f172a; pointer-events: none; }
.state { font-size: 9px; font-weight: 700; pointer-events: none; }
.art { pointer-events: none; }
.tint { cursor: pointer; opacity: 0; }
.tint.is-reservada { opacity: 0.32; }
.tint.is-ocupada { opacity: 0.38; }
.tint.is-seleccionada { opacity: 0.22; }
.tint.is-bloqueada,
.tint.is-mantenimiento { opacity: 0.4; }
.outline { fill: none; stroke: #2563eb; stroke-width: 2; stroke-dasharray: 6 4; pointer-events: none; }
.selected .card { stroke: #2563eb; }
</style>
