<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import EventOrgTreeNode from '@/components/events/EventOrgTreeNode.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import type { Club, ClubMinistry } from '@/modules/clubs/types'
import type { OrganizacionTreeNode } from '@/modules/organizaciones/types'
import {
  TIPO_ASOCIACION,
  TIPO_CLUB,
  TIPO_DISTRITO,
  TIPO_IGLESIA,
  TIPO_UNION,
  TIPOS_HIJO_CLUB,
} from '@/modules/organizaciones/types'

export type EventAudienceKey = 'libre' | 'conquistadores' | 'aventureros' | 'guias_mayores'
type SelectionMode = 'hierarchy' | 'manual'

const props = defineProps<{
  organizacionId: number | null
  organizacionIds: number[]
  orgOptions: Array<{ id: number; label: string }>
  orgTree: OrganizacionTreeNode[]
  clubs: Club[]
  audience: EventAudienceKey[]
  audienceLabel: string
  audienceOptions: Array<{ key: EventAudienceKey; label: string; css: string }>
}>()

const emit = defineEmits<{
  'update:organizacionId': [value: number | null]
  'update:organizacionIds': [value: number[]]
  toggleAudience: [key: EventAudienceKey]
}>()

const { t } = useI18n()

const selectionMode = ref<SelectionMode>('hierarchy')
const inheritance = ref(true)
const search = ref('')
const expanded = ref<Set<number>>(new Set())
const expandAllFlag = ref(false)

const isLibre = computed(
  () => props.audience.includes('libre') || props.audience.length === 0,
)

const clubByOrgId = computed(() => {
  const map = new Map<number, Club>()
  for (const club of props.clubs) {
    if (club.organizacion_id) map.set(club.organizacion_id, club)
  }
  return map
})

const allowedMinistries = computed((): ClubMinistry[] | null => {
  if (isLibre.value) return null
  return props.audience.filter(
    (k): k is ClubMinistry =>
      k === 'conquistadores' || k === 'aventureros' || k === 'guias_mayores',
  )
})

function isClubNode(node: OrganizacionTreeNode): boolean {
  return (
    node.tipo_organizacion_id === TIPO_CLUB ||
    (TIPOS_HIJO_CLUB as readonly number[]).includes(node.tipo_organizacion_id)
  )
}

function clubMatchesAudience(node: OrganizacionTreeNode): boolean {
  if (isLibre.value) return true
  const ministries = allowedMinistries.value
  if (!ministries?.length) return true

  const club = clubByOrgId.value.get(node.id)
  if (club?.tipos?.length) {
    return club.tipos.some((tipo) => ministries.includes(tipo))
  }

  const tipo = node.tipo_organizacion_id
  if (ministries.includes('conquistadores') && tipo === 7) return true
  if (ministries.includes('aventureros') && tipo === 6) return true
  if (ministries.includes('guias_mayores') && tipo === 8) return true
  return false
}

function findNode(nodes: OrganizacionTreeNode[], id: number): OrganizacionTreeNode | null {
  for (const node of nodes) {
    if (node.id === id) return node
    const found = findNode(node.children || [], id)
    if (found) return found
  }
  return null
}

function collectDescendantIds(node: OrganizacionTreeNode): number[] {
  const ids = [node.id]
  for (const child of node.children || []) {
    ids.push(...collectDescendantIds(child))
  }
  return ids
}

function pruneForAudience(nodes: OrganizacionTreeNode[]): OrganizacionTreeNode[] {
  if (isLibre.value) {
    return nodes.map((n) => ({
      ...n,
      children: pruneForAudience(n.children || []),
    }))
  }

  const result: OrganizacionTreeNode[] = []
  for (const node of nodes) {
    const children = pruneForAudience(node.children || [])
    if (isClubNode(node)) {
      if (clubMatchesAudience(node)) {
        result.push({ ...node, children })
      }
      continue
    }
    if (children.length) {
      result.push({ ...node, children })
    }
  }
  return result
}

