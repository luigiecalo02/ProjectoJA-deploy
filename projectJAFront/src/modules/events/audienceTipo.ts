import type { ClubMinistry } from '@/modules/clubs/types'

/**
 * El nombre manda. Los IDs 6/7/8 no son fiables: el seeder creó
 * Conquistadores antes que Aventureros y no coinciden con las constantes.
 */
export function audienceKeyFromTipo(
  _id?: number | null,
  nombre?: string | null,
): ClubMinistry | null {
  const name = (nombre || '').toLowerCase()
  if (name.includes('conquistador')) return 'conquistadores'
  if (name.includes('aventurero')) return 'aventureros'
  if (name.includes('guía') || name.includes('guia')) return 'guias_mayores'
  return null
}
