<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import LoginHeroFitEditor from '@/components/settings/LoginHeroFitEditor.vue'
import {
  LOGIN_HERO_ICONS,
  normalizeLoginHeroCopy,
  normalizeLoginHeroVariant,
  type LoginHeroCopy,
  type LoginHeroDevice,
  type LoginHeroVariant,
} from '@/modules/settings/loginHeroCopy'
import { loginHeroFitVars } from '@/modules/settings/loginHeroFit'

const props = defineProps<{
  imageUrl: string
  copy: LoginHeroCopy
  canUpdate: boolean
  saving?: boolean
}>()

const emit = defineEmits<{
  save: [copy: LoginHeroCopy]
}>()

const { t } = useI18n()
const device = ref<LoginHeroDevice>('desktop')
const draft = reactive<LoginHeroCopy>(normalizeLoginHeroCopy(props.copy))

watch(
  () => props.copy,
  (next) => Object.assign(draft, normalizeLoginHeroCopy(next)),
)

const current = computed<LoginHeroVariant>(() => draft[device.value])
const iconOptions = LOGIN_HERO_ICONS.map((icon) => ({
  value: icon,
  label: icon.replace('pi pi-', ''),
}))

function setDevice(next: LoginHeroDevice): void {
  device.value = next
}

function copyFromOther(): void {
  const source = device.value === 'desktop' ? draft.mobile : draft.desktop
  draft[device.value] = normalizeLoginHeroVariant(source)
}

function save(): void {
  emit('save', normalizeLoginHeroCopy(draft))
}
</script>

<template>
  <div class="hero-studio">
    <div class="hero-studio__tabs" role="tablist">
      <button
        type="button"
        class="hero-studio__tab"
        :class="{ 'is-active': device === 'desktop' }"
        @click="setDevice('desktop')"
      >
        <i class="pi pi-desktop" aria-hidden="true" />
        {{ t('settings.heroDeviceDesktop') }}
      </button>
      <button
        type="button"
        class="hero-studio__tab"
        :class="{ 'is-active': device === 'mobile' }"
        @click="setDevice('mobile')"
      >
        <i class="pi pi-mobile" aria-hidden="true" />
        {{ t('settings.heroDeviceMobile') }}
      </button>
    </div>

    <div class="hero-studio__preview" :class="`is-${device}`">
      <div class="hero-studio__frame" :style="loginHeroFitVars(current.fit)">
        <LoginHeroFitEditor
          :key="device"
          embedded
          :image-url="imageUrl"
          :fit="draft[device].fit"
          :can-update="canUpdate"
          @update:fit="draft[device].fit = $event"
        />
        <div class="hero-studio__copy">
          <h3>
            <span>{{ current.line1 }}</span>
            <em>{{ current.line2 }}</em>
          </h3>
          <p>{{ current.subtitle }}</p>
          <ul>
            <li v-for="(item, index) in current.features" :key="index">
              <span class="hero-studio__icon"><i :class="item.icon" /></span>
              <div>
                <strong>{{ item.title }}</strong>
                <small>{{ item.desc }}</small>
              </div>
            </li>
          </ul>
        </div>
      </div>
      <p class="hero-studio__hint">{{ t('settings.heroFitDrag') }}</p>
    </div>

    <div class="hero-studio__fields">
      <label>
        <span>{{ t('settings.heroLine1') }}</span>
        <InputText v-model="draft[device].line1" fluid :disabled="!canUpdate" maxlength="80" />
      </label>
      <label>
        <span>{{ t('settings.heroLine2') }}</span>
        <InputText v-model="draft[device].line2" fluid :disabled="!canUpdate" maxlength="80" />
      </label>
      <label class="hero-studio__full">
        <span>{{ t('settings.heroSubtitle') }}</span>
        <Textarea v-model="draft[device].subtitle" fluid rows="3" :disabled="!canUpdate" maxlength="240" />
      </label>
    </div>

    <div class="hero-studio__features">
      <article v-for="(item, index) in draft[device].features" :key="`${device}-${index}`" class="hero-studio__feature">
        <header>
          <strong>{{ t('settings.heroFeature', { n: index + 1 }) }}</strong>
          <span class="hero-studio__icon"><i :class="item.icon" /></span>
        </header>
        <label>
          <span>{{ t('settings.heroFeatureIcon') }}</span>
          <Select
            v-model="item.icon"
            :options="iconOptions"
            option-label="label"
            option-value="value"
            fluid
            :disabled="!canUpdate"
          >
            <template #value="slotProps">
              <span class="hero-studio__option">
                <i :class="slotProps.value" />
                {{ String(slotProps.value || '').replace('pi pi-', '') }}
              </span>
            </template>
            <template #option="slotProps">
              <span class="hero-studio__option">
                <i :class="slotProps.option.value" />
                {{ slotProps.option.label }}
              </span>
            </template>
          </Select>
        </label>
        <label>
          <span>{{ t('settings.heroFeatureTitle') }}</span>
          <InputText v-model="item.title" fluid :disabled="!canUpdate" maxlength="60" />
        </label>
        <label>
          <span>{{ t('settings.heroFeatureDesc') }}</span>
          <Textarea v-model="item.desc" fluid rows="2" :disabled="!canUpdate" maxlength="160" />
        </label>
      </article>
    </div>

    <div class="hero-studio__actions">
      <Button
        type="button"
        text
        :label="
          device === 'desktop' ? t('settings.heroCopyFromMobile') : t('settings.heroCopyFromDesktop')
        "
        :disabled="!canUpdate || saving"
        @click="copyFromOther"
      />
      <Button
        type="button"
        icon="pi pi-check"
        :label="t('settings.heroFitSave')"
        :loading="saving"
        :disabled="!canUpdate || saving"
        @click="save"
      />
    </div>
  </div>
