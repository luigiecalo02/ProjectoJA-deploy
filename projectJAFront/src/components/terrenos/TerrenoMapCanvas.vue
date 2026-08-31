<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  DEFAULT_MAP_CENTER,
  DEFAULT_MAP_ZOOM,
  geoJsonToLatLngPaths,
  loadGoogleMaps,
  loteFillColor,
  pathsToPolygonGeoJson,
} from '@/modules/terrenos/geometria'
import {
  DEFAULT_ESTRUCTURA_ALPHA,
  DEFAULT_ESTRUCTURA_HEX,
  DEFAULT_ZONA_ALPHA,
  DEFAULT_ZONA_HEX,
  parseMapColor,
} from '@/utils/color'
import type { GeoJsonGeometry, MapLayerMode, MapToolMode } from '@/modules/terrenos/types'

export type MapFeature = {
  key: string
  kind: 'terreno' | 'zona' | 'lote' | 'estructura'
  id: number
  label: string
  geometria?: GeoJsonGeometry | null
  estado?: string
  color?: string | null
  parentId?: number | null
  logoUrl?: string | null
  clubName?: string | null
  clubInitials?: string | null
}

const props = defineProps<{
  features: MapFeature[]
  center?: { lat: number; lng: number } | null
  zoom?: number
  tool: MapToolMode
  layer: MapLayerMode
  imagenUrl?: string | null
  selectedKey?: string | null
  /** Solo el terreno puede editarse; null = sin edición de vértices */
  editableKey?: string | null
  canEdit?: boolean
  /** Vista de asignación: sin pan, restringida al terreno, solo zoom. */
  lockViewport?: boolean
  /** Clic derecho en lotes para acciones (asignar / estado). */
  enableLoteActionsMenu?: boolean
  /** Color del trazo al dibujar una estructura. */
  drawColor?: string | null
}>()

const emit = defineEmits<{
  ready: []
  select: [feature: MapFeature]
  drawn: [payload: { geometria: GeoJsonGeometry; path: Array<{ lat: number; lng: number }> }]
  edited: [payload: { feature: MapFeature; geometria: GeoJsonGeometry; path: Array<{ lat: number; lng: number }> }]
  removeFeature: [feature: MapFeature]
  loteContextMenu: [payload: { feature: MapFeature; x: number; y: number }]
  missingKey: []
}>()

const { t } = useI18n()
const mapEl = ref<HTMLElement | null>(null)
const errorMsg = ref('')
const drawHint = ref('')
const editMenu = ref<{
  x: number
  y: number
  mode: 'vertex' | 'feature'
  feature: MapFeature
  path?: google.maps.MVCArray<google.maps.LatLng>
  vertex?: number
} | null>(null)

let map: google.maps.Map | null = null
const polygons = new Map<string, google.maps.Polygon>()
const labelMarkers = new Map<string, google.maps.Marker>()
type ClubLogoOverlay = google.maps.OverlayView & {
  setPosition(position: { lat: number; lng: number }): void
  setContent(
    logoUrl: string | null | undefined,
    initials: string | null | undefined,
    label: string,
    clubName?: string | null,
  ): void
}
const clubLogoOverlays = new Map<string, ClubLogoOverlay>()
const pathListeners = new Map<string, google.maps.MapsEventListener[]>()
const polyEditListeners = new Map<string, google.maps.MapsEventListener[]>()
const loteActionListeners = new Map<string, google.maps.MapsEventListener[]>()
let imageOverlay: HTMLImageElement | null = null
let hasFittedOnce = false
let viewportLockedOnce = false
let editEmitTimer: ReturnType<typeof setTimeout> | null = null
let menuCloseListener: ((e: MouseEvent) => void) | null = null
/** Evita emitir `edited` en cada vértice mientras se arrastra el polígono completo. */
let isDraggingShape = false

let drawVertices: Array<{ lat: number; lng: number }> = []
let previewLine: google.maps.Polyline | null = null
let previewPolygon: google.maps.Polygon | null = null
let vertexMarkers: google.maps.Marker[] = []
let mapClickListener: google.maps.MapsEventListener | null = null
let mapDblClickListener: google.maps.MapsEventListener | null = null
let mapMoveListener: google.maps.MapsEventListener | null = null
let drawingActive = false

/** Herramientas que dibujan polígono nuevo (no editan el terreno). */
const DRAW_CREATE_TOOLS: MapToolMode[] = ['draw_zona', 'draw_lote', 'draw_estructura']

