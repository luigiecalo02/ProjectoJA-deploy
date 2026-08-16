<script setup lang="ts">
import type { OrganizacionTreeNode } from '@/modules/organizaciones/types'

defineProps<{
  nodes: OrganizacionTreeNode[]
  selectedId: number | null
}>()

const emit = defineEmits<{
  select: [id: number]
}>()

function iconFor(node: OrganizacionTreeNode): string {
  const tipo = (node.tipo_nombre || '').toLowerCase()
  if (tipo.includes('club')) return 'pi pi-shield'
  return 'pi pi-building'
}
</script>

<template>
  <ul class="org-tree">
    <li v-for="node in nodes" :key="node.id" class="org-tree__node">
      <button
        type="button"
        class="org-tree__item"
        :class="{ 'org-tree__item--active': selectedId === node.id }"
        @click="emit('select', node.id)"
      >
        <i :class="iconFor(node)" />
        <span>{{ node.nombre }}</span>
      </button>
      <OrgTreeNodes
        v-if="node.children?.length"
        :nodes="node.children"
        :selected-id="selectedId"
        @select="emit('select', $event)"
      />
    </li>
  </ul>
</template>

<style scoped>
.org-tree {
  list-style: none;
  margin: 0;
  padding: 0 0 0 0.85rem;
}

.org-tree > .org-tree__node {
  padding-left: 0;
}

.org-tree__node {
  margin: 0.15rem 0;
}

.org-tree .org-tree {
  border-left: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  margin-left: 0.55rem;
  padding-left: 0.7rem;
}

.org-tree__item {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  border: 0;
  background: transparent;
  color: var(--pj-navy);
  font: inherit;
  font-size: 0.88rem;
  text-align: left;
  padding: 0.35rem 0.4rem;
  border-radius: 8px;
  cursor: pointer;
}

.org-tree__item i {
  color: color-mix(in srgb, var(--pj-navy) 70%, #64748b);
  font-size: 0.85rem;
}

.org-tree__item:hover {
  background: color-mix(in srgb, var(--pj-navy) 6%, transparent);
}

.org-tree__item--active {
  background: color-mix(in srgb, var(--pj-navy) 12%, transparent);
  font-weight: 600;
}
</style>