function filterBySearch(nodes: OrganizacionTreeNode[], q: string): OrganizacionTreeNode[] {
  if (!q.trim()) return nodes
  const needle = q.trim().toLowerCase()
  const out: OrganizacionTreeNode[] = []
  for (const node of nodes) {
    const children = filterBySearch(node.children || [], needle)
    const selfMatch =
      node.nombre.toLowerCase().includes(needle) ||
      (node.tipo_nombre || '').toLowerCase().includes(needle) ||
      (node.codigo || '').toLowerCase().includes(needle)
    if (selfMatch || children.length) {
      out.push({
        ...node,
        children: selfMatch ? pruneForAudience(node.children || []) : children,
      })
    }
  }
  return out
}

const organizerSubtree = computed((): OrganizacionTreeNode[] => {
  if (!props.organizacionId) return []
  const root = findNode(props.orgTree, props.organizacionId)
  if (!root) return []
  return [{ ...root, children: [...(root.children || [])] }]
})

const visibleTree = computed(() => {
  const pruned = pruneForAudience(organizerSubtree.value)
  return filterBySearch(pruned, search.value)
})

const flatSelectable = computed(() => {
  const rows: Array<{ id: number; label: string }> = []
  const walk = (nodes: OrganizacionTreeNode[], depth: number) => {
    for (const node of nodes) {
      rows.push({
        id: node.id,
        label: `${'— '.repeat(depth)}${node.nombre}${node.tipo_nombre ? ` · ${node.tipo_nombre}` : ''}`,
      })
      if (node.children?.length) walk(node.children, depth + 1)
    }
  }
  walk(pruneForAudience(organizerSubtree.value), 0)
  return rows
})

const selectedSet = computed(() => new Set(props.organizacionIds))

const selectedChips = computed(() => {
  const byId = new Map(props.orgOptions.map((o) => [o.id, o.label]))
  return props.organizacionIds.map((id) => ({
    id,
    label: byId.get(id) || `#${id}`,
  }))
})

function buildParentMap(nodes: OrganizacionTreeNode[]): Map<number, number | null> {
  const map = new Map<number, number | null>()
  const walk = (list: OrganizacionTreeNode[], parentId: number | null) => {
    for (const n of list) {
      map.set(n.id, parentId)
      walk(n.children || [], n.id)
    }
  }
  walk(nodes, null)
  return map
}

function isEffectivelySelected(node: OrganizacionTreeNode): boolean {
  if (selectedSet.value.has(node.id)) return true
  if (!inheritance.value) return false
  const parentMap = buildParentMap(organizerSubtree.value)
  let parentId = parentMap.get(node.id) ?? null
  while (parentId != null) {
    if (selectedSet.value.has(parentId)) return true
    parentId = parentMap.get(parentId) ?? null
  }
  return false
}

function countByTipo(
  nodes: OrganizacionTreeNode[],
  selectedOnly: boolean,
): Record<string, number> {
  const counts = {
    asociaciones: 0,
    distritos: 0,
    iglesias: 0,
    clubes: 0,
    otras: 0,
    total: 0,
  }

  const walk = (list: OrganizacionTreeNode[]) => {
    for (const node of list) {
      const include = !selectedOnly || isEffectivelySelected(node)
      if (include) {
        counts.total += 1
        if (node.tipo_organizacion_id === TIPO_ASOCIACION) counts.asociaciones += 1
        else if (node.tipo_organizacion_id === TIPO_DISTRITO) counts.distritos += 1
        else if (node.tipo_organizacion_id === TIPO_IGLESIA) counts.iglesias += 1
        else if (isClubNode(node)) counts.clubes += 1
        else counts.otras += 1
      }
      walk(node.children || [])
    }
  }
  walk(nodes)
  return counts
}

const summary = computed(() => {
  const tree = pruneForAudience(organizerSubtree.value)
  if (!props.organizacionIds.length) {
    return { ...countByTipo(tree, false), preview: true }
  }
  return { ...countByTipo(tree, true), preview: false }
})

