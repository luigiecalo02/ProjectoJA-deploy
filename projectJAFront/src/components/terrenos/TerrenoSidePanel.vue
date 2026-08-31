<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ColorAlphaPicker from '@/components/terrenos/ColorAlphaPicker.vue'
import {
  DEFAULT_ESTRUCTURA_ALPHA,
  DEFAULT_ESTRUCTURA_HEX,
  DEFAULT_ZONA_ALPHA,
  DEFAULT_ZONA_HEX,
  colorToCss,
} from '@/utils/color'
import type {
  ConfiguracionTerreno,
  EstructuraTerreno,
  LoteTerreno,
  Terreno,
  ZonaTerreno,
} from '@/modules/terrenos/types'

const props = withDefaults(
  defineProps<{
    mode?: 'plantilla' | 'config'
    terreno: Terreno | null
    config?: ConfiguracionTerreno | null
    configuraciones?: ConfiguracionTerreno[]
    selectedKind: 'terreno' | 'zona' | 'lote' | 'estructura' | null
    selectedZona: ZonaTerreno | null
    selectedLote: LoteTerreno | null
    selectedEstructura?: EstructuraTerreno | null
    canEdit: boolean
    saving?: boolean
  }>(),
  {
    mode: 'plantilla',
    config: null,
    configuraciones: () => [],
    selectedEstructura: null,
    saving: false,
  },
)

const emit = defineEmits<{
  'update:terreno': [value: Partial<Terreno>]
  'update:zona': [value: Partial<ZonaTerreno>]
  'update:lote': [value: Partial<LoteTerreno>]
  'update:estructura': [value: Partial<EstructuraTerreno>]
  'update:config': [value: Partial<ConfiguracionTerreno>]
  save: []
  remove: []
  upload: [file: File]
  selectZona: [zona: ZonaTerreno]
  selectLote: [lote: LoteTerreno]
  selectEstructura: [estructura: EstructuraTerreno]
  openConfig: [config: ConfiguracionTerreno]
  createConfig: []
  duplicateConfig: [config: ConfiguracionTerreno]
}>()

const { t } = useI18n()

const isPlantilla = computed(() => props.mode === 'plantilla')
const isConfig = computed(() => props.mode === 'config')

const zonas = computed(() =>
  isConfig.value ? props.config?.zonas || [] : [],
)

const lotesDirectos = computed(() =>
  isConfig.value ? props.config?.lotes || [] : [],
)

const title = computed(() => {
  if (props.selectedKind === 'lote' && props.selectedLote) {
    return props.selectedLote.codigo || props.selectedLote.nombre || t('terrenos.lote')
  }
  if (props.selectedKind === 'estructura' && props.selectedEstructura) {
    return props.selectedEstructura.nombre
  }
  if (props.selectedKind === 'zona' && props.selectedZona) {
    return props.selectedZona.nombre
  }
  if (isConfig.value && props.config) {
    return props.config.nombre
  }
  return props.terreno?.nombre || t('terrenos.terreno')
})

const tipoCapacidadOptions = [
  { label: t('terrenos.capacidadCalculada'), value: 'calculada' },
  { label: t('terrenos.capacidadManual'), value: 'manual' },
]

const tipoEstructuraOptions = computed(() =>
  ['general', 'banos', 'comedor', 'cocina', 'estacionamiento', 'escenario', 'enfermeria', 'almacen', 'otro'].map(
    (value) => ({ label: t(`terrenos.tipo.${value}`), value }),
  ),
)

function onFile(e: Event): void {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) emit('upload', file)
  input.value = ''
}

const showTerrenoForm = computed(
  () => isPlantilla.value && (props.selectedKind === 'terreno' || !props.selectedKind),
)

const showConfigOverview = computed(
  () =>
    isConfig.value &&
    (props.selectedKind === 'terreno' || !props.selectedKind || props.selectedKind === 'estructura'),
)
</script>