</template>

<style scoped>
.hero-studio {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.hero-studio__tabs {
  display: flex;
  gap: 0.45rem;
}

.hero-studio__tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 999px;
  padding: 0.45rem 0.9rem;
  font-size: 0.84rem;
  font-weight: 700;
  color: #475569;
  cursor: pointer;
}

.hero-studio__tab.is-active {
  background: var(--pj-navy);
  border-color: var(--pj-navy);
  color: #fff;
}

.hero-studio__preview {
  display: grid;
  justify-items: center;
  padding: 0.4rem 0;
}

.hero-studio__frame {
  width: 100%;
  overflow: hidden;
  border-radius: 1rem;
  position: relative;
}

.hero-studio__preview.is-mobile .hero-studio__frame {
  width: min(100%, 390px);
}

.hero-studio__preview.is-mobile :deep(.hero-fit__stage) {
  aspect-ratio: 9 / 12;
  min-height: 280px;
}

.hero-studio__hint {
  margin: 0.55rem 0 0;
  text-align: center;
  color: #64748b;
  font-size: 0.78rem;
  font-weight: 600;
}

.hero-studio__copy {
  position: absolute;
  inset: auto 1rem 1rem;
  z-index: 3;
  color: #fff;
  pointer-events: none;
}

.hero-studio__copy h3 {
  margin: 0;
  display: flex;
  flex-direction: column;
  font-size: 1.25rem;
  line-height: 1.05;
}

.hero-studio__copy em {
  font-family: 'Dancing Script', cursive;
  font-style: normal;
  color: var(--pj-gold, #ffcc00);
}

.hero-studio__copy p {
  margin: 0.45rem 0 0.7rem;
  font-size: 0.78rem;
  color: rgba(255, 255, 255, 0.88);
}

.hero-studio__copy ul {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.45rem;
}

.hero-studio__preview.is-desktop .hero-studio__copy ul {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.hero-studio__copy li,
.hero-studio__option {
  display: flex;
  gap: 0.4rem;
  align-items: flex-start;
}

.hero-studio__icon {
  width: 1.7rem;
  height: 1.7rem;
  border-radius: 999px;
  border: 1px solid var(--pj-gold, #ffcc00);
  display: grid;
  place-items: center;
  color: var(--pj-gold, #ffcc00);
  flex-shrink: 0;
}

.hero-studio__copy strong,
.hero-studio__copy small {
  display: block;
}

.hero-studio__copy small {
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.7rem;
}

.hero-studio__fields,
.hero-studio__feature {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.7rem;
}

.hero-studio__full,
.hero-studio__feature {
  grid-column: 1 / -1;
}

.hero-studio__feature {
  padding: 0.85rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.85rem;
  background: #f8fafc;
}

.hero-studio__feature header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.hero-studio__fields label,
.hero-studio__feature label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.8rem;
  font-weight: 700;
  color: #334155;
}

.hero-studio__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.45rem;
}

@media (max-width: 720px) {
  .hero-studio__fields,
  .hero-studio__feature {
    grid-template-columns: 1fr;
  }
}
</style>