async function init(): Promise<void> {
  const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY
  if (!key) {
    errorMsg.value = t('terrenos.missingMapsKey')
    emit('missingKey')
    return
  }

  try {
    const maps = await loadGoogleMaps(key)
    if (!mapEl.value) return

    map = new maps.Map(mapEl.value, {
      center: props.center ?? DEFAULT_MAP_CENTER,
      zoom: props.zoom ?? DEFAULT_MAP_ZOOM,
      mapTypeId: props.layer === 'satellite' ? 'hybrid' : 'roadmap',
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      disableDoubleClickZoom: false,
      rotateControl: false,
      scaleControl: false,
    })

    syncPolygons()
    syncLayer()
    syncTool()
    if (!hasFittedOnce) {
      fitAll()
      hasFittedOnce = true
    }
    applyViewportLock()
    emit('ready')
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : t('terrenos.mapsLoadError')
  }
}

function activeDrawColor(): { hex: string; alpha: number } {
  return parseMapColor(props.drawColor, '#1565c0', 0.35)
}

function featureStyle(f: MapFeature, selected: boolean): Record<string, unknown> {
  if (f.kind === 'estructura') {
    const parsed = parseMapColor(f.color, DEFAULT_ESTRUCTURA_HEX, DEFAULT_ESTRUCTURA_ALPHA)
    return {
      fillColor: parsed.hex,
      fillOpacity: selected ? Math.min(1, parsed.alpha + 0.12) : parsed.alpha,
      strokeColor: selected ? '#000' : parsed.hex,
      strokeWeight: selected ? 3 : 1.5,
      zIndex: 4,
    }
  }

  if (f.kind === 'zona') {
    const parsed = parseMapColor(f.color, DEFAULT_ZONA_HEX, DEFAULT_ZONA_ALPHA)
    return {
      fillColor: parsed.hex,
      fillOpacity: selected ? Math.min(1, parsed.alpha + 0.12) : parsed.alpha,
      strokeColor: selected ? '#000' : parsed.hex,
      strokeWeight: selected ? 3 : 1.5,
      zIndex: 2,
    }
  }

  const base = f.kind === 'terreno' ? '#455a64' : loteFillColor(f.estado || 'disponible')

  return {
    fillColor: base,
    fillOpacity: selected ? 0.55 : f.kind === 'lote' ? 0.4 : 0.25,
    strokeColor: selected ? '#000' : base,
    strokeWeight: selected ? 3 : f.kind === 'terreno' ? 2 : 1.5,
    zIndex: f.kind === 'lote' ? 3 : 1,
  }
}

function clearLoteActionListeners(key: string): void {
  const list = loteActionListeners.get(key)
  if (!list) return
  for (const l of list) google.maps.event.removeListener(l)
  loteActionListeners.delete(key)
}

function clearPathListeners(key: string): void {
  const list = pathListeners.get(key)
  if (!list) return
  for (const l of list) google.maps.event.removeListener(l)
  pathListeners.delete(key)
}

function clearPolyEditListeners(key: string): void {
  const list = polyEditListeners.get(key)
  if (!list) return
  for (const l of list) google.maps.event.removeListener(l)
  polyEditListeners.delete(key)
}

function closeEditMenu(): void {
  editMenu.value = null
  if (menuCloseListener) {
    window.removeEventListener('mousedown', menuCloseListener, true)
    menuCloseListener = null
  }
}

function openEditMenu(menu: NonNullable<typeof editMenu.value>): void {
  closeEditMenu()
  editMenu.value = menu
  menuCloseListener = (e: MouseEvent) => {
    const target = e.target as HTMLElement | null
    if (target?.closest('.terreno-map-canvas__edit-menu')) return
    closeEditMenu()
  }
  window.addEventListener('mousedown', menuCloseListener, true)
}

function deleteVertexFromMenu(): void {
  const menu = editMenu.value
  if (!menu || menu.mode !== 'vertex' || menu.path == null || menu.vertex == null) {
    closeEditMenu()
    return
  }
  if (menu.path.getLength() <= 3) {
    closeEditMenu()
    return
  }
  menu.path.removeAt(menu.vertex)
  closeEditMenu()
}

function deleteFeatureFromMenu(): void {
  const menu = editMenu.value
  if (!menu || menu.mode !== 'feature') {
    closeEditMenu()
    return
  }
  const feature = menu.feature
  closeEditMenu()
  emit('removeFeature', feature)
}

function pathFromPolygon(poly: google.maps.Polygon): Array<{ lat: number; lng: number }> {
  const path = poly.getPath()
  const points: Array<{ lat: number; lng: number }> = []
  for (let i = 0; i < path.getLength(); i++) {
    const p = path.getAt(i)
    points.push({ lat: p.lat(), lng: p.lng() })
  }
  return points
}

