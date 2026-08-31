<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM, loadGoogleMaps } from '@/modules/terrenos/geometria'

const props = defineProps<{
  latitud?: number | null
  longitud?: number | null
  zoom?: number | null
  disabled?: boolean
}>()

const emit = defineEmits<{
  change: [payload: { latitud: number; longitud: number; zoom: number }]
}>()

const { t } = useI18n()
const mapEl = ref<HTMLElement | null>(null)
const errorMsg = ref('')

let map: google.maps.Map | null = null
let marker: google.maps.Marker | null = null
let listeners: google.maps.MapsEventListener[] = []

function currentCenter(): { lat: number; lng: number } {
  if (props.latitud != null && props.longitud != null) {
    return { lat: props.latitud, lng: props.longitud }
  }
  return DEFAULT_MAP_CENTER
}

function emitFromMap(): void {
  if (!map || !marker) return
  const pos = marker.getPosition()
  if (!pos) return
  emit('change', {
    latitud: pos.lat(),
    longitud: pos.lng(),
    zoom: map.getZoom() ?? props.zoom ?? DEFAULT_MAP_ZOOM,
  })
}

function placeMarker(lat: number, lng: number): void {
  if (!map) return
  const position = { lat, lng }
  if (marker) {
    marker.setPosition(position)
  } else {
    marker = new google.maps.Marker({
      map,
      position,
      draggable: !props.disabled,
    })
    if (!props.disabled) {
      listeners.push(marker.addListener('dragend', () => emitFromMap()))
    }
  }
  map.panTo(position)
}

async function init(): Promise<void> {
  const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY
  if (!key) {
    errorMsg.value = t('terrenos.missingMapsKey')
    return
  }
  if (!mapEl.value) return
  try {
    await loadGoogleMaps(key)
    const center = currentCenter()
    map = new google.maps.Map(mapEl.value, {
      center,
      zoom: props.zoom ?? DEFAULT_MAP_ZOOM,
      mapTypeControl: true,
      streetViewControl: false,
      fullscreenControl: false,
    })
    placeMarker(center.lat, center.lng)
    if (!props.disabled) {
      listeners.push(
        map.addListener('click', (event: google.maps.MapMouseEvent) => {
          const loc = event.latLng
          if (!loc) return
          placeMarker(loc.lat(), loc.lng())
          emitFromMap()
        }),
      )
      listeners.push(map.addListener('zoom_changed', () => emitFromMap()))
    }
  } catch (error) {
    errorMsg.value = error instanceof Error ? error.message : t('common.error')
  }
}

watch(
  () => [props.latitud, props.longitud, props.zoom] as const,
  ([lat, lng, zoom]) => {
    if (!map || lat == null || lng == null) return
    placeMarker(lat, lng)
    if (zoom != null) map.setZoom(zoom)
  },
)

onMounted(() => void init())
onBeforeUnmount(() => {
  for (const listener of listeners) google.maps.event.removeListener(listener)
  marker?.setMap(null)
  map = null
})
</script>

<template>
  <div class="lugar-map">
    <p v-if="errorMsg" class="lugar-map__error">{{ errorMsg }}</p>
    <div ref="mapEl" class="lugar-map__canvas" />
    <p class="lugar-map__hint">{{ t('lugares.mapHint') }}</p>
  </div>
</template>

<style scoped>
.lugar-map {
  display: grid;
  grid-template-rows: auto 1fr auto;
  gap: 0.5rem;
  min-height: 0;
  height: 100%;
}
.lugar-map__canvas {
  min-height: 560px;
  height: 100%;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--pj-border);
}
.lugar-map__error,
.lugar-map__hint {
  margin: 0;
  color: var(--pj-text-muted);
  font-size: 0.85rem;
}
.lugar-map__error {
  color: var(--p-red-500);
}
</style>
