<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import { getPageChrome } from '@/composables/usePageChrome'
import { resolveFileUrl } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useBrandStore } from '@/stores/brand'
import { usePermission } from '@/composables/usePermission'
import { brandConfig } from '@/config/brand'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()
const brand = useBrandStore()
const { can } = usePermission()

const drawerOpen = ref(false)
const loggingOut = ref(false)
const stoppingImpersonation = ref(false)
const chrome = getPageChrome()
const chromeMenu = ref<InstanceType<typeof Menu> | null>(null)
const isMobileChrome = ref(false)
let mobileMedia: MediaQueryList | null = null

const chromeTitle = computed(() => chrome.title || (route.meta.titleKey ? t(route.meta.titleKey) : ''))
const chromeSubtitle = computed(() => chrome.subtitle)
const chromeBackTo = computed(() => chrome.backTo ?? route.meta.backTo ?? null)
const chromeActions = computed(() => chrome.actions)
const hasChrome = computed(() => Boolean(chromeTitle.value || chromeBackTo.value || chromeActions.value.length))
const showMobileChromeTitle = computed(() => Boolean(chromeTitle.value) && isMobileChrome.value)
const desktopActions = computed(() => chromeActions.value.filter((action) => !action.overflow))
const overflowActions = computed(() => {
  const forced = chromeActions.value.filter((action) => action.overflow)
  return forced.length ? forced : chromeActions.value
})
const currentRole = computed(() => auth.contexto?.rol_display_name || '')
const avatarFailed = ref(false)
const brandFailed = ref(false)

const sessionAvatar = computed(() => {
  if (avatarFailed.value) return null
  return resolveFileUrl(auth.user?.avatar_url)
})

const sidebarTitle = computed(
  () => auth.contexto?.organizacion_nombre?.trim() || t('app.name'),
)

const sidebarSubtitle = computed(() => {
  if (auth.contexto?.tipo_nombre) return auth.contexto.tipo_nombre
  return t('app.tagline')
})

const sidebarImage = computed(() => {
  if (brandFailed.value) return brandConfig.appIcon
  if (auth.contexto?.is_club) {
    return resolveFileUrl(auth.contexto.club_logo_url) || brandConfig.appIcon
  }
  return brandConfig.appIcon
})

const canSwitchContext = computed(() => (auth.contextOptions?.length ?? 0) > 1)

watch(
  () => auth.user?.avatar_url,
  () => {
    avatarFailed.value = false
  },
)

watch(
  () => auth.contexto?.club_logo_url,
  () => {
    brandFailed.value = false
  },
)
const chromeMenuItems = computed<MenuItem[]>(() =>
  overflowActions.value.map((action) => ({
    label: action.label,
    icon: action.icon,
    disabled: action.disabled || action.loading,
    command: () => action.onClick(),
  })),
)

function syncMobileChrome(event?: MediaQueryList | MediaQueryListEvent): void {
  isMobileChrome.value = event ? event.matches : Boolean(mobileMedia?.matches)
}

function goChromeBack(): void {
  if (chromeBackTo.value) {
    void router.push(chromeBackTo.value)
    return
  }
  router.back()
}

function toggleChromeMenu(event: Event): void {
  chromeMenu.value?.toggle(event)
}

