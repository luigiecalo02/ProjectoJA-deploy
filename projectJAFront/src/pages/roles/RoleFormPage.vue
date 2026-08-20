<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import { rolesService } from '@/services/rolesService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import type { RolePage } from '@/modules/roles/types'
import { primeIconOptions } from '@/utils/primeIcons'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const isEdit = computed(() => Boolean(route.params.id))
const roleId = computed(() => Number(route.params.id))

const loading = ref(true)
const saving = ref(false)
const pages = ref<RolePage[]>([])
const isSuper = ref(false)
const formError = ref('')

const form = reactive({
  display_name: '',
  description: '',
  icon: null as string | null,
  permission_ids: [] as number[],
})

const canEditPermissions = computed(() => {
  if (isSuper.value) return false
  return can('roles.assign_permissions') || can('roles.update') || can('roles.create')
})

const canSubmit = computed(() => {
  if (isSuper.value) return false
  if (isEdit.value) return can('roles.update')
  return can('roles.create')
})

function pageSelectedCount(page: RolePage): number {
  return page.permissions.filter((p) => form.permission_ids.includes(p.id)).length
}

function isPageFullySelected(page: RolePage): boolean {
  return page.permissions.length > 0 && pageSelectedCount(page) === page.permissions.length
}

function togglePage(page: RolePage, checked: boolean): void {
  const ids = page.permissions.map((p) => p.id)
  if (checked) {
    form.permission_ids = Array.from(new Set([...form.permission_ids, ...ids]))
  } else {
    form.permission_ids = form.permission_ids.filter((id) => !ids.includes(id))
  }
}

function togglePermission(id: number, checked: boolean): void {
  if (checked) {
    if (!form.permission_ids.includes(id)) {
      form.permission_ids.push(id)
    }
  } else {
    form.permission_ids = form.permission_ids.filter((pid) => pid !== id)
  }
}

async function load(): Promise<void> {
  loading.value = true
  formError.value = ''
  try {
    const [pagesData, role] = await Promise.all([
      rolesService.pages(),
      isEdit.value ? rolesService.get(roleId.value) : Promise.resolve(null),
    ])
    pages.value = pagesData

    if (role) {
      form.display_name = role.display_name
      form.description = role.description || ''
      form.icon = role.icon || null
      form.permission_ids = [...(role.permission_ids || [])]
      isSuper.value = role.is_super
    }
  } catch (error) {
    formError.value = getApiErrorMessage(error)
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  saving.value = true
  formError.value = ''
  try {
    const payload = {
      display_name: form.display_name.trim(),
      description: form.description.trim() || null,
      icon: form.icon?.trim() || null,
      permission_ids: [...form.permission_ids],
    }

    if (isEdit.value) {
      await rolesService.update(roleId.value, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('roles.updateSuccess'),
        life: 2500,
      })
    } else {
      const created = await rolesService.create(payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('roles.createSuccess'),
        life: 2500,
      })
      await router.replace({ name: 'roles.edit', params: { id: created.id } })
      return
    }

    await router.push({ name: 'roles' })
  } catch (error) {
    formError.value = getApiErrorMessage(error)
  } finally {
    saving.value = false
  }
}

