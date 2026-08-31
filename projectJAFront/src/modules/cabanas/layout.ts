import type {
  CabanaBed,
  CabanaDoor,
  CabanaPoint,
  CabanaRoom,
  EstadoCama,
  EventoCabana,
  GeneroAlojamiento,
  RoomShape,
} from './types'

export function occupancyOf(item: { ocupadas?: number; ocupacion?: number } | null | undefined): number {
  return Number(item?.ocupadas ?? item?.ocupacion ?? 0)
}

export function bedState(
  bed: CabanaBed,
  options: { selectedId?: number | null; assignedId?: number | null } = {},
): EstadoCama {
  if (options.selectedId === bed.id || options.assignedId === bed.id || bed.asignada_a_mi) {
    return 'seleccionada'
  }
  if (bed.bloqueada || bed.estado === 'bloqueada' || bed.estado === 'no_disponible' || bed.estado === 'mantenimiento') {
    return 'bloqueada'
  }
  const used = occupancyOf(bed)
  if (used <= 0) return 'disponible'
  if (used >= Number(bed.capacidad || 1)) return 'completa'
  return 'parcial'
}

export function roomState(
  room: CabanaRoom,
  options: { selectedId?: number | null; assignedId?: number | null } = {},
): EstadoCama {
  if (room.estado) return room.estado
  const beds = room.camas ?? []
  if (beds.length && beds.every((bed) => bedState(bed, options) === 'bloqueada')) return 'bloqueada'
  const used = occupancyOf(room) || beds.reduce((sum, bed) => sum + occupancyOf(bed), 0)
  const capacity = Number(room.capacidad || beds.reduce((sum, bed) => sum + Number(bed.capacidad || 1), 0))
  if (used <= 0) return 'disponible'
  if (used >= capacity) return 'completa'
  return 'parcial'
}

export function cabanaLabel(item: EventoCabana): string {
  return item.nombre || item.cabana?.nombre || 'Cabaña'
}

export function cabanaFloors(item: EventoCabana) {
  return item.pisos?.length ? item.pisos : (item.cabana?.pisos ?? [])
}

export function cabanaCapacity(item: EventoCabana): number {
  return Number(item.capacidad_total ?? item.capacidad ?? item.cabana?.capacidad_total ?? 0)
}

export function genderClass(gender: GeneroAlojamiento | string | null | undefined): string {
  if (gender === 'M' || gender === 'masculino') return 'gender-M'
  if (gender === 'F' || gender === 'femenino') return 'gender-F'
  return 'gender-MIXTO'
}

export const PX_PER_METER = 100

export function pxToMeters(px: number): number {
  return Math.round((Number(px) / PX_PER_METER) * 100) / 100
}

export function metersToPx(meters: number): number {
  return Math.max(1, Math.round(Number(meters) * PX_PER_METER))
}

export function genderLabel(gender: GeneroAlojamiento | string | null | undefined): string {
  if (gender === 'M' || gender === 'masculino') return 'Hombres'
  if (gender === 'F' || gender === 'femenino') return 'Mujeres'
  return 'Mixta'
}

export function roomCaption(room: Pick<CabanaRoom, 'codigo' | 'nombre' | 'genero'>): string {
  const code = room.codigo || room.nombre
  return `${code} - ${genderLabel(room.genero)}`
}

export function bedDisplaySize(bed: Pick<CabanaBed, 'ancho' | 'alto'>): { width: number; height: number } {
  return {
    width: Number(bed.ancho || 120),
    height: Number(bed.alto || 72),
  }
}

export type ObjectOrientation = 'horizontal' | 'vertical'
export type BedOrientation = ObjectOrientation

export function doorOrientation(door: Pick<CabanaDoor, 'rotacion'>): ObjectOrientation {
  const angle = ((Number(door.rotacion ?? 0) % 180) + 180) % 180
  return angle >= 45 && angle < 135 ? 'vertical' : 'horizontal'
}

export function applyDoorOrientation(door: CabanaDoor, orientation: ObjectOrientation): void {
  door.rotacion = orientation === 'vertical' ? 90 : 0
}