function scheduleEdited(f: MapFeature, poly: google.maps.Polygon): void {
  if (editEmitTimer) clearTimeout(editEmitTimer)
  editEmitTimer = setTimeout(() => {
    const path = pathFromPolygon(poly)
    if (path.length < 3) return
    emit('edited', { feature: f, geometria: pathsToPolygonGeoJson(path), path })
  }, 400)
}

function applyEditableState(): void {
  const editableKey = props.canEdit && props.editableKey ? props.editableKey : null
  for (const [key, poly] of polygons) {
    const shouldEdit = key === editableKey
    poly.setEditable(shouldEdit)
    poly.setDraggable(shouldEdit)
    clearPathListeners(key)
    clearPolyEditListeners(key)
    if (!shouldEdit) continue
    const feature = props.features.find((f) => f.key === key)
    if (!feature) continue
    const path = poly.getPath()
    const listeners = [
      path.addListener('set_at', () => {
        if (isDraggingShape) return
        scheduleEdited(feature, poly)
      }),
      path.addListener('insert_at', () => {
        if (isDraggingShape) return
        scheduleEdited(feature, poly)
      }),
      path.addListener('remove_at', () => {
        if (isDraggingShape) return
        scheduleEdited(feature, poly)
      }),
    ]
    pathListeners.set(key, listeners)

    const editUiListeners: google.maps.MapsEventListener[] = [
      poly.addListener('dragstart', () => {
        isDraggingShape = true
        if (editEmitTimer) {
          clearTimeout(editEmitTimer)
          editEmitTimer = null
        }
      }),
      poly.addListener('drag', () => {
        const position = pathCentroid(pathFromPolygon(poly))
        labelMarkers.get(key)?.setPosition(position)
        clubLogoOverlays.get(key)?.setPosition(position)
      }),
      poly.addListener('dragend', () => {
        isDraggingShape = false
        scheduleEdited(feature, poly)
      }),
      poly.addListener('contextmenu', (e: google.maps.PolyMouseEvent) => {
        const dom = e.domEvent
        if (dom) {
          dom.preventDefault()
          dom.stopPropagation()
        }
        const rect = mapEl.value?.getBoundingClientRect()
        const clientX = dom && 'clientX' in dom ? Number((dom as MouseEvent).clientX) : 0
        const clientY = dom && 'clientY' in dom ? Number((dom as MouseEvent).clientY) : 0
        const x = rect ? clientX - rect.left : clientX
        const y = rect ? clientY - rect.top : clientY

        if (e.vertex != null && e.vertex !== undefined) {
          if (path.getLength() <= 3) return
          openEditMenu({
            x,
            y,
            mode: 'vertex',
            feature,
            path,
            vertex: e.vertex,
          })
          return
        }

        if (feature.kind === 'terreno') return
        openEditMenu({
          x,
          y,
          mode: 'feature',
          feature,
        })
      }),
    ]
    polyEditListeners.set(key, editUiListeners)
  }
  if (editableKey && !drawingActive) {
    drawHint.value = t('terrenos.editTerrenoHint')
  } else if (props.lockViewport && props.enableLoteActionsMenu && !drawingActive) {
    drawHint.value = t('terrenos.distribucionMapHint')
  } else if (!drawingActive) {
    drawHint.value = ''
  }
}

function pathCentroid(path: Array<{ lat: number; lng: number }>): { lat: number; lng: number } {
  const n = path.length || 1
  return {
    lat: path.reduce((s, p) => s + p.lat, 0) / n,
    lng: path.reduce((s, p) => s + p.lng, 0) / n,
  }
}

function clearLabelMarker(key: string): void {
  const marker = labelMarkers.get(key)
  if (!marker) return
  marker.setMap(null)
  labelMarkers.delete(key)
}

function clearClubLogoOverlay(key: string): void {
  const overlay = clubLogoOverlays.get(key)
  if (!overlay) return
  overlay.setMap(null)
  clubLogoOverlays.delete(key)
}

