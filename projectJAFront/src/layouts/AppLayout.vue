<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Avatar from 'primevue/avatar'
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

const contextLabel = computed(() => {
  const ctx = auth.contexto
  if (!ctx) return t('auth.switchContext')
  return `${ctx.organizacion_nombre} · ${ctx.rol_display_name}`
})

const contextChipStyle = computed(() => {
  const ctx = auth.contexto
  if (!ctx?.is_club) return undefined
  const primary = ctx.color_principal?.trim()
  if (!primary) return undefined
  const secondary = ctx.color_secundario?.trim() || primary
  return {
    '--chip-accent': primary,
    '--chip-accent-2': secondary,
  }
})

const contextChipIcon = computed(() => {
  const ctx = auth.contexto
  if (ctx?.is_club && ctx.club_logo_url) return undefined
  return ctx?.icon || 'pi pi-sitemap'
})

const navItems = computed(() => {
  const items = [
    { to: { name: 'dashboard' }, label: t('nav.dashboard'), icon: 'pi pi-home', show: true },
    { to: { name: 'users' }, label: t('nav.users'), icon: 'pi pi-users', show: can('users.view') },
    { to: { name: 'roles' }, label: t('nav.roles'), icon: 'pi pi-shield', show: can('roles.view') },
    { to: { name: 'settings.brand' }, label: t('nav.settingsBrand'), icon: 'pi pi-palette', show: can('settings.view') },
    { to: { name: 'clubs' }, label: t('nav.clubs'), icon: 'pi pi-building', show: can('clubs.view') },
    { to: { name: 'organizaciones' }, label: t('nav.organizaciones'), icon: 'pi pi-sitemap', show: can('organizaciones.view') },
    { to: { name: 'personas' }, label: t('nav.personas'), icon: 'pi pi-id-card', show: can('personas.view') },
    { to: { name: 'integrantes' }, label: t('nav.integrantes'), icon: 'pi pi-users', show: can('integrantes.view') },
    { to: { name: 'events' }, label: t('nav.events'), icon: 'pi pi-calendar', show: can('events.view') },
    {
      to: { name: 'segurosConsulta' },
      label: t('nav.segurosConsulta'),
      icon: 'pi pi-shield',
      show: can('events.view'),
    },
    {
      to: { name: 'productosServicios' },
      label: t('nav.productosServicios'),
      icon: 'pi pi-box',
      show: can('events.view'),
    },
    { to: { name: 'terrenos' }, label: t('nav.terrenos'), icon: 'pi pi-map', show: can('terrenos.view') },
    { to: { name: 'cabanas' }, label: t('nav.cabanas'), icon: 'pi pi-building', show: can('cabanas.view') },
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
    name === 'personas' ||
    name === 'integrantes' ||
    name === 'organizaciones' ||
    name === 'terrenos' ||
    name === 'cabanas' ||
    name === 'segurosConsulta' ||
    name === 'productosServicios'
  ) {
    return String(route.name ?? '').startsWith(name)
  }
  return route.name === name
}

async function logout(): Promise<void> {
  loggingOut.value = true
  try {
    await auth.logout()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
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
</script>

<template>
  <div
    class="app-shell"
    :class="{ 'app-shell--dark': theme.isDark }"
    :style="{
      '--pj-pattern': brand.patternCss,
    }"
  >
    <aside class="app-sidebar desktop-only">
      <div class="app-sidebar__brand">
        <img class="brand-mark" :src="brandConfig.appIcon" :alt="t('app.name')" />
        <div>
          <strong class="pj-display">{{ t('app.name') }}</strong>
          <small>{{ t('app.tagline') }}</small>
        </div>
      </div>

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
    </aside>

    <div class="app-main">
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
          <img class="mobile-brand-logo mobile-only" :src="brandConfig.appIcon" :alt="t('app.name')" />
        </div>

        <div class="app-topbar__right">
          <button
            v-if="auth.contexto && (auth.contextOptions?.length ?? 0) > 1"
            type="button"
            class="context-chip"
            :class="{ 'context-chip--club': auth.contexto.is_club && auth.contexto.color_principal }"
            :style="contextChipStyle"
            :title="contextLabel"
            @click="changeContext"
          >
            <img
              v-if="auth.contexto.is_club && auth.contexto.club_logo_url"
              class="context-chip__logo"
              :src="auth.contexto.club_logo_url"
              :alt="auth.contexto.organizacion_nombre"
            />
            <i v-else-if="contextChipIcon" :class="[contextChipIcon, 'context-chip__icon']" />
            <span class="context-chip__label">{{ contextLabel }}</span>
            <i class="pi pi-chevron-down context-chip__caret" />
          </button>

          <Button
            class="app-topbar__action"
            text
            rounded
            :icon="theme.isDark ? 'pi pi-sun' : 'pi pi-moon'"
            :aria-label="theme.isDark ? t('nav.themeLight') : t('nav.themeDark')"
            @click="theme.toggle()"
          />

          <div class="user-chip">
            <Avatar
              :image="auth.user?.avatar_url || undefined"
              :label="auth.user?.name?.charAt(0)?.toUpperCase()"
              shape="circle"
              size="normal"
            />
            <span class="user-chip__name">{{ auth.user?.name }}</span>
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

      <main class="app-content">
        <RouterView />
      </main>
    </div>

    <Drawer v-model:visible="drawerOpen" position="left" class="mobile-drawer">
      <template #header>
        <strong class="pj-display">{{ t('app.name') }}</strong>
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
          v-if="auth.contexto && (auth.contextOptions?.length ?? 0) > 1"
          type="button"
          outlined
          fluid
          icon="pi pi-sitemap"
          :label="t('auth.switchContext')"
          @click="openContextSwitch"
        />
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
      </div>
    </Drawer>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr;
  overflow-x: clip;
}

.app-sidebar {
  background: color-mix(in srgb, var(--pj-bg-elevated) 92%, transparent);
  border-right: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  padding: 0.85rem 0.7rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.app-sidebar__brand {
  display: flex;
  gap: 0.55rem;
  align-items: center;
  padding: 0.15rem 0.35rem;
}

.brand-mark {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: contain;
  background: transparent;
  box-shadow: none;
  flex-shrink: 0;
}

.app-sidebar__brand strong {
  display: block;
  font-family: var(--pj-font-display);
  font-size: 0.95rem;
  letter-spacing: -0.03em;
}

.app-sidebar__brand small {
  color: var(--pj-text-muted);
  font-size: 0.68rem;
  line-height: 1.2;
}

.app-sidebar__nav {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
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
  color: var(--pj-navy);
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
  background-blend-mode: soft-light;
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
  background: color-mix(in srgb, #ffffff 88%, transparent);
  backdrop-filter: blur(10px);
  position: sticky;
  top: 0;
  z-index: 20;
}

.app-topbar__left {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex: 0 0 auto;
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
  display: none;
  align-items: center;
  gap: 0.55rem;
  padding: 0.2rem 0.55rem 0.2rem 0.2rem;
  border-radius: 999px;
  background: var(--pj-bg-muted);
}

.context-chip {
  min-width: 0;
  max-width: 7.5rem;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.28rem 0.65rem 0.28rem 0.35rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--chip-accent, var(--pj-navy)) 22%, transparent);
  background: color-mix(in srgb, var(--chip-accent, var(--pj-navy)) 10%, white);
  color: var(--chip-accent, var(--pj-navy));
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  transition: filter 0.15s ease, transform 0.15s ease;
}

.context-chip:hover {
  filter: brightness(0.97);
}

.context-chip--club {
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--chip-accent) 18%, white) 0%,
    color-mix(in srgb, var(--chip-accent-2, var(--chip-accent)) 18%, white) 100%
  );
  border-color: color-mix(in srgb, var(--chip-accent) 35%, transparent);
  color: var(--chip-accent);
}

