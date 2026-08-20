<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import { useAuthStore } from '@/stores/auth'
import { authService } from '@/services/authService'
import { getApiErrorMessage } from '@/services/api'
import { evaluatePasswordStrength } from '@/utils/passwordStrength'
import { useBrandStore } from '@/stores/brand'
import { clubInscripcionService } from '@/services/clubInscripcionService'
import type { ParticipantRegistrationField } from '@/modules/auth/types'

const { t } = useI18n()
const auth = useAuthStore()
const brand = useBrandStore()
const router = useRouter()
const route = useRoute()
const form = reactive({
  email: '',
  password: '',
  remember: true,
})

const loading = ref(false)
const errorMessage = ref('')
const clubFormEnabled = ref(true)
const mode = ref<'login' | 'register'>('login')
const registrationStep = ref<'identify' | 'otp' | 'complete'>('identify')
const challengeId = ref('')
const verificationToken = ref('')
const missingFields = ref<ParticipantRegistrationField[]>([])
const registrationMessage = ref('')
const registration = reactive({
  tipo_identificacion: 'CC',
  identificacion: '',
  otp: '',
  correo: '',
  telefono: '',
  sexo: '' as '' | 'M' | 'F',
  nombre1: '',
  apellido1: '',
  password: '',
  password_confirmation: '',
})

const needs = (field: ParticipantRegistrationField): boolean => missingFields.value.includes(field)
const registrationTitle = computed(() => {
  if (registrationStep.value === 'identify') return t('auth.registrationTitle')
  if (registrationStep.value === 'otp') return t('auth.registrationOtpTitle')
  return t('auth.registrationCompleteTitle')
})

onMounted(async () => {
  try {
    const options = await clubInscripcionService.options()
    clubFormEnabled.value = options.enabled
  } catch {
    clubFormEnabled.value = true
  }
})

async function submit(): Promise<void> {
  errorMessage.value = ''
  loading.value = true
  try {
    await auth.login({ email: form.email, password: form.password })
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    if (auth.requiresContext) {
      await router.replace({ name: 'auth.context', query: { redirect } })
    } else {
      await router.replace(redirect)
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('auth.invalidCredentials'))
  } finally {
    loading.value = false
  }
}

function switchMode(nextMode: 'login' | 'register'): void {
  mode.value = nextMode
  errorMessage.value = ''
  registrationMessage.value = ''
}

async function startRegistration(): Promise<void> {
  errorMessage.value = ''
  loading.value = true
  try {
    const result = await authService.startParticipantRegistration({
      tipo_identificacion: registration.tipo_identificacion,
      identificacion: registration.identificacion,
    })
    challengeId.value = result.challenge_id
    registrationStep.value = 'otp'
    registrationMessage.value = t('auth.registrationGenericSent')
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('common.error'))
  } finally {
    loading.value = false
  }
}

async function verifyRegistration(): Promise<void> {
  errorMessage.value = ''
  loading.value = true
  try {
    const result = await authService.verifyParticipantRegistration({
      challenge_id: challengeId.value,
      otp: registration.otp,
    })
    verificationToken.value = result.verification_token
    missingFields.value = result.missing_fields
    registrationStep.value = 'complete'
    registrationMessage.value = ''
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('auth.registrationInvalidOtp'))
  } finally {
    loading.value = false
  }
}