export function normalizeAngle(degrees: number): number {
  return ((Number(degrees) % 360) + 360) % 360
}

export function objectCenter(item: { x: number; y: number; ancho?: number; alto?: number }): { x: number; y: number } {
  if (item.alto != null) {
    return { x: item.x + Number(item.ancho || 120) / 2, y: item.y + Number(item.alto) / 2 }
  }
  return { x: item.x, y: item.y }
}

export function rotateTransform(item: { x: number; y: number; ancho?: number; alto?: number; rotacion?: number }): string {
  const angle = normalizeAngle(item.rotacion ?? 0)
  const center = objectCenter(item)
  return `rotate(${angle} ${center.x} ${center.y})`
}

export function rotateHandlePoint(item: { x: number; y: number; ancho?: number; alto?: number }): { x: number; y: number } {
  if (item.alto != null) {
    return { x: item.x + Number(item.ancho || 120) / 2, y: item.y - 24 }
  }
  return { x: item.x, y: item.y - 26 }
}

export function normalizeLegacyBedRotation(bed: CabanaBed): void {
  const width = Number(bed.ancho || 120)
  const height = Number(bed.alto || 72)
  const angle = normalizeAngle(bed.rotacion ?? 0)
  if (height > width && (bed.rotacion == null || angle === 0)) {
    bed.ancho = height
    bed.alto = width
    bed.rotacion = 90
  }
}

export function angleFromPoints(center: { x: number; y: number }, point: { x: number; y: number }): number {
  return normalizeAngle((Math.atan2(point.y - center.y, point.x - center.x) * 180) / Math.PI)
}

export function doorRect(door: CabanaDoor): { x: number; y: number; width: number; height: number } {
  const length = Number(door.ancho || 56)
  const thickness = 16
  return { x: door.x - length / 2, y: door.y - thickness / 2, width: length, height: thickness }
}

export function bedOrientation(bed: Pick<CabanaBed, 'ancho' | 'alto' | 'rotacion'>): BedOrientation {
  const angle = ((Number(bed.rotacion ?? 0) % 180) + 180) % 180
  if (angle >= 45 && angle < 135) return 'vertical'
  const { width, height } = bedDisplaySize(bed)
  return height > width ? 'vertical' : 'horizontal'
}

export function bedSizeForOrientation(orientation: ObjectOrientation): { ancho: number; alto: number; rotacion: number } {
  return orientation === 'vertical'
    ? { ancho: 72, alto: 120, rotacion: 90 }
    : { ancho: 120, alto: 72, rotacion: 0 }
}

export function applyBedOrientation(
  bed: CabanaBed,
  _room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto'>,
  orientation: BedOrientation,
): void {
  bed.rotacion = orientation === 'vertical' ? 180 : 0
}

export function bedFlipped(bed: Pick<CabanaBed, 'rotacion'>): boolean {
  const angle = normalizeAngle(bed.rotacion ?? 0)
  return angle >= 90 && angle < 270
}

export function applyBedFacing(bed: CabanaBed, flipped: boolean): void {
  bed.rotacion = flipped ? 180 : 0
}

export function catalogBedStatus(bed: CabanaBed): 'disponible' | 'ocupada' | 'mantenimiento' | 'bloqueada' {
  if (bed.estado === 'mantenimiento') return 'mantenimiento'
  if (bed.estado === 'no_disponible' || bed.bloqueada) return 'bloqueada'
  if (occupancyOf(bed) > 0) return 'ocupada'
  return 'disponible'
}

export type VisualBedStatus = 'disponible' | 'reservada' | 'ocupada' | 'seleccionada' | 'mantenimiento' | 'bloqueada'

export function visualBedStatus(
  bed: CabanaBed,
  options: { selectedId?: number | null; assignedId?: number | null } = {},
): VisualBedStatus {
  if (options.selectedId === bed.id || options.assignedId === bed.id || bed.asignada_a_mi) {
    return 'seleccionada'
  }
  if (bed.estado === 'mantenimiento') return 'mantenimiento'
  if (bed.estado === 'no_disponible' || bed.bloqueada || bed.estado === 'bloqueada') return 'bloqueada'
  const used = occupancyOf(bed)
  const capacity = Number(bed.capacidad || 1)
  if (used <= 0) return 'disponible'
  if (used >= capacity) return 'ocupada'
  return 'reservada'
}

