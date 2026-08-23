import type { ClubMinistry } from '@/modules/clubs/types'
import {
  TIPO_AVENTUREROS,
  TIPO_CONQUISTADORES,
  TIPO_GUIAS_MAYORES,
} from '@/modules/organizaciones/types'

/**
 * Los IDs de catálogo no siempre coinciden con las constantes (el seeder
 * crea Conquistadores antes que Aventureros). El nombre manda.
 */
export function audienceKeyFromTipo(
  id?: number | null,
  nombre?: string | null,
): ClubMinistry | null {
  const name = (nombre || '').toLowerCase()
  if (name.includes('conquistador')) return 'conquistadores'
  if (name.includes('aventurero')) return 'aventureros'
  if (name.includes('guía') || name.includes('guia')) return 'guias_mayores'

  if (id === TIPO_CONQUISTADORES) return 'conquistadores'
  if (id === TIPO_AVENTUREROS) return 'aventureros'
  if (id === TIPO_GUIAS_MAYORES) return 'guias_mayores'
  return null
}