function createClubLogoOverlay(
  position: { lat: number; lng: number },
  logoUrl: string | null | undefined,
  initials: string | null | undefined,
  label: string,
  clubName?: string | null,
): ClubLogoOverlay {
  class LogoOverlay extends google.maps.OverlayView {
    private position = position
    private readonly root = document.createElement('div')
    private readonly avatar = document.createElement('span')
    private readonly image = document.createElement('img')
    private readonly initials = document.createElement('strong')
    private readonly text = document.createElement('span')

    constructor() {
      super()
      this.root.className = 'lote-club-logo-overlay'
      this.avatar.className = 'lote-club-logo-overlay__avatar'
      this.avatar.append(this.image, this.initials)
      this.root.append(this.avatar, this.text)
      this.setContent(logoUrl, initials, label, clubName)
    }

    onAdd(): void {
      this.getPanes().overlayMouseTarget.appendChild(this.root)
    }

    draw(): void {
      const pixel = this.getProjection().fromLatLngToDivPixel(
        new google.maps.LatLng(this.position.lat, this.position.lng),
      )
      if (!pixel) return
      this.root.style.left = `${pixel.x}px`
      this.root.style.top = `${pixel.y}px`
    }

    onRemove(): void {
      this.root.remove()
    }

    setPosition(nextPosition: { lat: number; lng: number }): void {
      this.position = nextPosition
      this.draw()
    }

    setContent(
      nextLogoUrl: string | null | undefined,
      nextInitials: string | null | undefined,
      nextLabel: string,
      nextClubName?: string | null,
    ): void {
      this.image.src = nextLogoUrl || ''
      this.image.alt = nextClubName || nextLabel
      this.image.hidden = !nextLogoUrl
      this.initials.textContent = nextInitials || 'CL'
      this.initials.hidden = Boolean(nextLogoUrl)
      this.text.textContent = nextLabel
      this.root.title = nextClubName || nextLabel
    }
  }

  const overlay = new LogoOverlay()
  overlay.setMap(map)
  return overlay
}

function loteMarkerIcon() {
  return {
    path: google.maps.SymbolPath.CIRCLE,
    scale: 0,
    fillOpacity: 0,
    strokeWeight: 0,
  }
}

function syncLabelMarkers(): void {
  if (!map) return
  const keep = new Set<string>()

  for (const f of props.features) {
    if (f.kind !== 'lote' || !f.label?.trim()) {
      clearLabelMarker(f.key)
      continue
    }
    const paths = geoJsonToLatLngPaths(f.geometria)
    if (!paths[0]?.length) {
      clearLabelMarker(f.key)
      continue
    }
    keep.add(f.key)
    const position = pathCentroid(paths[0])
    const selected = f.key === props.selectedKey
    if (f.logoUrl || f.clubInitials) {
      clearLabelMarker(f.key)
      let overlay = clubLogoOverlays.get(f.key)
      if (!overlay) {
        overlay = createClubLogoOverlay(
          position,
          f.logoUrl,
          f.clubInitials,
          f.label,
          f.clubName,
        )
        clubLogoOverlays.set(f.key, overlay)
      } else {
        overlay.setPosition(position)
        overlay.setContent(f.logoUrl, f.clubInitials, f.label, f.clubName)
      }
      continue
    }

    clearClubLogoOverlay(f.key)
    let marker = labelMarkers.get(f.key)
    const label: google.maps.MarkerLabel = {
      text: f.label,
      color: selected ? '#000000' : '#102027',
      fontSize: selected ? '13px' : '12px',
      fontWeight: '700',
      className: f.logoUrl ? 'lote-map-label has-club-logo' : 'lote-map-label',
    }
    if (!marker) {
      marker = new google.maps.Marker({
        map,
        position,
        clickable: false,
        zIndex: 20,
        label,
        icon: loteMarkerIcon(),
        title: f.clubName || f.label,
      })
      labelMarkers.set(f.key, marker)
    } else {
      marker.setPosition(position)
      marker.setLabel(label)
      marker.setIcon(loteMarkerIcon())
      marker.setTitle(f.clubName || f.label)
      marker.setMap(map)
    }
  }

  for (const key of [...labelMarkers.keys()]) {
    if (!keep.has(key)) clearLabelMarker(key)
  }
  for (const key of [...clubLogoOverlays.keys()]) {
    if (!keep.has(key)) clearClubLogoOverlay(key)
  }
}

function syncPolygons(): void {
  if (!map) return
  const keep = new Set(props.features.map((f) => f.key))

  for (const [key, poly] of polygons) {
    if (!keep.has(key)) {
      clearPathListeners(key)
      clearPolyEditListeners(key)
      clearLoteActionListeners(key)
      clearLabelMarker(key)
      clearClubLogoOverlay(key)
      google.maps.event.clearInstanceListeners(poly)
      poly.setMap(null)
      polygons.delete(key)
    }
  }

  for (const f of props.features) {
    const paths = geoJsonToLatLngPaths(f.geometria)
    if (!paths.length) continue

    let poly = polygons.get(f.key)
    if (!poly) {
      poly = new google.maps.Polygon({
        paths: paths[0],
        map,
        clickable: !drawingActive,
        editable: false,
        ...featureStyle(f, f.key === props.selectedKey),
      })
      poly.addListener('click', () => {
        if (!drawingActive) emit('select', f)
      })
      polygons.set(f.key, poly)
    } else {
      // No pisar paths mientras el usuario edita vértices
      const isEditing = props.editableKey === f.key && props.canEdit
      poly.setOptions({
        ...(isEditing ? {} : { paths: paths[0] }),
        clickable: !drawingActive,
        ...featureStyle(f, f.key === props.selectedKey),
      })
    }
  }

  syncLabelMarkers()
  applyEditableState()
  syncLoteActionMenus()
}