export interface BedVisualUnit {
  key: string
  mode: 'single' | 'bunk' | 'double'
  anchor: CabanaBed
  top: CabanaBed | null
  bottom: CabanaBed | null
  beds: CabanaBed[]
}

export function bedVisualUnits(beds: CabanaBed[]): BedVisualUnit[] {
  const seen = new Set<string>()
  const units: BedVisualUnit[] = []
  for (const bed of beds) {
    if (bed.tipo === 'camarote' && bed.grupo_camarote) {
      if (seen.has(bed.grupo_camarote)) continue
      seen.add(bed.grupo_camarote)
      const group = beds.filter((item) => item.grupo_camarote === bed.grupo_camarote)
      const bottom = group.find((item) => item.nivel_camarote === 'abajo') ?? group[0]
      const top = group.find((item) => item.nivel_camarote === 'arriba') ?? null
      units.push({ key: bed.grupo_camarote, mode: 'bunk', anchor: bottom, top, bottom, beds: group })
      continue
    }
    if (bed.tipo === 'camarote') {
      units.push({
        key: `bunk-${bed.id}`,
        mode: 'bunk',
        anchor: bed,
        top: bed.nivel_camarote === 'arriba' ? bed : null,
        bottom: bed.nivel_camarote === 'abajo' ? bed : bed,
        beds: [bed],
      })
      continue
    }
    units.push({
      key: `bed-${bed.id}`,
      mode: bed.tipo === 'doble' ? 'double' : 'single',
      anchor: bed,
      top: null,
      bottom: null,
      beds: [bed],
    })
  }
  return units
}

export function isBedInsideRoom(
  bed: Pick<CabanaBed, 'x' | 'y'>,
  room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>,
): boolean {
  return pointInRoom({ x: bed.x, y: bed.y }, room)
}

export function roomShape(room: Pick<CabanaRoom, 'forma'> | null | undefined): RoomShape {
  return room?.forma === 'circle' || room?.forma === 'polygon' ? room.forma : 'rect'
}

export function roomCenter(room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto'>): CabanaPoint {
  return { x: room.x + room.ancho / 2, y: room.y + room.alto / 2 }
}

export function roomRadius(room: Pick<CabanaRoom, 'ancho' | 'alto'>): number {
  return Math.min(room.ancho, room.alto) / 2
}

export function rectVertices(room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto'>): CabanaPoint[] {
  return [
    { x: room.x, y: room.y },
    { x: room.x + room.ancho, y: room.y },
    { x: room.x + room.ancho, y: room.y + room.alto },
    { x: room.x, y: room.y + room.alto },
  ]
}

export function roomPolygon(room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>): CabanaPoint[] {
  if (roomShape(room) === 'polygon' && (room.vertices?.length ?? 0) >= 3) {
    return room.vertices as CabanaPoint[]
  }
  return rectVertices(room)
}

export function boundsFromPoints(points: CabanaPoint[]): Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto'> {
  const xs = points.map((point) => point.x)
  const ys = points.map((point) => point.y)
  const x = Math.min(...xs)
  const y = Math.min(...ys)
  return { x, y, ancho: Math.max(1, Math.max(...xs) - x), alto: Math.max(1, Math.max(...ys) - y) }
}

export function pointInPolygon(point: CabanaPoint, vertices: CabanaPoint[]): boolean {
  let inside = false
  for (let i = 0, j = vertices.length - 1; i < vertices.length; j = i, i += 1) {
    const a = vertices[i]
    const b = vertices[j]
    const intersects = a.y > point.y !== b.y > point.y
      && point.x < ((b.x - a.x) * (point.y - a.y)) / ((b.y - a.y) || Number.EPSILON) + a.x
    if (intersects) inside = !inside
  }
  return inside
}

