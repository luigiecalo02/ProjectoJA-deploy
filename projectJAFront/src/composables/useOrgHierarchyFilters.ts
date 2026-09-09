import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { organizacionesService } from '@/services/organizacionesService'
import { useAuthStore } from '@/stores/auth'
import type { ClubMinistry } from '@/modules/clubs/types'
import {
  TIPO_ASOCIACION,
  TIPO_CLUB,
  TIPO_DISTRITO,
  TIPO_IGLESIA,
  TIPO_UNION,
  TIPO_ZONA,
  TIPOS_HIJO_CLUB,
  type OrganizacionTreeNode,
} from '@/modules/organizaciones/types'

export type OrgFilterLevel = 'union' | 'asociacion' | 'zona' | 'distrito' | 'iglesia' | 'club'

const CLUB_TIPOS = [TIPO_CLUB, ...TIPOS_HIJO_CLUB] as readonly number[]

const LEVEL_RANK: Record<OrgFilterLevel, number> = {
  union: 1,
  asociacion: 2,
  zona: 3,
  distrito: 4,
  iglesia: 5,
  club: 6,
}

export function useOrgHierarchyFilters(options: { alwaysShowTipoClub?: boolean } = {}) {
  const { t } = useI18n()
  const auth = useAuthStore()

  const orgTree = ref<OrganizacionTreeNode[]>([])
  const tipoClub = ref<ClubMinistry | null>(null)
  const orgFilters = reactive({
    unionId: null as number | null,
    asociacionId: null as number | null,
    zonaId: null as number | null,
    distritoId: null as number | null,
    iglesiaId: null as number | null,
  })

  const tipoClubOptions = computed(() => [
    { label: t('users.filterTipoClubAll'), value: null },
    { label: t('clubs.typeAventureros'), value: 'aventureros' as ClubMinistry },
    { label: t('clubs.typeConquistadores'), value: 'conquistadores' as ClubMinistry },
    { label: t('clubs.typeGuias'), value: 'guias_mayores' as ClubMinistry },
  ])

  function rankOfTipo(tipoId: number | null | undefined): number | null {
    if (!tipoId) return null
    if (tipoId === TIPO_UNION) return LEVEL_RANK.union
    if (tipoId === TIPO_ASOCIACION) return LEVEL_RANK.asociacion
    if (tipoId === TIPO_ZONA) return LEVEL_RANK.zona
    if (tipoId === TIPO_DISTRITO) return LEVEL_RANK.distrito
    if (tipoId === TIPO_IGLESIA) return LEVEL_RANK.iglesia
    if (CLUB_TIPOS.includes(tipoId)) return LEVEL_RANK.club
    return null
  }

  const canUseOrgFilters = computed(() => {
    const user = auth.user
    if (!user) return false
    return Boolean(
      user.is_super ||
        user.is_admin ||
        (user.roles ?? []).includes('super_admin') ||
        (user.roles ?? []).includes('admin'),
    )
  })

  const isPlatformScope = computed(() => {
    if (!canUseOrgFilters.value) return false
    const ctx = auth.contexto
    return !ctx?.organizacion_id || Boolean(ctx.is_platform)
  })

  const scopeOrgId = computed(() => {
    if (isPlatformScope.value) return null
    return auth.contexto?.organizacion_id ?? null
  })

  function findOrgNode(nodes: OrganizacionTreeNode[], id: number | null): OrganizacionTreeNode | null {
    if (!id) return null
    for (const node of nodes) {
      if (node.id === id) return node
      const found = findOrgNode(node.children || [], id)
      if (found) return found
    }
    return null
  }

  function optionsFromNodes(
    nodes: OrganizacionTreeNode[],
    tipoIds: readonly number[],
  ): Array<{ id: number; nombre: string }> {
    return nodes
      .filter((node) => tipoIds.includes(node.tipo_organizacion_id))
      .map((node) => ({ id: node.id, nombre: node.nombre }))
      .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'))
  }

  const unionOptions = computed(() => optionsFromNodes(orgTree.value, [TIPO_UNION]))

  const asociacionOptions = computed(() => {
    const parent = findOrgNode(orgTree.value, orgFilters.unionId)
    return parent ? optionsFromNodes(parent.children || [], [TIPO_ASOCIACION]) : []
  })

  const zonaOptions = computed(() => {
    const parent = findOrgNode(orgTree.value, orgFilters.asociacionId)
    return parent ? optionsFromNodes(parent.children || [], [TIPO_ZONA]) : []
  })

  const distritoOptions = computed(() => {
    if (orgFilters.zonaId) {
      const zona = findOrgNode(orgTree.value, orgFilters.zonaId)
      return zona ? optionsFromNodes(zona.children || [], [TIPO_DISTRITO]) : []
    }
    const parent = findOrgNode(orgTree.value, orgFilters.asociacionId)
    return parent ? optionsFromNodes(parent.children || [], [TIPO_DISTRITO]) : []
  })

  const iglesiaOptions = computed(() => {
    const parent = findOrgNode(orgTree.value, orgFilters.distritoId)
    return parent ? optionsFromNodes(parent.children || [], [TIPO_IGLESIA]) : []
  })

  const scopeTipoId = computed(() => {
    if (isPlatformScope.value) return null
    return (
      auth.contexto?.tipo_organizacion_id
      ?? findOrgNode(orgTree.value, scopeOrgId.value)?.tipo_organizacion_id
      ?? null
    )
  })

  function isLevelImplicit(level: OrgFilterLevel): boolean {
    if (isPlatformScope.value) return false
    const scopeRank = rankOfTipo(scopeTipoId.value)
    if (scopeRank === null) return false
    return LEVEL_RANK[level] <= scopeRank
  }

  function applyScopeLocks(): void {
    const id = scopeOrgId.value
    const tipo = scopeTipoId.value
    if (!id || !tipo) return
    if (tipo === TIPO_UNION) orgFilters.unionId = id
    else if (tipo === TIPO_ASOCIACION) orgFilters.asociacionId = id
    else if (tipo === TIPO_ZONA) orgFilters.zonaId = id
    else if (tipo === TIPO_DISTRITO) orgFilters.distritoId = id
    else if (tipo === TIPO_IGLESIA) orgFilters.iglesiaId = id
  }

  const selectedOrganizacionId = computed(
    () =>
      orgFilters.iglesiaId
      ?? orgFilters.distritoId
      ?? orgFilters.zonaId
      ?? orgFilters.asociacionId
      ?? orgFilters.unionId
      ?? scopeOrgId.value,
  )

  const showUnionFilter = computed(
    () => canUseOrgFilters.value && !isLevelImplicit('union') && unionOptions.value.length > 0,
  )
  const showAsociacionFilter = computed(
    () =>
      canUseOrgFilters.value &&
      !isLevelImplicit('asociacion') &&
      Boolean(orgFilters.unionId) &&
      asociacionOptions.value.length > 0,
  )
  const showZonaFilter = computed(
    () =>
      canUseOrgFilters.value &&
      !isLevelImplicit('zona') &&
      Boolean(orgFilters.asociacionId) &&
      zonaOptions.value.length > 0,
  )
  const showDistritoFilter = computed(
    () =>
      canUseOrgFilters.value &&
      !isLevelImplicit('distrito') &&
      Boolean(orgFilters.zonaId || orgFilters.asociacionId) &&
      distritoOptions.value.length > 0,
  )
  const showIglesiaFilter = computed(
    () =>
      canUseOrgFilters.value &&
      !isLevelImplicit('iglesia') &&
      Boolean(orgFilters.distritoId) &&
      iglesiaOptions.value.length > 0,
  )
  const showTipoClubFilter = computed(
    () => Boolean(options.alwaysShowTipoClub) || (canUseOrgFilters.value && !isLevelImplicit('club')),
  )
  const showHierarchyFilters = computed(
    () =>
      showUnionFilter.value ||
      showAsociacionFilter.value ||
      showZonaFilter.value ||
      showDistritoFilter.value ||
      showIglesiaFilter.value,
  )

  const scopeLabel = computed(() => {
    if (!canUseOrgFilters.value || isPlatformScope.value) return ''
    const name = auth.contexto?.organizacion_nombre?.trim()
    return name ? t('users.filterScope', { org: name }) : ''
  })

  function clearBelow(level: Exclude<OrgFilterLevel, 'club'>): void {
    const below: Record<Exclude<OrgFilterLevel, 'club'>, OrgFilterLevel[]> = {
      union: ['asociacion', 'zona', 'distrito', 'iglesia'],
      asociacion: ['zona', 'distrito', 'iglesia'],
      zona: ['distrito', 'iglesia'],
      distrito: ['iglesia'],
      iglesia: [],
    }
    for (const next of below[level]) {
      if (isLevelImplicit(next)) continue
      if (next === 'asociacion') orgFilters.asociacionId = null
      if (next === 'zona') orgFilters.zonaId = null
      if (next === 'distrito') orgFilters.distritoId = null
      if (next === 'iglesia') orgFilters.iglesiaId = null
    }
  }

  async function loadOrgTree(): Promise<void> {
    try {
      orgTree.value = await organizacionesService.tree()
      applyScopeLocks()
    } catch {
      orgTree.value = []
    }
  }

  return {
    orgFilters,
    tipoClub,
    tipoClubOptions,
    unionOptions,
    asociacionOptions,
    zonaOptions,
    distritoOptions,
    iglesiaOptions,
    selectedOrganizacionId,
    canUseOrgFilters,
    showUnionFilter,
    showAsociacionFilter,
    showZonaFilter,
    showDistritoFilter,
    showIglesiaFilter,
    showTipoClubFilter,
    showHierarchyFilters,
    scopeLabel,
    clearBelow,
    loadOrgTree,
  }
}

export type OrgHierarchyFiltersState = ReturnType<typeof useOrgHierarchyFilters>
