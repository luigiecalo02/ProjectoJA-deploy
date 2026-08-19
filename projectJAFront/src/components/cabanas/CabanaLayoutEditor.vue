<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Slider from 'primevue/slider'
import type {
  Cabana,
  CabanaBed,
  CabanaDoor,
  CabanaFloor,
  CabanaLayoutPayload,
  CabanaPoint,
  CabanaRoom,
  RoomShape,
} from '@/modules/cabanas/types'
import {
  angleFromPoints,
  applyBedOrientation,
  applyDoorOrientation,
  bedOrientation,
  boundsFromPoints,
  catalogBedStatus,
  defaultDoorForRoom,
  doorOrientation,
  doorRect,
  genderClass,
  metersToPx,
  normalizeAngle,
  normalizeLegacyBedRotation,
  objectCenter,
  pxToMeters,
  roomCaption,
  roomPath,
  roomShape,
  rotateHandlePoint,
  rotateTransform,
  snapToRoomPerimeter,
  type ObjectOrientation,
} from '@/modules/cabanas/layout'

type EditorTool = 'select' | 'draw-square' | 'draw-circle' | 'draw-polygon' | 'place-door' | 'erase'
type Selection =
  | { kind: 'room'; room: CabanaRoom }
  | { kind: 'bed'; room: CabanaRoom; bed: CabanaBed }
  | { kind: 'door'; room: CabanaRoom; door: CabanaDoor }
  | null

const props = defineProps<{
  cabana: Cabana
  catalog?: Cabana[]
  saving?: boolean
  readonly?: boolean
}>()

const emit = defineEmits<{
  save: [payload: CabanaLayoutPayload]
  'open-cabana': [id: number]
}>()

const floors = ref<CabanaFloor[]>([])
const activeFloorId = ref<number | null>(null)
const activeRoomId = ref<number | null>(null)
const expandedFloors = ref<number[]>([])
const tool = ref<EditorTool>('select')
const drawOrientation = ref<ObjectOrientation>('horizontal')
const drawAngle = ref(0)
const selection = ref<Selection>(null)
const zoom = ref(1)
const draftRoom = ref<{ x: number; y: number; ancho: number; alto: number; forma: RoomShape } | null>(null)
const draftPolygon = ref<CabanaPoint[]>([])
const past = ref<string[]>([])
const future = ref<string[]>([])
let nextTemporaryId = -1
let interaction:
  | {
      kind: 'draw' | 'move-room' | 'resize-room' | 'move-bed' | 'resize-bed' | 'rotate-bed' | 'rotate-door'
      startX: number
      startY: number
      room?: CabanaRoom
      bed?: CabanaBed
      initial: { x: number; y: number; ancho?: number; alto?: number }
    }
  | null = null

const genderOptions = [
  { label: 'Hombres', value: 'M' },
  { label: 'Mujeres', value: 'F' },
  { label: 'Mixta', value: 'MIXTO' },
]
const bedTypeOptions = [
  { label: 'Sencilla', value: 1 },
  { label: 'Doble', value: 2 },
  { label: 'Múltiple', value: 3 },
]
const bedStatusOptions = [
  { label: 'Disponible', value: 'disponible' },
  { label: 'Mantenimiento', value: 'mantenimiento' },
  { label: 'No disponible', value: 'no_disponible' },
]

const cabanaOptions = computed(() => {
  const items = props.catalog?.length ? props.catalog : [props.cabana]
  return items.map((item) => ({ label: item.nombre, value: item.id }))
})
const floorOptions = computed(() => floors.value.map((floor) => ({ label: floor.nombre, value: floor.id })))
const roomOptions = computed(() =>
  (activeFloor.value?.cuartos ?? []).map((room) => ({ label: roomCaption(room), value: room.id })),
)
const activeFloor = computed(() => floors.value.find((floor) => floor.id === activeFloorId.value) ?? null)
const activeRoom = computed(
  () => activeFloor.value?.cuartos.find((room) => room.id === activeRoomId.value) ?? null,
)
const viewWidth = computed(() => {
  if (activeRoom.value) return activeRoom.value.ancho + 120
  return activeFloor.value?.ancho || 1000
})
const viewHeight = computed(() => {
  if (activeRoom.value) return activeRoom.value.alto + 120
  return activeFloor.value?.alto || 650
})
const viewBox = computed(() => {
  if (activeRoom.value) {
    return `${activeRoom.value.x - 60} ${activeRoom.value.y - 60} ${viewWidth.value} ${viewHeight.value}`
  }
  return `0 0 ${viewWidth.value} ${viewHeight.value}`
})
const roomStats = computed(() => {
  const room = activeRoom.value
  const beds = room?.camas ?? []
  const configured = beds.length
  const occupied = beds.filter((bed) => catalogBedStatus(bed) === 'ocupada').length
  const blocked = beds.filter((bed) => ['mantenimiento', 'bloqueada'].includes(catalogBedStatus(bed))).length
  const available = Math.max(0, configured - occupied - blocked)
  const capacity = beds.reduce((sum, bed) => sum + Number(bed.capacidad || 1), 0) || room?.capacidad || 0
  return { capacity, configured, available, occupied }
})
const selectedBed = computed(() => (selection.value?.kind === 'bed' ? selection.value.bed : null))
const selectedRoom = computed(() => {
  if (selection.value?.kind === 'room') return selection.value.room
  if (selection.value?.kind === 'bed') return selection.value.room
  return activeRoom.value
})
const selectedAngle = computed(() => {
  if (selection.value?.kind === 'bed') return normalizeAngle(selection.value.bed.rotacion ?? 0)
  if (selection.value?.kind === 'door') return normalizeAngle(selection.value.door.rotacion ?? 0)
  return normalizeAngle(drawAngle.value)
})
const anglePresets = [0, 45, 90, 135, 180, 270]
const canUndo = computed(() => past.value.length > 0)
const canRedo = computed(() => future.value.length > 0)

watch(
  () => props.cabana,
  (cabana) => {
    floors.value = cloneFloors(cabana.pisos)
    if (!floors.value.length && !props.readonly) addFloor(false)
    activeFloorId.value = floors.value[0]?.id ?? null
    activeRoomId.value = floors.value[0]?.cuartos[0]?.id ?? null
    expandedFloors.value = floors.value.map((floor) => floor.id)
    selection.value = activeRoom.value ? { kind: 'room', room: activeRoom.value } : null
    const empty = !floors.value.some((floor) => floor.cuartos.length)
    tool.value = !props.readonly && empty ? 'draw-square' : 'select'
    past.value = []
    future.value = []
  },
  { immediate: true },
)

function cloneFloors(pisos: CabanaFloor[] | undefined): CabanaFloor[] {
  const floorsClone = JSON.parse(JSON.stringify(pisos ?? [])) as CabanaFloor[]
  for (const floor of floorsClone) {
    floor.cuartos = (floor.cuartos ?? []).map((room) => {
      const forma = roomShape(room)
      return {
        ...room,
        forma,
        vertices: room.vertices ?? [],
        puertas: room.puertas?.length ? room.puertas : [defaultDoorForRoom({ ...room, forma }, undefined, drawOrientation.value)],
        camas: (room.camas ?? []).map((bed) => {
          normalizeLegacyBedRotation(bed)
          return bed
        }),
      }
    })
  }
  return floorsClone
}