export function pointInRoom(
  point: CabanaPoint,
  room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>,
): boolean {
  if (roomShape(room) === 'circle') {
    const center = roomCenter(room)
    const radius = roomRadius(room)
    return (point.x - center.x) ** 2 + (point.y - center.y) ** 2 <= radius ** 2
  }
  return pointInPolygon(point, roomPolygon(room))
}

export function roomPath(room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>): string {
  const points = roomPolygon(room)
  return `M ${points.map((point) => `${point.x} ${point.y}`).join(' L ')} Z`
}

export function snapToRoomPerimeter(
  room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>,
  point: CabanaPoint,
): CabanaPoint {
  const snapped = snapDoorToRoomWall(room, point)
  return { x: snapped.x, y: snapped.y }
}

export function snapDoorToRoomWall(
  room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>,
  point: CabanaPoint,
  doorWidth = 56,
): { x: number; y: number; rotacion: number } {
  if (roomShape(room) === 'circle') {
    const center = roomCenter(room)
    const radius = roomRadius(room)
    const dx = point.x - center.x
    const dy = point.y - center.y
    const length = Math.hypot(dx, dy) || 1
    return {
      x: center.x + (dx / length) * radius,
      y: center.y + (dy / length) * radius,
      rotacion: normalizeAngle((Math.atan2(dy, dx) * 180) / Math.PI + 90),
    }
  }
  const vertices = roomPolygon(room)
  let best = { x: vertices[0]?.x ?? point.x, y: vertices[0]?.y ?? point.y, rotacion: 0 }
  let bestDist = Number.POSITIVE_INFINITY
  const inset = Math.max(8, Number(doorWidth || 56) / 2)
  for (let i = 0; i < vertices.length; i += 1) {
    const a = vertices[i]
    const b = vertices[(i + 1) % vertices.length]
    const candidate = closestPointOnSegment(point, a, b)
    if (candidate.dist >= bestDist) continue
    bestDist = candidate.dist
    const placed = insetPointOnSegment(a, b, candidate.point, inset)
    best = {
      x: placed.x,
      y: placed.y,
      rotacion: wallRotation(a, b),
    }
  }
  return best
}

export function defaultDoorForRoom(
  room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto' | 'forma' | 'vertices'>,
  id = -1,
  orientation: ObjectOrientation = 'horizontal',
): CabanaDoor {
  const rotacion = orientation === 'vertical' ? 90 : 0
  if (roomShape(room) === 'circle') {
    const center = roomCenter(room)
    return { id, x: center.x, y: room.y + room.alto, ancho: 56, rotacion }
  }
  const vertices = roomPolygon(room)
  if (roomShape(room) === 'polygon' && vertices.length >= 2) {
    const a = vertices[0]
    const b = vertices[1]
    return { id, x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, ancho: 56, rotacion }
  }
  return { id, x: room.x + room.ancho / 2, y: room.y + room.alto, ancho: 56, rotacion }
}

function closestPointOnSegment(point: CabanaPoint, a: CabanaPoint, b: CabanaPoint): { point: CabanaPoint; dist: number } {
  const dx = b.x - a.x
  const dy = b.y - a.y
  const length = dx * dx + dy * dy || 1
  const t = Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / length))
  const snapped = { x: a.x + dx * t, y: a.y + dy * t }
  return { point: snapped, dist: Math.hypot(point.x - snapped.x, point.y - snapped.y) }
}

function wallRotation(a: CabanaPoint, b: CabanaPoint): number {
  const angle = normalizeAngle((Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI)
  return angle >= 180 ? angle - 180 : angle
}

function insetPointOnSegment(a: CabanaPoint, b: CabanaPoint, point: CabanaPoint, inset: number): CabanaPoint {
  const dx = b.x - a.x
  const dy = b.y - a.y
  const length = Math.hypot(dx, dy)
  if (length <= inset * 2) {
    return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }
  }
  const t = ((point.x - a.x) * dx + (point.y - a.y) * dy) / (length * length)
  const pad = inset / length
  const clamped = Math.max(pad, Math.min(1 - pad, t))
  return { x: a.x + dx * clamped, y: a.y + dy * clamped }
}
