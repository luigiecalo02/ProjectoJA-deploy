<script setup lang="ts">
import Checkbox from 'primevue/checkbox'
import type { Club } from '@/modules/clubs/types'
import type { OrganizacionTreeNode } from '@/modules/organizaciones/types'

defineOptions({ name: 'EventOrgTreeNode' })

const props = defineProps<{
  node: OrganizacionTreeNode
  depth: number
  expanded: Set<number>
  isChecked: (n: OrganizacionTreeNode) => boolean
  isIndeterminate: (n: OrganizacionTreeNode) => boolean
  iconForTipo: (tipoId: number) => string
  clubByOrgId: Map<number, Club>
}>()

const emit = defineEmits<{
  toggleExpand: [id: number]
  toggleCheck: [node: OrganizacionTreeNode, checked: boolean]
}>()

const hasChildren = !!props.node.children?.length
const open = () => props.expanded.has(props.node.id)
const ministry = () => props.clubByOrgId.get(props.node.id)?.tipos?.[0] || ''
</script>

<template>
  <li class="org-tree__item">
    <div class="org-tree__row" :style="{ paddingLeft: `${depth * 1.1}rem` }">
      <button
        v-if="hasChildren"
        type="button"
        class="org-tree__twist"
        @click="emit('toggleExpand', node.id)"
      >
        <i :class="open() ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
      </button>
      <span v-else class="org-tree__twist-spacer" />

      <Checkbox
        binary
        :model-value="isChecked(node)"
        :indeterminate="isIndeterminate(node)"
        :input-id="`org-check-${node.id}`"
        @update:model-value="(v) => emit('toggleCheck', node, !!v)"
      />

      <i :class="['org-tree__icon', iconForTipo(node.tipo_organizacion_id)]" />

      <div class="org-tree__text">
        <strong>{{ node.nombre }}</strong>
        <span class="org-tree__tipo">
          {{ node.tipo_nombre || '' }}{{ ministry() ? ` · ${ministry()}` : '' }}
        </span>
      </div>
    </div>

    <ul v-if="hasChildren && open()" class="org-tree">
      <EventOrgTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
        :expanded="expanded"
        :is-checked="isChecked"
        :is-indeterminate="isIndeterminate"
        :icon-for-tipo="iconForTipo"
        :club-by-org-id="clubByOrgId"
        @toggle-expand="(id) => emit('toggleExpand', id)"
        @toggle-check="(n, checked) => emit('toggleCheck', n, checked)"
      />
    </ul>
  </li>
</template>

<style scoped>
.org-tree {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.org-tree__row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.35rem 0.25rem;
  border-radius: 8px;
}

.org-tree__row:hover {
  background: color-mix(in srgb, var(--pj-navy) 4%, transparent);
}

.org-tree__twist,
.org-tree__twist-spacer {
  width: 1.25rem;
  height: 1.25rem;
  border: 0;
  background: transparent;
  display: grid;
  place-content: center;
  color: var(--pj-text-muted);
  cursor: pointer;
  flex-shrink: 0;
}

.org-tree__icon {
  color: var(--pj-primary, #2563eb);
  font-size: 0.85rem;
}

.org-tree__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.2;
}

.org-tree__text strong {
  font-size: 0.88rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.org-tree__tipo {
  font-size: 0.72rem;
  color: var(--pj-text-muted);
  text-transform: capitalize;
}
</style>