const highestLevelLabel = computed(() => {
  const tree = pruneForAudience(organizerSubtree.value)
  let best: { rank: number; name: string } | null = null
  const rankOf = (tipoId: number) => {
    if (tipoId === TIPO_UNION) return 1
    if (tipoId === TIPO_ASOCIACION) return 2
    if (tipoId === TIPO_DISTRITO) return 3
    if (tipoId === TIPO_IGLESIA) return 4
    if (tipoId === TIPO_CLUB || (TIPOS_HIJO_CLUB as readonly number[]).includes(tipoId)) return 5
    return 99
  }
  const walk = (nodes: OrganizacionTreeNode[]) => {
    for (const node of nodes) {
      if (isEffectivelySelected(node)) {
        const rank = rankOf(node.tipo_organizacion_id)
        if (!best || rank < best.rank) {
          best = { rank, name: node.tipo_nombre || node.nombre }
        }
      }
      walk(node.children || [])
    }
  }
  walk(tree)
  return best?.name || t('events.wizard.orgsNoSelection')
})

const audienceHint = computed(() => {
  if (isLibre.value) return t('events.wizard.orgsAudienceLibre')
  return t('events.wizard.orgsAudienceFiltered', { audience: props.audienceLabel })
})

function setOrganizer(id: number | null): void {
  emit('update:organizacionId', id)
  if (!id) {
    emit('update:organizacionIds', [])
    return
  }
  const root = findNode(props.orgTree, id)
  if (!root) {
    emit('update:organizacionIds', [])
    return
  }
  const allowed = new Set(collectDescendantIds(root))
  emit(
    'update:organizacionIds',
    props.organizacionIds.filter((oid) => allowed.has(oid)),
  )
}

function setSelectedIds(ids: number[]): void {
  emit('update:organizacionIds', [...new Set(ids)])
}

function removeChip(id: number): void {
  setSelectedIds(props.organizacionIds.filter((x) => x !== id))
}

function matchingClubIdsUnder(node: OrganizacionTreeNode): number[] {
  const ids: number[] = []
  const walk = (n: OrganizacionTreeNode) => {
    if (isClubNode(n) && clubMatchesAudience(n)) ids.push(n.id)
    for (const child of n.children || []) walk(child)
  }
  walk(node)
  return ids
}

function toggleNode(node: OrganizacionTreeNode, checked: boolean): void {
  const ids = new Set(props.organizacionIds)
  if (checked) {
    ids.add(node.id)
    // Con herencia el padre basta: hijas ven el evento; el backend filtra por Dirigido a.
    if (!inheritance.value) {
      if (isLibre.value) {
        for (const id of collectDescendantIds(node)) ids.add(id)
      } else {
        for (const id of matchingClubIdsUnder(node)) ids.add(id)
      }
    }
  } else if (inheritance.value) {
    ids.delete(node.id)
    for (const id of collectDescendantIds(node)) ids.delete(id)
  } else if (isLibre.value) {
    for (const id of collectDescendantIds(node)) ids.delete(id)
  } else if (isClubNode(node)) {
    ids.delete(node.id)
  } else {
    for (const id of matchingClubIdsUnder(node)) ids.delete(id)
  }
  setSelectedIds([...ids])
}

function isChecked(node: OrganizacionTreeNode): boolean {
  if (selectedSet.value.has(node.id)) return true
  if (!inheritance.value) return false
  // Marcado por ancestro seleccionado
  const parentMap = buildParentMap(organizerSubtree.value)
  let parentId = parentMap.get(node.id) ?? null
  while (parentId != null) {
    if (selectedSet.value.has(parentId)) return true
    parentId = parentMap.get(parentId) ?? null
  }
  return false
}

function isIndeterminate(node: OrganizacionTreeNode): boolean {
  if (isChecked(node) || inheritance.value) return false
  if (isLibre.value) {
    const descendants = collectDescendantIds(node).slice(1)
    if (!descendants.length) return false
    const some = descendants.some((id) => selectedSet.value.has(id))
    const all = descendants.every((id) => selectedSet.value.has(id))
    return some && !all
  }
  const clubs = matchingClubIdsUnder(node)
  if (!clubs.length) return false
  const some = clubs.some((id) => selectedSet.value.has(id))
  const all = clubs.every((id) => selectedSet.value.has(id))
  return some && !all
}

