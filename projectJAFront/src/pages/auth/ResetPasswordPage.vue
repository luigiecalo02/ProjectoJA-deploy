<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Password from 'primevue/password'
import Message from 'primevue/message'
import { accountMailService } from '@/services/accountMailService'
import { getApiErrorMessage } from '@/services/api'
import { evaluatePasswordStrength, PASSWORD_MAX_LENGTH } from '@/utils/passwordStrength'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const errorMessage = ref('')
const token = computed(() => String(route.query.token || ''))
const email = computed(() => String(route.query.email || ''))
const strength = computed(() => evaluatePasswordStrength(password.value))

async function submit(): Promise<void> {
  if (!strength.value.canSave || password.value !== passwordConfirmation.value) {
    errorMessage.value = t('validation.passwordIncomplete')
    return
  }
  loading.value = true
  errorMessage.value = ''
  try {
    await accountMailService.reset({
      email: email.value,
      token: token.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    await router.replace({ name: 'login', query: { reset: '1' } })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-simple" @submit.prevent="submit">
    <h1>{{ t('auth.resetTitle') }}</h1>
    <p>{{ t('auth.resetSubtitle') }}</p>
    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>
    <label>{{ t('auth.password') }}</label>
    <Password v-model="password" :feedback="false" toggle-mask fluid :maxlength="PASSWORD_MAX_LENGTH" />
    <label>{{ t('clubInscripcion.passwordConfirm') }}</label>
    <Password v-model="passwordConfirmation" :feedback="false" toggle-mask fluid />
    <small>{{ t('validation.passwordStrong') }}</small>
    <Button type="submit" :label="t('auth.resetSubmit')" :loading="loading" />
  </form>
</template>

<style scoped>
.auth-simple { display: flex; flex-direction: column; gap: 0.7rem; }
.auth-simple h1 { margin: 0; font-size: 1.35rem; }
.auth-simple p { margin: 0; color: var(--pj-text-muted); }
</style>
