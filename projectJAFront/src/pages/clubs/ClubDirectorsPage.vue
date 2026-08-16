<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

onMounted(() => {
  void router.replace({ name: 'clubs.edit', params: { id: route.params.id } })
})
</script>

<template>
  <div />
</template>