function selectAllVisible(): void {
  if (!organizerSubtree.value[0]) return
  const root = organizerSubtree.value[0]
  if (inheritance.value) {
    // Toda la jerarquía bajo la organizadora; audiencia se aplica en backend.
    setSelectedIds([root.id])
    return
  }
  if (isLibre.value) {
    setSelectedIds(collectDescendantIds(root))
    return
  }
  setSelectedIds(matchingClubIdsUnder(root))
}

function toggleExpand(id: number): void {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

function collectExpandable(nodes: OrganizacionTreeNode[]): number[] {
  const ids: number[] = []
  for (const n of nodes) {
    if (n.children?.length) {
      ids.push(n.id)
      ids.push(...collectExpandable(n.children))
    }
  }
  return ids
}

function expandAll(): void {
  expandAllFlag.value = true
  expanded.value = new Set(collectExpandable(visibleTree.value))
}

function collapseAll(): void {
  expandAllFlag.value = false
  expanded.value = new Set()
}

function iconForTipo(tipoId: number): string {
  if (tipoId === TIPO_IGLESIA) return 'pi pi-home'
  if (tipoId === TIPO_CLUB || (TIPOS_HIJO_CLUB as readonly number[]).includes(tipoId)) {
    return 'pi pi-flag'
  }
  if (tipoId === TIPO_DISTRITO) return 'pi pi-map'
  return 'pi pi-building'
}

watch(visibleTree, (nodes) => {
  if (!expandAllFlag.value) {
    expanded.value = new Set(nodes.filter((n) => n.children?.length).map((n) => n.id))
    return
  }
  expanded.value = new Set(collectExpandable(nodes))
})
</script>

<template>
  <div class="orgs-step">
    <div class="step-section-title">
      <i class="pi pi-sitemap" />
      <h2>{{ t('events.wizard.stepOrgs') }}</h2>
    </div>
    <p class="step-lead">{{ t('events.wizard.orgsLead') }}</p>

    <div class="field audience-field">
      <label>{{ t('events.audienceTitle') }}</label>
      <div class="audience-picker">
        <button
          v-for="opt in audienceOptions"
          :key="opt.key"
          type="button"
          class="audience-chip"
          :class="[opt.css, { 'is-active': audience.includes(opt.key) }]"
          @click="emit('toggleAudience', opt.key)"
        >
          {{ opt.label }}
        </button>
      </div>
      <small class="pj-muted">{{ t('events.audienceHint') }}</small>
    </div>

    <Message severity="info" :closable="false" class="orgs-step__info">
      {{ audienceHint }}
    </Message>

    <div class="orgs-layout">
      <aside class="orgs-panel orgs-panel--left">
        <div class="field">
          <label for="organizacion_id">{{ t('events.organizador') }}</label>
          <Select
            id="organizacion_id"
            :model-value="organizacionId"
            :options="orgOptions"
            option-label="label"
            option-value="id"
            filter
            show-clear
            :placeholder="t('events.organizadorPlaceholder')"
            class="w-full"
            @update:model-value="setOrganizer"
          />
          <small class="pj-muted">{{ t('events.wizard.orgsOrganizerHint') }}</small>
        </div>

        <div class="field">
          <label>{{ t('events.wizard.orgsSelectionMode') }}</label>
          <div class="mode-cards">
            <button
              type="button"
              class="mode-card"
              :class="{ 'is-active': selectionMode === 'hierarchy' }"
              @click="selectionMode = 'hierarchy'"
            >
              <strong>{{ t('events.wizard.orgsModeHierarchy') }}</strong>
              <span>{{ t('events.wizard.orgsModeHierarchyHint') }}</span>
            </button>
            <button
              type="button"
              class="mode-card"
              :class="{ 'is-active': selectionMode === 'manual' }"
              @click="selectionMode = 'manual'"
            >
              <strong>{{ t('events.wizard.orgsModeManual') }}</strong>
              <span>{{ t('events.wizard.orgsModeManualHint') }}</span>
            </button>
          </div>
        </div>

        <div v-if="selectedChips.length" class="field">
          <label>{{ t('events.wizard.orgsSelected') }}</label>
          <div class="chip-list">
            <span v-for="chip in selectedChips" :key="chip.id" class="org-chip">
              {{ chip.label }}
              <button type="button" :aria-label="t('common.delete')" @click="removeChip(chip.id)">
                <i class="pi pi-times" />
              </button>
            </span>
          </div>
        </div>

        <div class="field field--row inheritance-row">
          <div>
            <label for="inheritance">{{ t('events.wizard.orgsInheritance') }}</label>
            <small class="pj-muted">{{ t('events.wizard.orgsInheritanceHint') }}</small>
          </div>
          <ToggleSwitch input-id="inheritance" v-model="inheritance" />
        </div>
      </aside>

      <section class="orgs-panel orgs-panel--center">
        <Message v-if="!organizacionId" severity="warn" :closable="false">
          {{ t('events.wizard.orgsNeedOrganizer') }}
        </Message>

        <template v-else>
          <div v-if="selectionMode === 'manual'" class="field">
            <label>{{ t('events.organizaciones') }}</label>
            <MultiSelect
              :model-value="organizacionIds"
              :options="flatSelectable"
              option-label="label"
              option-value="id"
              display="chip"
              filter
              :placeholder="t('events.organizacionesPlaceholder')"
              class="w-full"
              @update:model-value="setSelectedIds"
            />
          </div>

          <template v-else>
            <div class="tree-toolbar">
              <AppSearchField
                v-model="search"
                class="tree-search"
                :placeholder="t('events.wizard.orgsSearch')"
              />
              <div class="tree-toolbar__actions">
                <Button
                  type="button"
                  :label="t('events.wizard.orgsSelectAll')"
                  text
                  size="small"
                  @click="selectAllVisible"
                />
                <Button
                  type="button"
                  :label="
                    expandAllFlag
                      ? t('events.wizard.orgsCollapseAll')
                      : t('events.wizard.orgsExpandAll')
                  "
                  text
                  size="small"
                  @click="expandAllFlag ? collapseAll() : expandAll()"
                />
              </div>
            </div>

            <div v-if="!visibleTree.length" class="pj-muted empty-tree">
              {{ t('events.wizard.orgsTreeEmpty') }}
            </div>

            <ul v-else class="org-tree">
              <EventOrgTreeNode
                v-for="node in visibleTree"
                :key="node.id"
                :node="node"
                :depth="0"
                :expanded="expanded"
                :is-checked="isChecked"
                :is-indeterminate="isIndeterminate"
                :icon-for-tipo="iconForTipo"
                :club-by-org-id="clubByOrgId"
                @toggle-expand="toggleExpand"
                @toggle-check="toggleNode"
              />
            </ul>
          </template>
        </template>
      </section>

      <aside class="orgs-panel orgs-panel--right">
        <div class="summary-card">
          <p class="summary-card__label">{{ t('events.wizard.orgsSummaryTitle') }}</p>
          <p class="summary-card__total">
            <strong>{{ summary.total }}</strong>
            <span>{{ t('events.wizard.orgsSummaryCount') }}</span>
          </p>
          <p v-if="summary.preview" class="summary-card__meta">
            {{ t('events.wizard.orgsSummaryPreview') }}
          </p>
          <p v-else class="summary-card__meta">
            {{ t('events.wizard.orgsHighestLevel') }}:
            <strong>{{ highestLevelLabel }}</strong>
          </p>

          <h4>{{ t('events.wizard.orgsTypesIncluded') }}</h4>
          <ul class="summary-card__types">
            <li>
              <span>{{ t('events.wizard.orgsTypeAsociaciones') }}</span>
              <strong>{{ summary.asociaciones }}</strong>
            </li>
            <li>
              <span>{{ t('events.wizard.orgsTypeDistritos') }}</span>
              <strong>{{ summary.distritos }}</strong>
            </li>
            <li>
              <span>{{ t('events.wizard.orgsTypeIglesias') }}</span>
              <strong>{{ summary.iglesias }}</strong>
            </li>
            <li>
              <span>{{ t('events.wizard.orgsTypeClubes') }}</span>
              <strong>{{ summary.clubes }}</strong>
            </li>
          </ul>

          <p class="summary-card__note pj-muted">
            {{
              inheritance
                ? t('events.wizard.orgsSummaryInheritanceOn')
                : t('events.wizard.orgsSummaryInheritanceOff')
            }}
          </p>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.orgs-step {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.step-section-title {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.step-section-title i {
  color: var(--pj-primary, #2563eb);
}

.step-section-title h2 {
  margin: 0;
  font-size: 1.05rem;
}

.step-lead {
  margin: 0;
  color: var(--pj-text-muted);
  font-size: 0.9rem;
}

.audience-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.audience-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.audience-chip {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  color: var(--pj-text-muted);
}

.audience-chip.is-active.badge--conquistadores {
  background: color-mix(in srgb, #ea580c 16%, transparent);
  border-color: #ea580c;
  color: #c2410c;
}

.audience-chip.is-active.badge--aventureros {
  background: color-mix(in srgb, #16a34a 16%, transparent);
  border-color: #16a34a;
  color: #15803d;
}

.audience-chip.is-active.badge--guias {
  background: color-mix(in srgb, #7c3aed 16%, transparent);
  border-color: #7c3aed;
  color: #6d28d9;
}

.audience-chip.is-active.badge--all {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-color: #2563eb;
  color: #1d4ed8;
}

.orgs-step__info {
  width: 100%;
}

.orgs-layout {
  display: grid;
  grid-template-columns: minmax(220px, 0.9fr) minmax(0, 1.4fr) minmax(200px, 0.75fr);
  gap: 0.9rem;
  align-items: start;
}

.orgs-panel {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.9rem;
  background: #fff;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field--row {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.w-full {
  width: 100%;
}

.mode-cards {
  display: grid;
  gap: 0.45rem;
}

.mode-card {
  text-align: left;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 10px;
  padding: 0.65rem 0.75rem;
  background: transparent;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.mode-card strong {
  font-size: 0.86rem;
}

.mode-card span {
  font-size: 0.75rem;
  color: var(--pj-text-muted);
}

.mode-card.is-active {
  border-color: var(--pj-primary, #2563eb);
  background: color-mix(in srgb, #2563eb 8%, #fff);
}

.chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.org-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border-radius: 999px;
  padding: 0.2rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 600;
  background: color-mix(in srgb, #2563eb 12%, #fff);
  color: #1d4ed8;
}

.org-chip button {
  border: 0;
  background: transparent;
  cursor: pointer;
  padding: 0;
  color: inherit;
  display: grid;
  place-content: center;
}

.inheritance-row {
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.tree-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.tree-search {
  min-width: 0;
  flex: 1 0 100%;
}

.tree-toolbar__actions {
  display: flex;
  gap: 0.15rem;
}

.empty-tree {
  padding: 1rem 0.25rem;
}

.org-tree {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 28rem;
  overflow: auto;
}

.summary-card__label {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--pj-text-muted);
}

.summary-card__total {
  margin: 0.35rem 0;
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
}

.summary-card__total strong {
  font-size: 1.8rem;
  color: #16a34a;
}

.summary-card__meta {
  margin: 0 0 0.75rem;
  font-size: 0.82rem;
  color: var(--pj-text-muted);
}

.summary-card h4 {
  margin: 0 0 0.4rem;
  font-size: 0.85rem;
}

.summary-card__types {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.summary-card__types li {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.84rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 60%, transparent);
}

.summary-card__note {
  margin: 0.75rem 0 0;
  font-size: 0.78rem;
}

@media (max-width: 1100px) {
  .orgs-layout {
    grid-template-columns: 1fr 1fr;
  }

  .orgs-panel--right {
    grid-column: 1 / -1;
  }
}

@media (max-width: 768px) {
  .orgs-layout {
    grid-template-columns: 1fr;
  }
}
</style>
