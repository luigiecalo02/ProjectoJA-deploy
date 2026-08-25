<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import PageLoader from '@/components/PageLoader.vue'
import BrandSettingsPage from '@/pages/settings/BrandSettingsPage.vue'
import CuentasBancariasSettingsPage from '@/pages/settings/CuentasBancariasSettingsPage.vue'
import { usePermission } from '@/composables/usePermission'
import { getApiErrorMessage } from '@/services/api'
import { mailSettingsService } from '@/services/mailSettingsService'
import { publicFormSettingsService } from '@/services/publicFormSettingsService'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const { can } = usePermission()
const canUpdate = computed(() => can('settings.update'))

const allowedTabs = ['correo', 'formulario', 'cuentas', 'apariencia'] as const
const tab = ref(allowedTabs.includes(route.query.tab as (typeof allowedTabs)[number]) ? String(route.query.tab) : 'correo')

watch(tab, (value) => {
  if (route.query.tab !== value) {
    void router.replace({ query: { ...route.query, tab: value } })
  }
})

const loading = ref(true)
const saving = ref(false)
const savingForm = ref(false)
const testing = ref(false)
const publicForm = reactive({
  enabled: true,
  allow_request_asociacion: true,
  allow_request_distrito: true,
  allow_request_iglesia: true,
  allow_request_club: true,
})
const mail = reactive({
  host: '',
  port: 587,
  encryption: 'tls' as 'tls' | 'ssl' | 'none',
  username: '',
  password: '',
  from_address: '',
  from_name: '',
  password_set: false,
  configured: false,
})
const testTo = ref('')

const encryptionOptions = [
  { label: 'TLS', value: 'tls' },
  { label: 'SSL', value: 'ssl' },
  { label: 'Ninguno', value: 'none' },
]

