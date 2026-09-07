/** Pide al navegador que no evicte IndexedDB ni la caché del paquete de campo. */
export async function requestPersistentFieldStorage(): Promise<boolean> {
  if (typeof navigator === 'undefined' || !navigator.storage?.persist) {
    return false
  }
  try {
    if (await navigator.storage.persisted()) {
      return true
    }
    return await navigator.storage.persist()
  } catch {
    return false
  }
}
