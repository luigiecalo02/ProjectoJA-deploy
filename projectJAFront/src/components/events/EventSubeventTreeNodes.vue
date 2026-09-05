<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import type { ClubEvent } from '@/modules/events/types'
import { cssColor } from '@/utils/color'
import { iconFontSize } from '@/utils/iconSize'

const props = withDefaults(
  defineProps<{
    nodes: ClubEvent[]
    depth?: number
    expanded: Set<number>
    selectedId?: number | null
    dragId?: number | null
    dropTarget?: { id: number; mode: 'before' | 'after' | 'into' } | null
    pulseId?: number | null
  }>(),
  {
    depth: 0,
    selectedId: null,
    dragId: null,
    dropTarget: null,
    pulseId: null,
  },
)

const emit = defineEmits<{
  toggle: [id: number]
  select: [item: ClubEvent]
  edit: [item: ClubEvent]
  remove: [item: ClubEvent]
  duplicate: [item: ClubEvent]
  enter: [item: ClubEvent]
  addChild: [item: ClubEvent]
  dragStart: [item: ClubEvent, event: DragEvent]
  dragOver: [item: ClubEvent, event: DragEvent]
  drop: [item: ClubEvent, event: DragEvent]
  dragEnd: []
}>()

const { t } = useI18n()

function childCount(node: ClubEvent): number {
  return node.hijos_count ?? node.hijos?.length ?? 0
}

function hasChildren(node: ClubEvent): boolean {
  return childCount(node) > 0
}

function iconFor(item: ClubEvent): string {
  return item.icono || item.categoria_subevento?.icono || item.tipo_evento?.icono || 'pi pi-sitemap'
}

function colorFor(item: ClubEvent): string | undefined {
  return cssColor(item.color || item.categoria_subevento?.color || item.tipo_evento?.color)
}

function iconSizeFor(item: ClubEvent): string {
  return iconFontSize(item.icono_tamano, 22)
}

function showsImage(item: ClubEvent): boolean {
  return Boolean(item.image_url) && !item.icono
}

function dropClassFor(itemId: number): string {
  if (props.dropTarget?.id !== itemId) return ''
  return `is-drop-${props.dropTarget.mode}`
}
</script>

<template>
  <TransitionGroup
    tag="ul"
    name="sub-tree-move"
    class="sub-tree"
    :class="`depth-${depth}`"
  >
    <li v-for="node in nodes" :key="node.id" class="sub-tree__node">
      <div
        class="sub-tree__row"
        :class="[
          {
            'is-selected': selectedId === node.id,
            'is-dragging': dragId === node.id,
            'is-pulse': pulseId === node.id,
          },
          dropClassFor(node.id),
        ]"
        draggable="true"
        @click="emit('select', node)"
        @dragstart="emit('dragStart', node, $event)"
        @dragend="emit('dragEnd')"
        @dragover="emit('dragOver', node, $event)"
        @drop="emit('drop', node, $event)"
      >
        <span class="sub-tree__orden">{{ node.orden ?? 0 }}</span>
        <button
          v-if="hasChildren(node)"
          type="button"
          class="sub-tree__toggle"
          :aria-expanded="expanded.has(node.id)"
          @click.stop="emit('toggle', node.id)"
        >
          <i :class="expanded.has(node.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
        </button>
        <span v-else class="sub-tree__spacer" />

        <span v-if="showsImage(node)" class="sub-tree__thumb">
          <img :src="node.image_url" :alt="node.name" />
        </span>
        <span
          v-else
          class="sub-tree__icon"
          :style="{ color: colorFor(node), fontSize: iconSizeFor(node) }"
        >
          <i :class="iconFor(node)" />
        </span>

        <div class="sub-tree__body">
          <strong>{{ node.name }}</strong>
          <small v-if="node.descripcion">{{ node.descripcion }}</small>
        </div>

        <span class="sub-tree__score">{{ Number(node.puntaje_maximo || 0) }} pts</span>

        <span
          class="sub-tree__status"
          :class="node.estado === 'publicado' ? 'is-active' : 'is-draft'"
        >
          {{
            node.estado === 'publicado'
              ? t('events.estadoPublicado')
              : t('events.estadoBorrador')
          }}
        </span>

        <div class="sub-tree__actions" @click.stop>
          <Button
            v-if="hasChildren(node)"
            type="button"
            :icon="expanded.has(node.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'"
            text
            rounded
            size="small"
            v-tooltip.top="t('events.wizard.subToggleChildren')"
            @click="emit('toggle', node.id)"
          />
          <Button
            type="button"
            icon="pi pi-plus"
            text
            rounded
            size="small"
            v-tooltip.top="t('events.wizard.subAddChild')"
            @click="emit('addChild', node)"
          />
          <Button
            type="button"
            icon="pi pi-sitemap"
            text
            rounded
            size="small"
            v-tooltip.top="t('events.wizard.subOpenChildren')"
            @click="emit('enter', node)"
          />
          <Button
            type="button"
            icon="pi pi-pencil"
            text
            rounded
            size="small"
            @click="emit('edit', node)"
          />
          <Button
            type="button"
            icon="pi pi-copy"
            text
            rounded
            size="small"
            v-tooltip.top="t('events.wizard.subDuplicate')"
            @click="emit('duplicate', node)"
          />
          <Button
            v-if="!hasChildren(node)"
            type="button"
            icon="pi pi-trash"
            text
            rounded
            size="small"
            severity="danger"
            @click="emit('remove', node)"
          />
        </div>
      </div>

      <EventSubeventTreeNodes
        v-if="hasChildren(node) && expanded.has(node.id) && node.hijos?.length"
        :nodes="node.hijos"
        :depth="depth + 1"
        :expanded="expanded"
        :selected-id="selectedId"
        :drag-id="dragId"
        :drop-target="dropTarget"
        :pulse-id="pulseId"
        @toggle="emit('toggle', $event)"
        @select="emit('select', $event)"
        @edit="emit('edit', $event)"
        @remove="emit('remove', $event)"
        @duplicate="emit('duplicate', $event)"
        @enter="emit('enter', $event)"
        @add-child="emit('addChild', $event)"
        @drag-start="(item, event) => emit('dragStart', item, event)"
        @drag-over="(item, event) => emit('dragOver', item, event)"
        @drop="(item, event) => emit('drop', item, event)"
        @drag-end="emit('dragEnd')"
      />
    </li>
  </TransitionGroup>
