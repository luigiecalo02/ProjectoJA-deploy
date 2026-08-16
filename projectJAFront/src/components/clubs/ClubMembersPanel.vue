<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Avatar from 'primevue/avatar'
import Tag from 'primevue/tag'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import { personasService } from '@/services/clubsService'
import { getApiErrorMessage } from '@/services/api'
import type { ClubPersona, Persona } from '@/modules/clubs/types'

const props = defineProps<{
  personaIds: number[]
  personas: ClubPersona[]
  clubId: number
  clubOrganizacionId: number | null
  iglesiaOrganizacionId: number | null
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:personaIds': [ids: number[]]
  'update:personas': [personas: ClubPersona[]]
  refreshed: []
}>()

const { t } = useI18n()
const toast = useToast()

const memberSearch = ref('')
const dialogOpen = ref(false)
const dialogTab = ref<'select' | 'create'>('select')
const savingPersona = ref(false)
const loadingCandidates = ref(false)
const candidateSearch = ref('')
const candidates = ref<Persona[]>([])

const idTypes = [
  { label: 'CC', value: 'CC' },
  { label: 'TI', value: 'TI' },
  { label: 'CE', value: 'CE' },
  { label: 'Pasaporte', value: 'Pasaporte' },
  { label: 'NIT', value: 'NIT' },
]

const sexOptions = [
  { label: 'Masculino', value: 'M' },
  { label: 'Femenino', value: 'F' },
  { label: 'Otro', value: 'Otro' },
]

const personaForm = reactive({
  tipo_identificacion: 'CC',
  identificacion: '',
  nombre1: '',
  nombre2: '',
  apellido1: '',
  apellido2: '',
  fecha_nacimiento: null as Date | null,
  sexo: null as string | null,
  telefono: '',
  correo: '',
  direccion_actual: '',
})

const filteredMembers = computed(() => {
  const q = memberSearch.value.trim().toLowerCase()
  if (!q) return props.personas
  return props.personas.filter((p) =>
    [p.full_name, p.correo, p.telefono, p.identificacion]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  )
})

const availableCandidates = computed(() => {
  const selected = new Set(props.personaIds)
  return candidates.value.filter((p) => !selected.has(p.id))
})

function toDateString(value: Date | null): string | null {
  if (!value) return null
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
}

function orgLabel(persona: Persona): string {
  const active = (persona.organizaciones ?? []).filter((o) => o.estado !== false)
  if (!active.length) return '—'
  return active.map((o) => o.organizacion_nombre || `#${o.organizacion_id}`).join(', ')
}

function toClubPersona(persona: Persona): ClubPersona {
  return {
    id: persona.id,
    user_id: persona.user_id,
    tipo_identificacion: persona.tipo_identificacion,
    identificacion: persona.identificacion,
    nombre1: persona.nombre1,
    nombre2: persona.nombre2,
    apellido1: persona.apellido1,
    apellido2: persona.apellido2,
    correo: persona.correo,
    telefono: persona.telefono,
    full_name: persona.full_name,
  }
}

function resetPersonaForm(): void {
  personaForm.tipo_identificacion = 'CC'
  personaForm.identificacion = ''
  personaForm.nombre1 = ''
  personaForm.nombre2 = ''
  personaForm.apellido1 = ''
  personaForm.apellido2 = ''
  personaForm.fecha_nacimiento = null
  personaForm.sexo = null
  personaForm.telefono = ''
  personaForm.correo = ''
  personaForm.direccion_actual = ''
}

async function loadCandidates(search = ''): Promise<void> {
  if (!props.iglesiaOrganizacionId) {
    candidates.value = []
    return
  }
  loadingCandidates.value = true
  try {
    const result = await personasService.list({
      page: 1,
      per_page: 50,
      search: search.trim() || undefined,
      organizacion_padre_id: props.iglesiaOrganizacionId,
    })
    candidates.value = result.items
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loadingCandidates.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
function onCandidateSearch(value: string | undefined): void {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    void loadCandidates(String(value ?? ''))
  }, 300)
}

function openDialog(): void {
  dialogTab.value = 'select'
  candidateSearch.value = ''
  resetPersonaForm()
  dialogOpen.value = true
  void loadCandidates()
}

function removeMember(id: number): void {
  emit(
    'update:personaIds',
    props.personaIds.filter((pid) => pid !== id),
  )
  emit(
    'update:personas',
    props.personas.filter((p) => p.id !== id),
  )
}

