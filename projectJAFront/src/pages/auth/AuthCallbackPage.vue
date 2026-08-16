<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ProgressSpinner from 'primevue/progressspinner'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const error = ref('')

onMounted(async () => {
  const token = String(route.query.token ?? '')
  if (!token) {
    error.value = 'Token no recibido'
    return
  }
  try {
    await auth.acceptToken(token)
    if (auth.requiresContext) {
      await router.replace({ name: 'auth.context' })
    } else {
      await router.replace({ name: 'dashboard' })
    }
  } catch {
    error.value = 'No se pudo completar el inicio de sesión'
  }
})
</script>

<template>
  <div class="callback">
    <ProgressSpinner v-if="!error" />
    <p v-if="error">{{ error }}</p>
  </div>
</template>

<style scoped>
.callback {
  min-height: 40vh;
  display: grid;
  place-items: center;
  color: var(--p-text-muted-color);
}
</style>
