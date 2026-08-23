<script setup lang="ts">
import { computed } from 'vue'
import Drawer from 'primevue/drawer'
import { stackDrawerThemes, type StackDrawerLevel } from './stackDrawerTheme'

const props = withDefaults(
  defineProps<{
    visible: boolean
    level?: StackDrawerLevel
    title?: string
    subtitle?: string
    blockScroll?: boolean
    position?: 'right' | 'bottom'
  }>(),
  {
    level: 1,
    title: '',
    subtitle: '',
    blockScroll: true,
    position: 'right',
  },
)

const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

const theme = computed(() => stackDrawerThemes[props.level])
const isBottom = computed(() => props.position === 'bottom')

const drawerVisible = computed({
  get: () => props.visible,
  set: (value: boolean) => emit('update:visible', value),
})

const rootStyle = computed(() => {
  const base = {
    padding: '0',
    border: 'none',
    borderStyle: 'none',
    borderWidth: '0',
    overflow: 'hidden',
    display: 'flex',
    flexDirection: 'column',
  } as const

  if (isBottom.value) {
    return {
      ...base,
      width: '100vw',
      maxWidth: '100vw',
      height: 'calc(100dvh - 4.75rem - env(safe-area-inset-top, 0px))',
      maxHeight: 'calc(100dvh - 4.75rem - env(safe-area-inset-top, 0px))',
      borderRadius: '18px 18px 0 0',
    }
  }

  return {
    ...base,
    width: theme.value.width,
    maxWidth: '100vw',
    borderRadius: '0',
  }
})
</script>

<template>
  <Drawer
    v-model:visible="drawerVisible"
    :position="position"
    :block-scroll="blockScroll"
    append-to="body"
    :pt="{
      root: {
        class: ['stack-drawer', theme.cssClass, { 'stack-drawer--bottom': isBottom }],
        style: rootStyle,
      },
    }"
  >
    <!-- Layout propio: evita el padding del header de Aura que deja marco blanco -->
    <template #container="{ closeCallback }">
      <div class="stack-drawer-panel" :class="theme.cssClass">
        <header class="stack-drawer-panel__header">
          <div class="stack-drawer-panel__head">
            <slot name="header">
              <strong v-if="title">{{ title }}</strong>
              <small v-if="subtitle">{{ subtitle }}</small>
            </slot>
          </div>
          <button
            type="button"
            class="stack-drawer-panel__close"
            aria-label="Close"
            @click="closeCallback"
          >
            <i class="pi pi-times" />
          </button>
        </header>

        <div class="stack-drawer-panel__content">
          <div class="stack-drawer__body">
            <slot />
          </div>
        </div>

        <footer v-if="$slots.footer" class="stack-drawer-panel__footer">
          <div class="stack-drawer__footer">
            <slot name="footer" />
          </div>
        </footer>
      </div>
    </template>
  </Drawer>
</template>

<style>
/* Panel a filo: sin márgenes del tema PrimeVue */

.p-drawer.stack-drawer,
.p-drawer.stack-drawer--l1,
.p-drawer.stack-drawer--l2,
.p-drawer.stack-drawer--l3,
.p-drawer.stack-drawer--l4 {
  padding: 0 !important;
  border-radius: 0 !important;
  overflow: hidden !important;
  max-width: 100vw !important;
  /* Anula drawer-style de PrimeVue (border-style: solid + border-inline-start-width) */
  border: none !important;
  border-style: none !important;
  border-width: 0 !important;
}

.stack-drawer-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 100%;
  min-height: 0;
  background: var(--p-drawer-background, #fff);
  color: var(--p-drawer-color, inherit);
}

.stack-drawer-panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0;
  padding: 0.85rem 1.1rem;
  border: 0;
  border-radius: 0;
  color: #fff;
  flex-shrink: 0;
}

.stack-drawer-panel__head {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
  flex: 1;
}

.stack-drawer-panel__head strong {
  font-size: 1.05rem;
  line-height: 1.25;
  color: inherit;
}

.stack-drawer-panel__head small {
  font-size: 0.82rem;
  line-height: 1.3;
  opacity: 0.9;
  color: inherit;
}

.stack-drawer-panel__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  margin: 0;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: #fff;
  cursor: pointer;
  flex-shrink: 0;
}

.stack-drawer-panel__close:hover {
  background: rgb(255 255 255 / 0.14);
}

.stack-drawer-panel__content {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 1rem 1.1rem;
}

.stack-drawer__body {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  min-height: 0;
}

.stack-drawer-panel__footer {
  flex-shrink: 0;
  padding: 0.85rem 1.1rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border, #e2e8f0) 80%, transparent);
}

.stack-drawer__footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.45rem;
  flex-wrap: wrap;
}

/* Nivel 1 — Guías Mayores */
.stack-drawer-panel.stack-drawer--l1 .stack-drawer-panel__header {
  background: #1e3a8a;
}
.p-drawer.stack-drawer--l1 {
  box-shadow: -8px 0 28px color-mix(in srgb, #1e3a8a 28%, transparent);
}

/* Nivel 2 — Conquistadores */
.stack-drawer-panel.stack-drawer--l2 .stack-drawer-panel__header {
  background: #b91c1c;
}
.p-drawer.stack-drawer--l2 {
  box-shadow: -8px 0 28px color-mix(in srgb, #b91c1c 28%, transparent);
  z-index: 1201 !important;
}

/* Nivel 3 — Aventureros */
.stack-drawer-panel.stack-drawer--l3 .stack-drawer-panel__header {
  background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 55%, #fff 160%);
  border-bottom: 3px solid #fff;
}
.p-drawer.stack-drawer--l3 {
  box-shadow: -8px 0 28px color-mix(in srgb, #1d4ed8 28%, transparent);
  z-index: 1202 !important;
}

/* Nivel 4 — Jóvenes Adventistas */
.stack-drawer-panel.stack-drawer--l4 .stack-drawer-panel__header {
  background: linear-gradient(90deg, #0ea5e9 0%, #111827 48%, #facc15 100%);
}
.stack-drawer-panel.stack-drawer--l4 .stack-drawer-panel__header small {
  color: #fef9c3;
}
.p-drawer.stack-drawer--l4 {
  box-shadow: -8px 0 28px color-mix(in srgb, #111827 35%, transparent);
  z-index: 1203 !important;
}

@media (max-width: 899px) {
  .p-drawer.stack-drawer,
  .p-drawer.stack-drawer--l1,
  .p-drawer.stack-drawer--l2,
  .p-drawer.stack-drawer--l3,
  .p-drawer.stack-drawer--l4 {
    width: 100vw !important;
  }

  .p-drawer.stack-drawer--bottom {
    width: 100vw !important;
    height: calc(100dvh - 4.75rem - env(safe-area-inset-top, 0px)) !important;
    max-height: calc(100dvh - 4.75rem - env(safe-area-inset-top, 0px)) !important;
    top: auto !important;
    bottom: 0 !important;
    border-radius: 18px 18px 0 0 !important;
    box-shadow: 0 -10px 32px rgb(15 23 42 / 0.22) !important;
  }
}
</style>
