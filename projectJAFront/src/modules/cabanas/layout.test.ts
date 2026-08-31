import { applyBedFacing, applyBedOrientation, applyDoorOrientation, bedFlipped, bedOrientation, bedState, bedVisualUnits, doorOrientation, isBedInsideRoom, normalizeAngle, normalizeLegacyBedRotation, objectCenter, occupancyOf, pointInRoom, pxToMeters, roomCaption, roomState, rotateTransform, snapDoorToRoomWall, visualBedStatus } from './layout'
import type { CabanaBed, CabanaRoom } from './types'

const bed = (overrides: Partial<CabanaBed> = {}): CabanaBed => ({
  id: 1,
  codigo: 'A-1',
  x: 20,
  y: 30,
  capacidad: 2,
  ocupadas: 0,
  ...overrides,
})

const room = (overrides: Partial<CabanaRoom> = {}): CabanaRoom => ({
  id: 1,
  nombre: 'Mixto',
  x: 0,
  y: 0,
  ancho: 200,
  alto: 150,
  genero: 'MIXTO',
  capacidad: 2,
  camas: [bed()],
  ...overrides,
})

export function runLayoutTests(): string[] {
  const cases: Array<[string, () => boolean]> = [
    ['ocupacion usa ocupadas o ocupacion', () => occupancyOf({ ocupacion: 3 }) === 3 && occupancyOf({ ocupadas: 1 }) === 1],
    ['cama vacia esta disponible', () => bedState(bed()) === 'disponible'],
    ['cama parcial cuando hay cupo', () => bedState(bed({ ocupadas: 1 })) === 'parcial'],
    ['cama completa al llenar capacidad', () => bedState(bed({ ocupadas: 2 })) === 'completa'],
    ['cama seleccionada tiene prioridad', () => bedState(bed({ ocupadas: 2 }), { selectedId: 1 }) === 'seleccionada'],
    ['cuarto completo si todas las camas lo estan', () => roomState(room({ ocupadas: 2, capacidad: 2 })) === 'completa'],
    ['cama dentro del cuarto', () => isBedInsideRoom(bed(), room())],
    ['cama fuera del cuarto', () => !isBedInsideRoom(bed({ x: 400 }), room())],
    ['100px equivalen a 1 metro', () => pxToMeters(600) === 6],
    ['etiqueta de cuarto incluye codigo y genero', () => roomCaption(room({ codigo: '101', genero: 'M' })) === '101 - Hombres'],
    ['cama ancha es horizontal', () => bedOrientation(bed({ ancho: 120, alto: 72 })) === 'horizontal'],
    ['orientacion opuesta voltea la cama', () => {
      const item = bed({ x: 20, y: 30, ancho: 120, alto: 72, rotacion: 0 })
      applyBedOrientation(item, room(), 'vertical')
      return item.rotacion === 180
    }],
    ['aplicar facing derecha deja rotacion 180', () => {
      const item = bed({ rotacion: 0 })
      applyBedFacing(item, true)
      return item.rotacion === 180 && bedFlipped(item)
    }],
    ['punto dentro del circulo', () => pointInRoom({ x: 100, y: 100 }, room({ x: 0, y: 0, ancho: 200, alto: 200, forma: 'circle' }))],
    ['punto fuera del circulo', () => !pointInRoom({ x: 5, y: 5 }, room({ x: 0, y: 0, ancho: 200, alto: 200, forma: 'circle' }))],
    ['puerta vertical guarda 90 grados', () => {
      const door = { id: 1, x: 10, y: 10, ancho: 56, rotacion: 0 }
      applyDoorOrientation(door, 'vertical')
      return doorOrientation(door) === 'vertical' && door.rotacion === 90
    }],
    ['normaliza angulos negativos', () => normalizeAngle(-45) === 315],
    ['el centro de la puerta es su propio punto', () => {
      const center = objectCenter({ x: 40, y: 80, ancho: 56 })
      return center.x === 40 && center.y === 80
    }],
    ['el giro svg usa el centro de la cama', () => rotateTransform({ x: 10, y: 20, ancho: 120, alto: 72, rotacion: 45 }) === 'rotate(45 70 56)'],
    ['cama alta sin angulo se normaliza a 90', () => {
      const item = bed({ ancho: 72, alto: 120, rotacion: 0 })
      normalizeLegacyBedRotation(item)
      return item.ancho === 120 && item.alto === 72 && item.rotacion === 90
    }],
    ['puerta se pega a la pared inferior', () => {
      const snapped = snapDoorToRoomWall(room(), { x: 90, y: 180 })
      return snapped.y === 150 && snapped.rotacion === 0
    }],
    ['puerta se pega a la pared derecha en vertical', () => {
      const snapped = snapDoorToRoomWall(room(), { x: 240, y: 80 })
      return snapped.x === 200 && snapped.rotacion === 90
    }],
    ['puerta no se sale de la esquina del muro', () => {
      const snapped = snapDoorToRoomWall(room(), { x: 0, y: 0 }, 56)
      return snapped.x >= 28 && snapped.y === 0
    }],
    ['camarote se agrupa en un solo icono', () => {
      const units = bedVisualUnits([
        bed({ id: 1, tipo: 'camarote', grupo_camarote: 'g1', nivel_camarote: 'abajo' }),
        bed({ id: 2, tipo: 'camarote', grupo_camarote: 'g1', nivel_camarote: 'arriba' }),
        bed({ id: 3, codigo: 'S1', tipo: 'sencilla' }),
      ])
      return units.length === 2 && units[0].mode === 'bunk' && units[1].mode === 'single'
    }],
    ['ocupacion parcial se ve reservada', () => visualBedStatus(bed({ ocupadas: 1, capacidad: 2 })) === 'reservada'],
  ]

  return cases.filter(([, run]) => !run()).map(([name]) => name)
}

const failed = runLayoutTests()
if (failed.length) {
  throw new Error(`Fallaron reglas de croquis: ${failed.join(', ')}`)
}