function computeTerrenoBounds(): google.maps.LatLngBounds | null {
  const bounds = new google.maps.LatLngBounds()
  let empty = true
  const terreno = props.features.find((f) => f.kind === 'terreno' && f.geometria)
  const source = terreno ? [terreno] : props.features
  for (const f of source) {
    for (const path of geoJsonToLatLngPaths(f.geometria)) {
      path.forEach((pt) => {
        bounds.extend(pt)
        empty = false
      })
    }
  }
  return empty ? null : bounds
}

/** Amplía el bounds para restriction/padding y que quepa el terreno completo en pantalla. */
function expandBounds(bounds: google.maps.LatLngBounds, factor = 0.4): google.maps.LatLngBounds {
  const ne = bounds.getNorthEast()
  const sw = bounds.getSouthWest()
  const latSpan = Math.max(ne.lat() - sw.lat(), 0.0008)
  const lngSpan = Math.max(ne.lng() - sw.lng(), 0.0008)
  const latPad = latSpan * factor
  const lngPad = lngSpan * factor
  return new google.maps.LatLngBounds(
    { lat: sw.lat() - latPad, lng: sw.lng() - lngPad },
    { lat: ne.lat() + latPad, lng: ne.lng() + lngPad },
  )
}

function applyViewportLock(): void {
  if (!map) return
  if (!props.lockViewport) {
    map.setOptions({
      draggable: true,
      gestureHandling: 'auto',
      keyboardShortcuts: true,
      zoomControl: true,
      scrollwheel: true,
      restriction: null,
      minZoom: null as unknown as undefined,
      maxZoom: null as unknown as undefined,
    })
    viewportLockedOnce = false
    return
  }

  const core = computeTerrenoBounds()
  if (!core) return
  // Margen moderado: encuadre ajustado al terreno sin recortar bordes.
  const padded = expandBounds(core, 0.35)

  map.setOptions({
    draggable: true,
    gestureHandling: 'greedy',
    keyboardShortcuts: false,
    scrollwheel: true,
    zoomControl: true,
    disableDoubleClickZoom: false,
    restriction: null,
    minZoom: null as unknown as undefined,
    maxZoom: 21,
  })

  // Forzar resize por si el contenedor cambió de tamaño (panel oculto/visible)
  google.maps.event.trigger(map, 'resize')
  map.fitBounds(core, 40)

  google.maps.event.addListenerOnce(map, 'idle', () => {
    if (!map || !props.lockViewport) return
    const z = map.getZoom()
    const fitted = z != null ? Math.floor(z) : 15
    map.setOptions({
      restriction: {
        latLngBounds: padded,
        strictBounds: false,
      },
      // Zoom inicial = terreno completo; se puede acercar, y alejar un nivel
      minZoom: Math.max(1, fitted - 1),
      maxZoom: 21,
      zoomControl: true,
      scrollwheel: true,
      gestureHandling: 'greedy',
    })
    // Reafirmar encuadre tras aplicar restriction
    map.fitBounds(core, 40)
    viewportLockedOnce = true
  })
}

function lockAndFit(): void {
  viewportLockedOnce = false
  applyViewportLock()
}

function syncLoteActionMenus(): void {
  for (const key of [...loteActionListeners.keys()]) clearLoteActionListeners(key)
  if (!props.enableLoteActionsMenu || drawingActive) return

  for (const f of props.features) {
    if (f.kind !== 'lote') continue
    const poly = polygons.get(f.key)
    if (!poly) continue
    const listener = poly.addListener('contextmenu', (e: google.maps.PolyMouseEvent) => {
      const dom = e.domEvent
      if (dom) {
        dom.preventDefault()
        dom.stopPropagation()
      }
      const rect = mapEl.value?.getBoundingClientRect()
      const clientX = dom && 'clientX' in dom ? Number((dom as MouseEvent).clientX) : 0
      const clientY = dom && 'clientY' in dom ? Number((dom as MouseEvent).clientY) : 0
      emit('loteContextMenu', {
        feature: f,
        x: rect ? clientX - rect.left : clientX,
        y: rect ? clientY - rect.top : clientY,
      })
      emit('select', f)
    })
    loteActionListeners.set(f.key, [listener])
  }
}

