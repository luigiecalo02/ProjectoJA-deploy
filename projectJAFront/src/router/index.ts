import {
  createRouter,
  createWebHistory,
  type NavigationGuardNext,
  type RouteLocationNormalized,
  type RouteRecordRaw,
} from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    component: () => import('@/layouts/AuthLayout.vue'),
    meta: { guest: true },
    children: [
      {
        path: '',
        name: 'login',
        component: () => import('@/pages/auth/LoginPage.vue'),
      },
      {
        path: '/registrar-club',
        name: 'club.inscripcion',
        component: () => import('@/pages/auth/ClubInscripcionPage.vue'),
        meta: { guest: true, wide: true },
      },
      {
        path: '/recuperar-contrasena',
        name: 'auth.forgot',
        component: () => import('@/pages/auth/ForgotPasswordPage.vue'),
        meta: { guest: true },
      },
      {
        path: '/restablecer-contrasena',
        name: 'auth.reset',
        component: () => import('@/pages/auth/ResetPasswordPage.vue'),
        meta: { guest: true },
      },
      {
        path: '/confirmar-cuenta',
        name: 'auth.verify',
        component: () => import('@/pages/auth/VerifyEmailPage.vue'),
        meta: { guest: true },
      },
    ],
  },
  {
    path: '/auth/callback',
    name: 'auth.callback',
    component: () => import('@/pages/auth/AuthCallbackPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/seleccionar-contexto',
    component: () => import('@/layouts/ContextLayout.vue'),
    meta: { requiresAuth: true, contextSelection: true },
    children: [
      {
        path: '',
        name: 'auth.context',
        component: () => import('@/pages/auth/SelectContextPage.vue'),
      },
    ],
  },
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { titleKey: 'dashboard.title' },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('@/pages/users/UsersListPage.vue'),
        meta: { permission: 'users.view', titleKey: 'users.title' },
      },
      {
        path: 'users/create',
        name: 'users.create',
        component: () => import('@/pages/users/UserFormPage.vue'),
        meta: { permission: 'users.create', titleKey: 'users.new', backTo: { name: 'users' } },
      },
      {
        path: 'users/:id/edit',
        name: 'users.edit',
        component: () => import('@/pages/users/UserFormPage.vue'),
        meta: { permission: 'users.update', titleKey: 'users.edit', backTo: { name: 'users' } },
      },
      {
        path: 'roles',
        name: 'roles',
        component: () => import('@/pages/roles/RolesListPage.vue'),
        meta: { permission: 'roles.view', titleKey: 'roles.title' },
      },
      {
        path: 'roles/create',
        name: 'roles.create',
        component: () => import('@/pages/roles/RoleFormPage.vue'),
        meta: { permission: 'roles.create', titleKey: 'roles.new', backTo: { name: 'roles' } },
      },
      {
        path: 'roles/:id/edit',
        name: 'roles.edit',
        component: () => import('@/pages/roles/RoleFormPage.vue'),
        meta: { permission: 'roles.view', titleKey: 'roles.edit', backTo: { name: 'roles' } },
      },
      {
        path: 'configuracion',
        name: 'settings.platform',
        component: () => import('@/pages/settings/PlatformSettingsPage.vue'),
        meta: { permission: 'settings.view', titleKey: 'settings.title' },
      },
      {
        path: 'configuracion/apariencia',
        name: 'settings.brand',
        redirect: { name: 'settings.platform', query: { tab: 'apariencia' } },
      },
      {
        path: 'configuracion/cuentas-bancarias',
        name: 'settings.bankAccounts',
        redirect: { name: 'settings.platform', query: { tab: 'cuentas' } },
      },
      {
        path: 'events',
        name: 'events',
        component: () => import('@/pages/events/EventsListPage.vue'),
        meta: { permission: 'events.view', titleKey: 'events.title' },
      },
      {
        path: 'events/create',
        name: 'events.create',
        component: () => import('@/pages/events/EventFormPage.vue'),
        meta: { permission: 'events.create', titleKey: 'events.wizard.createTitle', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/edit',
        name: 'events.edit',
        component: () => import('@/pages/events/EventFormPage.vue'),
        meta: { permission: 'events.update', titleKey: 'events.edit', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/participate',
        name: 'events.participate',
        component: () => import('@/pages/events/EventParticipationPage.vue'),
        meta: { permission: 'events.view', titleKey: 'events.participate', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/enroll',
        name: 'events.enroll',
        component: () => import('@/pages/events/EventEnrollV2Page.vue'),
        meta: { permission: 'events.view', titleKey: 'events.enroll', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/inscripciones-revision',
        name: 'events.inscripcionesRevision',
        component: () => import('@/pages/events/EventInscripcionesRevisionPage.vue'),
        meta: { permission: 'events.update', titleKey: 'events.revisionTitle', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/alojamiento',
        name: 'events.alojamiento',
        component: () => import('@/pages/events/EventAlojamientoPage.vue'),
        meta: { permissionsAny: ['cabanas.self_assign', 'events.view'], titleKey: 'alojamiento.title', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/judge',
        name: 'events.judge',
        component: () => import('@/pages/events/EventJudgePage.vue'),
        meta: { permission: 'events.evaluate', titleKey: 'events.judgeTitle', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/judge/evaluaciones',
        name: 'events.judge.evaluaciones',
        component: () => import('@/pages/events/EventJudgeEvaluacionesPage.vue'),
        meta: { permission: 'events.evaluate', titleKey: 'events.judgeEvalTitle', backTo: { name: 'events.judge' } },
      },
      {
        path: 'events/:id/standings',
        name: 'events.standings',
        component: () => import('@/pages/events/EventStandingsPage.vue'),
        meta: { permissionsAny: ['events.view_scores', 'events.evaluate'], titleKey: 'events.standingsTitle', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/standings2',
        name: 'events.standings2',
        component: () => import('@/pages/events/EventStandingsTreePage.vue'),
        meta: { permissionsAny: ['events.view_scores', 'events.evaluate'], titleKey: 'events.standingsTitle', backTo: { name: 'events' } },
      },
      {
        path: 'events/:id/distribucion',
        name: 'events.distribucion',
        component: () => import('@/pages/terrenos/EventDistribucionTerrenoPage.vue'),
        meta: { permissionsAny: ['terrenos.view', 'events.view'], titleKey: 'terrenos.distribucionTitle', backTo: { name: 'events' } },
      },
      {
        path: 'productos-servicios',
        name: 'productosServicios',
        component: () => import('@/pages/events/ProductosServiciosPage.vue'),
        meta: { permission: 'productos_servicios.view', titleKey: 'productosServicios.title' },
      },
      {
        path: 'seguros/consulta',
        name: 'segurosConsulta',
        component: () => import('@/pages/events/SeguroConsultaPage.vue'),
        meta: { permission: 'seguros_consulta.view', titleKey: 'segurosConsulta.title' },
      },
      {
        path: 'terrenos',
        name: 'terrenos',
        component: () => import('@/pages/terrenos/TerrenosListPage.vue'),
        meta: { permission: 'terrenos.view', titleKey: 'terrenos.title' },
      },
      {
        path: 'cabanas',
        name: 'cabanas',
        component: () => import('@/pages/cabanas/CabanasListPage.vue'),
        meta: { permission: 'cabanas.view', titleKey: 'cabanas.title' },
      },
      {
        path: 'cabanas/:id/layout',
        name: 'cabanas.layout',
        component: () => import('@/pages/cabanas/CabanaLayoutPage.vue'),
        meta: { permission: 'cabanas.view', titleKey: 'cabanas.layout', backTo: { name: 'cabanas' } },
      },
      {
        path: 'terrenos/:id',
        name: 'terrenos.map',
        component: () => import('@/pages/terrenos/TerrenoMapPage.vue'),
        meta: { permission: 'terrenos.view', titleKey: 'terrenos.title', backTo: { name: 'terrenos' } },
      },
      {
        path: 'terrenos/:id/configuraciones/:configId',
        name: 'terrenos.config',
        component: () => import('@/pages/terrenos/ConfigMapPage.vue'),
        meta: { permission: 'terrenos.view' },
      },
      {
        path: 'terrenos/:id/configuraciones/:configId',
        name: 'terrenos.config',
        component: () => import('@/pages/terrenos/ConfigMapPage.vue'),
        meta: { permission: 'terrenos.view' },
      },
      {
        path: 'clubs',
        name: 'clubs',
        component: () => import('@/pages/clubs/ClubsListPage.vue'),
        meta: { permission: 'clubs.view', titleKey: 'clubs.title' },
      },
      {
        path: 'clubs/create',
        name: 'clubs.create',
        component: () => import('@/pages/clubs/ClubFormPage.vue'),
        meta: { permission: 'clubs.create', titleKey: 'clubs.new', backTo: { name: 'clubs' } },
      },
      {
        path: 'clubs/:id/edit',
        name: 'clubs.edit',
        component: () => import('@/pages/clubs/ClubFormPage.vue'),
        meta: { permission: 'clubs.update', titleKey: 'clubs.edit', backTo: { name: 'clubs' } },
      },
      {
        path: 'clubs/:id/directors',
        name: 'clubs.directors',
        redirect: (to) => ({ name: 'clubs.edit', params: { id: to.params.id } }),
      },
      {
        path: 'mi-club',
        name: 'mi-club',
        component: () => import('@/pages/clubs/MiClubPage.vue'),
        meta: { permission: 'mi_club.view', titleKey: 'miClub.title' },
      },
      {
        path: 'mi-club/create',
        name: 'mi-club.create',
        component: () => import('@/pages/clubs/ClubFormPage.vue'),
        meta: { permission: 'mi_club.create', titleKey: 'clubs.new', backTo: { name: 'mi-club' } },
      },
      {
        path: 'mi-club/:id/edit',
        name: 'mi-club.edit',
        component: () => import('@/pages/clubs/ClubFormPage.vue'),
        meta: { permission: 'mi_club.update', titleKey: 'clubs.edit', backTo: { name: 'mi-club' } },
      },
      {
        path: 'organizaciones',
        name: 'organizaciones',
        component: () => import('@/pages/organizaciones/OrganizacionesListPage.vue'),
        meta: { permission: 'organizaciones.view', titleKey: 'organizaciones.title' },
      },
      {
        path: 'organizaciones/create',
        name: 'organizaciones.create',
        component: () => import('@/pages/organizaciones/OrganizacionFormPage.vue'),
        meta: { permission: 'organizaciones.create', titleKey: 'organizaciones.createTitle', backTo: { name: 'organizaciones' } },
      },
      {
        path: 'organizaciones/:id/edit',
        name: 'organizaciones.edit',
        component: () => import('@/pages/organizaciones/OrganizacionFormPage.vue'),
        meta: { permission: 'organizaciones.update', titleKey: 'organizaciones.edit', backTo: { name: 'organizaciones' } },
      },
      {
        path: 'personas',
        name: 'personas',
        component: () => import('@/pages/personas/PersonasListPage.vue'),
        meta: { permission: 'personas.view', titleKey: 'personas.title' },
      },
      {
        path: 'personas/create',
        name: 'personas.create',
        component: () => import('@/pages/personas/PersonaFormPage.vue'),
        meta: { permission: 'personas.create', titleKey: 'personas.new', backTo: { name: 'personas' } },
      },
      {
        path: 'personas/:id/edit',
        name: 'personas.edit',
        component: () => import('@/pages/personas/PersonaFormPage.vue'),
        meta: { permission: 'personas.update', titleKey: 'personas.edit', backTo: { name: 'personas' } },
      },
      {
        path: 'integrantes',
        name: 'integrantes',
        component: () => import('@/pages/integrantes/IntegrantesListPage.vue'),
        meta: { permission: 'integrantes.view', titleKey: 'integrantes.title' },
      },
      {
        path: 'integrantes/create',
        name: 'integrantes.create',
        component: () => import('@/pages/personas/PersonaFormPage.vue'),
        meta: { permission: 'integrantes.create', titleKey: 'integrantes.new', backTo: { name: 'integrantes' } },
      },
      {
        path: 'integrantes/:id/edit',
        name: 'integrantes.edit',
        component: () => import('@/pages/personas/PersonaFormPage.vue'),
        meta: { permission: 'integrantes.update', titleKey: 'integrantes.edit', backTo: { name: 'integrantes' } },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (
  to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext,
) => {
  const auth = useAuthStore()
  await auth.bootstrap()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next({ name: 'login', query: { redirect: to.fullPath } })
  }

  if (to.meta.guest && auth.isAuthenticated && to.name !== 'auth.callback') {
    if (auth.requiresContext) {
      return next({ name: 'auth.context' })
    }
    return next({ name: 'dashboard' })
  }

  if (auth.isAuthenticated && auth.requiresContext && !to.meta.contextSelection) {
    return next({
      name: 'auth.context',
      query: to.name === 'auth.context' ? to.query : { redirect: to.fullPath },
    })
  }

  if (auth.isAuthenticated && !auth.requiresContext && to.meta.contextSelection) {
    return next({ name: 'dashboard' })
  }

  if (
    auth.contexto?.is_club &&
    (to.name === 'mi-club.create' || to.name === 'mi-club.edit')
  ) {
    return next({ name: 'mi-club' })
  }

  const permission = to.meta.permission as string | undefined
  if (permission && !auth.hasPermission(permission)) {
    return next({ name: 'dashboard' })
  }

  const permissionsAny = to.meta.permissionsAny as string[] | undefined
  if (permissionsAny?.length && !permissionsAny.some((p) => auth.hasPermission(p))) {
    return next({ name: 'dashboard' })
  }

  return next()
})

export default router