<template>
  <aside class="terreno-side-panel">
    <header class="terreno-side-panel__head">
      <h2 class="pj-display">{{ title }}</h2>
      <p v-if="selectedKind" class="muted">{{ t(`terrenos.kind.${selectedKind}`) }}</p>
      <p v-else-if="isConfig" class="muted">{{ t('terrenos.configuracion') }}</p>
    </header>

    <div v-if="!terreno && !config" class="muted">{{ t('terrenos.loading') }}</div>

    <template v-else>
      <p v-if="isPlantilla && showTerrenoForm" class="hint">{{ t('terrenos.plantillaHint') }}</p>

      <div v-if="showTerrenoForm && terreno" class="form-stack">
        <label>
          <span>{{ t('terrenos.nombre') }}</span>
          <InputText
            :model-value="terreno.nombre"
            :disabled="!canEdit"
            @update:model-value="emit('update:terreno', { nombre: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.descripcion') }}</span>
          <Textarea
            :model-value="terreno.descripcion ?? ''"
            :disabled="!canEdit"
            rows="3"
            @update:model-value="emit('update:terreno', { descripcion: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.metrosPorPersona') }}</span>
          <InputNumber
            :model-value="terreno.metros_por_persona"
            :disabled="!canEdit"
            :min-fraction-digits="0"
            :max-fraction-digits="2"
            @update:model-value="emit('update:terreno', { metros_por_persona: Number($event || 10) })"
          />
        </label>
        <div class="metrics">
          <span>{{ t('terrenos.area') }}: {{ terreno.area_total ?? '—' }} m²</span>
          <span>{{ t('terrenos.perimetro') }}: {{ terreno.perimetro ?? '—' }} m</span>
        </div>
        <label v-if="canEdit" class="file-btn">
          <span>{{ t('terrenos.uploadImagen') }}</span>
          <input type="file" accept="image/*" @change="onFile" />
        </label>
        <img
          v-if="terreno.imagen_referencia"
          :src="terreno.imagen_referencia"
          class="preview"
          alt=""
        />

        <div class="tree">
          <h3>{{ t('terrenos.estructuras') }}</h3>
          <p v-if="!(terreno.estructuras || []).length" class="muted">—</p>
          <button
            v-for="estructura in terreno.estructuras || []"
            :key="estructura.id"
            type="button"
            class="tree-item"
            @click="emit('selectEstructura', estructura)"
          >
            <span
              class="swatch"
              :style="{ background: colorToCss(estructura.color, DEFAULT_ESTRUCTURA_HEX, DEFAULT_ESTRUCTURA_ALPHA) }"
            />
            <span class="tree-item__text">
              <strong>{{ estructura.nombre }}</strong>
              <small>{{ t(`terrenos.tipo.${estructura.tipo}`) }}</small>
            </span>
          </button>
        </div>

        <div class="tree">
          <div class="tree-head">
            <h3>{{ t('terrenos.configuraciones') }}</h3>
            <Button
              v-if="canEdit"
              :label="t('terrenos.createConfig')"
              icon="pi pi-plus"
              size="small"
              text
              @click="emit('createConfig')"
            />
          </div>
          <p v-if="!configuraciones.length" class="muted">—</p>
          <div v-for="cfg in configuraciones" :key="cfg.id" class="config-row">
            <button type="button" class="tree-item config-row__main" @click="emit('openConfig', cfg)">
              <span>
                <strong>{{ cfg.nombre }}</strong>
                <Tag v-if="cfg.es_default" :value="t('terrenos.configDefault')" severity="info" class="tag-inline" />
              </span>
              <small>
                {{ cfg.zonas_count ?? (cfg.zonas || []).length }} {{ t('terrenos.zonas') }} ·
                {{ cfg.lotes_count ?? (cfg.lotes || []).length }} {{ t('terrenos.lotes') }}
              </small>
            </button>
            <div class="config-row__actions">
              <Button
                :label="t('terrenos.openConfig')"
                icon="pi pi-map"
                size="small"
                text
                @click="emit('openConfig', cfg)"
              />
              <Button
                v-if="canEdit"
                :label="t('terrenos.duplicateConfig')"
                icon="pi pi-copy"
                size="small"
                text
                @click="emit('duplicateConfig', cfg)"
              />
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="showConfigOverview" class="form-stack">
        <p class="hint">{{ t('terrenos.configMapTitle') }}</p>
        <label v-if="config">
          <span>{{ t('terrenos.nombre') }}</span>
          <InputText
            :model-value="config.nombre"
            :disabled="!canEdit"
            @update:model-value="emit('update:config', { nombre: String($event) })"
          />
        </label>
        <label v-if="config">
          <span>{{ t('terrenos.descripcion') }}</span>
          <Textarea
            :model-value="config.descripcion ?? ''"
            :disabled="!canEdit"
            rows="2"
            @update:model-value="emit('update:config', { descripcion: String($event) })"
          />
        </label>
        <div v-if="terreno" class="metrics">
          <span>{{ t('terrenos.terreno') }}: {{ terreno.nombre }}</span>
        </div>
        <div class="tree">
          <h3>{{ t('terrenos.zonas') }}</h3>
          <p v-if="!zonas.length" class="muted">—</p>
          <button
            v-for="zona in zonas"
            :key="zona.id"
            type="button"
            class="tree-item"
            @click="emit('selectZona', zona)"
          >
            <span
              class="swatch"
              :style="{ background: colorToCss(zona.color, DEFAULT_ZONA_HEX, DEFAULT_ZONA_ALPHA) }"
            />
            <span class="tree-item__text">
              <strong>{{ zona.nombre }}</strong>
              <small>{{ (zona.lotes || []).length }} {{ t('terrenos.lotes') }}</small>
            </span>
          </button>
        </div>
        <div v-if="lotesDirectos.length" class="tree">
          <h3>{{ t('terrenos.lotesDirectos') }}</h3>
          <button
            v-for="lote in lotesDirectos"
            :key="lote.id"
            type="button"
            class="tree-item"
            @click="emit('selectLote', lote)"
          >
            <strong>{{ lote.codigo }}</strong>
            <small>{{ lote.capacidad_maxima ?? '—' }} pax</small>
          </button>
        </div>
      </div>

      <div v-else-if="selectedKind === 'zona' && selectedZona" class="form-stack">
        <label>
          <span>{{ t('terrenos.nombre') }}</span>
          <InputText
            :model-value="selectedZona.nombre"
            :disabled="!canEdit"
            @update:model-value="emit('update:zona', { nombre: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.descripcion') }}</span>
          <Textarea
            :model-value="selectedZona.descripcion ?? ''"
            :disabled="!canEdit"
            rows="3"
            @update:model-value="emit('update:zona', { descripcion: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.color') }}</span>
          <ColorAlphaPicker
            :model-value="selectedZona.color"
            :disabled="!canEdit"
            :default-hex="DEFAULT_ZONA_HEX"
            :default-alpha="DEFAULT_ZONA_ALPHA"
            @update:model-value="emit('update:zona', { color: $event })"
          />
        </label>
        <div class="metrics">
          <span>{{ t('terrenos.area') }}: {{ selectedZona.area ?? '—' }} m²</span>
        </div>
        <div class="tree">
          <h3>{{ t('terrenos.lotes') }}</h3>
          <button
            v-for="lote in selectedZona.lotes || []"
            :key="lote.id"
            type="button"
            class="tree-item"
            @click="emit('selectLote', lote)"
          >
            <strong>{{ lote.codigo }}</strong>
            <small>{{ lote.capacidad_maxima ?? '—' }} pax</small>
          </button>
        </div>
      </div>

      <div v-else-if="selectedKind === 'estructura' && selectedEstructura && isPlantilla" class="form-stack">
        <label>
          <span>{{ t('terrenos.nombre') }}</span>
          <InputText
            :model-value="selectedEstructura.nombre"
            :disabled="!canEdit"
            @update:model-value="emit('update:estructura', { nombre: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.tipoEstructura') }}</span>
          <Select
            :model-value="selectedEstructura.tipo"
            :options="tipoEstructuraOptions"
            option-label="label"
            option-value="value"
            :disabled="!canEdit"
            @update:model-value="emit('update:estructura', { tipo: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.descripcion') }}</span>
          <Textarea
            :model-value="selectedEstructura.descripcion ?? ''"
            :disabled="!canEdit"
            rows="3"
            @update:model-value="emit('update:estructura', { descripcion: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.color') }}</span>
          <ColorAlphaPicker
            :model-value="selectedEstructura.color"
            :disabled="!canEdit"
            :default-hex="DEFAULT_ESTRUCTURA_HEX"
            :default-alpha="DEFAULT_ESTRUCTURA_ALPHA"
            @update:model-value="emit('update:estructura', { color: $event })"
          />
        </label>
        <div class="metrics">
          <span>{{ t('terrenos.area') }}: {{ selectedEstructura.area ?? '—' }} m²</span>
          <span class="hint-muted">{{ t('terrenos.kind.estructura') }} · sin lotes</span>
        </div>
      </div>

      <div v-else-if="selectedKind === 'lote' && selectedLote" class="form-stack">
        <label>
          <span>{{ t('terrenos.codigo') }}</span>
          <InputText
            :model-value="selectedLote.codigo"
            :disabled="!canEdit"
            @update:model-value="emit('update:lote', { codigo: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.nombre') }}</span>
          <InputText
            :model-value="selectedLote.nombre ?? ''"
            :disabled="!canEdit"
            @update:model-value="emit('update:lote', { nombre: String($event) })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.tipoCapacidad') }}</span>
          <Select
            :model-value="selectedLote.tipo_capacidad"
            :options="tipoCapacidadOptions"
            option-label="label"
            option-value="value"
            :disabled="!canEdit"
            @update:model-value="emit('update:lote', { tipo_capacidad: $event as 'calculada' | 'manual' })"
          />
        </label>
        <label>
          <span>{{ t('terrenos.capacidadMaxima') }}</span>
          <InputNumber
            :model-value="selectedLote.capacidad_maxima ?? undefined"
            :disabled="!canEdit || selectedLote.tipo_capacidad === 'calculada'"
            @update:model-value="emit('update:lote', { capacidad_maxima: Number($event || 0) })"
          />
        </label>
        <div class="metrics">
          <span>{{ t('terrenos.area') }}: {{ selectedLote.area ?? '—' }} m²</span>
          <span>{{ t('terrenos.capacidadCalculada') }}: {{ selectedLote.capacidad_calculada ?? '—' }}</span>
        </div>
      </div>

      <footer v-if="canEdit && (isPlantilla || isConfig)" class="actions">
        <Button
          :label="t('common.save')"
          icon="pi pi-check"
          :loading="saving"
          @click="emit('save')"
        />
        <Button
          v-if="selectedKind === 'zona' || selectedKind === 'lote' || (isPlantilla && selectedKind === 'estructura')"
          :label="t('common.delete')"
          severity="danger"
          text
          icon="pi pi-trash"
          @click="emit('remove')"
        />
      </footer>
    </template>
  </aside>