function snapshot(): string {
  return JSON.stringify({
    floors: floors.value,
    activeFloorId: activeFloorId.value,
    activeRoomId: activeRoomId.value,
  })
}

function restore(raw: string): void {
  const parsed = JSON.parse(raw) as {
    floors: CabanaFloor[]
    activeFloorId: number | null
    activeRoomId: number | null
  }
  floors.value = parsed.floors
  activeFloorId.value = parsed.activeFloorId
  activeRoomId.value = parsed.activeRoomId
  const room = activeRoom.value
  selection.value = room ? { kind: 'room', room } : null
}

function pushHistory(): void {
  past.value.push(snapshot())
  if (past.value.length > 40) past.value.shift()
  future.value = []
}

function undo(): void {
  const previous = past.value.pop()
  if (!previous) return
  future.value.push(snapshot())
  restore(previous)
}

function redo(): void {
  const next = future.value.pop()
  if (!next) return
  past.value.push(snapshot())
  restore(next)
}

function toggleFloor(id: number): void {
  expandedFloors.value = expandedFloors.value.includes(id)
    ? expandedFloors.value.filter((item) => item !== id)
    : [...expandedFloors.value, id]
}

function selectFloor(id: number): void {
  activeFloorId.value = id
  const first = floors.value.find((floor) => floor.id === id)?.cuartos[0]
  activeRoomId.value = first?.id ?? null
  selection.value = first ? { kind: 'room', room: first } : null
}

function selectRoom(room: CabanaRoom, floorId: number): void {
  activeFloorId.value = floorId
  activeRoomId.value = room.id
  selection.value = { kind: 'room', room }
  tool.value = 'select'
}

function addFloor(recordHistory = true): void {
  if (props.readonly) return
  if (recordHistory) pushHistory()
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
  expandedFloors.value.push(floor.id)
  activeFloorId.value = floor.id
  activeRoomId.value = null
  selection.value = null
  tool.value = 'draw-square'
}

function nextRoomCode(floor: CabanaFloor): string {
  return `${floor.orden}${String(floor.cuartos.length + 1).padStart(2, '0')}`
}

function nextRoomPosition(floor: CabanaFloor): { x: number; y: number } {
  const width = 600
  const height = 400
  const gap = 40
  const cols = Math.max(1, Math.floor((floor.ancho - 40) / (width + gap)))
  const index = floor.cuartos.length
  return {
    x: 40 + (index % cols) * (width + gap),
    y: 40 + Math.floor(index / cols) * (height + gap),
  }
}

function createRoom(overrides: Partial<CabanaRoom> = {}): CabanaRoom | null {
  if (!activeFloor.value || props.readonly) return null
  pushHistory()
  const index = activeFloor.value.cuartos.length + 1
  const code = nextRoomCode(activeFloor.value)
  const position = nextRoomPosition(activeFloor.value)
  const room: CabanaRoom = {
    id: nextTemporaryId--,
    piso_id: activeFloor.value.id,
    nombre: `Habitación ${index}`,
    codigo: code,
    ...position,
    ancho: 400,
    alto: 400,
    forma: 'rect',
    vertices: [],
    puertas: [],
    genero: 'MIXTO',
    capacidad: 1,
    camas: [],
    ...overrides,
  }
  if (!room.puertas?.length) {
    room.puertas = [defaultDoorForRoom(room, nextTemporaryId--, drawOrientation.value)]
  }
  activeFloor.value.cuartos.push(room)
  activeRoomId.value = room.id
  selection.value = { kind: 'room', room }
  tool.value = 'place-door'
  return room
}

function nextBedSlot(room: CabanaRoom): { x: number; y: number } {
  const width = 120
  const height = 72
  const gap = 22
  const pad = 28
  const cols = Math.max(1, Math.floor((room.ancho - pad * 2 + gap) / (width + gap)))
  for (let index = 0; index < 48; index += 1) {
    const x = room.x + pad + (index % cols) * (width + gap)
    const y = room.y + pad + Math.floor(index / cols) * (height + gap)
    if (y + height > room.y + room.alto - 20) break
    const taken = room.camas.some((bed) => Math.abs(bed.x - x) < width - 8 && Math.abs(bed.y - y) < height - 8)
    if (!taken) return { x, y }
  }
  return { x: room.x + pad, y: room.y + pad }
}

function createBed(room: CabanaRoom, point?: { x: number; y: number }): CabanaBed {
  pushHistory()
  const slot = point ?? nextBedSlot(room)
  const index = room.camas.length + 1
  const bed: CabanaBed = {
    id: nextTemporaryId--,
    cuarto_id: room.id,
    codigo: `C${room.codigo || index}`,
    nombre: `C${room.codigo || index}`,
    x: clamp(slot.x, room.x + 12, room.x + room.ancho - 128),
    y: clamp(slot.y, room.y + 12, room.y + room.alto - 80),
    ancho: 120,
    alto: 72,
    rotacion: drawAngle.value,
    capacidad: 1,
    estado: 'disponible',
    genero: room.genero,
  }
  if (room.camas.some((item) => item.codigo === bed.codigo)) {
    bed.codigo = `C${room.codigo || 'H'}-${index}`
    bed.nombre = bed.codigo
  }
  room.camas.push(bed)
  room.capacidad = Math.max(1, room.camas.reduce((sum, item) => sum + Number(item.capacidad || 1), 0))
  selection.value = { kind: 'bed', room, bed }
  tool.value = 'select'
  return bed
}

function addBedFromToolbar(): void {
  if (!activeRoom.value || props.readonly) return
  createBed(activeRoom.value)
}

function pointFromEvent(event: PointerEvent): { x: number; y: number } {
  const svg = event.currentTarget instanceof SVGSVGElement
    ? event.currentTarget
    : (event.currentTarget as SVGElement).ownerSVGElement
  if (!svg) return { x: 0, y: 0 }
  const rect = svg.getBoundingClientRect()
  return {
    x: ((event.clientX - rect.left) / rect.width) * viewWidth.value + (activeRoom.value ? activeRoom.value.x - 60 : 0),
    y: ((event.clientY - rect.top) / rect.height) * viewHeight.value + (activeRoom.value ? activeRoom.value.y - 60 : 0),
  }
}

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value))
}

function startCanvas(event: PointerEvent): void {
  if (props.readonly) return
  const point = pointFromEvent(event)
  if ((tool.value === 'draw-square' || tool.value === 'draw-circle') && activeFloor.value && !activeRoom.value) {
    interaction = { kind: 'draw', startX: point.x, startY: point.y, initial: { x: point.x, y: point.y } }
    draftRoom.value = {
      x: point.x,
      y: point.y,
      ancho: 0,
      alto: 0,
      forma: tool.value === 'draw-circle' ? 'circle' : 'rect',
    }
    ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
    return
  }
  if (tool.value === 'draw-polygon' && activeFloor.value && !activeRoom.value) {
    addPolygonVertex(point)
    return
  }
  if (tool.value === 'place-door' && activeRoom.value) {
    placeDoor(activeRoom.value, point)
    return
  }
  if (event.target === event.currentTarget && activeRoom.value) {
    selection.value = { kind: 'room', room: activeRoom.value }
  }
}

function addPolygonVertex(point: CabanaPoint): void {
  const first = draftPolygon.value[0]
  if (first && draftPolygon.value.length >= 3 && Math.hypot(point.x - first.x, point.y - first.y) <= 18) {
    closePolygon()
    return
  }
  draftPolygon.value.push(point)
}

