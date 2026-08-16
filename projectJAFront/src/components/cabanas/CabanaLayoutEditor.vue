<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import type {
  Cabana,
  CabanaBed,
  CabanaFloor,
  CabanaLayoutPayload,
  CabanaRoom,
} from '@/modules/cabanas/types'
import { genderClass } from '@/modules/cabanas/layout'

type EditorTool = 'select' | 'draw-room' | 'create-bed'
type Selection =
  | { kind: 'room'; room: CabanaRoom }
  | { kind: 'bed'; room: CabanaRoom; bed: CabanaBed }
  | null

const props = defineProps<{
  cabana: Cabana
  saving?: boolean
  readonly?: boolean
}>()

const emit = defineEmits<{
  save: [payload: CabanaLayoutPayload]
}>()

const floors = ref<CabanaFloor[]>([])
const activeFloorId = ref<number | null>(null)
const tool = ref<EditorTool>('select')
const selection = ref<Selection>(null)
const propertiesVisible = ref(false)
const draftRoom = ref<{ x: number; y: number; ancho: number; alto: number } | null>(null)
let nextTemporaryId = -1
let interaction:
  | {
      kind: 'draw' | 'move-room' | 'resize-room' | 'move-bed'
      startX: number
      startY: number
      room?: CabanaRoom
      bed?: CabanaBed
      initial: { x: number; y: number; ancho?: number; alto?: number }
    }
  | null = null

const activeFloor = computed(() => floors.value.find((floor) => floor.id === activeFloorId.value) ?? null)
const viewWidth = computed(() => activeFloor.value?.ancho || 1000)
const viewHeight = computed(() => activeFloor.value?.alto || 650)
const genderOptions = [
  { label: 'Masculino', value: 'M' },
  { label: 'Femenino', value: 'F' },
  { label: 'Mixto', value: 'MIXTO' },
]

watch(
  () => props.cabana,
  (cabana) => {
    floors.value = structuredClone(cabana.pisos ?? [])
    if (!floors.value.length && !props.readonly) addFloor()
    activeFloorId.value = floors.value[0]?.id ?? null
    selection.value = null
  },
  { immediate: true },
)

function addFloor(): void {
  if (props.readonly) return
  const floor: CabanaFloor = {
    id: nextTemporaryId--,
    cabana_id: props.cabana.id,
    nombre: `Piso ${floors.value.length + 1}`,
    orden: floors.value.length + 1,
    ancho: 1000,
    alto: 650,
    cuartos: [],
  }
  floors.value.push(floor)
  activeFloorId.value = floor.id
}

function removeFloor(): void {
  if (!activeFloor.value || props.readonly || floors.value.length === 1) return
  const index = floors.value.findIndex((floor) => floor.id === activeFloor.value?.id)
  floors.value.splice(index, 1)
  floors.value.forEach((floor, order) => {
    floor.orden = order + 1
  })
  activeFloorId.value = floors.value[Math.max(0, index - 1)]?.id ?? null
  selection.value = null
}

function pointFromEvent(event: PointerEvent): { x: number; y: number } {
  const svg = event.currentTarget instanceof SVGSVGElement
    ? event.currentTarget
    : (event.currentTarget as SVGElement).ownerSVGElement
  if (!svg) return { x: 0, y: 0 }
  const rect = svg.getBoundingClientRect()
  return {
    x: ((event.clientX - rect.left) / rect.width) * viewWidth.value,
    y: ((event.clientY - rect.top) / rect.height) * viewHeight.value,
  }
}

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value))
}

