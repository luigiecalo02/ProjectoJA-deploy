<script setup lang="ts">
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useI18n } from 'vue-i18n'
import OrgListTreeNodes from '@/components/organizaciones/OrgListTreeNodes.vue'
import type { OrganizacionTreeNode } from '@/modules/organizaciones/types'

const props = defineProps<{
  nodes: OrganizacionTreeNode[]
  expanded: Set<number>
  canEdit?: boolean
  canDelete?: boolean
  canCreate?: boolean
  depth?: number
}>()

const emit = defineEmits<{
  toggle: [id: number]
  edit: [id: number]
  add: [node: OrganizacionTreeNode]
  remove: [node: OrganizacionTreeNode]
  approve: [node: OrganizacionTreeNode]
  reject: [node: OrganizacionTreeNode]
  relocate: [node: OrganizacionTreeNode]
}>()

const { t } = useI18n()

function iconFor(node: OrganizacionTreeNode): string {
  const tipo = (node.tipo_nombre || '').toLowerCase()
  if (
    tipo.includes('club') ||
    tipo.includes('conquist') ||
    tipo.includes('aventur') ||
    tipo.includes('guía') ||
    tipo.includes('guia')
  ) {
    return 'pi pi-shield'
  }
  if (tipo.includes('iglesia')) return 'pi pi-home'
  if (tipo.includes('distrito')) return 'pi pi-map-marker'
  return 'pi pi-building'
}

function locationLabel(node: OrganizacionTreeNode): string {
  return [node.pais_nombre, node.departamento_nombre, node.ciudad_nombre].filter(Boolean).join(' · ')
}

function isExpanded(id: number): boolean {
  return props.expanded.has(id)
}
</script>

<template>
  <li v-for="node in nodes" :key="node.id" class="org-ltn">
    <div class="org-ltn__row" :style="{ paddingLeft: `${(depth ?? 0) * 1.1}rem` }">
      <button
        v-if="node.children?.length"
        type="button"
        class="org-ltn__toggle"
        :aria-expanded="isExpanded(node.id)"
        @click="emit('toggle', node.id)"
      >
        <i :class="isExpanded(node.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
      </button>
      <span v-else class="org-ltn__toggle org-ltn__toggle--spacer" />

      <i class="org-ltn__icon" :class="iconFor(node)" />

      <div class="org-ltn__main">
        <div class="org-ltn__title-line">
          <span class="org-ltn__name">{{ node.nombre }}</span>
          <Tag v-if="node.tipo_nombre" :value="node.tipo_nombre" severity="info" class="org-ltn__tag" />
          <Tag
            v-if="node.estado_aprobacion && node.estado_aprobacion !== 'aprobada'"
            :value="t(`organizaciones.aprobacion.${node.estado_aprobacion}`)"
            :severity="node.estado_aprobacion === 'pendiente' ? 'warn' : 'danger'"
            class="org-ltn__tag"
          />
          <Tag
            :value="node.estado ? t('common.active') : t('common.inactive')"
            :severity="node.estado ? 'success' : 'secondary'"
            class="org-ltn__tag"
          />
        </div>
        <div class="org-ltn__meta">
          <span v-if="node.codigo">{{ node.codigo }}</span>
          <span v-if="locationLabel(node)">{{ locationLabel(node) }}</span>
        </div>
      </div>

      <div class="org-ltn__actions">
        <Button
          v-if="canCreate"
          icon="pi pi-plus"
          text
          rounded
          size="small"
          severity="success"
          v-tooltip.top="t('organizaciones.addChild')"
          @click="emit('add', node)"
        />
        <Button
          v-if="canEdit && node.estado_aprobacion === 'pendiente'"
          icon="pi pi-check"
          text
          rounded
          size="small"
          severity="success"
          v-tooltip.top="t('organizaciones.approve')"
          @click="emit('approve', node)"
        />
        <Button
          v-if="canEdit && node.estado_aprobacion === 'pendiente'"
          icon="pi pi-directions"
          text
          rounded
          size="small"
          v-tooltip.top="t('organizaciones.relocate')"
          @click="emit('relocate', node)"
        />
        <Button
          v-if="canEdit && node.estado_aprobacion === 'pendiente'"
          icon="pi pi-times"
          text
          rounded
          size="small"
          severity="danger"
          v-tooltip.top="t('organizaciones.reject')"
          @click="emit('reject', node)"
        />
        <Button
          v-if="canEdit"
          icon="pi pi-pencil"
          text
          rounded
          size="small"
          @click="emit('edit', node.id)"
        />
        <Button
          v-if="canDelete"
          icon="pi pi-trash"
          text
          rounded
          size="small"
          severity="danger"
          @click="emit('remove', node)"
        />
      </div>
    </div>

    <ul v-if="node.children?.length && isExpanded(node.id)" class="org-ltn__children">
      <OrgListTreeNodes
        :nodes="node.children"
        :expanded="expanded"
        :can-edit="canEdit"
        :can-delete="canDelete"
        :can-create="canCreate"
        :depth="(depth ?? 0) + 1"
        @toggle="emit('toggle', $event)"
        @edit="emit('edit', $event)"
        @add="emit('add', $event)"
        @remove="emit('remove', $event)"
        @approve="emit('approve', $event)"
        @reject="emit('reject', $event)"
        @relocate="emit('relocate', $event)"
      />
    </ul>
  </li>
</template>

<style scoped>
.org-ltn {
  list-style: none;
  margin: 0;
}

.org-ltn__row {
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
  padding: 0.45rem 0.5rem;
  border-radius: 10px;
}

.org-ltn__row:hover {
  background: color-mix(in srgb, var(--pj-navy) 5%, transparent);
}

.org-ltn__toggle {
  width: 1.5rem;
  height: 1.5rem;
  border: 0;
  background: transparent;
  color: var(--pj-text-muted);
  display: grid;
  place-items: center;
  cursor: pointer;
  flex-shrink: 0;
  border-radius: 6px;
  margin-top: 0.1rem;
}

.org-ltn__toggle:hover {
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  color: var(--pj-navy);
}

.org-ltn__toggle--spacer {
  cursor: default;
  pointer-events: none;
}

.org-ltn__icon {
  color: color-mix(in srgb, var(--pj-navy) 75%, #64748b);
  margin-top: 0.25rem;
  flex-shrink: 0;
}

.org-ltn__main {
  flex: 1;
  min-width: 0;
}

.org-ltn__title-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}

.org-ltn__name {
  font-weight: 600;
  color: var(--pj-navy);
  font-size: 0.95rem;
}

.org-ltn__tag {
  font-size: 0.7rem;
}

.org-ltn__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  margin-top: 0.15rem;
  font-size: 0.8rem;
  color: var(--pj-text-muted);
}

.org-ltn__actions {
  display: flex;
  flex-shrink: 0;
}

.org-ltn__children {
  list-style: none;
  margin: 0;
  padding: 0 0 0 0.35rem;
  border-left: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  margin-left: 1.15rem;
}
</style>
