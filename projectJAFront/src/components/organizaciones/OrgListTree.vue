<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import OrgListTreeNodes from '@/components/organizaciones/OrgListTreeNodes.vue'
import type { OrganizacionTreeNode } from '@/modules/organizaciones/types'

const props = defineProps<{
  nodes: OrganizacionTreeNode[]
  canEdit?: boolean
  canDelete?: boolean
  canCreate?: boolean
  expandAll?: boolean
}>()

const emit = defineEmits<{
  edit: [id: number]
  add: [node: OrganizacionTreeNode]
  remove: [node: OrganizacionTreeNode]
  approve: [node: OrganizacionTreeNode]
  reject: [node: OrganizacionTreeNode]
  relocate: [node: OrganizacionTreeNode]
}>()

const { t } = useI18n()
const expanded = ref<Set<number>>(new Set())
const initialized = ref(false)

function collectExpandableIds(nodes: OrganizacionTreeNode[]): number[] {
  const ids: number[] = []
  for (const node of nodes) {
    if (node.children?.length) {
      ids.push(node.id)
      ids.push(...collectExpandableIds(node.children))
    }
  }
  return ids
}

function syncExpandedInitial(): void {
  if (props.expandAll) {
    expanded.value = new Set(collectExpandableIds(props.nodes))
    return
  }
  // Por defecto solo raíces con hijos.
  expanded.value = new Set(props.nodes.filter((n) => n.children?.length).map((n) => n.id))
}

/** Conserva nodos abiertos al actualizar el árbol (realtime / patch / filtros). */
function preserveExpandedOnNodesChange(): void {
  const expandable = new Set(collectExpandableIds(props.nodes))

  if (props.expandAll) {
    expanded.value = new Set(expandable)
    return
  }

  const next = new Set<number>()
  for (const id of expanded.value) {
    if (expandable.has(id)) next.add(id)
  }
  expanded.value = next
}

watch(
  () => props.nodes,
  () => {
    if (!initialized.value) {
      syncExpandedInitial()
      initialized.value = true
      return
    }
    preserveExpandedOnNodesChange()
  },
  { immediate: true, deep: true },
)

watch(
  () => props.expandAll,
  (value) => {
    if (!initialized.value) return
    if (value) {
      expanded.value = new Set(collectExpandableIds(props.nodes))
    }
  },
)

function toggle(id: number): void {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

function expandAllNodes(): void {
  expanded.value = new Set(collectExpandableIds(props.nodes))
}

function collapseAllNodes(): void {
  expanded.value = new Set()
}

const hasExpandable = computed(() => collectExpandableIds(props.nodes).length > 0)
</script>

<template>
  <div class="org-list-tree">
    <div v-if="hasExpandable" class="org-list-tree__toolbar">
      <Button
        type="button"
        :label="t('organizaciones.expandAll')"
        icon="pi pi-plus"
        text
        size="small"
        @click="expandAllNodes"
      />
      <Button
        type="button"
        :label="t('organizaciones.collapseAll')"
        icon="pi pi-minus"
        text
        size="small"
        @click="collapseAllNodes"
      />
    </div>

    <ul class="org-list-tree__root">
      <OrgListTreeNodes
        :nodes="nodes"
        :expanded="expanded"
        :can-edit="canEdit"
        :can-delete="canDelete"
        :can-create="canCreate"
        :depth="0"
        @toggle="toggle"
        @edit="emit('edit', $event)"
        @add="emit('add', $event)"
        @remove="emit('remove', $event)"
        @approve="emit('approve', $event)"
        @reject="emit('reject', $event)"
        @relocate="emit('relocate', $event)"
      />
    </ul>
  </div>
</template>

<style scoped>
.org-list-tree__toolbar {
  display: flex;
  gap: 0.25rem;
  margin-bottom: 0.55rem;
}

.org-list-tree__root {
  list-style: none;
  margin: 0;
  padding: 0;
}
</style>
