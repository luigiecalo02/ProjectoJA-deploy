export type StackDrawerLevel = 1 | 2 | 3 | 4

/** Anchos y colores de cabecera por nivel (ministerios JA). */
export const stackDrawerThemes: Record<
  StackDrawerLevel,
  { width: string; label: string; cssClass: string }
> = {
  1: {
    width: '90vw',
    label: 'Guías Mayores',
    cssClass: 'stack-drawer--l1',
  },
  2: {
    width: '70vw',
    label: 'Conquistadores',
    cssClass: 'stack-drawer--l2',
  },
  3: {
    width: '50vw',
    label: 'Aventureros',
    cssClass: 'stack-drawer--l3',
  },
  4: {
    width: '30vw',
    label: 'Jóvenes Adventistas',
    cssClass: 'stack-drawer--l4',
  },
}
