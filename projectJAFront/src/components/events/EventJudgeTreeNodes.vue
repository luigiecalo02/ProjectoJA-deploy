<script setup lang="ts">
import type { JudgeNodeStatus, JudgeTreeNode } from '@/modules/events/types'
import { cssColor } from '@/utils/color'

const props = withDefaults(
  defineProps<{
    nodes: JudgeTreeNode[]
    depth?: number
    expanded: Set<number>
    selectedId?: number | null
    pendingById: Record<number, number>
    statusById: Record<number, JudgeNodeStatus>
    pendingLabel: string
    scoredLabel: string
  }>(),
  {
    depth: 0,
    selectedId: null,
  },
)

const emit = defineEmits<{
  toggle: [id: number]
  select: [node: JudgeTreeNode]
}>()

function hasChildren(node: JudgeTreeNode): boolean {
  return (node.hijos?.length ?? 0) > 0
}

function iconFor(node: JudgeTreeNode): string {
  const icon = node.icono?.trim()
  if (!icon) return 'pi pi-flag'
  return icon.startsWith('pi ') ? icon : `pi ${icon}`
}
</script>

<template>
  <ul class="judge-tree" :class="[`depth-${depth}`, { 'is-nested': depth > 0 }]">
    <li
      v-for="node in nodes"
      :key="node.id"
      class="judge-tree__node"
      :class="{ 'is-open': hasChildren(node) && expanded.has(node.id) }"
    >
      <button
        type="button"
        class="judge-tree__row"
        :class="{
          'is-selected': selectedId === node.id,
          'is-parent-open': hasChildren(node) && expanded.has(node.id),
          [`is-${statusById[node.id] || 'neutral'}`]: true,
        }"
        @click="emit('select', node)"
      >
        <span
          v-if="hasChildren(node)"
          class="judge-tree__toggle"
          role="button"
          tabindex="0"
          :aria-expanded="expanded.has(node.id)"
          @click.stop="emit('toggle', node.id)"
          @keydown.enter.stop.prevent="emit('toggle', node.id)"
          @keydown.space.stop.prevent="emit('toggle', node.id)"
        >
          <i :class="expanded.has(node.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
        </span>

        <span
          class="judge-tree__thumb"
          :style="!node.image_url && node.color ? { color: cssColor(node.color) } : undefined"
        >
          <img v-if="node.image_url" :src="node.image_url" :alt="node.name" />
          <i v-else :class="iconFor(node)" />
        </span>

        <div class="judge-tree__body">
          <strong>{{ node.name }}</strong>
          <div v-if="depth === 0" class="judge-tree__meta">
            <span v-if="node.categoria" class="judge-tree__pill">{{ node.categoria }}</span>
            <span
              v-if="node.puede_calificar === false"
              class="judge-tree__pill judge-tree__pill--readonly"
            >
              Solo lectura
            </span>
            <span v-if="node.puntaje_maximo != null" class="judge-tree__pts">
              {{ node.puntaje_maximo }} pts
            </span>
          </div>
          <div v-else class="judge-tree__meta judge-tree__meta--simple">
            <span
              v-if="node.puede_calificar === false"
              class="judge-tree__pill judge-tree__pill--readonly"
            >
              Solo lectura
            </span>
            <span v-if="node.puntaje_maximo != null" class="judge-tree__pts">
              {{ node.puntaje_maximo }} pts
            </span>
          </div>
        </div>

        <span
          v-if="statusById[node.id] === 'evaluado'"
          class="judge-tree__status is-scored"
        >
          {{ scoredLabel }}
        </span>
        <span
          v-else-if="statusById[node.id] === 'pendiente'"
          class="judge-tree__status is-pending"
        >
          {{ pendingLabel }}
        </span>

        <span
          v-if="(pendingById[node.id] || 0) > 0"
          class="judge-tree__badge"
          :title="String(pendingById[node.id])"
        >
          {{ pendingById[node.id] > 99 ? '99+' : pendingById[node.id] }}
        </span>
      </button>

      <div
        v-if="hasChildren(node) && expanded.has(node.id) && node.hijos?.length"
        class="judge-tree__children"
      >
        <EventJudgeTreeNodes
          :nodes="node.hijos"
          :depth="depth + 1"
          :expanded="expanded"
          :selected-id="selectedId"
          :pending-by-id="pendingById"
          :status-by-id="statusById"
          :pending-label="pendingLabel"
          :scored-label="scoredLabel"
          @toggle="emit('toggle', $event)"
          @select="emit('select', $event)"
        />
      </div>
    </li>
  </ul>