function clearDrawPreview(): void {
  previewLine?.setMap(null)
  previewLine = null
  previewPolygon?.setMap(null)
  previewPolygon = null
  for (const m of vertexMarkers) m.setMap(null)
  vertexMarkers = []
  drawVertices = []
}

function stopDrawingListeners(): void {
  if (mapClickListener) {
    google.maps.event.removeListener(mapClickListener)
    mapClickListener = null
  }
  if (mapDblClickListener) {
    google.maps.event.removeListener(mapDblClickListener)
    mapDblClickListener = null
  }
  if (mapMoveListener) {
    google.maps.event.removeListener(mapMoveListener)
    mapMoveListener = null
  }
}

function finishPolygon(): void {
  if (drawVertices.length < 3) {
    drawHint.value = t('terrenos.drawNeedVertices')
    return
  }
  const path = [...drawVertices]
  const geometria = pathsToPolygonGeoJson(path)
  clearDrawPreview()
  drawHint.value = ''
  emit('drawn', { geometria, path })
}

/** Radio de cierre en metros según zoom actual (~14 px en pantalla). */
function snapCloseRadiusM(lat: number): number {
  const zoom = map?.getZoom() ?? props.zoom ?? 16
  const mPerPx = (156543.03392 * Math.cos((lat * Math.PI) / 180)) / 2 ** zoom
  return Math.max(4, Math.min(25, mPerPx * 14))
}

function removeLastVertex(): void {
  if (!drawVertices.length) return
  drawVertices.pop()
  const marker = vertexMarkers.pop()
  marker?.setMap(null)
  previewLine?.setPath(drawVertices)
  if (previewPolygon) {
    if (drawVertices.length >= 3) {
      previewPolygon.setOptions({ paths: drawVertices })
    } else {
      previewPolygon.setMap(null)
      previewPolygon = null
    }
  }
}

function startDrawing(): void {
  if (!map) return
  stopDrawingListeners()
  clearDrawPreview()
  drawingActive = true
  drawHint.value = t('terrenos.drawHint')
  map.setOptions({
    draggableCursor: 'crosshair',
    disableDoubleClickZoom: true,
  })
  for (const poly of polygons.values()) {
    poly.setOptions({ clickable: false })
  }

  previewLine = new google.maps.Polyline({
    map,
    path: [],
    strokeColor: activeDrawColor().hex,
    strokeOpacity: 0.9,
    strokeWeight: 2,
    clickable: false,
  })

  mapClickListener = map.addListener('click', (e: unknown) => {
    const ev = e as { latLng?: { lat: () => number; lng: () => number } }
    if (!ev.latLng || !map) return
    const point = { lat: ev.latLng.lat(), lng: ev.latLng.lng() }

    // Cerrar solo si el clic está muy cerca del primero (radio en px → m del zoom actual)
    if (drawVertices.length >= 3) {
      const first = drawVertices[0]
      const dist = window.google?.maps?.geometry?.spherical?.computeDistanceBetween(
        new google.maps.LatLng(first.lat, first.lng),
        new google.maps.LatLng(point.lat, point.lng),
      )
      if (dist !== undefined && dist < snapCloseRadiusM(first.lat)) {
        finishPolygon()
        return
      }
    }

    drawVertices.push(point)
    vertexMarkers.push(
      new google.maps.Marker({
        map,
        position: point,
        clickable: false,
        icon: {
          path: 'M0,0 m-4,0 a4,4 0 1,0 8,0 a4,4 0 1,0 -8,0',
          scale: 1,
          fillColor: activeDrawColor().hex,
          fillOpacity: 1,
          strokeWeight: 1,
          strokeColor: '#ffffff',
        },
      }),
    )
    previewLine?.setPath(drawVertices)
    if (drawVertices.length >= 3) {
      if (!previewPolygon) {
        previewPolygon = new google.maps.Polygon({
          map,
          paths: drawVertices,
          fillColor: activeDrawColor().hex,
          fillOpacity: activeDrawColor().alpha,
          strokeWeight: 0,
          clickable: false,
        })
      } else {
        previewPolygon.setOptions({ paths: drawVertices })
      }
      drawHint.value = t('terrenos.drawHintClose')
    }
  })

  mapDblClickListener = map.addListener('dblclick', (e: unknown) => {
    const ev = e as { stop?: () => void; latLng?: { lat: () => number; lng: () => number } }
    ev.stop?.()
    // El segundo clic del doble clic ya añadió un vértice: quitarlo y cerrar
    removeLastVertex()
    finishPolygon()
  })

  mapMoveListener = map.addListener('mousemove', (e: unknown) => {
    if (!previewLine || drawVertices.length === 0) return
    const ev = e as { latLng?: { lat: () => number; lng: () => number } }
    if (!ev.latLng) return
    const ghost = { lat: ev.latLng.lat(), lng: ev.latLng.lng() }
    previewLine.setPath([...drawVertices, ghost])
  })
}

