import type { GeoJsonGeometry } from '@/modules/terrenos/types'

/** Centro inicial del mapa (Bogotá, Colombia) */
export const DEFAULT_MAP_CENTER = { lat: 4.711, lng: -74.0721 }
export const DEFAULT_MAP_ZOOM = 12

const LOTE_COLORS: Record<string, string> = {
  disponible: '#2e7d32',
  asignado: '#1565c0',
  reservado: '#f9a825',
  no_disponible: '#c62828',
}

export function loteFillColor(estado: string): string {
  return LOTE_COLORS[estado] ?? '#607d8b'
}

export function geoJsonToLatLngPaths(
  geo: GeoJsonGeometry | null | undefined,
): Array<Array<{ lat: number; lng: number }>> {
  if (!geo) return []
  const geometry = geo.type === 'Feature' ? geo.geometry : geo
  if (!geometry?.coordinates) return []

  if (geometry.type === 'Polygon') {
    const ring = (geometry.coordinates as number[][][])[0] ?? []
    return [ring.map(([lng, lat]) => ({ lat, lng }))]
  }

  if (geometry.type === 'MultiPolygon') {
    return (geometry.coordinates as number[][][][]).map((poly) =>
      (poly[0] ?? []).map(([lng, lat]) => ({ lat, lng })),
    )
  }

  return []
}

export function pathsToPolygonGeoJson(path: Array<{ lat: number; lng: number }>): GeoJsonGeometry {
  const coords = path.map((p) => [p.lng, p.lat])
  if (coords.length && (coords[0][0] !== coords[coords.length - 1][0] || coords[0][1] !== coords[coords.length - 1][1])) {
    coords.push([...coords[0]])
  }
  return { type: 'Polygon', coordinates: [coords] }
}

/** Ray casting: punto dentro de anillo [lng,lat] o {lat,lng} */
export function pointInRing(point: { lat: number; lng: number }, ring: Array<{ lat: number; lng: number }>): boolean {
  let inside = false
  const n = ring.length
  for (let i = 0, j = n - 1; i < n; j = i++) {
    const xi = ring[i].lng
    const yi = ring[i].lat
    const xj = ring[j].lng
    const yj = ring[j].lat
    const intersect = yi > point.lat !== yj > point.lat
      && point.lng < ((xj - xi) * (point.lat - yi)) / ((yj - yi) || 1e-12) + xi
    if (intersect) inside = !inside
  }
  return inside
}

/**
 * Comprueba que el path hijo esté contenido en la geometría padre.
 * Si el padre no tiene geometría, retorna false.
 */
export function isPathContainedInGeo(
  childPath: Array<{ lat: number; lng: number }>,
  parentGeo: GeoJsonGeometry | null | undefined,
): boolean {
  if (!childPath.length) return true
  const parentPaths = geoJsonToLatLngPaths(parentGeo)
  if (!parentPaths.length) return false

  const samples: Array<{ lat: number; lng: number }> = []
  for (let i = 0; i < childPath.length; i++) {
    samples.push(childPath[i])
    const next = childPath[(i + 1) % childPath.length]
    samples.push({
      lat: (childPath[i].lat + next.lat) / 2,
      lng: (childPath[i].lng + next.lng) / 2,
    })
  }
  const centroid = {
    lat: childPath.reduce((s, p) => s + p.lat, 0) / childPath.length,
    lng: childPath.reduce((s, p) => s + p.lng, 0) / childPath.length,
  }
  samples.push(centroid)

  return samples.every((pt) => parentPaths.some((ring) => pointInRing(pt, ring)))
}

/** Solape aproximado entre un path y una geometría (o dos geometrías vía path). */
export function pathIntersectsGeo(
  path: Array<{ lat: number; lng: number }>,
  otherGeo: GeoJsonGeometry | null | undefined,
): boolean {
  if (!path.length || !otherGeo) return false
  const otherPaths = geoJsonToLatLngPaths(otherGeo)
  if (!otherPaths.length) return false

  const samples: Array<{ lat: number; lng: number }> = []
  for (let i = 0; i < path.length; i++) {
    samples.push(path[i])
    const next = path[(i + 1) % path.length]
    samples.push({
      lat: (path[i].lat + next.lat) / 2,
      lng: (path[i].lng + next.lng) / 2,
    })
  }
  samples.push({
    lat: path.reduce((s, p) => s + p.lat, 0) / path.length,
    lng: path.reduce((s, p) => s + p.lng, 0) / path.length,
  })

  if (samples.some((pt) => otherPaths.some((ring) => pointInRing(pt, ring)))) {
    return true
  }

  // También: puntos de la otra geometría dentro del path dibujado
  for (const ring of otherPaths) {
    for (let i = 0; i < ring.length; i++) {
      if (pointInRing(ring[i], path)) return true
      const next = ring[(i + 1) % ring.length]
      const mid = { lat: (ring[i].lat + next.lat) / 2, lng: (ring[i].lng + next.lng) / 2 }
      if (pointInRing(mid, path)) return true
    }
  }

  return false
}

export function measurePaths(path: Array<{ lat: number; lng: number }>): { area: number; perimetro: number } {
  if (path.length < 3) return { area: 0, perimetro: 0 }

  const spherical = window.google?.maps?.geometry?.spherical
  if (spherical) {
    const closed = [...path]
    if (closed[0].lat !== closed[closed.length - 1].lat || closed[0].lng !== closed[closed.length - 1].lng) {
      closed.push(closed[0])
    }
    return {
      area: Math.round(spherical.computeArea(path) * 100) / 100,
      perimetro: Math.round(spherical.computeLength(closed) * 100) / 100,
    }
  }

  // Fallback planar approximation
  const lat0 = path[0].lat
  const lon0 = path[0].lng
  const mPerDegLat = 111320
  const mPerDegLon = 111320 * Math.cos((lat0 * Math.PI) / 180)
  let area = 0
  let peri = 0
  for (let i = 0; i < path.length; i++) {
    const a = path[i]
    const b = path[(i + 1) % path.length]
    const x1 = (a.lng - lon0) * mPerDegLon
    const y1 = (a.lat - lat0) * mPerDegLat
    const x2 = (b.lng - lon0) * mPerDegLon
    const y2 = (b.lat - lat0) * mPerDegLat
    area += x1 * y2 - x2 * y1
    const dLat = ((b.lat - a.lat) * Math.PI) / 180
    const dLon = ((b.lng - a.lng) * Math.PI) / 180
    const lat1 = (a.lat * Math.PI) / 180
    const lat2 = (b.lat * Math.PI) / 180
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2
    peri += 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(h)))
  }

  return { area: Math.round(Math.abs(area / 2) * 100) / 100, perimetro: Math.round(peri * 100) / 100 }
}

let mapsPromise: Promise<typeof google.maps> | null = null

export function loadGoogleMaps(apiKey: string): Promise<typeof google.maps> {
  const existing = window.google?.maps
  if (existing) return Promise.resolve(existing)
  if (mapsPromise) return mapsPromise

  mapsPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&libraries=geometry`
    script.async = true
    script.onload = () => {
      const maps = window.google?.maps
      if (!maps) {
        reject(new Error('Google Maps no disponible'))
        return
      }
      resolve(maps)
    }
    script.onerror = () => reject(new Error('No se pudo cargar Google Maps'))
    document.head.appendChild(script)
  })

  return mapsPromise
}