function addExisting(persona: Persona): void {
  if (props.personaIds.includes(persona.id)) return
  emit('update:personaIds', [...props.personaIds, persona.id])
  emit('update:personas', [...props.personas, toClubPersona(persona)])
  toast.add({
    severity: 'success',
    summary: t('common.success'),
    detail: t('clubs.memberAddedLocal'),
    life: 2200,
  })
}

async function createPersona(): Promise<void> {
  if (props.disabled || !props.clubOrganizacionId) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('clubs.membersAfterSave'),
      life: 3500,
    })
    return
  }
  if (!personaForm.identificacion.trim() || !personaForm.nombre1.trim() || !personaForm.apellido1.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('validation.required'),
      life: 3000,
    })
    return
  }

  savingPersona.value = true
  try {
    await personasService.create({
      tipo_identificacion: personaForm.tipo_identificacion,
      identificacion: personaForm.identificacion.trim(),
      nombre1: personaForm.nombre1.trim(),
      nombre2: personaForm.nombre2.trim() || null,
      apellido1: personaForm.apellido1.trim(),
      apellido2: personaForm.apellido2.trim() || null,
      fecha_nacimiento: toDateString(personaForm.fecha_nacimiento),
      sexo: personaForm.sexo,
      telefono: personaForm.telefono.trim() || null,
      correo: personaForm.correo.trim() || null,
      direccion_actual: personaForm.direccion_actual.trim() || null,
      club_ids: [props.clubId],
      organizacion_ids: [props.clubOrganizacionId],
    })
    dialogOpen.value = false
    resetPersonaForm()
    emit('refreshed')
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('personas.createSuccess'),
      life: 2500,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    savingPersona.value = false
  }
}

watch(
  () => props.iglesiaOrganizacionId,
  () => {
    if (dialogOpen.value) void loadCandidates(candidateSearch.value)
  },
)
</script>