function closePolygon(): void {
  if (draftPolygon.value.length < 3 || !activeFloor.value) return
  const bounds = boundsFromPoints(draftPolygon.value)
  createRoom({
    ...bounds,
    forma: 'polygon',
    vertices: draftPolygon.value,
  })
  draftPolygon.value = []
}

function startRoom(room: CabanaRoom, event: PointerEvent): void {
  event.stopPropagation()
  if (tool.value === 'place-door' && !props.readonly) {
    placeDoor(room, pointFromEvent(event))
    return
  }
  if (!activeRoom.value || activeRoom.value.id !== room.id) {
    selectRoom(room, activeFloorId.value ?? room.piso_id ?? 0)
    return
  }
  if (props.readonly || tool.value === 'erase') {
    selection.value = { kind: 'room', room }
    if (tool.value === 'erase' && !props.readonly) removeSelection()
    return
  }
  const point = pointFromEvent(event)
  selection.value = { kind: 'room', room }
  interaction = { kind: 'move-room', startX: point.x, startY: point.y, room, initial: { x: room.x, y: room.y } }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function startResizeRoom(room: CabanaRoom, event: PointerEvent): void {
  if (props.readonly) return
  event.stopPropagation()
  pushHistory()
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
  drawOrientation.value = bedOrientation(bed)
  drawAngle.value = normalizeAngle(bed.rotacion ?? 0)
  if (props.readonly) return
  if (tool.value === 'erase') {
    removeSelection()
    return
  }
  if (tool.value !== 'select') return
  pushHistory()
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

function startResizeBed(room: CabanaRoom, bed: CabanaBed, event: PointerEvent): void {
  if (props.readonly) return
  event.stopPropagation()
  pushHistory()
  const point = pointFromEvent(event)
  selection.value = { kind: 'bed', room, bed }
  interaction = {
    kind: 'resize-bed',
    startX: point.x,
    startY: point.y,
    room,
    bed,
    initial: { x: bed.x, y: bed.y, ancho: bed.ancho ?? 120, alto: bed.alto ?? 72 },
  }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function startRotateBed(room: CabanaRoom, bed: CabanaBed, event: PointerEvent): void {
  if (props.readonly) return
  event.stopPropagation()
  pushHistory()
  selection.value = { kind: 'bed', room, bed }
  const point = pointFromEvent(event)
  interaction = {
    kind: 'rotate-bed',
    startX: point.x,
    startY: point.y,
    room,
    bed,
    initial: { x: bed.x, y: bed.y },
  }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function startRotateDoor(room: CabanaRoom, door: CabanaDoor, event: PointerEvent): void {
  if (props.readonly) return
  event.stopPropagation()
  pushHistory()
  selection.value = { kind: 'door', room, door }
  const point = pointFromEvent(event)
  interaction = {
    kind: 'rotate-door',
    startX: point.x,
    startY: point.y,
    room,
    initial: { x: door.x, y: door.y },
  }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function setObjectAngle(degrees: number | null, options: { history?: boolean } = {}): void {
  if (degrees == null || props.readonly) return
  const angle = normalizeAngle(degrees)
  drawAngle.value = angle
  if (angle === 90 || angle === 270) drawOrientation.value = 'vertical'
  else if (angle === 0 || angle === 180) drawOrientation.value = 'horizontal'
  if (selection.value?.kind === 'bed') {
    if (options.history !== false) pushHistory()
    selection.value.bed.rotacion = angle
    return
  }
  if (selection.value?.kind === 'door') {
    if (options.history !== false) pushHistory()
    selection.value.door.rotacion = angle
  }
}

function snapRotateAngle(degrees: number, event: PointerEvent): number {
  return event.shiftKey ? normalizeAngle(Math.round(degrees / 15) * 15) : degrees
}

function onPointerMove(event: PointerEvent): void {
  if (!interaction || !activeFloor.value) return
  const point = pointFromEvent(event)
  const dx = point.x - interaction.startX
  const dy = point.y - interaction.startY
  if (interaction.kind === 'draw' && draftRoom.value) {
    const equal = draftRoom.value.forma === 'rect' || draftRoom.value.forma === 'circle'
    const width = equal ? Math.max(Math.abs(dx), Math.abs(dy)) : Math.abs(dx)
    const height = equal ? width : Math.abs(dy)
    draftRoom.value = {
      ...draftRoom.value,
      x: dx >= 0 ? interaction.startX : interaction.startX - width,
      y: dy >= 0 ? interaction.startY : interaction.startY - height,
      ancho: width,
      alto: height,
    }
    return
  }
  if (interaction.kind === 'move-room' && interaction.room && !activeRoom.value) {
    const room = interaction.room
    const nextX = clamp(interaction.initial.x + dx, 0, viewWidth.value - room.ancho)
    const nextY = clamp(interaction.initial.y + dy, 0, viewHeight.value - room.alto)
    const shiftX = nextX - room.x
    const shiftY = nextY - room.y
    room.x = nextX
    room.y = nextY
    for (const bed of room.camas) {
      bed.x += shiftX
      bed.y += shiftY
    }
    return
  }
  if (interaction.kind === 'resize-room' && interaction.room) {
    interaction.room.ancho = clamp((interaction.initial.ancho ?? 0) + dx, 240, 2000)
    interaction.room.alto = clamp((interaction.initial.alto ?? 0) + dy, 200, 1600)
    return
  }
  if (interaction.kind === 'move-bed' && interaction.room && interaction.bed) {
    const width = interaction.bed.ancho ?? 120
    const height = interaction.bed.alto ?? 72
    interaction.bed.x = clamp(interaction.initial.x + dx, interaction.room.x + 8, interaction.room.x + interaction.room.ancho - width - 8)
    interaction.bed.y = clamp(interaction.initial.y + dy, interaction.room.y + 8, interaction.room.y + interaction.room.alto - height - 8)
    return
  }
  if (interaction.kind === 'resize-bed' && interaction.room && interaction.bed) {
    interaction.bed.ancho = clamp((interaction.initial.ancho ?? 120) + dx, 80, interaction.room.x + interaction.room.ancho - interaction.bed.x - 8)
    interaction.bed.alto = clamp((interaction.initial.alto ?? 72) + dy, 56, interaction.room.y + interaction.room.alto - interaction.bed.y - 8)
    return
  }
  if (interaction.kind === 'rotate-bed' && interaction.bed) {
    interaction.bed.rotacion = snapRotateAngle(angleFromPoints(objectCenter(interaction.bed), point), event)
    drawAngle.value = interaction.bed.rotacion
    return
  }
  if (interaction.kind === 'rotate-door' && selection.value?.kind === 'door') {
    selection.value.door.rotacion = snapRotateAngle(angleFromPoints(selection.value.door, point), event)
    drawAngle.value = selection.value.door.rotacion
  }
}

function finishInteraction(): void {
  if (!interaction) return
  if (interaction.kind === 'draw' && draftRoom.value && draftRoom.value.ancho >= 140 && draftRoom.value.alto >= 140) {
    createRoom({
      x: draftRoom.value.x,
      y: draftRoom.value.y,
      ancho: draftRoom.value.ancho,
      alto: draftRoom.value.alto,
      forma: draftRoom.value.forma,
    })
  }
  interaction = null
  draftRoom.value = null
}

function selectDoor(room: CabanaRoom, door: CabanaDoor): void {
  selection.value = { kind: 'door', room, door }
  drawOrientation.value = doorOrientation(door)
  drawAngle.value = normalizeAngle(door.rotacion ?? 0)
  if (tool.value === 'erase' && !props.readonly) removeSelection()
}

function placeDoor(room: CabanaRoom, point: CabanaPoint): void {
  pushHistory()
  const snapped = snapToRoomPerimeter(room, point)
  const door: CabanaDoor = {
    id: nextTemporaryId--,
    x: snapped.x,
    y: snapped.y,
    ancho: 56,
    rotacion: drawAngle.value,
  }
  room.puertas = [...(room.puertas ?? []), door]
  selection.value = { kind: 'door', room, door }
  tool.value = 'select'
}

function removeSelection(): void {
  if (!selection.value || props.readonly) return
  pushHistory()
  const selected = selection.value
  if (selected.kind === 'door') {
    selected.room.puertas = (selected.room.puertas ?? []).filter((door) => door.id !== selected.door.id)
    selection.value = { kind: 'room', room: selected.room }
    return
  }
  if (selected.kind === 'bed') {
    selected.room.camas = selected.room.camas.filter((bed) => bed.id !== selected.bed.id)
    selected.room.capacidad = Math.max(1, selected.room.camas.reduce((sum, item) => sum + Number(item.capacidad || 1), 0) || 1)
    selection.value = { kind: 'room', room: selected.room }
    return
  }
  if (!activeFloor.value) return
  activeFloor.value.cuartos = activeFloor.value.cuartos.filter((room) => room.id !== selected.room.id)
  activeRoomId.value = activeFloor.value.cuartos[0]?.id ?? null
  selection.value = activeRoom.value ? { kind: 'room', room: activeRoom.value } : null
}

function setRoomMeters(room: CabanaRoom, axis: 'ancho' | 'alto', meters: number | null): void {
  if (meters == null || props.readonly) return
  pushHistory()
  room[axis] = metersToPx(meters)
}

function setBedMeters(bed: CabanaBed, room: CabanaRoom, axis: 'x' | 'y' | 'ancho' | 'alto', meters: number | null): void {
  if (meters == null || props.readonly) return
  pushHistory()
  const px = metersToPx(meters)
  if (axis === 'x') bed.x = room.x + px
  else if (axis === 'y') bed.y = room.y + px
  else bed[axis] = px
}

function setBedType(bed: CabanaBed, value: number | null): void {
  if (!value || props.readonly) return
  pushHistory()
  bed.capacidad = value === 3 ? Math.max(3, bed.capacidad || 3) : value
}

function bedTypeValue(bed: CabanaBed): number {
  if (bed.capacidad >= 3) return 3
  return bed.capacidad === 2 ? 2 : 1
}

function setDrawOrientation(orientation: ObjectOrientation): void {
  drawOrientation.value = orientation
  drawAngle.value = orientation === 'vertical' ? 90 : 0
  if (props.readonly) return
  if (selection.value?.kind === 'bed') {
    if (normalizeAngle(selection.value.bed.rotacion ?? 0) === drawAngle.value) return
    pushHistory()
    applyBedOrientation(selection.value.bed, selection.value.room, orientation)
    return
  }
  if (selection.value?.kind === 'door') {
    if (normalizeAngle(selection.value.door.rotacion ?? 0) === drawAngle.value) return
    pushHistory()
    applyDoorOrientation(selection.value.door, orientation)
  }
}

function statusLabel(status: string): string {
  if (status === 'ocupada') return 'Ocupada'
  if (status === 'mantenimiento') return 'Mantenimiento'
  if (status === 'bloqueada') return 'No disponible'
  return 'Disponible'
}

function save(): void {
  emit('save', {
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
        capacidad: Math.max(1, room.camas.reduce((sum, bed) => sum + Number(bed.capacidad || 1), 0) || room.capacidad),
        forma: room.forma ?? 'rect',
        vertices: room.vertices ?? [],
        puertas: (room.puertas ?? []).map((door) => ({
          id: door.id,
          x: door.x,
          y: door.y,
          ancho: door.ancho ?? 56,
          rotacion: door.rotacion ?? 0,
        })),
        camas: room.camas.map((bed) => ({
          ...(bed.id > 0 ? { id: bed.id } : {}),
          codigo: bed.codigo,
          nombre: bed.nombre,
          x: bed.x,
          y: bed.y,
          ancho: bed.ancho ?? 120,
          alto: bed.alto ?? 72,
          rotacion: bed.rotacion ?? 0,
          capacidad: bed.capacidad,
          estado: bed.estado === 'mantenimiento' || bed.estado === 'no_disponible' ? bed.estado : 'disponible',
        })),
      })),
    })),
  })
}
</script>