onMounted(async () => {
  try {
    const [next, formFlags] = await Promise.all([
      mailSettingsService.get(),
      publicFormSettingsService.get(),
    ])
    Object.assign(mail, next)
    Object.assign(publicForm, formFlags)
    if (!testTo.value) testTo.value = next.from_address
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
})

async function saveMail(): Promise<void> {
  saving.value = true
  try {
    const next = await mailSettingsService.update({
      host: mail.host,
      port: mail.port,
      encryption: mail.encryption,
      username: mail.username,
      password: mail.password || undefined,
      from_address: mail.from_address,
      from_name: mail.from_name,
    })
    Object.assign(mail, next)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('settings.mailSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function savePublicForm(): Promise<void> {
  savingForm.value = true
  try {
    const next = await publicFormSettingsService.update({ ...publicForm })
    Object.assign(publicForm, next)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('settings.publicFormSaved'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    savingForm.value = false
  }
}

async function sendTest(): Promise<void> {
  testing.value = true
  try {
    await mailSettingsService.test(testTo.value)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('settings.mailTestSent'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('settings.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('settings.subtitle') }}</p>
      </div>
    </header>

    <Tabs v-model:value="tab">
      <TabList>
        <Tab value="correo">{{ t('settings.tabMail') }}</Tab>
        <Tab value="formulario">{{ t('settings.tabPublicForm') }}</Tab>
        <Tab value="cuentas">{{ t('settings.tabBankAccounts') }}</Tab>
        <Tab value="apariencia">{{ t('settings.tabAppearance') }}</Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="correo">
          <PageLoader v-if="loading" :label="t('common.loading')" />
          <form v-else class="mail-form" @submit.prevent="saveMail">
            <Message v-if="!mail.configured" severity="warn" :closable="false">
              {{ t('settings.mailNotConfigured') }}
            </Message>
            <h2>{{ t('settings.mailTitle') }}</h2>
            <p class="pj-muted">{{ t('settings.mailSubtitle') }}</p>
            <div class="grid-2">
              <div class="field">
                <label>{{ t('settings.mailHost') }}</label>
                <InputText v-model="mail.host" fluid :disabled="!canUpdate" />
              </div>
              <div class="field">
                <label>{{ t('settings.mailPort') }}</label>
                <InputNumber v-model="mail.port" fluid :min="1" :max="65535" :disabled="!canUpdate" />
              </div>
            </div>
            <div class="grid-2">
              <div class="field">
                <label>{{ t('settings.mailEncryption') }}</label>
                <Select v-model="mail.encryption" :options="encryptionOptions" option-label="label" option-value="value" fluid :disabled="!canUpdate" />
              </div>
              <div class="field">
                <label>{{ t('settings.mailUsername') }}</label>
                <InputText v-model="mail.username" fluid :disabled="!canUpdate" />
              </div>
            </div>
            <div class="field">
              <label>{{ t('settings.mailPassword') }}</label>
              <Password v-model="mail.password" :feedback="false" toggle-mask fluid :disabled="!canUpdate" />
              <small v-if="mail.password_set" class="pj-muted">{{ t('settings.mailPasswordSet') }}</small>
            </div>
            <div class="grid-2">
              <div class="field">
                <label>{{ t('settings.mailFromAddress') }}</label>
                <InputText v-model="mail.from_address" type="email" fluid :disabled="!canUpdate" />
              </div>
              <div class="field">
                <label>{{ t('settings.mailFromName') }}</label>
                <InputText v-model="mail.from_name" fluid :disabled="!canUpdate" />
              </div>
            </div>
            <div class="mail-actions" v-if="canUpdate">
              <Button type="submit" :label="t('settings.mailSave')" :loading="saving" />
              <InputText v-model="testTo" :placeholder="t('settings.mailTestTo')" />
              <Button type="button" outlined :label="t('settings.mailTest')" :loading="testing" @click="sendTest" />
            </div>
          </form>
        </TabPanel>
        <TabPanel value="formulario">
          <form class="mail-form" @submit.prevent="savePublicForm">
            <h2>{{ t('settings.publicFormTitle') }}</h2>
            <p class="pj-muted">{{ t('settings.publicFormSubtitle') }}</p>
            <label class="check check--main">
              <Checkbox v-model="publicForm.enabled" binary :disabled="!canUpdate" />
              <span>{{ t('settings.publicFormEnabled') }}</span>
            </label>
            <Message v-if="!publicForm.enabled" severity="warn" :closable="false">
              {{ t('settings.publicFormDisabledHint') }}
            </Message>
            <label class="check">
              <Checkbox v-model="publicForm.allow_request_asociacion" binary :disabled="!canUpdate || !publicForm.enabled" />
              <span>{{ t('settings.allowRequestAsociacion') }}</span>
            </label>
            <label class="check">
              <Checkbox v-model="publicForm.allow_request_distrito" binary :disabled="!canUpdate || !publicForm.enabled" />
              <span>{{ t('settings.allowRequestDistrito') }}</span>
            </label>
            <label class="check">
              <Checkbox v-model="publicForm.allow_request_iglesia" binary :disabled="!canUpdate || !publicForm.enabled" />
              <span>{{ t('settings.allowRequestIglesia') }}</span>
            </label>
            <label class="check">
              <Checkbox v-model="publicForm.allow_request_club" binary :disabled="!canUpdate || !publicForm.enabled" />
              <span>{{ t('settings.allowRequestClub') }}</span>
            </label>
            <div v-if="canUpdate">
              <Button type="submit" :label="t('settings.publicFormSave')" :loading="savingForm" />
            </div>
          </form>
        </TabPanel>
        <TabPanel value="cuentas">
          <CuentasBancariasSettingsPage />
        </TabPanel>
        <TabPanel value="apariencia">
          <BrandSettingsPage />
        </TabPanel>
      </TabPanels>
    </Tabs>
  </section>
</template>

<style scoped>
.mail-form { display: flex; flex-direction: column; gap: 0.75rem; padding: 0.5rem 0 1rem; }
.mail-form h2 { margin: 0; font-size: 1.15rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-weight: 600; font-size: 0.82rem; }
.field :deep(.p-password) { width: 100%; }
.mail-actions { display: flex; flex-wrap: wrap; gap: 0.55rem; align-items: center; }
.check { display: flex; align-items: center; gap: 0.55rem; font-weight: 600; }
.check--main { font-size: 1rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--pj-border); margin-bottom: 0.25rem; }
.pj-muted { color: var(--pj-text-muted); font-size: 0.85rem; }
@media (max-width: 700px) {
  .grid-2 { grid-template-columns: 1fr; }
}
</style>
