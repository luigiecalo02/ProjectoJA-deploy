<script setup lang="ts">
import { onMounted } from 'vue'

onMounted(() => {
  window.location.replace(`/api/docs${window.location.hash || ''}`)
})
</script>

<template>
  <p class="pj-muted" style="padding: 1.5rem">Abriendo documentación…</p>
</template>
