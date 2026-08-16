import type { CabanaBed, CabanaRoom, EstadoCama, EventoCabana, GeneroAlojamiento } from './types'

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

export function isBedInsideRoom(
  bed: Pick<CabanaBed, 'x' | 'y'>,
  room: Pick<CabanaRoom, 'x' | 'y' | 'ancho' | 'alto'>,
): boolean {
  return bed.x >= room.x && bed.y >= room.y && bed.x <= room.x + room.ancho && bed.y <= room.y + room.alto
}