const navItems = computed(() => {
  const items = [
    { to: { name: 'dashboard' }, label: t('nav.dashboard'), icon: 'pi pi-home', show: can('dashboard.view') },
    { to: { name: 'users' }, label: t('nav.users'), icon: 'pi pi-users', show: can('users.view') },
    { to: { name: 'roles' }, label: t('nav.roles'), icon: 'pi pi-shield', show: can('roles.view') },
    { to: { name: 'settings.platform' }, label: t('nav.settingsBrand'), icon: 'pi pi-cog', show: can('settings.view') },
    { to: { name: 'clubs' }, label: t('nav.clubs'), icon: 'pi pi-building', show: can('clubs.view') },
    { to: { name: 'mi-club' }, label: t('nav.miClub'), icon: 'pi pi-flag', show: can('mi_club.view') },
    { to: { name: 'organizaciones' }, label: t('nav.organizaciones'), icon: 'pi pi-sitemap', show: can('organizaciones.view') },
    { to: { name: 'personas' }, label: t('nav.personas'), icon: 'pi pi-id-card', show: can('personas.view') },
    { to: { name: 'integrantes' }, label: t('nav.integrantes'), icon: 'pi pi-users', show: can('integrantes.view') },
    { to: { name: 'events' }, label: t('nav.events'), icon: 'pi pi-calendar', show: can('events.view') },
    {
      to: { name: 'segurosConsulta' },
      label: t('nav.segurosConsulta'),
      icon: 'pi pi-shield',
      show: can('seguros_consulta.view'),
    },
    {
      to: { name: 'productosServicios' },
      label: t('nav.productosServicios'),
      icon: 'pi pi-box',
      show: can('productos_servicios.view'),
    },
    { to: { name: 'lugares' }, label: t('nav.lugares'), icon: 'pi pi-map-marker', show: can('lugares.view') },
  ]
  return items.filter((item) => item.show)
})

function isActive(name: string): boolean {
  if (
    name === 'users' ||
    name === 'roles' ||
    name === 'settings.brand' ||
    name === 'events' ||
    name === 'clubs' ||
    name === 'mi-club' ||
    name === 'personas' ||
    name === 'integrantes' ||
    name === 'organizaciones' ||
    name === 'lugares' ||
    name === 'terrenos' ||
    name === 'cabanas' ||
    name === 'segurosConsulta' ||
    name === 'productosServicios'
  ) {
    return String(route.name ?? '').startsWith(name)
  }
  return route.name === name
}

const pageIcon = computed(() => {
  const match = navItems.value.find((item) => isActive(String(item.to.name)))
  return match?.icon || 'pi pi-th-large'
})

