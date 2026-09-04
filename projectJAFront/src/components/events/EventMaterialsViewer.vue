<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { previewFromEvidenceUrl } from '@/modules/events/evidencePreview'
import type { EventoArchivoMaterial } from '@/modules/events/types'

const props = defineProps<{
  files?: EventoArchivoMaterial[]
}>()

const { t } = useI18n()

const items = computed(() => props.files ?? [])

function preview(item: EventoArchivoMaterial) {
  return item.url ? previewFromEvidenceUrl(item.url) : null
}

function icon(item: EventoArchivoMaterial): string {
  if (item.tipo === 'youtube') return 'pi pi-youtube'
  if (item.tipo === 'pdf') return 'pi pi-file-pdf'
  if (item.tipo === 'video') return 'pi pi-video'
  if (item.tipo === 'imagen') return 'pi pi-image'
  return 'pi pi-file'
}
</script>

<template>
  <section v-if="items.length" class="materials-view">
    <h3>{{ t('events.materials.title') }}</h3>
    <ul>
      <li v-for="item in items" :key="item.id">
        <i :class="icon(item)" />
        <div>
          <strong>{{ item.titulo || item.name || t('events.materials.untitled') }}</strong>
          <template v-if="preview(item)?.kind === 'youtube' && preview(item)?.embedSrc">
            <iframe
              :src="preview(item)!.embedSrc"
              title="YouTube"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen
            />
          </template>
          <a v-else-if="item.url" :href="item.url" target="_blank" rel="noopener">
            {{ t('events.materials.open') }}
          </a>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.materials-view { display: grid; gap: 0.6rem; }
.materials-view h3 { margin: 0; font-size: 1rem; }
.materials-view ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.55rem; }
.materials-view li {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.55rem;
  align-items: start;
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--pj-border);
  border-radius: 10px;
}
.materials-view iframe {
  width: 100%;
  aspect-ratio: 16 / 9;
  border: 0;
  border-radius: 8px;
  margin-top: 0.4rem;
}
</style>