</template>

<style scoped>
.judge-tree {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
}

.judge-tree.is-nested {
  gap: 0.28rem;
}

.judge-tree__node.is-open > .judge-tree__row.is-parent-open {
  border-color: color-mix(in srgb, #2563eb 28%, transparent);
  background: color-mix(in srgb, #2563eb 5%, #fff);
}

.judge-tree__children {
  margin: 0.3rem 0 0.15rem 0.55rem;
  padding: 0.35rem 0 0.15rem 0.55rem;
  border-left: 2px solid color-mix(in srgb, #2563eb 22%, transparent);
  display: grid;
  gap: 0.28rem;
  animation: judge-tree-open 160ms ease-out;
}

@keyframes judge-tree-open {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.judge-tree__row {
  position: relative;
  display: flex;
  gap: 0.45rem;
  align-items: center;
  width: 100%;
  text-align: left;
  padding: 0.5rem 0.55rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  background: #fff;
  cursor: pointer;
  color: inherit;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    box-shadow 0.15s ease;
}

.judge-tree.is-nested .judge-tree__row {
  padding: 0.42rem 0.5rem;
  border-radius: 10px;
  border-color: transparent;
  background: color-mix(in srgb, var(--pj-bg, #f8fafc) 70%, #fff);
}

.judge-tree__row:hover {
  border-color: color-mix(in srgb, #2563eb 35%, transparent);
  background: color-mix(in srgb, #2563eb 6%, #fff);
}

.judge-tree__row.is-selected {
  border-color: #2563eb;
  background: color-mix(in srgb, #2563eb 10%, #fff);
  box-shadow: inset 3px 0 0 #2563eb;
}

.judge-tree__toggle {
  width: 1.3rem;
  height: 1.3rem;
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
}

.judge-tree__toggle {
  border-radius: 7px;
  color: var(--pj-text-muted, #64748b);
}

.judge-tree__toggle:hover {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.judge-tree__thumb {
  width: 2.35rem;
  height: 2.35rem;
  border-radius: 9px;
  overflow: hidden;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: color-mix(in srgb, #2563eb 10%, #f1f5f9);
  color: #1d4ed8;
  font-size: 0.9rem;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--pj-border) 45%, transparent);
}

.judge-tree.is-nested .judge-tree__thumb {
  width: 2rem;
  height: 2rem;
  border-radius: 8px;
  font-size: 0.8rem;
}

.judge-tree__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.judge-tree__body {
  min-width: 0;
  flex: 1;
  display: grid;
  gap: 0.12rem;
}

.judge-tree__body strong {
  font-size: 0.8rem;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  letter-spacing: 0.01em;
}

.judge-tree.is-nested .judge-tree__body strong {
  font-size: 0.76rem;
  font-weight: 650;
  text-transform: none;
}

.judge-tree:not(.is-nested) .judge-tree__body strong {
  text-transform: uppercase;
}

.judge-tree__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.22rem;
  align-items: center;
}

.judge-tree__meta--simple {
  gap: 0.2rem;
}

.judge-tree__pill {
  font-size: 0.62rem;
  font-weight: 650;
  padding: 0.06rem 0.35rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  color: var(--pj-text-muted, #64748b);
  max-width: 7.5rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.judge-tree__pill--readonly {
  border-color: color-mix(in srgb, #94a3b8 50%, transparent);
  background: color-mix(in srgb, #94a3b8 12%, transparent);
  color: #64748b;
}

.judge-tree__pts {
  font-size: 0.66rem;
  font-weight: 700;
  color: var(--pj-text-muted, #64748b);
}

.judge-tree__status {
  justify-self: end;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  font-size: 0.64rem;
  font-weight: 700;
  white-space: nowrap;
}

.judge-tree__status.is-scored {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.judge-tree__status.is-pending {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.judge-tree__badge {
  min-width: 1.1rem;
  height: 1.1rem;
  padding: 0 0.22rem;
  border-radius: 3px;
  display: inline-grid;
  place-items: center;
  background: #facc15;
  color: #111827;
  font-size: 0.68rem;
  font-weight: 800;
  line-height: 1;
  box-shadow: 0 0 0 1px color-mix(in srgb, #ca8a04 35%, transparent);
}
</style>
