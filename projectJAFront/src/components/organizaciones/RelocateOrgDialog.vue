<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { TIPO_ASOCIACION, TIPO_DISTRITO, TIPO_IGLESIA } from '@/modules/organizaciones/types'
import type { OrganizacionParentOption, OrganizacionTreeNode } from '@/modules/organizaciones/types'
import { organizacionesService } from '@/services/organizacionesService'
import { getApiErrorMessage } from '@/services/api'

const props = defineProps<{
  visible: boolean
  node: OrganizacionTreeNode | null
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  relocated: [orgId: number]
}>()

const { t } = useI18n()
const saving = ref(false)
const errorMessage = ref('')
const asociaciones = ref<OrganizacionParentOption[]>([])
const distritos = ref<OrganizacionParentOption[]>([])
const iglesias = ref<OrganizacionParentOption[]>([])
const clubes = ref<Array<{ id: number; nombre: string }>>([])
const form = ref({
  asociacion_id: null as number | null,
  distrito_id: null as number | null,
  iglesia_id: null as number | null,
  club_id: null as number | null,
  observacion: '',
})

watch(
  () => props.visible,
  async (open) => {
    if (!open) return
    errorMessage.value = ''
    form.value = { asociacion_id: null, distrito_id: null, iglesia_id: null, club_id: null, observacion: '' }
    distritos.value = []
    iglesias.value = []
    clubes.value = []
    asociaciones.value = await organizacionesService.approvedOptions(TIPO_ASOCIACION)
  },
)

watch(
  () => form.value.asociacion_id,
  async (id) => {
    form.value.distrito_id = null
    form.value.iglesia_id = null
    form.value.club_id = null
    iglesias.value = []
    clubes.value = []
    distritos.value = id ? await organizacionesService.approvedOptions(TIPO_DISTRITO, id) : []
  },
)

watch(
  () => form.value.distrito_id,
  async (id) => {
    form.value.iglesia_id = null
    form.value.club_id = null
    clubes.value = []
    iglesias.value = id ? await organizacionesService.approvedOptions(TIPO_IGLESIA, id) : []
  },
)

watch(
  () => form.value.iglesia_id,
  async (id) => {
    form.value.club_id = null
    clubes.value = id ? await organizacionesService.approvedClubs(id) : []
  },
)

async function submit(): Promise<void> {
  if (!props.node || !form.value.asociacion_id || !form.value.distrito_id || !form.value.iglesia_id) {
    errorMessage.value = t('organizaciones.relocateRequired')
    return
  }
  saving.value = true
  errorMessage.value = ''
  try {
    await organizacionesService.reubicar(props.node.id, {
      asociacion_id: form.value.asociacion_id,
      distrito_id: form.value.distrito_id,
      iglesia_id: form.value.iglesia_id,
      club_id: form.value.club_id,
      observacion: form.value.observacion.trim() || undefined,
    })
    emit('relocated', props.node.id)
    emit('update:visible', false)
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('organizaciones.relocateTitle')"
    :style="{ width: 'min(92vw, 28rem)' }"
    @update:visible="emit('update:visible', $event)"
  >
    <p class="pj-muted">{{ t('organizaciones.relocateHint') }}</p>
    <p v-if="errorMessage" class="relocate-error">{{ errorMessage }}</p>
    <div class="relocate-fields">
      <label>{{ t('clubInscripcion.asociacion') }}</label>
      <Select
        v-model="form.asociacion_id"
        :options="asociaciones"
        option-label="nombre"
        option-value="id"
        filter
        fluid
      />
      <label>{{ t('clubInscripcion.distrito') }}</label>
      <Select
        v-model="form.distrito_id"
        :options="distritos"
        option-label="nombre"
        option-value="id"
        filter
        fluid
        :disabled="!form.asociacion_id"
      />
      <label>{{ t('clubInscripcion.iglesia') }}</label>
      <Select
        v-model="form.iglesia_id"
        :options="iglesias"
        option-label="nombre"
        option-value="id"
        filter
        fluid
        :disabled="!form.distrito_id"
      />
      <label>{{ t('organizaciones.existingClub') }}</label>
      <Select
        v-model="form.club_id"
        :options="clubes"
        option-label="nombre"
        option-value="id"
        filter
        show-clear
        fluid
        :disabled="!form.iglesia_id"
        :placeholder="t('organizaciones.existingClubPlaceholder')"
      />
      <label>{{ t('organizaciones.revisionNote') }}</label>
      <Textarea v-model="form.observacion" rows="2" fluid />
    </div>
    <template #footer>
      <Button :label="t('common.cancel')" text @click="emit('update:visible', false)" />
      <Button :label="t('organizaciones.relocate')" :loading="saving" @click="submit" />
    </template>
  </Dialog>
</template>

<style scoped>
.relocate-fields { display: flex; flex-direction: column; gap: 0.45rem; }
.relocate-fields label { font-weight: 600; font-size: 0.82rem; }
.relocate-error { color: var(--pj-danger, #ed1c24); font-size: 0.85rem; }
.pj-muted { color: var(--pj-text-muted); font-size: 0.85rem; }
</style>
