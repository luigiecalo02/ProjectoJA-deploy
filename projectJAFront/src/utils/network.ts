/** Red usable para llamadas al API. En iPhone el Wi‑Fi sin internet a veces marca onLine. */
export function hasUsableNetwork(): boolean {
  return typeof navigator === 'undefined' || navigator.onLine
}
