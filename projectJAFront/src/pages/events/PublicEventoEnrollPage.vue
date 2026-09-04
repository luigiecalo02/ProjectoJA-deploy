<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import RadioButton from 'primevue/radiobutton'
import Message from 'primevue/message'
import DatePicker from 'primevue/datepicker'
import { getApiErrorMessage } from '@/services/api'
import {
  publicEventosService,
  type PublicEventoDetail,
  type PublicEventoEnrollResult,
} from '@/services/publicEventosService'
import { resolveAssetUrl } from '@/modules/settings/assetUrl'
import EventMaterialsViewer from '@/components/events/EventMaterialsViewer.vue'
import { evaluatePasswordStrength, PASSWORD_MAX_LENGTH } from '@/utils/passwordStrength'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const eventId = computed(() => Number(route.params.id))
const loading = ref(true)
const submitting = ref(false)
const errorMessage = ref('')
const evento = ref<PublicEventoDetail | null>(null)
const submitted = ref<PublicEventoEnrollResult | null>(null)
const step = ref(0)
const comprobante = ref<File | null>(null)

const form = reactive({
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
  evento_lote_id: null as number | null,
  evento_cabana_id: null as number | null,
  crear_usuario: false,
  password: '',
  password_confirmation: '',
})

const idTypes = [
  { label: 'CC', value: 'CC' },
  { label: 'TI', value: 'TI' },
  { label: 'CE', value: 'CE' },
  { label: 'PAS', value: 'PAS' },
]
const sexOptions = [
  { label: t('publicEventos.sexM'), value: 'M' },
  { label: t('publicEventos.sexF'), value: 'F' },
  { label: t('publicEventos.sexOther'), value: 'Otro' },
]

const passwordStrength = computed(() => evaluatePasswordStrength(form.password))
const passwordLevelLabel = computed(() => {
  const labels = {
    mala: t('validation.passwordLevelMala'),
    facil: t('validation.passwordLevelFacil'),
    media: t('validation.passwordLevelMedia'),
    dificil: t('validation.passwordLevelDificil'),
  }
  return labels[passwordStrength.value.level]
})

const steps = computed(() => {
  const items = [{ key: 'datos', label: t('publicEventos.stepDatos') }]
  if (evento.value?.usar_lotes) items.push({ key: 'lote', label: t('publicEventos.stepLote') })
  if (evento.value?.usar_cabanas) items.push({ key: 'cabana', label: t('publicEventos.stepCabana') })
  items.push({ key: 'pago', label: t('publicEventos.stepPago') })
  return items
})

const currentStep = computed(() => steps.value[step.value]?.key ?? 'datos')

const selectedCabana = computed(() =>
  evento.value?.cabanas.find((item) => item.id === form.evento_cabana_id) ?? null,
)

const cabanaTotal = computed(() => {
  if (!form.evento_cabana_id || !evento.value?.oferta_cabana) return 0
  return evento.value.oferta_cabana.total
})

const seguroTotal = computed(() => {
  if (!evento.value?.requiere_seguro) return 0
  return evento.value.seguro_valor ?? 0
})

const inscripcionTotal = computed(() => evento.value?.precio ?? 0)

const grandTotal = computed(() =>
  Math.round((inscripcionTotal.value + seguroTotal.value + cabanaTotal.value) * 100) / 100,
)

function money(value: number): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(value)
}

function formatRange(start?: string | null, end?: string | null): string {
  if (!start) return t('publicEventos.dateTbd')
  const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }
  const from = new Date(start)
  if (!end) return from.toLocaleDateString('es-CO', opts)
  return `${from.toLocaleDateString('es-CO', opts)} – ${new Date(end).toLocaleDateString('es-CO', opts)}`
}

