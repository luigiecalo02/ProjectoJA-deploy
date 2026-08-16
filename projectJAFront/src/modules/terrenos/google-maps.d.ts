/* Minimal Google Maps typings used by terrenos module */
declare namespace google.maps {
  class Map {
    constructor(el: HTMLElement, opts?: Record<string, unknown>)
    setMapTypeId(id: string): void
    setCenter(c: { lat: number; lng: number }): void
    setZoom(z: number): void
    fitBounds(b: LatLngBounds): void
    getDiv(): HTMLElement
    setOptions(opts: Record<string, unknown>): void
    addListener(event: string, handler: (...args: unknown[]) => void): MapsEventListener
  }
  class LatLng {
    constructor(lat: number, lng: number)
    lat(): number
    lng(): number
  }
  class Size {
    constructor(width: number, height: number)
  }
  class Point {
    readonly x: number
    readonly y: number
    constructor(x: number, y: number)
  }
  class OverlayView {
    setMap(map: Map | null): void
    getPanes(): {
      overlayLayer: HTMLElement
      overlayMouseTarget: HTMLElement
    }
    getProjection(): {
      fromLatLngToDivPixel(position: LatLng): Point | null
    }
    onAdd(): void
    draw(): void
    onRemove(): void
  }
  interface MarkerLabel {
    text: string
    color?: string
    fontSize?: string
    fontWeight?: string
    className?: string
  }
  enum SymbolPath {
    CIRCLE,
  }
  class LatLngBounds {
    extend(p: { lat: number; lng: number } | LatLng): void
    isEmpty(): boolean
  }
  class Polygon {
    constructor(opts?: Record<string, unknown>)
    setMap(map: Map | null): void
    getPath(): MVCArray
    setOptions(opts: Record<string, unknown>): void
    addListener(event: string, handler: (...args: unknown[]) => void): MapsEventListener
  }
  class Polyline {
    constructor(opts?: Record<string, unknown>)
    setMap(map: Map | null): void
    getPath(): MVCArray
    setPath(path: Array<{ lat: number; lng: number }>): void
    setOptions(opts: Record<string, unknown>): void
  }
  class Marker {
    constructor(opts?: Record<string, unknown>)
    setMap(map: Map | null): void
    setPosition(p: { lat: number; lng: number }): void
    setLabel(label: MarkerLabel): void
    setIcon(icon: Record<string, unknown>): void
    setTitle(title: string): void
  }
  class MVCArray {
    getArray(): Array<{ lat: () => number; lng: () => number }>
    getLength(): number
    getAt(i: number): { lat: () => number; lng: () => number }
  }
  class MapsEventListener {
    remove(): void
  }
  namespace event {
    function clearInstanceListeners(instance: unknown): void
    function addListener(instance: unknown, event: string, handler: (...args: unknown[]) => void): MapsEventListener
    function removeListener(listener: MapsEventListener): void
  }
  namespace geometry {
    namespace spherical {
      function computeArea(path: Array<{ lat: number; lng: number }>): number
      function computeLength(path: Array<{ lat: number; lng: number }>): number
      function computeDistanceBetween(a: LatLng | { lat: number; lng: number }, b: LatLng | { lat: number; lng: number }): number
    }
  }
}

interface Window {
  google?: { maps: typeof google.maps }
}
