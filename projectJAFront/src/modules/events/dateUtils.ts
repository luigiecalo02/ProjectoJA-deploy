/**
 * Utilidades de fecha calendario (sin corrimiento por zona horaria).
 * El API guarda fechas a medianoche UTC; hay que leer el Y-M-D del string.
 */

export function toApiDate(value: Date | null): string {
  if (!value) {
    const d = new Date()
    d.setDate(d.getDate() + 7)
    d.setHours(0, 0, 0, 0)
    value = d
  }
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} 00:00:00`
}

export function toApiDateOrEmpty(value: Date | null): string {
  if (!value) return ''
  return toApiDate(value)
}

/** Convierte ISO/API/Date a Date local a medianoche del día calendario. */
export function dateOnly(value: string | Date | null | undefined): Date | null {
  if (!value) return null

  if (typeof value === 'string') {
    const m = value.trim().match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (m) {
      return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 0, 0, 0, 0)
    }
  }

  if (value instanceof Date) {
    if (Number.isNaN(value.getTime())) return null
    // DatePicker a veces entrega medianoche UTC del día elegido
    if (
      value.getUTCHours() === 0 &&
      value.getUTCMinutes() === 0 &&
      value.getUTCSeconds() === 0 &&
      value.getUTCMilliseconds() === 0
    ) {
      return new Date(value.getUTCFullYear(), value.getUTCMonth(), value.getUTCDate(), 0, 0, 0, 0)
    }
    return new Date(value.getFullYear(), value.getMonth(), value.getDate(), 0, 0, 0, 0)
  }

  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return null
  return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0, 0)
}

export function formatDateOnly(
  value: string | Date | null | undefined,
  locale = 'es-ES',
  options: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  },
): string {
  const d = dateOnly(value)
  if (!d) return '—'
  return d.toLocaleDateString(locale, options)
}

export function addOneDay(from: Date): Date {
  const d = dateOnly(from) || new Date(from)
  d.setDate(d.getDate() + 1)
  return d
}

export function ensureEndAfterStart(start: Date, end: Date): Date {
  const startDay = dateOnly(start) || start
  const endDay = dateOnly(end) || end
  if (endDay.getTime() >= startDay.getTime()) return endDay
  return addOneDay(startDay)
}
