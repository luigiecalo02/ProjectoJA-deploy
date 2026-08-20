<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import ClubsListPage from '@/pages/clubs/ClubsListPage.vue'
import ClubFormPage from '@/pages/clubs/ClubFormPage.vue'

const auth = useAuthStore()
const isClubSession = computed(() => Boolean(auth.contexto?.is_club))
</script>

<template>
  <ClubFormPage v-if="isClubSession" />
  <ClubsListPage v-else />
</template>
