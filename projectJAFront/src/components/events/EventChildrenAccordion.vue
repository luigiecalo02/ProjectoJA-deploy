<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ClubEvent } from '@/modules/events/types'

const props = withDefaults(
  defineProps<{
    nodes: ClubEvent[]
    depth?: number
    expanded: Set<number>
    mode?: 'judge' | 'director' | 'default'
    rootId: number
  }>(),
  { depth: 0, mode: 'default' },
)

const emit = defineEmits<{
  toggle: [id: number]
  open: [node: ClubEvent, rootId: number]
}>()

const { t } = useI18n()

const hasNodes = computed(() => props.nodes.length > 0)

function childCount(node: ClubEvent): number {
  return node.hijos_count ?? node.hijos?.length ?? 0
}

function hasChildren(node: ClubEvent): boolean {
  return childCount(node) > 0
}

function isClickable(node: ClubEvent): boolean {
  if (props.mode === 'judge') {
    return Boolean(node.asignado_a_mi && node.es_calificable && !node.puntaje_desde_hijos)
  }
  if (props.mode === 'director') {
    return Boolean(node.requiere_evidencia || node.es_calificable)
  }
  return false
}

function onOpen(node: ClubEvent): void {
  if (!isClickable(node)) return
  emit('open', node, props.rootId)
}
</script>

<template>
  <ul v-if="hasNodes" class="evt-tree" :class="[`depth-${depth}`, `mode-${mode}`]">
    <li v-for="node in nodes" :key="node.id" class="evt-tree__item">
      <button
        type="button"
        class="evt-tree__card"
        :class="{
          'is-open': hasChildren(node) && expanded.has(node.id),
          'is-clickable': isClickable(node),
          'is-assigned': node.asignado_a_mi,
          'has-evidence': node.evidencia_enviada === true,
          'no-evidence': node.evidencia_enviada === false,
        }"
        @click="isClickable(node) ? onOpen(node) : hasChildren(node) ? emit('toggle', node.id) : undefined"
      >
        <span
          v-if="hasChildren(node)"
          class="evt-tree__toggle"
          role="button"
          tabindex="0"
          @click.stop="emit('toggle', node.id)"
          @keydown.enter.stop.prevent="emit('toggle', node.id)"
          @keydown.space.stop.prevent="emit('toggle', node.id)"
        >
          <i :class="expanded.has(node.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
        </span>
        <span v-else class="evt-tree__spacer" />

        <span v-if="node.image_url" class="evt-tree__thumb">
          <img :src="node.image_url" :alt="node.name" />
        </span>
        <span
          v-else
          class="evt-tree__icon"
          :style="{ color: node.categoria_subevento?.color || node.tipo_evento?.color || undefined }"
        >
          <i :class="node.categoria_subevento?.icono || node.tipo_evento?.icono || 'pi pi-flag'" />
        </span>

        <div class="evt-tree__body">
          <strong>{{ node.name }}</strong>
          <small v-if="node.puntaje_maximo != null">{{ Number(node.puntaje_maximo) }} pts</small>
        </div>

        <div v-if="mode === 'judge' && node.progreso_juez" class="evt-tree__stats">
          <span class="stat is-ok">
            <i class="pi pi-check" />
            {{ node.progreso_juez.calificados }}
            {{ t('events.listScoredShort') }}
          </span>
          <span class="stat is-pending">
            <i class="pi pi-clock" />
            {{ node.progreso_juez.pendientes }}
            {{ t('events.listPendingShort') }}
          </span>
        </div>

        <div v-else-if="mode === 'director'" class="evt-tree__stats">
          <template v-if="node.evidencia_enviada === true">
            <span class="stat is-ok">
              <i class="pi pi-check-circle" />
              {{ t('events.listEvidenceSent') }}
            </span>
          </template>
          <template v-else-if="node.evidencia_enviada === false">
            <span class="stat is-pending">
              <i class="pi pi-upload" />
              {{ t('events.listEvidenceMissing') }}
            </span>
          </template>
          <template v-else-if="node.progreso_evidencia">
            <span class="stat is-ok">
              {{ node.progreso_evidencia.con_evidencia }} {{ t('events.listWithEvidenceShort') }}
            </span>
            <span class="stat is-pending">
              {{ node.progreso_evidencia.sin_evidencia }} {{ t('events.listWithoutEvidenceShort') }}
            </span>
          </template>
        </div>

        <i v-if="isClickable(node)" class="pi pi-arrow-right evt-tree__go" />
      </button>

      <div
        v-if="hasChildren(node) && expanded.has(node.id) && node.hijos?.length"
        class="evt-tree__children"
      >
        <EventChildrenAccordion
          :nodes="node.hijos"
          :depth="depth + 1"
          :expanded="expanded"
          :mode="mode"
          :root-id="rootId"
          @toggle="emit('toggle', $event)"
          @open="(n, r) => emit('open', n, r)"
        />
      </div>
    </li>
  </ul>
