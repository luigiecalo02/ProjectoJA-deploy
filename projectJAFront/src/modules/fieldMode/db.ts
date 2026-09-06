import { clearEvidenceCache } from '@/modules/fieldMode/evidenceCache'
import type { FieldOfflinePack, FieldOutboxItem, FieldPackRecord, FieldPhotoOutboxItem } from '@/modules/fieldMode/types'

const DB_NAME = 'projectja_field'
const DB_VERSION = 2
const PACKS = 'packs'
const OUTBOX = 'outbox'
const PHOTOS = 'photos'

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)
    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(PACKS)) {
        db.createObjectStore(PACKS, { keyPath: 'userId' })
      }
      if (!db.objectStoreNames.contains(OUTBOX)) {
        const store = db.createObjectStore(OUTBOX, { keyPath: 'id' })
        store.createIndex('userId', 'userId', { unique: false })
      }
      if (!db.objectStoreNames.contains(PHOTOS)) {
        const store = db.createObjectStore(PHOTOS, { keyPath: 'id' })
        store.createIndex('userId', 'userId', { unique: false })
      }
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('No se pudo abrir IndexedDB'))
  })
}

function requestToPromise<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('Error de IndexedDB'))
  })
}

function txDone(tx: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve()
    tx.onerror = () => reject(tx.error ?? new Error('Error de transacción IndexedDB'))
    tx.onabort = () => reject(tx.error ?? new Error('Transacción IndexedDB abortada'))
  })
}

export async function saveFieldPack(userId: number, pack: FieldOfflinePack): Promise<void> {
  const db = await openDb()
  const tx = db.transaction(PACKS, 'readwrite')
  const record: FieldPackRecord = {
    userId,
    downloadedAt: pack.downloaded_at,
    pack,
  }
  tx.objectStore(PACKS).put(record)
  await txDone(tx)
}

export async function getFieldPack(userId: number): Promise<FieldPackRecord | null> {
  const db = await openDb()
  const tx = db.transaction(PACKS, 'readonly')
  const record = await requestToPromise(tx.objectStore(PACKS).get(userId))
  return (record as FieldPackRecord | undefined) ?? null
}

export async function putOutboxItem(item: FieldOutboxItem): Promise<void> {
  const db = await openDb()
  const tx = db.transaction(OUTBOX, 'readwrite')
  tx.objectStore(OUTBOX).put(item)
  await txDone(tx)
}

export async function listOutbox(userId: number): Promise<FieldOutboxItem[]> {
  const db = await openDb()
  const tx = db.transaction(OUTBOX, 'readonly')
  const items = await requestToPromise(tx.objectStore(OUTBOX).index('userId').getAll(userId))
  return ((items as FieldOutboxItem[]) ?? []).sort((a, b) => a.createdAt.localeCompare(b.createdAt))
}

export async function deleteOutboxItem(id: string): Promise<void> {
  const db = await openDb()
  const tx = db.transaction(OUTBOX, 'readwrite')
  tx.objectStore(OUTBOX).delete(id)
  await txDone(tx)
}

export async function putPhotoItem(item: FieldPhotoOutboxItem): Promise<void> {
  const db = await openDb()
  const tx = db.transaction(PHOTOS, 'readwrite')
  tx.objectStore(PHOTOS).put(item)
  await txDone(tx)
}

export async function listPhotos(userId: number): Promise<FieldPhotoOutboxItem[]> {
  const db = await openDb()
  const tx = db.transaction(PHOTOS, 'readonly')
  const items = await requestToPromise(tx.objectStore(PHOTOS).index('userId').getAll(userId))
  return ((items as FieldPhotoOutboxItem[]) ?? []).sort((a, b) => a.createdAt.localeCompare(b.createdAt))
}

export async function deletePhotoItem(id: string): Promise<void> {
  const db = await openDb()
  const tx = db.transaction(PHOTOS, 'readwrite')
  tx.objectStore(PHOTOS).delete(id)
  await txDone(tx)
}

export async function clearFieldDataForUser(userId: number): Promise<void> {
  const db = await openDb()
  const tx = db.transaction([PACKS, OUTBOX, PHOTOS], 'readwrite')
  tx.objectStore(PACKS).delete(userId)
  const outbox = tx.objectStore(OUTBOX)
  const items = await requestToPromise(outbox.index('userId').getAll(userId))
  for (const item of (items as FieldOutboxItem[]) ?? []) {
    outbox.delete(item.id)
  }
  const photos = tx.objectStore(PHOTOS)
  const photoItems = await requestToPromise(photos.index('userId').getAll(userId))
  for (const item of (photoItems as FieldPhotoOutboxItem[]) ?? []) {
    photos.delete(item.id)
  }
  await txDone(tx)
  await clearEvidenceCache()
}

export async function clearAllFieldData(): Promise<void> {
  const db = await openDb()
  const tx = db.transaction([PACKS, OUTBOX, PHOTOS], 'readwrite')
  tx.objectStore(PACKS).clear()
  tx.objectStore(OUTBOX).clear()
  tx.objectStore(PHOTOS).clear()
  await txDone(tx)
  await clearEvidenceCache()
}

export function outboxKey(userId: number, actividadId: number, organizacionId: number): string {
  return `${userId}:${actividadId}:${organizacionId}`
}

export function photoOutboxKey(
  userId: number,
  actividadId: number,
  organizacionId: number,
): string {
  return `${userId}:photo:${actividadId}:${organizacionId}:${Date.now()}:${Math.random().toString(36).slice(2, 8)}`
}
