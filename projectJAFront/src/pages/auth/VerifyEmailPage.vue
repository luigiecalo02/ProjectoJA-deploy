<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { accountMailService } from '@/services/accountMailService'
import { getApiErrorMessage } from '@/services/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const loading = ref(false)
const sending = ref(false)
const errorMessage = ref('')
const infoMessage = ref('')
const ok = ref(false)
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const code = ref('')

onMounted(async () => {
  const id = Number(route.query.id)
  const hash = String(route.query.hash || '')
  if (!id || !hash) {
    return
  }
  loading.value = true
  try {
    await accountMailService.verify(id, hash)
    ok.value = true
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('auth.verifyError'))
  } finally {
    loading.value = false
  }
})

async function submitCode(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    await accountMailService.verifyCode(email.value.trim(), code.value.trim())
    ok.value = true
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('auth.verifyCodeError'))
  } finally {
    loading.value = false
  }
}

async function resend(): Promise<void> {
  if (!email.value.trim()) {
    errorMessage.value = t('auth.verifyEmailRequired')
    return
  }
  sending.value = true
  errorMessage.value = ''
  try {
    const result = await accountMailService.recover({ email: email.value.trim() })
    email.value = result.email
    infoMessage.value = result.already_verified
      ? t('auth.verifyAlreadyDone')
      : t('auth.verifySentTo', { email: result.email })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <form class="auth-simple" @submit.prevent="submitCode">
    <h1>{{ t('auth.verifyTitle') }}</h1>
    <p v-if="loading && !email">{{ t('auth.verifyWorking') }}</p>
    <p v-else-if="!ok">{{ t('auth.verifyCodeOnlySubtitle') }}</p>
    <Message v-if="ok" severity="success" :closable="false">{{ t('auth.verifySuccess') }}</Message>
    <Message v-else-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>
    <Message v-if="infoMessage && !ok" severity="info" :closable="false">{{ infoMessage }}</Message>
    <template v-if="!ok">
      <label>{{ t('auth.email') }}</label>
      <InputText v-model="email" type="email" fluid required />
      <label>{{ t('auth.verifyCode') }}</label>
      <InputText
        v-model="code"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="6"
        fluid
        required
        :placeholder="t('auth.verifyCodePlaceholder')"
        @update:model-value="code = String($event ?? '').replace(/\D/g, '').slice(0, 6)"
      />
      <Button type="submit" :label="t('auth.verifySubmit')" :loading="loading" />
      <Button type="button" text :label="t('auth.verifyResend')" :loading="sending" @click="resend" />
    </template>
    <Button type="button" text :label="t('clubInscripcion.backLogin')" @click="router.push({ name: 'login' })" />
  </form>
</template>

<style scoped>
.auth-simple { display: flex; flex-direction: column; gap: 0.7rem; }
.auth-simple h1 { margin: 0; font-size: 1.35rem; }
.auth-simple p { margin: 0; color: var(--pj-text-muted); }
</style>