function stopDrawing(): void {
  drawingActive = false
  stopDrawingListeners()
  clearDrawPreview()
  drawHint.value = ''
  map?.setOptions({
    draggableCursor: null,
    disableDoubleClickZoom: false,
  })
  for (const poly of polygons.values()) {
    poly.setOptions({ clickable: true })
  }
}

function syncTool(): void {
  if (!map) return

  // Crear terreno: si ya hay geometría, editar vértices (no redibujar desde cero)
  if (props.tool === 'draw_terreno') {
    const terrenoFeature = props.features.find((f) => f.kind === 'terreno' && f.geometria)
    if (terrenoFeature) {
      stopDrawing()
      applyEditableState()
      return
    }
    startDrawing()
    return
  }

  // Zona / lote / estructura: solo dibujar, sin editar polígonos existentes
  if (DRAW_CREATE_TOOLS.includes(props.tool)) {
    startDrawing()
    applyEditableState()
    return
  }

  stopDrawing()
  applyEditableState()
}

function syncLayer(): void {
  if (!map) return
  map.setMapTypeId(props.layer === 'satellite' ? 'hybrid' : 'roadmap')

  const div = map.getDiv()
  if (imageOverlay) {
    imageOverlay.remove()
    imageOverlay = null
  }
  if (props.layer === 'imagen' && props.imagenUrl) {
    imageOverlay = document.createElement('img')
    imageOverlay.src = props.imagenUrl
    imageOverlay.alt = 'referencia'
    imageOverlay.className = 'terreno-map-canvas__image-overlay'
    div.appendChild(imageOverlay)
  }
}

function fitAll(): void {
  if (!map) return
  const bounds = new google.maps.LatLngBounds()
  let empty = true
  for (const f of props.features) {
    for (const path of geoJsonToLatLngPaths(f.geometria)) {
      path.forEach((pt) => {
        bounds.extend(pt)
        empty = false
      })
    }
  }
  if (!empty) map.fitBounds(bounds)
}

watch(() => props.features, () => {
  syncPolygons()
  // Solo ajustar vista la primera vez que hay geometría (carga inicial)
  if (!hasFittedOnce) {
    const hasGeo = props.features.some((f) => !!f.geometria)
    if (hasGeo) {
      fitAll()
      hasFittedOnce = true
      applyViewportLock()
    }
  } else if (props.lockViewport && !viewportLockedOnce) {
    applyViewportLock()
  }
}, { deep: true })
watch(() => props.selectedKey, () => syncPolygons())
watch(() => props.tool, () => syncTool())
watch(() => [props.editableKey, props.canEdit], () => applyEditableState())
watch(() => [props.lockViewport, props.enableLoteActionsMenu], () => {
  applyViewportLock()
  syncLoteActionMenus()
  applyEditableState()
})
watch(() => [props.layer, props.imagenUrl], () => syncLayer())
watch(() => props.drawColor, () => {
  const color = activeDrawColor()
  previewLine?.setOptions({ strokeColor: color.hex })
  previewPolygon?.setOptions({ fillColor: color.hex, fillOpacity: color.alpha })
  for (const marker of vertexMarkers) {
    const icon = marker.getIcon()
    if (icon && typeof icon === 'object' && 'fillColor' in icon) {
      marker.setIcon({ ...icon, fillColor: color.hex })
    }
  }
})
// No recentrar al cambiar lat/lng del terreno: conserva la ubicación actual del usuario

onMounted(() => void init())
onBeforeUnmount(() => {
  if (editEmitTimer) clearTimeout(editEmitTimer)
  closeEditMenu()
  stopDrawing()
  for (const key of [...pathListeners.keys()]) clearPathListeners(key)
  for (const key of [...polyEditListeners.keys()]) clearPolyEditListeners(key)
  for (const key of [...loteActionListeners.keys()]) clearLoteActionListeners(key)
  for (const key of [...labelMarkers.keys()]) clearLabelMarker(key)
  for (const key of [...clubLogoOverlays.keys()]) clearClubLogoOverlay(key)
  for (const poly of polygons.values()) {
    google.maps.event.clearInstanceListeners(poly)
    poly.setMap(null)
  }
  polygons.clear()
  imageOverlay?.remove()
})