</template>

<style scoped>
.sub-tree {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.sub-tree.depth-0 {
  margin-top: 0.45rem;
  padding: 0.45rem;
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-bg) 90%, var(--pj-border));
  border: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
}

.sub-tree__node {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.sub-tree-move-move {
  transition: opacity 0.2s ease;
}

.sub-tree-move-enter-active,
.sub-tree-move-leave-active {
  transition: opacity 0.18s ease;
}

.sub-tree-move-enter-from,
.sub-tree-move-leave-to {
  opacity: 0;
}

.sub-tree__row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-height: 2.4rem;
  padding: 0.4rem 0.45rem;
  border-radius: 8px;
  background: #fff;
  border: 1px solid transparent;
  box-sizing: border-box;
  cursor: grab;
  transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.sub-tree__row:hover {
  border-color: color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.sub-tree__row.is-selected {
  border-color: color-mix(in srgb, var(--p-primary-color, #3b82f6) 55%, transparent);
  background: color-mix(in srgb, var(--p-primary-color, #3b82f6) 8%, #fff);
}

.sub-tree__row.is-dragging {
  opacity: 0.4;
}

.sub-tree__row.is-drop-before {
  box-shadow: inset 0 3px 0 0 #2563eb;
  background: color-mix(in srgb, #2563eb 5%, #fff);
}

.sub-tree__row.is-drop-after {
  box-shadow: inset 0 -3px 0 0 #2563eb;
  background: color-mix(in srgb, #2563eb 5%, #fff);
}

.sub-tree__row.is-drop-into {
  border-color: #2563eb;
  background: color-mix(in srgb, #2563eb 10%, #fff);
  box-shadow: inset 0 0 0 1px #2563eb;
}

.sub-tree__row.is-pulse {
  animation: sub-pulse 0.65s ease;
}

@keyframes sub-pulse {
  0% {
    background: color-mix(in srgb, #2563eb 18%, #fff);
  }
  100% {
    background: #fff;
  }
}

.sub-tree__toggle,
.sub-tree__spacer {
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
}

.sub-tree__toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--pj-text-muted);
  cursor: pointer;
}

.sub-tree__orden {
  min-width: 1.35rem;
  font-size: 0.75rem;
  font-weight: 800;
  color: var(--pj-text-muted);
  text-align: center;
  flex-shrink: 0;
}

.sub-tree__thumb,
.sub-tree__icon {
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 6px;
  overflow: hidden;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: color-mix(in srgb, var(--pj-border) 30%, transparent);
}

.sub-tree__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.sub-tree__body {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.sub-tree__body strong {
  font-size: 0.86rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sub-tree__body small {
  font-size: 0.72rem;
  color: var(--pj-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sub-tree__score {
  font-size: 0.75rem;
  color: var(--pj-text-muted);
  white-space: nowrap;
}

.sub-tree__status {
  font-size: 0.7rem;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  white-space: nowrap;
}

.sub-tree__status.is-active {
  background: color-mix(in srgb, #16a34a 15%, transparent);
  color: #15803d;
}

.sub-tree__status.is-draft {
  background: color-mix(in srgb, #ca8a04 15%, transparent);
  color: #a16207;
}

.sub-tree__actions {
  display: inline-flex;
  align-items: center;
  gap: 0.05rem;
  margin-left: auto;
}

@media (max-width: 900px) {
  .sub-tree__score,
  .sub-tree__status {
    display: none;
  }
}
</style>