async function completeRegistration(): Promise<void> {
  errorMessage.value = ''
  if (registration.password !== registration.password_confirmation) {
    errorMessage.value = t('validation.passwordMatch')
    return
  }
  if (!evaluatePasswordStrength(registration.password).canSave) {
    errorMessage.value = t('validation.passwordIncomplete')
    return
  }
  loading.value = true
  try {
    const result = await authService.completeParticipantRegistration({
      verification_token: verificationToken.value,
      ...(needs('correo') && { correo: registration.correo }),
      ...(needs('telefono') && { telefono: registration.telefono }),
      ...(needs('sexo') && { sexo: registration.sexo as 'M' | 'F' }),
      ...(needs('nombre1') && { nombre1: registration.nombre1 }),
      ...(needs('apellido1') && { apellido1: registration.apellido1 }),
      password: registration.password,
      password_confirmation: registration.password_confirmation,
    })
    await auth.acceptToken(result.token)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.replace(auth.requiresContext ? { name: 'auth.context', query: { redirect } } : redirect)
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('common.error'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form
    v-if="mode === 'login'"
    class="login-form"
    @submit.prevent="submit"
  >
    <header class="login-form__header">
      <img class="login-form__logos" :src="brand.loginLogos" :alt="t('auth.clubsAlt')" />
      <h1>{{ t('auth.welcomeBack') }}</h1>
      <p>{{ t('auth.loginSubtitle') }}</p>
    </header>

    <Message v-if="route.query.reset === '1'" severity="success" :closable="false">
      {{ t('auth.resetSuccess') }}
    </Message>
    <Message v-if="errorMessage" severity="error" :closable="false">
      {{ errorMessage }}
    </Message>

    <div class="pj-field">
      <label for="email">{{ t('auth.email') }}</label>
      <span class="input-shell">
        <i class="pi pi-envelope" aria-hidden="true" />
        <InputText
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="username"
          :placeholder="t('auth.emailPlaceholder')"
          required
          fluid
        />
      </span>
    </div>

    <div class="pj-field">
      <label for="password">{{ t('auth.password') }}</label>
      <span class="input-shell input-shell--password">
        <i class="pi pi-lock" aria-hidden="true" />
        <Password
          id="password"
          v-model="form.password"
          :feedback="false"
          toggle-mask
          autocomplete="current-password"
          :placeholder="t('auth.passwordPlaceholder')"
          required
          fluid
          input-class="w-full"
        />
      </span>
    </div>

    <div class="login-form__meta">
      <label class="remember">
        <Checkbox v-model="form.remember" binary input-id="remember" />
        <span>{{ t('auth.remember') }}</span>
      </label>
      <button type="button" class="forgot" @click="router.push({ name: 'auth.forgot' })">
        {{ t('auth.forgot') }}
      </button>
    </div>

    <Button
      type="submit"
      :label="t('auth.submit')"
      icon="pi pi-arrow-right"
      icon-pos="right"
      :loading="loading"
      class="login-form__submit"
    />

    <div class="login-divider">
      <span>{{ t('auth.orContinue') }}</span>
    </div>

    <div class="oauth-row">
      <Button
        type="button"
        outlined
        severity="secondary"
        class="oauth-btn"
        icon="pi pi-google"
        :label="t('auth.googleContinue')"
      />
      <Button
        type="button"
        class="oauth-btn oauth-btn--facebook"
        icon="pi pi-facebook"
        :label="t('auth.facebookContinue')"
      />
    </div>

    <div class="login-alt-actions">
      <Button
        type="button"
        text
        :label="t('auth.registrationOpen')"
        @click="switchMode('register')"
      />
      <Button
        v-if="clubFormEnabled"
        type="button"
        outlined
        icon="pi pi-flag"
        :label="t('auth.registerClub')"
        @click="router.push({ name: 'club.inscripcion' })"
      />
    </div>
  </form>

  <form
    v-else
    class="login-form"
    @submit.prevent="
      registrationStep === 'identify'
        ? startRegistration()
        : registrationStep === 'otp'
          ? verifyRegistration()
          : completeRegistration()
    "
  >
    <header class="login-form__header">
      <img class="login-form__logos" :src="brand.loginLogos" :alt="t('auth.clubsAlt')" />
      <h1>{{ registrationTitle }}</h1>
      <p>{{ t(`auth.registrationStep${registrationStep}`) }}</p>
    </header>

    <Message v-if="errorMessage" severity="error" :closable="false">
      {{ errorMessage }}
    </Message>
    <Message v-if="registrationMessage" severity="info" :closable="false">
      {{ registrationMessage }}
    </Message>

    <template v-if="registrationStep === 'identify'">
      <div class="pj-field">
        <label for="registration-id-type">{{ t('auth.registrationIdType') }}</label>
        <select
          id="registration-id-type"
          v-model="registration.tipo_identificacion"
          class="registration-select"
          required
        >
          <option value="CC">CC</option>
          <option value="TI">TI</option>
          <option value="CE">CE</option>
          <option value="PASAPORTE">Pasaporte</option>
        </select>
      </div>
      <div class="pj-field">
        <label for="registration-id">{{ t('auth.registrationIdNumber') }}</label>
        <InputText
          id="registration-id"
          v-model="registration.identificacion"
          autocomplete="off"
          required
          fluid
        />
      </div>
    </template>

    <template v-else-if="registrationStep === 'otp'">
      <div class="pj-field">
        <label for="registration-otp">{{ t('auth.registrationOtp') }}</label>
        <InputText
          id="registration-otp"
          v-model="registration.otp"
          inputmode="numeric"
          maxlength="6"
          autocomplete="one-time-code"
          required
          fluid
        />
      </div>
    </template>

    <template v-else>
      <div v-if="needs('nombre1')" class="pj-field">
        <label for="registration-first-name">{{ t('personas.firstName') }}</label>
        <InputText id="registration-first-name" v-model="registration.nombre1" required fluid />
      </div>
      <div v-if="needs('apellido1')" class="pj-field">
        <label for="registration-last-name">{{ t('personas.lastName') }}</label>
        <InputText id="registration-last-name" v-model="registration.apellido1" required fluid />
      </div>
      <div v-if="needs('correo')" class="pj-field">
        <label for="registration-email">{{ t('auth.email') }}</label>
        <InputText id="registration-email" v-model="registration.correo" type="email" required fluid />
      </div>
      <div v-if="needs('telefono')" class="pj-field">
        <label for="registration-phone">{{ t('personas.phone') }}</label>
        <InputText id="registration-phone" v-model="registration.telefono" type="tel" required fluid />
      </div>
      <div v-if="needs('sexo')" class="pj-field">
        <label for="registration-sex">{{ t('personas.sex') }}</label>
        <select id="registration-sex" v-model="registration.sexo" class="registration-select" required>
          <option disabled value="">{{ t('auth.registrationSelectSex') }}</option>
          <option value="F">{{ t('auth.registrationFemale') }}</option>
          <option value="M">{{ t('auth.registrationMale') }}</option>
        </select>
      </div>
      <div class="pj-field">
        <label for="registration-password">{{ t('auth.password') }}</label>
        <Password
          id="registration-password"
          v-model="registration.password"
          toggle-mask
          autocomplete="new-password"
          required
          fluid
          input-class="w-full"
        />
      </div>
      <div class="pj-field">
        <label for="registration-password-confirmation">{{ t('auth.registrationPasswordConfirm') }}</label>
        <Password
          id="registration-password-confirmation"
          v-model="registration.password_confirmation"
          :feedback="false"
          toggle-mask
          autocomplete="new-password"
          required
          fluid
          input-class="w-full"
        />
      </div>
      <small>{{ t('validation.passwordStrong') }}</small>
    </template>

    <Button
      type="submit"
      :label="
        registrationStep === 'identify'
          ? t('auth.registrationSendCode')
          : registrationStep === 'otp'
            ? t('auth.registrationVerifyCode')
            : t('auth.registrationFinish')
      "
      :loading="loading"
      class="login-form__submit"
    />
    <div class="login-alt-actions">
      <Button
        type="button"
        text
        :label="t('auth.registrationBackToLogin')"
        @click="switchMode('login')"
      />
      <Button
        v-if="clubFormEnabled"
        type="button"
        outlined
        icon="pi pi-flag"
        :label="t('auth.registerClub')"
        @click="router.push({ name: 'club.inscripcion' })"
      />
    </div>
  </form>
</template>

<style scoped>
.login-form {
  display: flex;
  flex-direction: column;
  gap: 0.95rem;
}

.login-form__header {
  text-align: center;
  margin-bottom: 0.35rem;
}

.login-form__logos {
  width: min(210px, 70%);
  margin: 0 auto 0.85rem;
  display: block;
}

.login-form__header h1 {
  margin: 0;
  font-family: 'Sora', var(--pj-font-display), sans-serif;
  font-size: 1.45rem;
  color: var(--pj-navy);
}

.login-form__header p {
  margin: 0.35rem 0 0;
  color: #64748b;
  font-size: 0.92rem;
}

.input-shell {
  position: relative;
  display: block;
}

.input-shell > i {
  position: absolute;
  left: 0.9rem;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  z-index: 2;
  pointer-events: none;
}

.input-shell :deep(input),
.input-shell :deep(.p-inputtext),
.input-shell :deep(.p-password-input) {
  padding-left: 2.5rem !important;
  border-radius: 0.75rem !important;
}

.input-shell--password :deep(.p-password) {
  width: 100%;
}

.login-form__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  font-size: 0.85rem;
}

.remember {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  color: #475569;
  cursor: pointer;
}

.forgot {
  color: var(--pj-navy);
  opacity: 0.85;
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
  font: inherit;
}

.login-form__submit {
  width: 100%;
  margin-top: 0.15rem;
  background: var(--pj-navy) !important;
  border-color: var(--pj-navy) !important;
  border-radius: 0.8rem !important;
  font-weight: 600;
}

.login-divider {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 0.75rem;
  color: #94a3b8;
  font-size: 0.82rem;
}

.login-divider::before,
.login-divider::after {
  content: '';
  height: 1px;
  background: #e2e8f0;
}

.login-alt-actions {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.login-alt-actions :deep(.p-button) {
  justify-content: center;
}

.oauth-row {
  display: grid;
  gap: 0.65rem;
}

.oauth-btn {
  width: 100%;
  justify-content: center;
  border-radius: 0.8rem !important;
}

.oauth-btn--facebook {
  background: #1877f2 !important;
  border-color: #1877f2 !important;
}

.registration-select {
  width: 100%;
  min-height: 2.75rem;
  padding: 0.65rem 0.8rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.75rem;
  background: var(--p-inputtext-background, #fff);
  color: inherit;
}
</style>