</template>

<style scoped>
.terreno-side-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  height: 100%;
  padding: 1rem;
  overflow: auto;
}

.terreno-side-panel__head h2 {
  margin: 0;
  font-size: 1.25rem;
}

.muted {
  color: var(--pj-muted, #667);
  margin: 0;
}

.hint {
  margin: 0;
  font-size: 0.85rem;
  color: var(--pj-muted, #667);
  line-height: 1.4;
}

.hint-muted {
  color: var(--pj-muted, #667);
  font-size: 0.8rem;
}

.form-stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.form-stack label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.85rem;
}

.metrics {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
  color: var(--pj-muted, #667);
}

.tree {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.tree-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
}

.tree h3 {
  margin: 0.5rem 0 0;
  font-size: 0.95rem;
}

.tree-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  text-align: left;
}
.tree-item__text {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}
.swatch {
  flex: 0 0 0.9rem;
  width: 0.9rem;
  height: 0.9rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, #000 18%, transparent);
  background:
    linear-gradient(45deg, #ccc 25%, transparent 25%) 0 0 / 6px 6px,
    #fff;
}

.tree-item:hover {
  background: color-mix(in srgb, var(--pj-accent, #0d47a1) 8%, transparent);
}

.config-row {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.config-row__main {
  flex-direction: column;
  align-items: flex-start;
}

.config-row__main span {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.config-row__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
}

.tag-inline {
  font-size: 0.7rem;
}

.preview {
  width: 100%;
  max-height: 140px;
  object-fit: cover;
  border-radius: 8px;
}

.file-btn input {
  font-size: 0.8rem;
}

.actions {
  display: flex;
  gap: 0.5rem;
  margin-top: auto;
  padding-top: 0.5rem;
}
</style>