.context-chip__logo {
  width: 1.55rem;
  height: 1.55rem;
  border-radius: 999px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1.5px solid color-mix(in srgb, var(--chip-accent, #64748b) 40%, white);
}

.context-chip__icon {
  font-size: 0.95rem;
  margin-left: 0.2rem;
}

.context-chip__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

.context-chip__caret {
  font-size: 0.7rem;
  opacity: 0.7;
  flex-shrink: 0;
}

.user-chip__name {
  font-size: 0.875rem;
  font-weight: 600;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-content {
  padding: var(--pj-space-page);
  padding-bottom: max(var(--pj-space-page), env(safe-area-inset-bottom, 0px));
  flex: 1;
  min-width: 0;
  max-width: 100%;
}

:deep(.mobile-drawer .p-drawer-content) {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.mobile-drawer__footer {
  margin-top: auto;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 1rem 0 max(0.75rem, env(safe-area-inset-bottom, 0px));
}

.mobile-drawer__user {
  margin: 0;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--pj-text-muted);
}

@media (max-width: 899px) {
  .context-chip__label {
    display: none;
  }

  .context-chip {
    max-width: none;
    padding-right: 0.45rem;
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

  .context-chip {
    max-width: min(320px, 48vw);
  }

  .mobile-brand-logo {
    display: none !important;
  }

  .user-chip {
    display: inline-flex;
  }

  .app-content {
    padding: 0.9rem 1.1rem 1.25rem;
  }

  .app-topbar {
    padding: 0 1rem;
  }
}
</style>