<template>
  <section class="studio">
    <header class="studio__top">
      <div class="studio__filters">
        <label>
          Cabaña
          <Select
            :model-value="cabana.id"
            :options="cabanaOptions"
            option-label="label"
            option-value="value"
            @update:model-value="(id) => emit('open-cabana', Number(id))"
          />
        </label>
        <label>
          Piso
          <Select
            :model-value="activeFloorId"
            :options="floorOptions"
            option-label="label"
            option-value="value"
            @update:model-value="selectFloor"
          />
        </label>
        <label>
          Habitación
          <Select
            :model-value="activeRoomId"
            :options="roomOptions"
            option-label="label"
            option-value="value"
            placeholder="Selecciona"
            @update:model-value="(id) => {
              const room = activeFloor?.cuartos.find((item) => item.id === id)
              if (room) selectRoom(room, activeFloorId ?? 0)
            }"
          />
        </label>
        <Button
          v-if="!readonly"
          label="Nueva habitación"
          icon="pi pi-plus"
          @click="createRoom({ forma: 'rect', ancho: 400, alto: 400 })"
        />
        <Button
          v-if="!readonly"
          label="Guardar"
          icon="pi pi-save"
          :loading="saving"
          severity="secondary"
          outlined
          @click="save"
        />
      </div>
      <div class="studio__stats">
        <article>
          <span>Capacidad habitación</span>
          <strong>{{ roomStats.capacity }} personas</strong>
        </article>
        <article>
          <span>Camas configuradas</span>
          <strong>{{ roomStats.configured }} camas</strong>
        </article>
        <article class="is-ok">
          <span>Disponibles</span>
          <strong>{{ roomStats.available }} camas</strong>
        </article>
        <article class="is-busy">
          <span>Ocupadas</span>
          <strong>{{ roomStats.occupied }} camas</strong>
        </article>
      </div>
    </header>

    <div class="studio__body">
      <aside class="studio__tree">
        <h3>Estructura de la cabaña</h3>
        <div v-for="floor in floors" :key="floor.id" class="tree-floor">
          <button type="button" class="tree-floor__head" @click="toggleFloor(floor.id); selectFloor(floor.id)">
            <i :class="expandedFloors.includes(floor.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
            <i class="pi pi-building" />
            <span>{{ floor.nombre }}</span>
          </button>
          <div v-if="expandedFloors.includes(floor.id)" class="tree-floor__rooms">
            <button
              v-for="room in floor.cuartos"
              :key="room.id"
              type="button"
              :class="{ active: activeRoomId === room.id }"
              @click="selectRoom(room, floor.id)"
            >
              <i class="pi pi-th-large" />
              {{ roomCaption(room) }}
            </button>
          </div>
        </div>
        <Button
          v-if="!readonly"
          label="Agregar piso"
          icon="pi pi-plus"
          text
          class="tree-add"
          @click="addFloor()"
        />
      </aside>

      <div class="studio__stage">
        <div class="stage-toolbar">
          <div class="tools">
            <Button
              label="Seleccionar"
              icon="pi pi-cursor"
              size="small"
              :outlined="tool !== 'select'"
              @click="tool = 'select'"
            />
            <Button
              v-if="!activeRoom"
              label="Cuadrada"
              icon="pi pi-stop"
              size="small"
              :outlined="tool !== 'draw-square'"
              :disabled="readonly"
              @click="tool = 'draw-square'"
            />
            <Button
              v-if="!activeRoom"
              label="Circular"
              icon="pi pi-circle"
              size="small"
              :outlined="tool !== 'draw-circle'"
              :disabled="readonly"
              @click="tool = 'draw-circle'"
            />
            <Button
              v-if="!activeRoom"
              label="Polígono"
              icon="pi pi-share-alt"
              size="small"
              :outlined="tool !== 'draw-polygon'"
              :disabled="readonly"
              @click="tool = 'draw-polygon'; draftPolygon = []"
            />
            <Button
              v-if="activeRoom"
              label="Agregar puerta"
              icon="pi pi-sign-in"
              size="small"
              :outlined="tool !== 'place-door'"
              :disabled="readonly"
              @click="tool = 'place-door'"
            />
            <Button
              label="Agregar cama"
              icon="pi pi-plus"
              size="small"
              :disabled="readonly || !activeRoom"
              @click="addBedFromToolbar"
            />
            <Button
              label="Borrar"
              icon="pi pi-trash"
              size="small"
              :outlined="tool !== 'erase'"
              :disabled="readonly"
              severity="danger"
              @click="tool = 'erase'"
            />
            <Button
              label="Horizontal"
              icon="pi pi-arrows-h"
              size="small"
              :outlined="drawOrientation !== 'horizontal'"
              :disabled="readonly"
              @click="setDrawOrientation('horizontal')"
            />
            <Button
              label="Vertical"
              icon="pi pi-arrows-v"
              size="small"
              :outlined="drawOrientation !== 'vertical'"
              :disabled="readonly"
              @click="setDrawOrientation('vertical')"
            />
            <div v-if="!readonly" class="angle-tools">
              <span>Ángulo</span>
              <Slider
                :model-value="selectedAngle"
                :min="0"
                :max="359"
                :step="1"
                class="angle-slider"
                @update:model-value="setObjectAngle($event, { history: false })"
              />
              <InputNumber
                :model-value="selectedAngle"
                :min="0"
                :max="359"
                suffix="°"
                :use-grouping="false"
                class="angle-input"
                @update:model-value="setObjectAngle($event)"
              />
              <Button
                v-for="preset in anglePresets"
                :key="preset"
                :label="`${preset}°`"
                size="small"
                text
                :severity="selectedAngle === preset ? 'primary' : 'secondary'"
                @click="setObjectAngle(preset)"
              />
            </div>
          </div>
          <div class="zoom">
            <Button icon="pi pi-minus" text rounded size="small" @click="zoom = Math.max(0.6, zoom - 0.1)" />
            <span>{{ Math.round(zoom * 100) }}%</span>
            <Button icon="pi pi-plus" text rounded size="small" @click="zoom = Math.min(1.8, zoom + 0.1)" />
            <Button icon="pi pi-window-maximize" text rounded size="small" @click="zoom = 1" />
          </div>
        </div>

        <div class="canvas-wrap">
          <svg
            v-if="activeFloor"
            class="layout-canvas"
            :class="`tool-${tool}`"
            :viewBox="viewBox"
            :style="{ width: `${Math.round(720 * zoom)}px` }"
            role="application"
            aria-label="Editor del croquis de la cabaña"
            @pointerdown="startCanvas"
            @pointermove="onPointerMove"
            @pointerup="finishInteraction"
            @pointercancel="finishInteraction"
            @dblclick="tool === 'draw-polygon' ? closePolygon() : undefined"
          >
            <defs>
              <pattern id="cabana-grid" width="20" height="20" patternUnits="userSpaceOnUse">
                <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#c5d0e0" stroke-width="0.8" />
              </pattern>
            </defs>
            <rect class="canvas-bg" :x="activeRoom ? activeRoom.x - 60 : 0" :y="activeRoom ? activeRoom.y - 60 : 0" :width="viewWidth" :height="viewHeight" fill="url(#cabana-grid)" />

            <template v-if="activeRoom">
              <text class="dim" :x="activeRoom.x + activeRoom.ancho / 2" :y="activeRoom.y - 16">
                {{ pxToMeters(activeRoom.ancho).toFixed(2) }} m
              </text>
              <text
                class="dim dim-v"
                :x="activeRoom.x - 18"
                :y="activeRoom.y + activeRoom.alto / 2"
              >
                {{ pxToMeters(activeRoom.alto).toFixed(2) }} m
              </text>
              <ellipse
                v-if="roomShape(activeRoom) === 'circle'"
                class="room-wall"
                :class="genderClass(activeRoom.genero)"
                :cx="activeRoom.x + activeRoom.ancho / 2"
                :cy="activeRoom.y + activeRoom.alto / 2"
                :rx="activeRoom.ancho / 2"
                :ry="activeRoom.alto / 2"
                @pointerdown="startRoom(activeRoom, $event)"
              />
              <path
                v-else-if="roomShape(activeRoom) === 'polygon'"
                class="room-wall"
                :class="genderClass(activeRoom.genero)"
                :d="roomPath(activeRoom)"
                @pointerdown="startRoom(activeRoom, $event)"
              />
              <rect
                v-else
                class="room-wall"
                :class="genderClass(activeRoom.genero)"
                :x="activeRoom.x"
                :y="activeRoom.y"
                :width="activeRoom.ancho"
                :height="activeRoom.alto"
                rx="4"
                @pointerdown="startRoom(activeRoom, $event)"
              />
              <g
                v-for="door in activeRoom.puertas ?? []"
                :key="door.id"
                class="door"
                :class="{ selected: selection?.kind === 'door' && selection.door.id === door.id }"
                :transform="rotateTransform(door)"
                @pointerdown.stop="selectDoor(activeRoom, door)"
              >
                <rect
                  :x="doorRect(door).x"
                  :y="doorRect(door).y"
                  :width="doorRect(door).width"
                  :height="doorRect(door).height"
                  rx="3"
                />
                <g v-if="!readonly && selection?.kind === 'door' && selection.door.id === door.id">
                  <line
                    class="rotate-stem"
                    :x1="door.x"
                    :y1="doorRect(door).y"
                    :x2="rotateHandlePoint(door).x"
                    :y2="rotateHandlePoint(door).y"
                  />
                  <circle
                    class="rotate-handle"
                    :cx="rotateHandlePoint(door).x"
                    :cy="rotateHandlePoint(door).y"
                    r="7"
                    @pointerdown="startRotateDoor(activeRoom, door, $event)"
                  />
                </g>
              </g>
              <g
                v-for="bed in activeRoom.camas"
                :key="bed.id"
                class="bed-card"
                :class="[`is-${catalogBedStatus(bed)}`, { selected: selectedBed?.id === bed.id }]"
                :transform="rotateTransform(bed)"
                @pointerdown="startBed(activeRoom, bed, $event)"
              >
                <rect :x="bed.x" :y="bed.y" :width="bed.ancho ?? 120" :height="bed.alto ?? 72" rx="8" />
                <rect class="pillow" :x="bed.x + 10" :y="bed.y + 8" width="28" height="14" rx="6" />
                <rect class="pillow" :x="bed.x + 44" :y="bed.y + 8" width="28" height="14" rx="6" />
                <text class="bed-code" :x="bed.x + 12" :y="bed.y + 40">
                  {{ bed.codigo }}
                </text>
                <text class="bed-status" :x="bed.x + 12" :y="bed.y + 56">
                  {{ statusLabel(catalogBedStatus(bed)) }}
                </text>
                <text class="bed-cap" :x="(bed.x + (bed.ancho ?? 120)) - 8" :y="bed.y + 56">
                  {{ bed.capacidad }}
                </text>
                <g v-if="!readonly && selectedBed?.id === bed.id">
                  <rect
                    class="bed-outline"
                    :x="bed.x - 6"
                    :y="bed.y - 6"
                    :width="(bed.ancho ?? 120) + 12"
                    :height="(bed.alto ?? 72) + 12"
                    rx="10"
                  />
                  <circle
                    class="handle"
                    :cx="bed.x + (bed.ancho ?? 120)"
                    :cy="bed.y + (bed.alto ?? 72)"
                    r="6"
                    @pointerdown="startResizeBed(activeRoom, bed, $event)"
                  />
                  <line
                    class="rotate-stem"
                    :x1="objectCenter(bed).x"
                    :y1="bed.y"
                    :x2="rotateHandlePoint(bed).x"
                    :y2="rotateHandlePoint(bed).y"
                  />
                  <circle
                    class="rotate-handle"
                    :cx="rotateHandlePoint(bed).x"
                    :cy="rotateHandlePoint(bed).y"
                    r="7"
                    @pointerdown="startRotateBed(activeRoom, bed, $event)"
                  />
                </g>
              </g>
              <rect
                v-if="!readonly && selection?.kind === 'room'"
                class="handle-rect"
                :x="activeRoom.x + activeRoom.ancho - 10"
                :y="activeRoom.y + activeRoom.alto - 10"
                width="18"
                height="18"
                rx="4"
                @pointerdown="startResizeRoom(activeRoom, $event)"
              />
            </template>

            <template v-else>
              <g
                v-for="room in activeFloor.cuartos"
                :key="room.id"
                class="floor-room"
                :class="genderClass(room.genero)"
                @pointerdown="startRoom(room, $event)"
              >
                <ellipse
                  v-if="roomShape(room) === 'circle'"
                  :cx="room.x + room.ancho / 2"
                  :cy="room.y + room.alto / 2"
                  :rx="room.ancho / 2"
                  :ry="room.alto / 2"
                />
                <path v-else-if="roomShape(room) === 'polygon'" :d="roomPath(room)" />
                <rect v-else :x="room.x" :y="room.y" :width="room.ancho" :height="room.alto" rx="8" />
                <text :x="room.x + 16" :y="room.y + 28">{{ roomCaption(room) }}</text>
                <text class="muted" :x="room.x + 16" :y="room.y + 48">
                  {{ room.camas.length }} camas · {{ (room.puertas ?? []).length }} puertas
                </text>
              </g>
              <ellipse
                v-if="draftRoom?.forma === 'circle'"
                class="draft-room"
                :cx="draftRoom.x + draftRoom.ancho / 2"
                :cy="draftRoom.y + draftRoom.alto / 2"
                :rx="draftRoom.ancho / 2"
                :ry="draftRoom.alto / 2"
              />
              <rect
                v-else-if="draftRoom"
                class="draft-room"
                :x="draftRoom.x"
                :y="draftRoom.y"
                :width="draftRoom.ancho"
                :height="draftRoom.alto"
                rx="8"
              />
              <g v-if="draftPolygon.length" class="draft-polygon">
                <polyline
                  :points="draftPolygon.map((point) => `${point.x},${point.y}`).join(' ')"
                  fill="none"
                />
                <circle
                  v-for="(point, index) in draftPolygon"
                  :key="index"
                  :cx="point.x"
                  :cy="point.y"
                  r="5"
                />
              </g>
            </template>
          </svg>
          <p v-if="!activeRoom" class="canvas-hint">
            {{
              readonly
                ? 'Selecciona una habitación en el árbol.'
                : tool === 'draw-polygon'
                  ? 'Haz clic para marcar vértices. Cierra el polígono con doble clic o volviendo al primer punto.'
                  : 'Elige Cuadrada, Circular o Polígono y dibuja sobre el plano.'
            }}
          </p>
          <p v-else-if="tool === 'place-door'" class="canvas-hint">
            Haz clic sobre el muro para colocar una puerta.
          </p>
        </div>

        <footer class="stage-footer">
          <ul class="legend">
            <li><i class="dot is-ok" /> Disponible</li>
            <li><i class="dot is-busy" /> Ocupada</li>
            <li><i class="dot is-wait" /> Mantenimiento</li>
            <li><i class="dot is-off" /> No disponible</li>
          </ul>
          <div class="history">
            <Button icon="pi pi-undo" text rounded :disabled="!canUndo || readonly" @click="undo" />
            <Button icon="pi pi-refresh" text rounded :disabled="!canRedo || readonly" @click="redo" />
          </div>
        </footer>
      </div>

      <aside class="studio__props">
        <template v-if="selection?.kind === 'door'">
          <header>
            <div>
              <h3>Puerta</h3>
              <small>Ubicada sobre el muro de la habitación.</small>
            </div>
            <Button v-if="!readonly" icon="pi pi-trash" text rounded severity="danger" @click="removeSelection" />
          </header>
          <label>
            Ancho
            <InputNumber v-model="selection.door.ancho" :min="24" :max="160" suffix=" px" :disabled="readonly" />
          </label>
          <label>
            Ángulo
            <div class="angle-field">
              <Slider
                :model-value="selectedAngle"
                :min="0"
                :max="359"
                :step="1"
                :disabled="readonly"
                @update:model-value="setObjectAngle($event, { history: false })"
              />
              <InputNumber
                :model-value="selectedAngle"
                :min="0"
                :max="359"
                suffix="°"
                :use-grouping="false"
                :disabled="readonly"
                @update:model-value="setObjectAngle($event)"
              />
            </div>
          </label>
          <div class="orient-actions">
            <Button
              v-for="preset in anglePresets"
              :key="`door-${preset}`"
              :label="`${preset}°`"
              size="small"
              :outlined="selectedAngle !== preset"
              :disabled="readonly"
              @click="setObjectAngle(preset)"
            />
          </div>
          <p class="tip">Gira la puerta a cualquier ángulo. Arrastra el mango circular o usa 0° / 90° para horizontal y vertical.</p>
        </template>
        <template v-else-if="selectedBed && selection?.kind === 'bed'">
          <header>
            <div>
              <h3>Cama seleccionada</h3>
              <small>{{ selectedBed.codigo }}</small>
            </div>
            <Button v-if="!readonly" icon="pi pi-trash" text rounded severity="danger" @click="removeSelection" />
          </header>
          <div class="prop-hero">
            <i class="pi pi-stop" />
            <div>
              <strong>{{ selectedBed.codigo }}</strong>
              <em :class="`is-${catalogBedStatus(selectedBed)}`">{{ statusLabel(catalogBedStatus(selectedBed)) }}</em>
            </div>
          </div>
          <label>Código / Nombre<InputText v-model="selectedBed.codigo" :disabled="readonly" /></label>
          <label>
            Capacidad
            <div class="field-inline">
              <InputNumber v-model="selectedBed.capacidad" :min="1" :max="20" :disabled="readonly" />
              <span>personas</span>
            </div>
          </label>
          <label>
            Tipo de cama
            <Select
              :model-value="bedTypeValue(selectedBed)"
              :options="bedTypeOptions"
              option-label="label"
              option-value="value"
              :disabled="readonly"
              @update:model-value="setBedType(selectedBed, $event)"
            />
          </label>
          <label>
            Estado
            <Select
              :model-value="selectedBed.estado || 'disponible'"
              :options="bedStatusOptions"
              option-label="label"
              option-value="value"
              :disabled="readonly"
              @update:model-value="selectedBed.estado = $event"
            />
          </label>
          <div class="pair">
            <label>
              Posición X
              <InputNumber
                :model-value="pxToMeters(selectedBed.x - selection.room.x)"
                :min="0"
                :max-fraction-digits="2"
                suffix=" m"
                :disabled="readonly"
                @update:model-value="setBedMeters(selectedBed, selection.room, 'x', $event)"
              />
            </label>
            <label>
              Posición Y
              <InputNumber
                :model-value="pxToMeters(selectedBed.y - selection.room.y)"
                :min="0"
                :max-fraction-digits="2"
                suffix=" m"
                :disabled="readonly"
                @update:model-value="setBedMeters(selectedBed, selection.room, 'y', $event)"
              />
            </label>
          </div>
          <div class="pair">
            <label>
              Ancho
              <InputNumber
                :model-value="pxToMeters(selectedBed.ancho ?? 120)"
                :min="0.4"
                :max-fraction-digits="2"
                suffix=" m"
                :disabled="readonly"
                @update:model-value="setBedMeters(selectedBed, selection.room, 'ancho', $event)"
              />
            </label>
            <label>
              Alto
              <InputNumber
                :model-value="pxToMeters(selectedBed.alto ?? 72)"
                :min="0.3"
                :max-fraction-digits="2"
                suffix=" m"
                :disabled="readonly"
                @update:model-value="setBedMeters(selectedBed, selection.room, 'alto', $event)"
              />
            </label>
          </div>
          <label>
            Ángulo
            <div class="angle-field">
              <Slider
                :model-value="selectedAngle"
                :min="0"
                :max="359"
                :step="1"
                :disabled="readonly"
                @update:model-value="setObjectAngle($event, { history: false })"
              />
              <InputNumber
                :model-value="selectedAngle"
                :min="0"
                :max="359"
                suffix="°"
                :use-grouping="false"
                :disabled="readonly"
                @update:model-value="setObjectAngle($event)"
              />
            </div>
          </label>
          <div class="orient-actions">
            <Button
              v-for="preset in anglePresets"
              :key="`bed-${preset}`"
              :label="`${preset}°`"
              size="small"
              :outlined="selectedAngle !== preset"
              :disabled="readonly"
              @click="setObjectAngle(preset)"
            />
          </div>
          <p class="tip">Gira la cama a cualquier ángulo. Arrastra el mango de arriba, usa el deslizador o escribe los grados. Mantén Shift para saltos de 15°.</p>
        </template>

        <template v-else-if="selectedRoom">
          <header>
            <div>
              <h3>Habitación</h3>
              <small>{{ roomCaption(selectedRoom) }}</small>
            </div>
            <Button v-if="!readonly" icon="pi pi-trash" text rounded severity="danger" @click="removeSelection" />
          </header>
          <label>Nombre<InputText v-model="selectedRoom.nombre" :disabled="readonly" /></label>
          <label>Código<InputText v-model="selectedRoom.codigo" :disabled="readonly" /></label>
          <label>
            Forma
            <Select
              :model-value="roomShape(selectedRoom)"
              :options="[
                { label: 'Cuadrada', value: 'rect' },
                { label: 'Circular', value: 'circle' },
                { label: 'Polígono', value: 'polygon' },
              ]"
              option-label="label"
              option-value="value"
              :disabled="readonly"
              @update:model-value="selectedRoom.forma = $event"
            />
          </label>
          <div class="doors-box">
            <strong>Puertas ({{ (selectedRoom.puertas ?? []).length }})</strong>
            <button
              v-for="door in selectedRoom.puertas ?? []"
              :key="door.id"
              type="button"
              :class="{ active: selection?.kind === 'door' && selection.door.id === door.id }"
              @click="selection = { kind: 'door', room: selectedRoom, door }"
            >
              Puerta en {{ pxToMeters(door.x - selectedRoom.x).toFixed(1) }} m,
              {{ pxToMeters(door.y - selectedRoom.y).toFixed(1) }} m
            </button>
            <Button
              v-if="!readonly"
              label="Colocar puerta"
              icon="pi pi-plus"
              size="small"
              text
              @click="tool = 'place-door'"
            />
          </div>
          <label>
            Género
            <Select v-model="selectedRoom.genero" :options="genderOptions" option-label="label" option-value="value" :disabled="readonly" />
          </label>
          <div class="pair">
            <label>
              Ancho
              <InputNumber
                :model-value="pxToMeters(selectedRoom.ancho)"
                :min="1"
                :max-fraction-digits="2"
                suffix=" m"
                :disabled="readonly"
                @update:model-value="setRoomMeters(selectedRoom, 'ancho', $event)"
              />
            </label>
            <label>
              Alto
              <InputNumber
                :model-value="pxToMeters(selectedRoom.alto)"
                :min="1"
                :max-fraction-digits="2"
                suffix=" m"
                :disabled="readonly"
                @update:model-value="setRoomMeters(selectedRoom, 'alto', $event)"
              />
            </label>
          </div>
          <p class="tip">Pulsa Agregar puerta y toca el muro. Luego coloca las camas dentro de la forma.</p>
        </template>

        <template v-else>
          <header>
            <div>
              <h3>Sin selección</h3>
              <small>Elige o crea una habitación para editar el croquis.</small>
            </div>
          </header>
        </template>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.studio {
  display: grid;
  gap: 0.85rem;
  min-width: 0;
}
.studio__top {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: end;
}
.studio__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  align-items: end;
}
.studio__filters label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--pj-text-muted);
}
.studio__filters :deep(.p-select) {
  min-width: 10.5rem;
}
.studio__stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(7.5rem, 1fr));
  gap: 0.5rem;
}
.studio__stats article {
  background: var(--pj-bg);
  border: 1px solid var(--pj-border);
  border-radius: 10px;
  padding: 0.45rem 0.65rem;
}
.studio__stats span {
  display: block;
  color: var(--pj-text-muted);
  font-size: 0.7rem;
}
.studio__stats strong {
  font-size: 0.92rem;
}
.studio__stats .is-ok strong { color: #15803d; }
.studio__stats .is-busy strong { color: #b91c1c; }
.studio__body {
  display: grid;
  grid-template-columns: 16rem minmax(0, 1fr) 18rem;
  gap: 0.75rem;
  min-height: 34rem;
  align-items: stretch;
}
.studio__tree, .studio__props, .studio__stage {
  border: 1px solid var(--pj-border);
  border-radius: 12px;
  background: var(--pj-bg-elevated);
  min-width: 0;
}
.studio__tree, .studio__props {
  padding: 0.85rem;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.studio__tree h3, .studio__props h3 {
  margin: 0;
  font-size: 0.92rem;
}
.studio__props header {
  display: flex;
  justify-content: space-between;
  align-items: start;
  gap: 0.4rem;
}
.studio__props header small, .studio__props header h3 { display: block; }
.studio__props label {
  display: grid;
  gap: 0.28rem;
  font-size: 0.78rem;
  font-weight: 700;
}
.studio__props :deep(.p-inputtext),
.studio__props :deep(.p-select),
.studio__props :deep(.p-inputnumber) {
  width: 100%;
}
.tree-floor__head, .tree-floor__rooms button {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.4rem;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  padding: 0.4rem 0.35rem;
  border-radius: 8px;
  cursor: pointer;
}
.tree-floor__rooms {
  display: grid;
  gap: 0.15rem;
  padding-left: 1rem;
}
.tree-floor__rooms button.active {
  background: color-mix(in srgb, var(--pj-primary) 12%, white);
  color: var(--pj-navy);
  font-weight: 700;
}
.tree-add { justify-content: flex-start; margin-top: auto; }
.studio__stage { display: grid; grid-template-rows: auto 1fr auto; }
.stage-toolbar, .stage-footer {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  align-items: center;
  padding: 0.55rem 0.7rem;
}
.tools, .zoom, .history, .legend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}
.zoom span { min-width: 3rem; text-align: center; font-size: 0.78rem; font-weight: 700; }
.canvas-wrap {
  overflow: auto;
  min-height: 22rem;
  background: #eef2f6;
  display: grid;
  place-items: center;
  position: relative;
}
.layout-canvas {
  display: block;
  max-width: none;
  min-height: 360px;
  touch-action: none;
  user-select: none;
  background: #f8fafc;
  border-radius: 8px;
}
.layout-canvas.tool-draw-square,
.layout-canvas.tool-draw-circle,
.layout-canvas.tool-draw-polygon,
.layout-canvas.tool-place-door { cursor: crosshair; }
.room-wall { fill: #fff; stroke: #94a3b8; stroke-width: 14; }
.room-wall.gender-M { stroke: #60a5fa; }
.room-wall.gender-F { stroke: #f472b6; }
.room-wall.gender-MIXTO { stroke: #a78bfa; }
.door rect { fill: #cbd5e1; stroke: #475569; stroke-width: 1; cursor: pointer; }
.door.selected rect { fill: #f59e0b; stroke: #b45309; }
.dim { fill: #64748b; font-size: 14px; font-weight: 700; text-anchor: middle; }
.dim-v { writing-mode: tb; }
.floor-room rect, .floor-room ellipse, .floor-room path { fill: #fff; stroke: #64748b; stroke-width: 3; }
.draft-polygon polyline { stroke: #2563eb; stroke-width: 2; stroke-dasharray: 8 6; }
.draft-polygon circle { fill: #2563eb; }
.doors-box {
  display: grid;
  gap: 0.35rem;
}
.doors-box button {
  border: 1px solid var(--pj-border);
  border-radius: 8px;
  background: var(--pj-bg);
  text-align: left;
  padding: 0.4rem 0.5rem;
  cursor: pointer;
}
.doors-box button.active {
  border-color: var(--p-primary-color);
  background: var(--pj-primary-soft);
}
.floor-room text { fill: #0f172a; font-size: 18px; font-weight: 700; pointer-events: none; }
.floor-room .muted { font-size: 13px; font-weight: 500; fill: #64748b; }
.bed-card { cursor: grab; }
.bed-card rect { fill: #fff; stroke: #22c55e; stroke-width: 2; }
.bed-card .pillow { fill: #e2e8f0; stroke: none; }
.bed-card text { pointer-events: none; }
.bed-card .bed-code { font-size: 13px; font-weight: 800; fill: #0f172a; }
.bed-card .bed-status { font-size: 10px; font-weight: 700; fill: #16a34a; }
.bed-card .bed-cap { font-size: 11px; font-weight: 800; text-anchor: end; fill: #334155; }
.bed-card.is-ocupada rect { stroke: #ef4444; }
.bed-card.is-ocupada .bed-status { fill: #dc2626; }
.bed-card.is-mantenimiento rect { stroke: #94a3b8; }
.bed-card.is-bloqueada rect { stroke: #3b82f6; }
.bed-outline { fill: none; stroke: #2563eb; stroke-dasharray: 6 4; stroke-width: 1.6; }
.handle { fill: #2563eb; stroke: #fff; stroke-width: 2; cursor: nwse-resize; }
.handle-rect { fill: #f59e0b; stroke: #fff; cursor: nwse-resize; }
.rotate-stem { stroke: #2563eb; stroke-width: 1.6; }
.rotate-handle { fill: #fff; stroke: #2563eb; stroke-width: 2.2; cursor: grab; }
.angle-tools {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  padding-left: 0.35rem;
}
.angle-tools > span {
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--pj-text-muted);
}
.angle-slider { width: 7.5rem; }
.angle-input { width: 5.5rem; }
.angle-field {
  display: grid;
  grid-template-columns: 1fr 5.5rem;
  gap: 0.55rem;
  align-items: center;
}
.draft-room { fill: rgb(37 99 235 / 10%); stroke: #2563eb; stroke-dasharray: 8 6; }
.canvas-hint {
  position: absolute;
  bottom: 0.7rem;
  margin: 0;
  color: var(--pj-text-muted);
  font-size: 0.8rem;
}
.legend { list-style: none; margin: 0; padding: 0; color: var(--pj-text-muted); font-size: 0.75rem; }
.dot {
  display: inline-block;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  margin-right: 0.25rem;
}
.dot.is-ok { background: #22c55e; }
.dot.is-busy { background: #ef4444; }
.dot.is-wait { background: #94a3b8; }
.dot.is-off { background: #3b82f6; }
.prop-hero {
  display: flex;
  gap: 0.6rem;
  align-items: center;
  padding: 0.55rem;
  border-radius: 10px;
  background: var(--pj-bg);
}
.prop-hero i { font-size: 1.2rem; color: var(--pj-navy); }
.prop-hero em {
  display: block;
  font-style: normal;
  font-size: 0.72rem;
  font-weight: 700;
}
.prop-hero em.is-disponible { color: #15803d; }
.prop-hero em.is-ocupada { color: #b91c1c; }
.pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.field-inline { display: flex; align-items: center; gap: 0.45rem; }
.field-inline .grow { flex: 1; }
.orient-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.4rem; }
.tip {
  margin: 0;
  padding: 0.65rem 0.7rem;
  border-radius: 10px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 0.75rem;
  line-height: 1.35;
}
@media (max-width: 1100px) {
  .studio__body { grid-template-columns: 1fr; }
  .studio__stats { grid-template-columns: repeat(2, 1fr); width: 100%; }
  .studio__tree { order: 2; }
  .studio__props { order: 3; }
}
</style>
