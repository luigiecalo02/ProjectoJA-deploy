<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { accountMailService } from '@/services/accountMailService'
import { getApiErrorMessage } from '@/services/api'

const { t } = useI18n()
const router = useRouter()
const lookupMode = ref<'email' | 'id'>('email')
const email = ref('')
const identificacion = ref('')
const loading = ref(false)
const sentEmail = ref('')
const errorMessage = ref('')

async function submit(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  sentEmail.value = ''
  try {
    const result = await accountMailService.forgot(
      lookupMode.value === 'email'
        ? { email: email.value.trim() }
        : { identificacion: identificacion.value.trim() },
    )
    sentEmail.value = result.email
    email.value = result.email
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="auth-simple" @submit.prevent="submit">
    <h1>{{ t('auth.forgotTitle') }}</h1>
    <p>{{ t('auth.forgotSubtitle') }}</p>
    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>
    <Message v-if="sentEmail" severity="success" :closable="false">
      {{ t('auth.forgotSentTo', { email: sentEmail }) }}
    </Message>
    <div class="lookup-toggle" role="group">
      <button
        type="button"
        :class="{ 'is-active': lookupMode === 'email' }"
        @click="lookupMode = 'email'"
      >
        {{ t('auth.verifyByEmail') }}
      </button>
      <button
        type="button"
        :class="{ 'is-active': lookupMode === 'id' }"
        @click="lookupMode = 'id'"
      >
        {{ t('auth.verifyById') }}
      </button>
    </div>
    <template v-if="lookupMode === 'email'">
      <label>{{ t('auth.email') }}</label>
      <InputText v-model="email" type="email" fluid required />
    </template>
    <template v-else>
      <label>{{ t('auth.registrationIdNumber') }}</label>
      <InputText v-model="identificacion" fluid required />
    </template>
    <Button type="submit" :label="t('auth.forgotSubmit')" :loading="loading" />
    <Button type="button" text :label="t('clubInscripcion.backLogin')" @click="router.push({ name: 'login' })" />
  </form>
</template>

<style scoped>
.auth-simple { display: flex; flex-direction: column; gap: 0.7rem; }
.auth-simple h1 { margin: 0; font-size: 1.35rem; }
.auth-simple p { margin: 0; color: var(--pj-text-muted); }
.lookup-toggle { display: flex; gap: 0.4rem; }
.lookup-toggle button {
  flex: 1;
  border: 1px solid var(--pj-border, #d0d5dd);
  background: transparent;
  border-radius: 8px;
  padding: 0.45rem 0.6rem;
  cursor: pointer;
}
.lookup-toggle button.is-active {
  border-color: var(--pj-primary, #2563eb);
  color: var(--pj-primary, #2563eb);
  font-weight: 600;
}
</style>