async function logout(): Promise<void> {
  loggingOut.value = true
  try {
    await auth.logout()
    if (auth.isAuthenticated) {
      await router.push({ name: 'dashboard' })
      return
    }
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

async function returnToAdmin(): Promise<void> {
  stoppingImpersonation.value = true
  try {
    await auth.stopImpersonation()
    await router.push({ name: 'users' })
  } finally {
    stoppingImpersonation.value = false
  }
}

async function changeContext(): Promise<void> {
  await auth.switchContext()
  await router.push({ name: 'auth.context', query: { redirect: route.fullPath } })
}

function navigate(to: { name: string }): void {
  drawerOpen.value = false
  void router.push(to)
}

function openContextSwitch(): void {
  drawerOpen.value = false
  void changeContext()
}

onMounted(() => {
  mobileMedia = window.matchMedia('(max-width: 899px)')
  syncMobileChrome(mobileMedia)
  mobileMedia.addEventListener('change', syncMobileChrome)
})

onBeforeUnmount(() => {
  mobileMedia?.removeEventListener('change', syncMobileChrome)
})
</script>

<template>
  <div
    class="app-shell"
    :class="{ 'app-shell--dark': theme.isDark, 'app-shell--page-chrome': hasChrome }"
    :style="{
      '--pj-pattern': brand.patternCss,
    }"
  >
    <div v-if="auth.isImpersonating" class="impersonation-banner" role="status">
      <span>
        <i class="pi pi-eye" />
        {{ t('users.impersonatingAs', { name: auth.user?.name || '' }) }}
        <em v-if="auth.impersonator?.name">{{ t('users.impersonatingFrom', { name: auth.impersonator.name }) }}</em>
      </span>
      <Button
        size="small"
        icon="pi pi-undo"
        :label="t('users.stopImpersonation')"
        :loading="stoppingImpersonation"
        @click="returnToAdmin"
      />
    </div>
    <aside class="app-sidebar desktop-only">
      <button
        type="button"
        class="app-sidebar__brand"
        :class="{ 'app-sidebar__brand--action': canSwitchContext }"
        :title="canSwitchContext ? t('auth.switchContext') : undefined"
        @click="canSwitchContext ? changeContext() : undefined"
      >
        <img
          class="brand-mark"
          :src="sidebarImage"
          :alt="sidebarTitle"
          @error="brandFailed = true"
        />
        <div>
          <strong class="pj-display">{{ sidebarTitle }}</strong>
          <small>{{ sidebarSubtitle }}</small>
          <span v-if="canSwitchContext" class="app-sidebar__switch">{{ t('auth.switchContext') }}</span>
        </div>
        <i v-if="canSwitchContext" class="pi pi-chevron-down app-sidebar__caret" />
      </button>

      <nav class="app-sidebar__nav">
        <button
          v-for="item in navItems"
          :key="String(item.to.name)"
          type="button"
          class="nav-link"
          :class="{ 'nav-link--active': isActive(String(item.to.name)) }"
          @click="navigate(item.to)"
        >
          <i :class="[item.icon, 'nav-link__icon']" />
          <span>{{ item.label }}</span>
        </button>
      </nav>

      <footer class="app-sidebar__legal">
        <strong>{{ t('app.name') }}</strong>
        <small>{{ t('app.copyright') }}</small>
      </footer>
    </aside>

    <div class="app-main">
      <div class="app-chrome">
        <header class="app-topbar">
          <div class="app-topbar__left">
            <Button
              class="mobile-only"
              text
              rounded
              icon="pi pi-bars"
              :aria-label="t('nav.menu')"
              @click="drawerOpen = true"
            />
            <Button
              v-if="chromeBackTo && isMobileChrome"
              class="page-chrome__back"
              text
              rounded
              icon="pi pi-arrow-left"
              :aria-label="t('common.back')"
              @click="goChromeBack"
            />
            <img
              v-if="!showMobileChromeTitle"
              class="mobile-brand-logo mobile-only"
              :src="sidebarImage"
              :alt="sidebarTitle"
              @error="brandFailed = true"
            />
            <div v-if="showMobileChromeTitle" class="page-chrome__titles">
              <strong class="page-chrome__title page-chrome__title--mobile">{{ chromeTitle }}</strong>
            </div>
          </div>

          <div class="app-topbar__right">
            <Button
              v-if="overflowActions.length"
              class="page-chrome__more mobile-only"
              text
              rounded
              icon="pi pi-ellipsis-v"
              :aria-label="t('nav.moreActions')"
              @click="toggleChromeMenu"
            />
            <Menu v-if="overflowActions.length" ref="chromeMenu" :model="chromeMenuItems" popup />

            <Button
              class="app-topbar__action"
              text
              rounded
              :icon="theme.isDark ? 'pi pi-sun' : 'pi pi-moon'"
              :aria-label="theme.isDark ? t('nav.themeLight') : t('nav.themeDark')"
              @click="theme.toggle()"
            />

            <div class="user-chip">
              <img
                v-if="sessionAvatar"
                class="user-chip__photo"
                :src="sessionAvatar"
                :alt="auth.user?.name || ''"
                @error="avatarFailed = true"
              />
              <span v-else class="user-chip__fallback">
                {{ auth.user?.name?.charAt(0)?.toUpperCase() || '?' }}
              </span>
              <div class="user-chip__meta">
                <span class="user-chip__name">{{ auth.user?.name }}</span>
                <small v-if="currentRole" class="user-chip__role">{{ currentRole }}</small>
              </div>
            </div>

            <Button
              class="app-topbar__action"
              text
              rounded
              icon="pi pi-sign-out"
              :loading="loggingOut"
              :aria-label="t('nav.logout')"
              @click="logout"
            />
          </div>
        </header>

        <section v-if="hasChrome" class="page-toolbar desktop-only" aria-label="Acciones de la página">
          <div class="page-toolbar__left">
            <Button
              v-if="chromeBackTo"
              class="page-chrome__back"
              text
              rounded
              icon="pi pi-arrow-left"
              :aria-label="t('common.back')"
              @click="goChromeBack"
            />
            <i
              v-if="chromeTitle"
              :class="[pageIcon, 'page-chrome__mark']"
              aria-hidden="true"
            />
            <div v-if="chromeTitle" class="page-chrome__titles">
              <strong class="page-chrome__title">{{ chromeTitle }}</strong>
              <small v-if="chromeSubtitle" class="page-chrome__subtitle">{{ chromeSubtitle }}</small>
            </div>
          </div>
          <div v-if="desktopActions.length" class="page-chrome__actions">
            <Button
              v-for="action in desktopActions"
              :key="action.key"
              :label="action.label"
              :icon="action.icon"
              :severity="action.severity"
              :outlined="action.outlined"
              :text="action.text"
              :loading="action.loading"
              :disabled="action.disabled"
              size="small"
              @click="action.onClick"
            />
          </div>
        </section>
      </div>

      <main class="app-content">
        <RouterView />
      </main>
    </div>

    <Drawer v-model:visible="drawerOpen" position="left" class="mobile-drawer">
      <template #header>
        <button
          type="button"
          class="app-sidebar__brand"
          :class="{ 'app-sidebar__brand--action': canSwitchContext }"
          :title="canSwitchContext ? t('auth.switchContext') : undefined"
          @click="canSwitchContext ? openContextSwitch() : undefined"
        >
          <img class="brand-mark" :src="sidebarImage" :alt="sidebarTitle" @error="brandFailed = true" />
          <div>
            <strong class="pj-display">{{ sidebarTitle }}</strong>
            <span v-if="canSwitchContext" class="app-sidebar__switch">{{ t('auth.switchContext') }}</span>
          </div>
          <i v-if="canSwitchContext" class="pi pi-chevron-down app-sidebar__caret" />
        </button>
      </template>
      <nav class="app-sidebar__nav">
        <button
          v-for="item in navItems"
          :key="`m-${String(item.to.name)}`"
          type="button"
          class="nav-link"
          :class="{ 'nav-link--active': isActive(String(item.to.name)) }"
          @click="navigate(item.to)"
        >
          <i :class="[item.icon, 'nav-link__icon']" />
          <span>{{ item.label }}</span>
        </button>
      </nav>
      <div class="mobile-drawer__footer">
        <p v-if="auth.user?.name" class="mobile-drawer__user">{{ auth.user.name }}</p>
        <Button
          type="button"
          severity="danger"
          outlined
          fluid
          icon="pi pi-sign-out"
          :label="t('nav.logout')"
          :loading="loggingOut"
          @click="logout"
        />
        <p class="app-sidebar__legal app-sidebar__legal--drawer">
          <strong>{{ t('app.name') }}</strong>
          <small>{{ t('app.copyright') }}</small>
        </p>
      </div>
    </Drawer>
  </div>
</template>

<style>
/* El Drawer de PrimeVue se monta en body: el scoped del layout no le pinta el fondo. */
html.dark .p-drawer.mobile-drawer,
html.dark .p-drawer.mobile-drawer .p-drawer-header,
html.dark .p-drawer.mobile-drawer .p-drawer-content,
html.dark .p-drawer.mobile-drawer .p-drawer-footer {
  background: var(--pj-bg-elevated);
  color: var(--pj-text);
}

.p-drawer.mobile-drawer {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.p-drawer.mobile-drawer .p-drawer-header {
  padding-top: calc(0.85rem + env(safe-area-inset-top, 0px));
}

.p-drawer.mobile-drawer .p-drawer-content {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
}
</style>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr;
  overflow-x: clip;
}

.impersonation-banner {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 1rem;
  background: #b45309;
  color: #fff7ed;
  z-index: 20;
}

.impersonation-banner span {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.65rem;
  font-weight: 700;
}

.impersonation-banner em {
  font-style: normal;
  font-weight: 500;
  opacity: 0.9;
}

.app-sidebar {
  background: color-mix(in srgb, var(--pj-bg-elevated) 92%, transparent);
  border-right: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  padding: 0.85rem 0.7rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: 100vh;
  position: sticky;
  top: 0;
}

.app-sidebar__brand {
  display: flex;
  gap: 0.55rem;
  align-items: flex-start;
  width: 100%;
  margin: 0;
  padding: 0.15rem 0.35rem;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  font: inherit;
}

.brand-mark {
  width: 40px;
  height: 40px;
  margin-top: 0.1rem;
  border-radius: 50%;
  object-fit: contain;
  background: transparent;
  box-shadow: none;
  flex-shrink: 0;
}

.app-sidebar__brand > div {
  min-width: 0;
  flex: 1 1 auto;
}

.app-sidebar__brand strong {
  display: block;
  font-family: var(--pj-font-display);
  font-size: 0.88rem;
  letter-spacing: -0.03em;
  line-height: 1.2;
  white-space: normal;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.app-sidebar__brand small {
  display: block;
  color: var(--pj-text-muted);
  font-size: 0.68rem;
  line-height: 1.25;
  white-space: normal;
}

.app-sidebar__switch {
  display: block;
  margin-top: 0.2rem;
  color: var(--pj-navy);
  font-size: 0.68rem;
  font-weight: 600;
  line-height: 1.25;
}

.app-sidebar__caret {
  margin-top: 0.35rem;
  font-size: 0.7rem;
  color: var(--pj-text-muted);
  flex-shrink: 0;
}

.app-sidebar__brand--action {
  cursor: pointer;
  border-radius: 10px;
}

.app-sidebar__brand--action:hover {
  background: color-mix(in srgb, var(--pj-navy) 6%, transparent);
}

.app-sidebar__nav {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}

.app-sidebar__legal {
  margin-top: auto;
  padding: 0.65rem 0.35rem 0.15rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 60%, transparent);
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.app-sidebar__legal strong {
  font-size: 0.78rem;
  font-weight: 700;
}

.app-sidebar__legal small {
  color: var(--pj-text-muted);
  font-size: 0.65rem;
  line-height: 1.35;
}

.app-sidebar__legal--drawer {
  margin-top: 0.75rem;
  border-top: 0;
  padding: 0;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  width: 100%;
  border: 0;
  background: transparent;
  color: var(--pj-text-muted);
  border-radius: 8px;
  padding: 0.5rem 0.65rem;
  cursor: pointer;
  text-align: left;
  font-size: 0.875rem;
  transition: background 0.15s ease, color 0.15s ease;
}

.nav-link:hover {
  background: var(--pj-primary-soft);
  color: var(--pj-text);
}

.nav-link--active {
  background: var(--pj-primary-soft);
  color: var(--pj-primary);
  font-weight: 600;
  box-shadow: inset 3px 0 0 var(--pj-gold);
}

.nav-link__icon {
  width: 1.1rem;
  height: 1.1rem;
}

.app-main {
  min-width: 0;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background-color: var(--pj-bg);
  background-image: var(--pj-pattern);
  background-repeat: repeat;
  background-size: 420px auto;
  background-position: center top;
}

.app-shell--dark .app-main {
  background-size: 480px auto;
}

.app-chrome {
  position: sticky;
  top: 0;
  z-index: 20;
  background: color-mix(in srgb, var(--pj-bg-elevated) 92%, transparent);
  backdrop-filter: blur(10px);
}

.app-topbar {
  min-height: calc(var(--pj-topbar-height) + env(safe-area-inset-top, 0px));
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  padding: env(safe-area-inset-top, 0px) max(0.5rem, env(safe-area-inset-right, 0px)) 0
    max(0.5rem, env(safe-area-inset-left, 0px));
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.app-topbar__left {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex: 1 1 auto;
  min-width: 0;
}

.page-toolbar {
  display: none;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  min-width: 0;
  padding: 0.7rem 1rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  background: color-mix(in srgb, var(--pj-bg-elevated) 88%, transparent);
}

.page-toolbar__left {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.page-chrome__mark {
  font-size: 2.35rem;
  line-height: 1;
  color: var(--pj-navy);
  flex-shrink: 0;
}

.page-chrome__titles {
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.page-chrome__title {
  font-size: 2rem;
  font-weight: 700;
  line-height: 1.15;
  letter-spacing: -0.03em;
}

.page-chrome__subtitle {
  color: var(--pj-text-muted);
  font-size: 0.8rem;
  line-height: 1.25;
}

.page-chrome__title--mobile {
  font-size: 1.05rem;
  letter-spacing: -0.02em;
}

.page-chrome__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.4rem;
  flex-shrink: 0;
}

.app-topbar__right {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.15rem;
  min-width: 0;
  flex: 1 1 auto;
}

.app-topbar__action {
  flex-shrink: 0;
}

.mobile-brand-logo {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: contain;
  background: transparent;
}

.user-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.2rem 0.65rem 0.2rem 0.2rem;
  border-radius: 999px;
  background: var(--pj-bg-muted);
}

.user-chip__photo,
.user-chip__fallback {
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  flex-shrink: 0;
}

.user-chip__photo {
  object-fit: cover;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
}

.user-chip__fallback {
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 0.85rem;
  color: var(--pj-navy);
  background: color-mix(in srgb, var(--pj-navy) 12%, white);
}

.user-chip__meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.user-chip__name {
  font-size: 0.82rem;
  font-weight: 700;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.2;
}

.user-chip__role {
  font-size: 0.7rem;
  font-weight: 500;
  color: var(--pj-text-muted);
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.2;
}

.app-content {
  padding: var(--pj-space-page);
  padding-bottom: max(var(--pj-space-page), env(safe-area-inset-bottom, 0px));
  flex: 1;
  min-width: 0;
  max-width: 100%;
}

.mobile-drawer__footer {
  margin-top: auto;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 1rem 0 max(1.15rem, env(safe-area-inset-bottom, 0px));
}

.mobile-drawer__user {
  margin: 0;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--pj-text-muted);
}

@media (max-width: 899px) {
  .user-chip {
    padding-right: 0.2rem;
    background: transparent;
  }

  .user-chip__meta {
    display: none;
  }

  .app-shell--page-chrome :deep(.pj-page__header:not(.standings-hero)),
  .app-shell--page-chrome :deep(.wizard__header),
  .app-shell--page-chrome :deep(.club-edit__header),
  .app-shell--page-chrome :deep(.org-form-page__header) {
    display: none;
  }
}

.desktop-only {
  display: none;
}

.mobile-only {
  display: inline-flex;
}

@media (min-width: 900px) {
  .app-shell {
    grid-template-columns: var(--pj-sidebar-width) 1fr;
  }

  .desktop-only {
    display: flex;
  }

  .mobile-only {
    display: none !important;
  }

  .mobile-brand-logo {
    display: none !important;
  }

  .app-content {
    padding: 0.9rem 1.1rem 1.25rem;
  }

  .app-topbar {
    padding: 0 1rem;
  }

  .page-toolbar.desktop-only {
    display: flex;
  }

  .app-shell--page-chrome :deep(.pj-page__header:not(.standings-hero)),
  .app-shell--page-chrome :deep(.wizard__header),
  .app-shell--page-chrome :deep(.club-edit__header),
  .app-shell--page-chrome :deep(.org-form-page__header) {
    display: none;
  }
}
</style>