</template>

<style scoped>
.evt-tree {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.evt-tree__children {
  margin: 0.35rem 0 0.1rem 0.7rem;
  padding-left: 0.65rem;
  border-left: 2px solid color-mix(in srgb, #0f766e 22%, transparent);
  display: grid;
  gap: 0.4rem;
  animation: evt-open 160ms ease-out;
}

@keyframes evt-open {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.evt-tree__card {
  width: 100%;
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr) auto auto;
  gap: 0.55rem;
  align-items: center;
  text-align: left;
  padding: 0.55rem 0.65rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 60%, transparent);
  background: #fff;
  color: inherit;
  cursor: default;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    box-shadow 0.15s ease;
}

.evt-tree__card.is-clickable {
  cursor: pointer;
}

.evt-tree__card.is-clickable:hover,
.evt-tree__card.is-open {
  border-color: color-mix(in srgb, #0f766e 30%, transparent);
  background: color-mix(in srgb, #0f766e 5%, #fff);
}

.evt-tree__card.has-evidence {
  box-shadow: inset 3px 0 0 #16a34a;
}

.evt-tree__card.no-evidence {
  box-shadow: inset 3px 0 0 #ca8a04;
}

.evt-tree__card.is-assigned:not(.has-evidence):not(.no-evidence) {
  box-shadow: inset 3px 0 0 #0f766e;
}

.evt-tree__toggle,
.evt-tree__spacer {
  width: 1.35rem;
  height: 1.35rem;
  display: inline-grid;
  place-items: center;
}

.evt-tree__toggle {
  border-radius: 8px;
  color: #64748b;
  cursor: pointer;
}

.evt-tree__toggle:hover {
  background: color-mix(in srgb, #0f766e 12%, transparent);
  color: #0f766e;
}

.evt-tree__thumb,
.evt-tree__icon {
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 10px;
  overflow: hidden;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: color-mix(in srgb, #0f766e 10%, #f1f5f9);
  color: #0f766e;
}

.depth-0 .evt-tree__thumb,
.depth-0 .evt-tree__icon {
  width: 2.75rem;
  height: 2.75rem;
}

.evt-tree__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.evt-tree__body {
  min-width: 0;
  display: grid;
  gap: 0.1rem;
}

.evt-tree__body strong {
  font-size: 0.88rem;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.evt-tree__body small {
  font-size: 0.72rem;
  color: var(--pj-text-muted, #64748b);
}

.evt-tree__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  justify-content: flex-end;
}

.stat {
  display: inline-flex;
  align-items: center;
  gap: 0.22rem;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
  white-space: nowrap;
}

.stat.is-ok {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.stat.is-pending {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.evt-tree__go {
  color: #0f766e;
  font-size: 0.85rem;
}

@media (max-width: 700px) {
  .evt-tree__card {
    grid-template-columns: auto auto minmax(0, 1fr) auto;
  }

  .evt-tree__stats {
    grid-column: 3 / -1;
    justify-content: flex-start;
  }

  .evt-tree__go {
    display: none;
  }
}
</style>
