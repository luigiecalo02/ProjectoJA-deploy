<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import { useAuthStore } from '@/stores/auth'
import { getApiErrorMessage } from '@/services/api'
import type { AuthContextOption } from '@/modules/auth/types'
import { brandConfig } from '@/config/brand'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const loading = ref(false)
const selectingKey = ref<string | null>(null)
const errorMessage = ref('')

const options = computed(() => {
  const fromUser = auth.contextOptions
  return fromUser.length ? fromUser : []
})

const redirectTarget = computed(() =>
  typeof route.query.redirect === 'string' ? route.query.redirect : '/',
)

function cardStyle(option: AuthContextOption): Record<string, string> | undefined {
  if (!option.is_club) return undefined
  const primary = option.color_principal?.trim()
  if (!primary) return undefined
  const secondary = option.color_secundario?.trim() || primary
  return {
    '--card-accent': primary,
    '--card-accent-2': secondary,
  }
}

function cardClasses(option: AuthContextOption): string[] {
  const classes = [`context-card--${option.theme || 'slate'}`]
  if (option.is_club && option.color_principal) {
    classes.push('context-card--club-colors')
  }
  if (option.is_club) {
    classes.push('context-card--club')
  }
  return classes
}

async function enter(option: AuthContextOption): Promise<void> {
  selectingKey.value = option.key
  errorMessage.value = ''
  try {
    await auth.selectContext(option)
    await router.replace(redirectTarget.value)
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    selectingKey.value = null
  }
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'login' })
}

onMounted(async () => {
  loading.value = true
  try {
    await auth.fetchMe()
    if (!auth.requiresContext && auth.contexto) {
      await router.replace(redirectTarget.value)
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="context-page">
    <header class="context-page__header">
      <img class="context-page__icon" :src="brandConfig.appIcon" :alt="t('app.name')" />
      <h1>{{ t('auth.selectContextTitle') }}</h1>
      <p>{{ t('auth.selectContextSubtitle') }}</p>
      <div class="context-page__user">
        <span>{{ auth.user?.name }}</span>
        <button type="button" class="link-btn" @click="logout">{{ t('nav.logout') }}</button>
      </div>
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <template v-else>
      <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

      <p v-if="!options.length" class="pj-muted empty">{{ t('auth.selectContextEmpty') }}</p>

      <div v-else class="context-grid">
        <article
          v-for="option in options"
          :key="option.key"
          class="context-card"
          :class="cardClasses(option)"
          :style="cardStyle(option)"
        >
          <div class="context-card__head">
            <span
              v-if="option.is_club && option.club_logo_url"
              class="context-card__logo"
              aria-hidden="true"
            >
              <img :src="option.club_logo_url" :alt="option.organizacion_nombre" />
            </span>
            <span v-else class="context-card__icon" aria-hidden="true">
              <i :class="option.icon || 'pi pi-sitemap'" />
            </span>
            <div>
              <h2>{{ option.organizacion_nombre }}</h2>
              <strong>{{ option.rol_display_name }}</strong>
            </div>
          </div>
          <p class="context-card__desc">{{ option.descripcion }}</p>
          <Button
            :label="t('auth.selectContextEnter')"
            icon="pi pi-arrow-right"
            icon-pos="right"
            class="context-card__btn"
            :loading="selectingKey === option.key"
            :disabled="Boolean(selectingKey) && selectingKey !== option.key"
            @click="enter(option)"
          />
        </article>
      </div>
    </template>
  </section>
</template>

<style scoped>
.context-page {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  width: min(1080px, 100%);
  margin: 0 auto;
  padding: 0.5rem 0 1.5rem;
}

.context-page__header {
  text-align: center;
}

.context-page__icon {
  width: 64px;
  height: 64px;
  object-fit: contain;
  margin: 0 auto 0.75rem;
  display: block;
}

.context-page__header h1 {
  margin: 0;
  font-family: 'Sora', var(--pj-font-display), sans-serif;
  font-size: clamp(1.25rem, 2.4vw, 1.65rem);
  color: var(--pj-navy);
  line-height: 1.3;
}

.context-page__header p {
  margin: 0.45rem 0 0;
  color: #64748b;
  font-size: 0.95rem;
}

.context-page__user {
  margin-top: 0.75rem;
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  color: #475569;
  font-size: 0.88rem;
}

.link-btn {
  border: 0;
  background: transparent;
  color: var(--pj-navy);
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}

.empty {
  text-align: center;
}

.context-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 1rem;
}

.context-card {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1.1rem;
  border-radius: 1rem;
  background: #fff;
  border: 1px solid color-mix(in srgb, var(--card-accent, #64748b) 28%, #e2e8f0);
  box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
  min-height: 240px;
}

.context-card__head {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.context-card__icon,
.context-card__logo {
  width: 3rem;
  height: 3rem;
  border-radius: 999px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  overflow: hidden;
  background: color-mix(in srgb, var(--card-accent, #64748b) 16%, white);
  color: var(--card-accent, #64748b);
  font-size: 1.1rem;
  border: 2px solid color-mix(in srgb, var(--card-accent, #64748b) 35%, white);
}

.context-card__logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.context-card__head h2 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
  line-height: 1.25;
}

.context-card__head strong {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.92rem;
  color: var(--card-accent, #334155);
}

.context-card__desc {
  margin: 0;
  flex: 1;
  color: #64748b;
  font-size: 0.88rem;
  line-height: 1.45;
}

.context-card__btn {
  width: 100%;
  border: 0 !important;
  background: var(--card-accent, #0f2f6b) !important;
  border-radius: 0.75rem !important;
  font-weight: 600;
}

.context-card--club-colors .context-card__btn {
  background: linear-gradient(
    135deg,
    var(--card-accent) 0%,
    var(--card-accent-2, var(--card-accent)) 100%
  ) !important;
}

.context-card--navy { --card-accent: #0f2f6b; }
.context-card--indigo { --card-accent: #4338ca; }
.context-card--green { --card-accent: #15803d; }
.context-card--cyan { --card-accent: #0e7490; }
.context-card--blue { --card-accent: #1d4ed8; }
.context-card--orange { --card-accent: #c2410c; }
.context-card--amber { --card-accent: #b45309; }
.context-card--teal { --card-accent: #0f766e; }
.context-card--rose { --card-accent: #be123c; }
.context-card--slate { --card-accent: #475569; }

@media (max-width: 640px) {
  .context-grid {
    grid-template-columns: 1fr;
  }
}
</style>
