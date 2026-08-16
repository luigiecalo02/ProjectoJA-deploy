import { bedState, isBedInsideRoom, occupancyOf, roomState } from './layout'
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
  ]

  return cases.filter(([, run]) => !run()).map(([name]) => name)
}

const failed = runLayoutTests()
if (failed.length) {
  throw new Error(`Fallaron reglas de croquis: ${failed.join(', ')}`)
}