function startCanvas(event: PointerEvent): void {
  if (props.readonly || tool.value !== 'draw-room' || !activeFloor.value) {
    if (event.target === event.currentTarget) selection.value = null
    return
  }
  const point = pointFromEvent(event)
  interaction = {
    kind: 'draw',
    startX: point.x,
    startY: point.y,
    initial: { x: point.x, y: point.y },
  }
  draftRoom.value = { x: point.x, y: point.y, ancho: 0, alto: 0 }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function startRoom(room: CabanaRoom, event: PointerEvent): void {
  event.stopPropagation()
  const point = pointFromEvent(event)
  if (props.readonly) {
    selection.value = { kind: 'room', room }
    return
  }
  if (tool.value === 'create-bed') {
    const index = room.camas.length + 1
    const bed: CabanaBed = {
      id: nextTemporaryId--,
      cuarto_id: room.id,
      codigo: `${room.codigo || room.nombre}-${index}`,
      x: clamp(point.x, room.x + 16, room.x + room.ancho - 16),
      y: clamp(point.y, room.y + 16, room.y + room.alto - 16),
      ancho: 36,
      alto: 26,
      rotacion: 0,
      capacidad: 1,
      genero: room.genero,
    }
    room.camas.push(bed)
    room.capacidad = Math.max(room.capacidad, room.camas.reduce((sum, item) => sum + item.capacidad, 0))
    selection.value = { kind: 'bed', room, bed }
    propertiesVisible.value = true
    return
  }
  if (tool.value !== 'select') return
  selection.value = { kind: 'room', room }
  interaction = {
    kind: 'move-room',
    startX: point.x,
    startY: point.y,
    room,
    initial: { x: room.x, y: room.y },
  }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function startResize(room: CabanaRoom, event: PointerEvent): void {
  if (props.readonly) return
  event.stopPropagation()
  const point = pointFromEvent(event)
  selection.value = { kind: 'room', room }
  interaction = {
    kind: 'resize-room',
    startX: point.x,
    startY: point.y,
    room,
    initial: { x: room.x, y: room.y, ancho: room.ancho, alto: room.alto },
  }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function startBed(room: CabanaRoom, bed: CabanaBed, event: PointerEvent): void {
  event.stopPropagation()
  selection.value = { kind: 'bed', room, bed }
  if (props.readonly || tool.value !== 'select') return
  const point = pointFromEvent(event)
  interaction = {
    kind: 'move-bed',
    startX: point.x,
    startY: point.y,
    room,
    bed,
    initial: { x: bed.x, y: bed.y },
  }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function onPointerMove(event: PointerEvent): void {
  if (!interaction || !activeFloor.value) return
  const point = pointFromEvent(event)
  const dx = point.x - interaction.startX
  const dy = point.y - interaction.startY
  if (interaction.kind === 'draw' && draftRoom.value) {
    draftRoom.value = {
      x: Math.min(interaction.startX, point.x),
      y: Math.min(interaction.startY, point.y),
      ancho: Math.abs(dx),
      alto: Math.abs(dy),
    }
  } else if (interaction.kind === 'move-room' && interaction.room) {
    const room = interaction.room
    room.x = clamp(interaction.initial.x + dx, 0, viewWidth.value - room.ancho)
    room.y = clamp(interaction.initial.y + dy, 0, viewHeight.value - room.alto)
    const bedDx = room.x - interaction.initial.x
    const bedDy = room.y - interaction.initial.y
    for (const bed of room.camas) {
      bed.x += bedDx - ((room as CabanaRoom & { __lastDx?: number }).__lastDx ?? 0)
      bed.y += bedDy - ((room as CabanaRoom & { __lastDy?: number }).__lastDy ?? 0)
    }
    ;(room as CabanaRoom & { __lastDx?: number }).__lastDx = bedDx
    ;(room as CabanaRoom & { __lastDy?: number }).__lastDy = bedDy
  } else if (interaction.kind === 'resize-room' && interaction.room) {
    interaction.room.ancho = clamp((interaction.initial.ancho ?? 0) + dx, 80, viewWidth.value - interaction.room.x)
    interaction.room.alto = clamp((interaction.initial.alto ?? 0) + dy, 65, viewHeight.value - interaction.room.y)
  } else if (interaction.kind === 'move-bed' && interaction.room && interaction.bed) {
    interaction.bed.x = clamp(interaction.initial.x + dx, interaction.room.x + 14, interaction.room.x + interaction.room.ancho - 14)
    interaction.bed.y = clamp(interaction.initial.y + dy, interaction.room.y + 14, interaction.room.y + interaction.room.alto - 14)
  }
}

function finishInteraction(): void {
  if (!interaction || !activeFloor.value) return
  if (interaction.kind === 'draw' && draftRoom.value && draftRoom.value.ancho >= 80 && draftRoom.value.alto >= 65) {
    const index = activeFloor.value.cuartos.length + 1
    const room: CabanaRoom = {
      id: nextTemporaryId--,
      piso_id: activeFloor.value.id,
      nombre: `Cuarto ${index}`,
      codigo: `C${index}`,
      ...draftRoom.value,
      genero: 'MIXTO',
      capacidad: 1,
      camas: [],
    }
    activeFloor.value.cuartos.push(room)
    selection.value = { kind: 'room', room }
    propertiesVisible.value = true
  }
  if (interaction.room) {
    delete (interaction.room as CabanaRoom & { __lastDx?: number }).__lastDx
    delete (interaction.room as CabanaRoom & { __lastDy?: number }).__lastDy
  }
  interaction = null
  draftRoom.value = null
}

function openProperties(value: Selection): void {
  selection.value = value
  propertiesVisible.value = value !== null
}

function removeSelection(): void {
  if (!selection.value || !activeFloor.value || props.readonly) return
  const selected = selection.value
  if (selected.kind === 'bed') {
    selected.room.camas = selected.room.camas.filter((bed) => bed.id !== selected.bed.id)
  } else {
    activeFloor.value.cuartos = activeFloor.value.cuartos.filter((room) => room.id !== selected.room.id)
  }
  selection.value = null
  propertiesVisible.value = false
}

function save(): void {
  const payload: CabanaLayoutPayload = {
    pisos: floors.value.map((floor) => ({
      ...(floor.id > 0 ? { id: floor.id } : {}),
      nombre: floor.nombre,
      orden: floor.orden,
      ancho: floor.ancho,
      alto: floor.alto,
      cuartos: floor.cuartos.map((room) => ({
        ...(room.id > 0 ? { id: room.id } : {}),
        nombre: room.nombre,
        codigo: room.codigo,
        x: room.x,
        y: room.y,
        ancho: room.ancho,
        alto: room.alto,
        genero: room.genero,
        capacidad: room.capacidad,
        camas: room.camas.map((bed) => ({
          ...(bed.id > 0 ? { id: bed.id } : {}),
          codigo: bed.codigo,
          nombre: bed.nombre,
          x: bed.x,
          y: bed.y,
          ancho: bed.ancho ?? 36,
          alto: bed.alto ?? 26,
          rotacion: bed.rotacion ?? 0,
          capacidad: bed.capacidad,
        })),
      })),
    })),
  }
  emit('save', payload)
}

</script>

<template>
  <section class="layout-editor">
    <header class="editor-toolbar">
      <div class="floor-tabs">
        <button
          v-for="floor in floors"
          :key="floor.id"
          type="button"
          :class="{ active: activeFloorId === floor.id }"
          @click="activeFloorId = floor.id; selection = null"
        >
          {{ floor.nombre }}
        </button>
        <Button v-if="!readonly" icon="pi pi-plus" text rounded size="small" @click="addFloor" />
      </div>
      <div v-if="!readonly" class="tools">
        <Button icon="pi pi-cursor" :outlined="tool !== 'select'" size="small" @click="tool = 'select'" />
        <Button
          icon="pi pi-stop"
          label="Cuarto"
          :outlined="tool !== 'draw-room'"
          size="small"
          @click="tool = 'draw-room'"
        />
        <Button
          icon="pi pi-circle"
          label="Cama"
          :outlined="tool !== 'create-bed'"
          size="small"
          @click="tool = 'create-bed'"
        />
        <Button icon="pi pi-save" label="Guardar" size="small" :loading="saving" @click="save" />
      </div>
    </header>

    <div v-if="activeFloor" class="floor-settings">
      <InputText v-model="activeFloor.nombre" :disabled="readonly" aria-label="Nombre del piso" />
      <span>{{ activeFloor.cuartos.length }} cuartos</span>
      <Button
        v-if="!readonly && floors.length > 1"
        icon="pi pi-trash"
        text
        severity="danger"
        size="small"
        @click="removeFloor"
      />
    </div>

    <div class="canvas-wrap">
      <svg
        v-if="activeFloor"
        class="layout-canvas"
        :class="`tool-${tool}`"
        :viewBox="`0 0 ${viewWidth} ${viewHeight}`"
        role="application"
        aria-label="Editor del croquis de la cabaña"
        @pointerdown="startCanvas"
        @pointermove="onPointerMove"
        @pointerup="finishInteraction"
        @pointercancel="finishInteraction"
      >
        <defs>
          <pattern id="cabana-grid" width="25" height="25" patternUnits="userSpaceOnUse">
            <path d="M 25 0 L 0 0 0 25" fill="none" stroke="currentColor" stroke-width="0.7" opacity=".16" />
          </pattern>
        </defs>
        <rect class="canvas-bg" width="100%" height="100%" fill="url(#cabana-grid)" />
        <g
          v-for="room in activeFloor.cuartos"
          :key="room.id"
          class="room"
          :class="[genderClass(room.genero), { selected: selection?.kind === 'room' && selection.room.id === room.id }]"
          @pointerdown="startRoom(room, $event)"
          @dblclick.stop="openProperties({ kind: 'room', room })"
        >
          <rect :x="room.x" :y="room.y" :width="room.ancho" :height="room.alto" rx="10" />
          <text :x="room.x + 12" :y="room.y + 23">{{ room.nombre }}</text>
          <text class="room-capacity" :x="room.x + 12" :y="room.y + 42">
            {{ room.ocupadas ?? 0 }}/{{ room.capacidad }}
          </text>
          <g
            v-for="bed in room.camas"
            :key="bed.id"
            class="bed"
            :class="{ selected: selection?.kind === 'bed' && selection.bed.id === bed.id }"
            @pointerdown="startBed(room, bed, $event)"
            @dblclick.stop="openProperties({ kind: 'bed', room, bed })"
          >
            <rect :x="bed.x - 15" :y="bed.y - 11" width="30" height="22" rx="5" />
            <text :x="bed.x" :y="bed.y + 4">{{ bed.codigo }}</text>
          </g>
          <rect
            v-if="!readonly && tool === 'select' && selection?.kind === 'room' && selection.room.id === room.id"
            class="resize-handle"
            :x="room.x + room.ancho - 10"
            :y="room.y + room.alto - 10"
            width="20"
            height="20"
            rx="3"
            @pointerdown="startResize(room, $event)"
          />
        </g>
        <rect
          v-if="draftRoom"
          class="draft-room"
          :x="draftRoom.x"
          :y="draftRoom.y"
          :width="draftRoom.ancho"
          :height="draftRoom.alto"
          rx="8"
        />
      </svg>
    </div>

    <p class="editor-help">
      Dibuja cuartos arrastrando sobre el plano. Selecciona para mover, usa la esquina para redimensionar y haz doble clic para editar propiedades.
    </p>

    <AppStackDrawer
      v-model:visible="propertiesVisible"
      :title="selection?.kind === 'bed' ? 'Propiedades de cama' : 'Propiedades del cuarto'"
      subtitle="Los cambios se aplican al guardar el croquis."
      :level="1"
    >
      <template v-if="selection?.kind === 'room'">
        <label>Nombre<InputText v-model="selection.room.nombre" :disabled="readonly" /></label>
        <label>Código<InputText v-model="selection.room.codigo" :disabled="readonly" /></label>
        <label>
          Género
          <Select
            v-model="selection.room.genero"
            :options="genderOptions"
            option-label="label"
            option-value="value"
            :disabled="readonly"
          />
        </label>
        <label>
          Capacidad
          <InputNumber v-model="selection.room.capacidad" :min="1" :disabled="readonly" />
        </label>
      </template>
      <template v-else-if="selection?.kind === 'bed'">
        <label>Código<InputText v-model="selection.bed.codigo" :disabled="readonly" /></label>
        <label>Nombre<InputText v-model="selection.bed.nombre" :disabled="readonly" /></label>
        <label>
          Capacidad
          <InputNumber v-model="selection.bed.capacidad" :min="1" :max="20" :disabled="readonly" />
        </label>
      </template>
      <template v-if="!readonly" #footer>
        <Button label="Eliminar" icon="pi pi-trash" severity="danger" text @click="removeSelection" />
        <Button label="Listo" icon="pi pi-check" @click="propertiesVisible = false" />
      </template>
    </AppStackDrawer>
  </section>
</template>

<style scoped>
.layout-editor { display: grid; gap: .75rem; min-width: 0; }
.editor-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .65rem; }
.floor-tabs, .tools { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
.floor-tabs button { border: 1px solid var(--pj-border); border-radius: 8px; background: var(--pj-bg-elevated); padding: .45rem .7rem; cursor: pointer; }
.floor-tabs button.active { background: var(--pj-primary-soft); color: var(--pj-navy); border-color: var(--p-primary-color); font-weight: 700; }
.floor-settings { display: flex; align-items: center; gap: .6rem; color: var(--pj-text-muted); }
.floor-settings :deep(.p-inputtext) { max-width: 16rem; }
.canvas-wrap { width: 100%; overflow: auto; border: 1px solid var(--pj-border); border-radius: 14px; background: var(--pj-bg-elevated); }
.layout-canvas { display: block; width: 100%; min-width: 560px; min-height: 360px; aspect-ratio: 1000 / 650; color: var(--pj-text); touch-action: none; user-select: none; }
.layout-canvas.tool-draw-room { cursor: crosshair; }
.canvas-bg { color: var(--pj-text-muted); }
.room { cursor: move; }
.room > rect:first-child { fill: color-mix(in srgb, #64748b 12%, white); stroke: #64748b; stroke-width: 2; }
.room.gender-M > rect:first-child { fill: color-mix(in srgb, #2563eb 13%, white); stroke: #2563eb; }
.room.gender-F > rect:first-child { fill: color-mix(in srgb, #db2777 12%, white); stroke: #db2777; }
.room.gender-MIXTO > rect:first-child { fill: color-mix(in srgb, #7c3aed 11%, white); stroke: #7c3aed; }
.room.selected > rect:first-child { stroke-width: 4; filter: drop-shadow(0 3px 4px rgb(15 23 42 / 18%)); }
.room text { pointer-events: none; fill: #172033; font-size: 17px; font-weight: 700; }
.room .room-capacity { font-size: 13px; font-weight: 500; fill: #475569; }
.bed { cursor: grab; }
.bed rect { fill: #fff; stroke: #334155; stroke-width: 1.5; }
.bed.selected rect { fill: #fef3c7; stroke: #d97706; stroke-width: 3; }
.bed text { text-anchor: middle; font-size: 9px; font-weight: 700; }
.resize-handle { fill: #f59e0b !important; stroke: #fff !important; cursor: nwse-resize; }
.draft-room { fill: rgb(37 99 235 / 12%); stroke: #2563eb; stroke-width: 2; stroke-dasharray: 8 6; pointer-events: none; }
.editor-help { margin: 0; color: var(--pj-text-muted); font-size: .82rem; }
:deep(.stack-drawer__body label) { display: grid; gap: .35rem; font-size: .86rem; font-weight: 600; }
:deep(.stack-drawer__body .p-inputtext), :deep(.stack-drawer__body .p-select), :deep(.stack-drawer__body .p-inputnumber) { width: 100%; }
@media (max-width: 720px) {
  .editor-toolbar, .floor-settings { align-items: stretch; }
  .tools { width: 100%; }
  .tools :deep(.p-button) { flex: 1; }
  .canvas-wrap { margin-inline: -.25rem; width: calc(100% + .5rem); }
}
</style>