defineExpose({ fitAll, lockAndFit })
</script>

<template>
  <div class="terreno-map-canvas">
    <div v-if="errorMsg" class="terreno-map-canvas__error">
      <i class="pi pi-exclamation-triangle" />
      <p>{{ errorMsg }}</p>
    </div>
    <div v-else-if="drawHint" class="terreno-map-canvas__hint">{{ drawHint }}</div>
    <div
      v-if="editMenu"
      class="terreno-map-canvas__edit-menu"
      :style="{ left: `${editMenu.x}px`, top: `${editMenu.y}px` }"
      @mousedown.stop
    >
      <button
        v-if="editMenu.mode === 'vertex'"
        type="button"
        @click="deleteVertexFromMenu"
      >
        {{ t('terrenos.deleteVertex') }}
      </button>
      <button
        v-else
        type="button"
        class="is-danger"
        @click="deleteFeatureFromMenu"
      >
        {{ t('terrenos.deletePolygon') }}
      </button>
    </div>
    <div ref="mapEl" class="terreno-map-canvas__map" />
  </div>
</template>

<style scoped>
.terreno-map-canvas {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 360px;
  border-radius: 12px;
  overflow: hidden;
  background: #e8eef2;
}

.terreno-map-canvas__map {
  width: 100%;
  height: 100%;
  min-height: 360px;
}

.terreno-map-canvas__hint {
  position: absolute;
  top: 0.75rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 2;
  max-width: min(480px, 90%);
  padding: 0.45rem 0.85rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--pj-surface, #fff) 92%, transparent);
  box-shadow: 0 2px 10px rgb(0 0 0 / 12%);
  font-size: 0.85rem;
  text-align: center;
  pointer-events: none;
}

.terreno-map-canvas__edit-menu {
  position: absolute;
  z-index: 5;
  min-width: 140px;
  padding: 0.25rem;
  border-radius: 8px;
  background: var(--pj-surface, #fff);
  box-shadow: 0 4px 16px rgb(0 0 0 / 18%);
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
}

.terreno-map-canvas__edit-menu button {
  display: block;
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.45rem 0.65rem;
  border-radius: 6px;
  cursor: pointer;
  font: inherit;
  color: var(--pj-text, #1a1a1a);
}

.terreno-map-canvas__edit-menu button:hover {
  background: color-mix(in srgb, var(--pj-border, #eee) 70%, transparent);
}

.terreno-map-canvas__edit-menu button.is-danger {
  color: #c62828;
}

.terreno-map-canvas__error {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: grid;
  place-content: center;
  gap: 0.5rem;
  padding: 1.5rem;
  text-align: center;
  background: color-mix(in srgb, var(--pj-surface, #fff) 92%, transparent);
  color: var(--pj-text, #1a1a1a);
}

.terreno-map-canvas__error i {
  font-size: 1.75rem;
  color: #c62828;
}

:global(.lote-map-label) {
  padding: 2px 5px;
  border-radius: 4px;
  background: color-mix(in srgb, #fff 78%, transparent);
  text-shadow: none;
}

:global(.lote-club-logo-overlay) {
  position: absolute;
  z-index: 20;
  display: flex;
  width: 46px;
  transform: translate(-50%, -50%);
  flex-direction: column;
  align-items: center;
  pointer-events: none;
}

:global(.lote-club-logo-overlay__avatar) {
  display: grid;
  width: 40px;
  height: 40px;
  box-sizing: border-box;
  overflow: hidden;
  place-items: center;
  border: 3px solid #fff;
  border-radius: 50%;
  background: #1d4ed8;
  color: #fff;
  box-shadow: 0 2px 7px rgb(0 0 0 / 35%);
}

:global(.lote-club-logo-overlay__avatar img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

:global(.lote-club-logo-overlay__avatar strong) {
  font-size: 13px;
  letter-spacing: 0.02em;
}

:global(.lote-club-logo-overlay > span:last-child) {
  max-width: 72px;
  padding: 1px 5px;
  margin-top: 2px;
  overflow: hidden;
  border-radius: 4px;
  background: rgb(255 255 255 / 88%);
  color: #102027;
  font-size: 11px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

:global(.terreno-map-canvas__image-overlay) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
  opacity: 0.55;
  z-index: 1;
}
</style>