<template>
  <section class="club-card members-panel">
    <div class="card-head">
      <div>
        <h2>{{ t('clubs.membersTitle') }}</h2>
        <p class="pj-muted">
          {{ disabled ? t('clubs.membersAfterSave') : t('clubs.membersSubtitle') }}
        </p>
      </div>
      <Button
        type="button"
        icon="pi pi-user-plus"
        :label="t('clubs.addMember')"
        size="small"
        :disabled="disabled"
        @click="openDialog"
      />
    </div>

    <Message v-if="disabled" severity="info" :closable="false" class="members-hint">
      {{ t('clubs.membersAfterSaveDetail') }}
    </Message>

    <template v-else>
      <div class="members-toolbar">
        <AppSearchField
          v-model="memberSearch"
          class="members-search"
          :placeholder="t('clubs.membersSearch')"
        />
      </div>

      <DataTable :value="filteredMembers" data-key="id" striped-rows size="small">
        <template #empty>
          <p class="pj-muted">{{ t('users.noMembers') }}</p>
        </template>
        <Column :header="t('personas.firstName')">
          <template #body="{ data }">
            <div class="member-name">
              <Avatar
                :label="(data.full_name || '?').charAt(0).toUpperCase()"
                shape="circle"
                style="background: var(--pj-primary-soft); color: var(--pj-navy)"
              />
              <strong>{{ data.full_name }}</strong>
            </div>
          </template>
        </Column>
        <Column :header="t('personas.idNumber')">
          <template #body="{ data }">
            {{ data.tipo_identificacion }} {{ data.identificacion }}
          </template>
        </Column>
        <Column field="correo" :header="t('personas.email')" />
        <Column field="telefono" :header="t('personas.phone')" />
        <Column :header="t('common.actions')" style="width: 5rem">
          <template #body="{ data }">
            <Button
              type="button"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              @click="removeMember(data.id)"
            />
          </template>
        </Column>
      </DataTable>
    </template>

    <Dialog
      v-model:visible="dialogOpen"
      modal
      :header="t('clubs.addMember')"
      :style="{ width: 'min(96vw, 46rem)' }"
    >
      <div class="drawer-tabs">
        <Button
          type="button"
          :label="t('clubs.boardTabSelect')"
          :outlined="dialogTab !== 'select'"
          size="small"
          @click="dialogTab = 'select'"
        />
        <Button
          type="button"
          :label="t('clubs.boardTabCreate')"
          :outlined="dialogTab !== 'create'"
          size="small"
          @click="dialogTab = 'create'"
        />
      </div>

      <p class="pj-muted sibling-hint">{{ t('clubs.membersSiblingHint') }}</p>

      <template v-if="dialogTab === 'select'">
        <AppSearchField
          v-model="candidateSearch"
          :placeholder="t('personas.searchPlaceholder')"
          @update:model-value="onCandidateSearch"
        />
        <PageLoader v-if="loadingCandidates" :label="t('common.loading')" />
        <DataTable
          v-else
          :value="availableCandidates"
          data-key="id"
          size="small"
          striped-rows
          class="candidates-table"
        >
          <template #empty>
            <p class="pj-muted">{{ t('clubs.membersSiblingEmpty') }}</p>
          </template>
          <Column :header="t('personas.fullName')">
            <template #body="{ data }">
              <div class="member-name">
                <Avatar
                  :label="(data.full_name || '?').charAt(0).toUpperCase()"
                  shape="circle"
                  style="background: var(--pj-primary-soft); color: var(--pj-navy)"
                />
                <div>
                  <strong>{{ data.full_name }}</strong>
                  <small class="pj-muted">{{ data.tipo_identificacion }} {{ data.identificacion }}</small>
                </div>
              </div>
            </template>
          </Column>
          <Column :header="t('clubs.memberFromOrg')">
            <template #body="{ data }">
              <Tag severity="secondary" :value="orgLabel(data)" />
            </template>
          </Column>
          <Column style="width: 7rem">
            <template #body="{ data }">
              <Button
                type="button"
                :label="t('clubs.boardPick')"
                size="small"
                @click="addExisting(data)"
              />
            </template>
          </Column>
        </DataTable>
      </template>

      <template v-else>
        <div class="persona-grid">
          <div class="field">
            <label>{{ t('personas.idType') }}</label>
            <Select
              v-model="personaForm.tipo_identificacion"
              :options="idTypes"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
          <div class="field">
            <label>{{ t('personas.idNumber') }}</label>
            <InputText v-model="personaForm.identificacion" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.firstName') }}</label>
            <InputText v-model="personaForm.nombre1" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.secondName') }}</label>
            <InputText v-model="personaForm.nombre2" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.lastName') }}</label>
            <InputText v-model="personaForm.apellido1" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.secondLastName') }}</label>
            <InputText v-model="personaForm.apellido2" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.birthDate') }}</label>
            <DatePicker v-model="personaForm.fecha_nacimiento" date-format="dd/mm/yy" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.sex') }}</label>
            <Select
              v-model="personaForm.sexo"
              :options="sexOptions"
              option-label="label"
              option-value="value"
              show-clear
              class="w-full"
            />
          </div>
          <div class="field">
            <label>{{ t('personas.phone') }}</label>
            <InputText v-model="personaForm.telefono" class="w-full" />
          </div>
          <div class="field">
            <label>{{ t('personas.email') }}</label>
            <InputText v-model="personaForm.correo" class="w-full" />
          </div>
          <div class="field span-2">
            <label>{{ t('personas.address') }}</label>
            <InputText v-model="personaForm.direccion_actual" class="w-full" />
          </div>
        </div>
        <div class="dialog-actions">
          <Button type="button" :label="t('common.cancel')" text @click="dialogOpen = false" />
          <Button type="button" :label="t('common.create')" :loading="savingPersona" @click="createPersona" />
        </div>
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.85rem;
}

.card-head h2 {
  margin: 0 0 0.2rem;
  font-size: 1.05rem;
}

.members-hint {
  margin-bottom: 0.75rem;
}

.members-toolbar {
  margin-bottom: 0.75rem;
}

.members-search {
  width: 100%;
}

.member-name {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.member-name small {
  display: block;
}

.drawer-tabs {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 0.65rem;
}

.sibling-hint {
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
}

.candidates-table {
  margin-top: 0.65rem;
}

.persona-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.span-2 {
  grid-column: span 2;
}

.w-full {
  width: 100%;
}

.dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  margin-top: 1rem;
}

@media (max-width: 640px) {
  .persona-grid {
    grid-template-columns: 1fr;
  }
  .span-2 {
    grid-column: auto;
  }
}
</style>