usePageChrome(() => ({
  title: isEdit.value ? t('roles.edit') : t('roles.new'),
  subtitle: t('roles.formHint'),
  backTo: { name: 'roles' },
  actions: canSubmit.value
    ? [
        {
          key: 'save',
          label: t('common.save'),
          icon: 'pi pi-save',
          loading: saving.value,
          onClick: () => void submit(),
        },
      ]
    : [],
}))

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">
          {{ isEdit ? t('roles.edit') : t('roles.new') }}
        </h1>
        <p class="pj-page__subtitle">{{ t('roles.formHint') }}</p>
      </div>
      <Button :label="t('common.back')" text @click="router.push({ name: 'roles' })" />
    </header>

    <PageLoader v-if="loading" :label="t('common.loading')" />

    <form v-else class="pj-panel role-form" @submit.prevent="submit">
      <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>
      <Message v-if="isSuper" severity="info" :closable="false">
        {{ t('roles.superHint') }}
      </Message>

      <div class="field">
        <label for="display_name">{{ t('roles.name') }}</label>
        <InputText
          id="display_name"
          v-model="form.display_name"
          :disabled="isSuper || !canSubmit"
          required
          class="w-full"
        />
      </div>

      <div class="field">
        <label for="description">{{ t('roles.description') }}</label>
        <Textarea
          id="description"
          v-model="form.description"
          rows="3"
          auto-resize
          :disabled="isSuper || !canSubmit"
          class="w-full"
        />
      </div>

      <div class="field">
        <div class="icon-field__head">
          <label for="role-icon">{{ t('roles.icon') }}</label>
          <Button
            v-if="form.icon && !isSuper && canSubmit"
            type="button"
            text
            size="small"
            :label="t('roles.iconClear')"
            @click="form.icon = null"
          />
        </div>
        <p class="pj-muted icon-field__hint">{{ t('roles.iconHint') }}</p>
        <Select
          id="role-icon"
          v-model="form.icon"
          :options="primeIconOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('roles.iconPlaceholder')"
          :disabled="isSuper || !canSubmit"
          filter
          show-clear
          class="w-full icon-select"
        >
          <template #value="{ value, placeholder }">
            <span v-if="value" class="icon-select__value">
              <i :class="value" aria-hidden="true" />
              <span>{{ value.replace(/^pi pi-/, '') }}</span>
            </span>
            <span v-else class="pj-muted">{{ placeholder }}</span>
          </template>
          <template #option="{ option }">
            <span class="icon-select__option">
              <i :class="option.value" aria-hidden="true" />
              <span>{{ option.label }}</span>
            </span>
          </template>
        </Select>
      </div>

      <div class="permissions-block">
        <div class="permissions-block__head">
          <h2>{{ t('roles.pagesTitle') }}</h2>
          <p class="pj-muted">{{ t('roles.pagesHint') }}</p>
        </div>

        <div v-if="!pages.length" class="pj-muted">{{ t('roles.noPages') }}</div>

        <article v-for="page in pages" :key="page.id" class="page-card">
          <header class="page-card__header">
            <div class="page-card__title">
              <i v-if="page.icon" :class="page.icon" />
              <div>
                <strong>{{ page.name }}</strong>
                <span class="pj-muted">{{ page.key }}</span>
              </div>
            </div>
            <div class="page-card__select-all" v-if="canEditPermissions && !isSuper">
              <Checkbox
                :input-id="`page-${page.id}`"
                :model-value="isPageFullySelected(page)"
                :binary="true"
                :indeterminate="pageSelectedCount(page) > 0 && !isPageFullySelected(page)"
                @update:model-value="(v: boolean) => togglePage(page, v)"
              />
              <label :for="`page-${page.id}`">{{ t('roles.selectPage') }}</label>
            </div>
          </header>

          <p v-if="page.description" class="page-card__desc">{{ page.description }}</p>

          <div class="page-card__perms">
            <label
              v-for="perm in page.permissions"
              :key="perm.id"
              class="perm-item"
              :class="{ 'perm-item--disabled': !canEditPermissions || isSuper }"
            >
              <Checkbox
                :model-value="isSuper || form.permission_ids.includes(perm.id)"
                :binary="true"
                :disabled="!canEditPermissions || isSuper"
                @update:model-value="(v: boolean) => togglePermission(perm.id, v)"
              />
              <span>
                <strong>{{ perm.display_name }}</strong>
                <small>{{ perm.name }}</small>
              </span>
            </label>
          </div>
        </article>
      </div>

      <div class="form-actions">
        <Button type="button" :label="t('common.cancel')" text @click="router.push({ name: 'roles' })" />
        <Button
          v-if="canSubmit"
          type="submit"
          :label="t('common.save')"
          :loading="saving"
          :disabled="!form.display_name.trim()"
        />
      </div>
    </form>
  </section>
</template>

<style scoped>
.role-form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.icon-field__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.icon-field__hint {
  margin: 0;
  font-size: 0.85rem;
}

.icon-select__value,
.icon-select__option {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
}

.icon-select__value i,
.icon-select__option i {
  width: 1.1rem;
  text-align: center;
  color: var(--pj-navy);
}

.w-full {
  width: 100%;
}

.permissions-block__head h2 {
  margin: 0 0 0.25rem;
  font-size: 1.1rem;
}

.page-card {
  border: 1px solid color-mix(in srgb, var(--pj-navy) 12%, transparent);
  border-radius: 12px;
  padding: 1rem;
  margin-top: 0.85rem;
  background: color-mix(in srgb, var(--pj-navy) 3%, transparent);
}

.page-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.page-card__title {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.page-card__title > div {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.page-card__select-all {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.page-card__desc {
  margin: 0.65rem 0 0;
  color: var(--p-text-muted-color, #64748b);
  font-size: 0.9rem;
}

.page-card__perms {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 0.65rem;
  margin-top: 0.9rem;
}

.perm-item {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  cursor: pointer;
}

.perm-item span {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  line-height: 1.25;
}

.perm-item small {
  color: var(--p-text-muted-color, #64748b);
  font-size: 0.75rem;
}

.perm-item--disabled {
  opacity: 0.75;
  cursor: default;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
</style>