function isoDate(value: Date | null): string | null {
  if (!value) return null
  const y = value.getFullYear()
  const m = String(value.getMonth() + 1).padStart(2, '0')
  const d = String(value.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function validateCurrent(): string | null {
  if (currentStep.value === 'datos') {
    if (!form.identificacion.trim() || !form.nombre1.trim() || !form.apellido1.trim() || !form.correo.trim()) {
      return t('publicEventos.requiredPersona')
    }
  }
  if (currentStep.value === 'pago' && form.crear_usuario) {
    if (!form.password || form.password !== form.password_confirmation) {
      return t('publicEventos.passwordMismatch')
    }
    if (passwordStrength.value.level === 'mala') {
      return t('validation.passwordStrong')
    }
  }
  if (currentStep.value === 'pago' && grandTotal.value > 0 && !comprobante.value) {
    return t('publicEventos.comprobanteRequired')
  }
  return null
}

function next(): void {
  errorMessage.value = ''
  const invalid = validateCurrent()
  if (invalid) {
    errorMessage.value = invalid
    return
  }
  if (step.value < steps.value.length - 1) {
    step.value += 1
  }
}

async function submit(): Promise<void> {
  errorMessage.value = ''
  const invalid = validateCurrent()
  if (invalid) {
    errorMessage.value = invalid
    return
  }
  submitting.value = true
  try {
    const { result } = await publicEventosService.enroll(eventId.value, {
      tipo_identificacion: form.tipo_identificacion,
      identificacion: form.identificacion.trim(),
      nombre1: form.nombre1.trim(),
      nombre2: form.nombre2.trim() || undefined,
      apellido1: form.apellido1.trim(),
      apellido2: form.apellido2.trim() || undefined,
      fecha_nacimiento: isoDate(form.fecha_nacimiento),
      sexo: form.sexo,
      telefono: form.telefono.trim() || undefined,
      correo: form.correo.trim(),
      evento_lote_id: form.evento_lote_id,
      evento_cabana_id: form.evento_cabana_id,
      crear_usuario: form.crear_usuario,
      password: form.password,
      password_confirmation: form.password_confirmation,
      comprobante: comprobante.value,
      comprobante_valor: grandTotal.value > 0 ? grandTotal.value : undefined,
    })
    submitted.value = result
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('publicEventos.submitError'))
  } finally {
    submitting.value = false
  }
}

function onFile(event: Event): void {
  const input = event.target as HTMLInputElement
  comprobante.value = input.files?.[0] ?? null
}

onMounted(async () => {
  try {
    evento.value = await publicEventosService.show(eventId.value)
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('publicEventos.loadError'))
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="enroll">
    <header class="enroll__head">
      <p class="kicker">{{ t('publicEventos.kicker') }}</p>
      <h1>{{ evento?.name || t('publicEventos.enrollTitle') }}</h1>
      <p v-if="evento">{{ formatRange(evento.starts_at, evento.ends_at) }}</p>
    </header>
    <EventMaterialsViewer v-if="evento?.archivos?.length" :files="evento.archivos" />

    <p v-if="loading" class="pj-muted">{{ t('common.loading') }}</p>

    <div v-else-if="submitted" class="done">
      <i class="pi pi-check-circle" />
      <h2>{{ t('publicEventos.doneTitle') }}</h2>
      <p>{{ t('publicEventos.doneBody', { email: submitted.correo_enmascarado }) }}</p>
      <p v-if="submitted.usuario_creado">{{ t('publicEventos.doneAccount') }}</p>
      <strong v-if="submitted.total > 0">{{ money(submitted.total) }}</strong>
      <Button :label="t('publicEventos.backLogin')" @click="router.push({ name: 'login' })" />
    </div>

    <form v-else-if="evento" class="enroll__form" @submit.prevent="currentStep === 'pago' ? submit() : next()">
      <ol class="steps">
        <li
          v-for="(item, index) in steps"
          :key="item.key"
          :class="{ 'is-active': index === step, 'is-done': index < step }"
        >
          {{ item.label }}
        </li>
      </ol>

      <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>

      <section v-if="currentStep === 'datos'" class="block">
        <div class="grid-2">
          <div class="field">
            <label>{{ t('personas.idType') }}</label>
            <Select v-model="form.tipo_identificacion" :options="idTypes" option-label="label" option-value="value" fluid />
          </div>
          <div class="field">
            <label>{{ t('personas.idNumber') }}</label>
            <InputText v-model="form.identificacion" fluid required />
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label>{{ t('personas.firstName') }}</label>
            <InputText v-model="form.nombre1" fluid required />
          </div>
          <div class="field">
            <label>{{ t('personas.secondName') }}</label>
            <InputText v-model="form.nombre2" fluid />
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label>{{ t('personas.lastName') }}</label>
            <InputText v-model="form.apellido1" fluid required />
          </div>
          <div class="field">
            <label>{{ t('personas.secondLastName') }}</label>
            <InputText v-model="form.apellido2" fluid />
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label>{{ t('personas.birthDate') }}</label>
            <DatePicker v-model="form.fecha_nacimiento" date-format="dd/mm/yy" fluid show-icon />
          </div>
          <div class="field">
            <label>{{ t('personas.sex') }}</label>
            <Select v-model="form.sexo" :options="sexOptions" option-label="label" option-value="value" show-clear fluid />
          </div>
        </div>
        <div class="field">
          <label>{{ t('personas.phone') }}</label>
          <InputText v-model="form.telefono" fluid />
        </div>
        <div class="field">
          <label>{{ t('personas.email') }}</label>
          <InputText v-model="form.correo" type="email" fluid required />
          <small class="pj-muted">{{ t('publicEventos.emailHint') }}</small>
        </div>
      </section>

      <section v-else-if="currentStep === 'lote'" class="block">
        <p class="pj-muted">{{ t('publicEventos.loteHint') }}</p>
        <p v-if="!evento.lotes.length" class="empty">{{ t('publicEventos.noLotes') }}</p>
        <label v-for="lote in evento.lotes" :key="lote.id" class="choice">
          <RadioButton v-model="form.evento_lote_id" :value="lote.id" :input-id="`lote-${lote.id}`" />
          <span>
            <strong>{{ lote.nombre || lote.codigo }}</strong>
            <small v-if="lote.capacidad_maxima">{{ t('publicEventos.capacity', { n: lote.capacidad_maxima }) }}</small>
          </span>
        </label>
        <Button
          v-if="form.evento_lote_id"
          type="button"
          text
          size="small"
          :label="t('publicEventos.clearChoice')"
          @click="form.evento_lote_id = null"
        />
      </section>

      <section v-else-if="currentStep === 'cabana'" class="block">
        <p class="pj-muted">
          {{ evento.oferta_cabana
            ? t('publicEventos.cabanaHintPaid', { price: money(evento.oferta_cabana.total), nights: evento.noches })
            : t('publicEventos.cabanaHintFree') }}
        </p>
        <p v-if="!evento.cabanas.length" class="empty">{{ t('publicEventos.noCabanas') }}</p>
        <label
          v-for="cabana in evento.cabanas"
          :key="cabana.id"
          class="choice"
          :class="{ 'is-disabled': !cabana.disponible }"
        >
          <RadioButton
            v-model="form.evento_cabana_id"
            :value="cabana.id"
            :input-id="`cabana-${cabana.id}`"
            :disabled="!cabana.disponible"
          />
          <img v-if="resolveAssetUrl(cabana.image_url)" :src="resolveAssetUrl(cabana.image_url)!" :alt="cabana.nombre" />
          <span>
            <strong>{{ cabana.nombre }}</strong>
            <small>{{ cabana.disponible ? t('publicEventos.capacity', { n: cabana.capacidad }) : t('publicEventos.cabanaTaken') }}</small>
          </span>
        </label>
        <Button
          v-if="form.evento_cabana_id"
          type="button"
          text
          size="small"
          :label="t('publicEventos.clearChoice')"
          @click="form.evento_cabana_id = null"
        />
      </section>

      <section v-else class="block">
        <div class="summary">
          <div><span>{{ t('publicEventos.feeInscripcion') }}</span><strong>{{ money(inscripcionTotal) }}</strong></div>
          <div v-if="seguroTotal"><span>{{ t('publicEventos.feeSeguro') }}</span><strong>{{ money(seguroTotal) }}</strong></div>
          <div v-if="selectedCabana">
            <span>{{ t('publicEventos.feeCabana') }} · {{ selectedCabana.nombre }}</span>
            <strong>{{ money(cabanaTotal) }}</strong>
          </div>
          <div class="total"><span>{{ t('publicEventos.total') }}</span><strong>{{ money(grandTotal) }}</strong></div>
        </div>

        <fieldset class="account">
          <legend>{{ t('publicEventos.accountChoice') }}</legend>
          <label class="choice">
            <RadioButton v-model="form.crear_usuario" :value="false" input-id="acc-no" />
            <span>{{ t('publicEventos.accountSkip') }}</span>
          </label>
          <label class="choice">
            <RadioButton v-model="form.crear_usuario" :value="true" input-id="acc-yes" />
            <span>{{ t('publicEventos.accountCreate') }}</span>
          </label>
        </fieldset>

        <div v-if="form.crear_usuario" class="grid-2">
          <div class="field">
            <label>{{ t('auth.password') }}</label>
            <Password v-model="form.password" :feedback="false" toggle-mask fluid :maxlength="PASSWORD_MAX_LENGTH" />
          </div>
          <div class="field">
            <label>{{ t('clubInscripcion.passwordConfirm') }}</label>
            <Password v-model="form.password_confirmation" :feedback="false" toggle-mask fluid />
          </div>
          <div class="password-strength span-2" aria-live="polite">
            <div class="password-strength__track">
              <span
                class="password-strength__fill"
                :class="`is-${passwordStrength.level}`"
                :style="{ width: form.password ? (passwordStrength.level === 'dificil' ? '100%' : passwordStrength.level === 'media' ? '70%' : passwordStrength.level === 'facil' ? '45%' : '25%') : '0' }"
              />
            </div>
            <strong :class="`is-${passwordStrength.level}`">{{ passwordLevelLabel }}</strong>
          </div>
        </div>

        <div v-if="grandTotal > 0" class="pay">
          <h3>{{ t('publicEventos.payTitle') }}</h3>
          <div v-if="evento.cuenta_bancaria" class="bank">
            <p><strong>{{ evento.cuenta_bancaria.nombre }}</strong></p>
            <p v-if="evento.cuenta_bancaria.banco">{{ evento.cuenta_bancaria.banco }} · {{ evento.cuenta_bancaria.tipo_cuenta }}</p>
            <p v-if="evento.cuenta_bancaria.numero_cuenta">{{ evento.cuenta_bancaria.numero_cuenta }}</p>
            <p v-if="evento.cuenta_bancaria.titular">{{ evento.cuenta_bancaria.titular }}</p>
            <img
              v-if="resolveAssetUrl(evento.cuenta_bancaria.qr_url)"
              class="qr"
              :src="resolveAssetUrl(evento.cuenta_bancaria.qr_url)!"
              alt="QR"
            />
          </div>
          <div class="field">
            <label>{{ t('publicEventos.comprobante') }}</label>
            <input type="file" accept="image/*,.pdf" @change="onFile" />
            <small v-if="comprobante" class="pj-muted">{{ comprobante.name }}</small>
          </div>
        </div>
      </section>

      <footer class="actions">
        <Button
          v-if="step > 0"
          type="button"
          text
          :label="t('common.back')"
          @click="step -= 1"
        />
        <Button
          v-else
          type="button"
          text
          :label="t('publicEventos.backList')"
          @click="router.push({ name: 'eventos.publicos' })"
        />
        <Button
          type="submit"
          :label="currentStep === 'pago' ? t('publicEventos.submit') : t('clubInscripcion.next')"
          :loading="submitting"
        />
      </footer>
    </form>
  </div>
</template>

<style scoped>
.enroll { display: flex; flex-direction: column; gap: 1rem; }
.enroll__head h1 { margin: 0.15rem 0; font-size: 1.45rem; }
.enroll__head p { margin: 0; color: var(--pj-text-muted); font-size: 0.88rem; }
.kicker { font-weight: 700; color: var(--pj-navy); font-size: 0.75rem; letter-spacing: 0.04em; text-transform: uppercase; }
.steps {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr));
  gap: 0.35rem;
  padding: 0;
  margin: 0;
}
.steps li {
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.4rem 0.45rem;
  border-radius: 8px;
  background: var(--pj-bg-muted);
  color: var(--pj-text-muted);
  text-align: center;
}
.steps li.is-active { background: var(--pj-primary-soft); color: var(--pj-navy); }
.steps li.is-done { color: var(--pj-success); }
.block, .enroll__form { display: flex; flex-direction: column; gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-weight: 600; font-size: 0.82rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; }
.span-2 { grid-column: 1 / -1; }
.choice {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.7rem 0.8rem;
  border: 1px solid var(--pj-border);
  border-radius: 12px;
}
.choice img { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; }
.choice span { display: flex; flex-direction: column; gap: 0.1rem; }
.choice small { color: var(--pj-text-muted); font-size: 0.75rem; }
.choice.is-disabled { opacity: 0.55; }
.empty { padding: 0.8rem; border-radius: 10px; background: var(--pj-bg-muted); color: var(--pj-text-muted); }
.summary { display: flex; flex-direction: column; gap: 0.4rem; padding: 0.8rem; border-radius: 12px; background: var(--pj-bg-muted); }
.summary > div, .total { display: flex; justify-content: space-between; gap: 0.6rem; }
.total { border-top: 1px solid var(--pj-border); padding-top: 0.4rem; }
.account { border: 0; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.45rem; }
.account legend { font-weight: 700; font-size: 0.85rem; margin-bottom: 0.35rem; }
.pay { display: flex; flex-direction: column; gap: 0.55rem; }
.pay h3 { margin: 0; font-size: 1rem; }
.bank { font-size: 0.85rem; }
.qr { width: 140px; height: auto; border-radius: 8px; }
.actions { display: flex; justify-content: space-between; gap: 0.6rem; }
.done { display: flex; flex-direction: column; align-items: flex-start; gap: 0.55rem; }
.done i { font-size: 2rem; color: var(--pj-success); }
.password-strength { display: flex; flex-direction: column; gap: 0.25rem; }
.password-strength__track { height: 0.4rem; border-radius: 999px; background: var(--pj-bg-muted); overflow: hidden; }
.password-strength__fill { display: block; height: 100%; }
.password-strength__fill.is-mala { background: #dc2626; }
.password-strength__fill.is-facil { background: #ea580c; }
.password-strength__fill.is-media { background: #ca8a04; }
.password-strength__fill.is-dificil { background: #15803d; }
@media (max-width: 640px) {
  .grid-2 { grid-template-columns: 1fr; }
}
</style>
