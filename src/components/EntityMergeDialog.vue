<template>
  <NcDialog
    v-if="open"
    :name="t('sheetmusic', 'Merge {entity}', { entity: singular }, { escape: false })"
    size="normal"
    @close="handleClose">
    <div :class="$style.dialogContent">
      <p :class="$style.description">
        {{ t('sheetmusic', 'Choose which {entity} to keep. All songs attached to the duplicate {entities} will be reassigned to the kept {entity}, and the duplicates will be deleted.', { entity: singular, entities: plural.toLowerCase() }, { escape: false }) }}
      </p>

      <div :class="$style.field">
        <label :class="$style.fieldLabel">
          {{ t('sheetmusic', 'Keep {entity}', { entity: singular }, { escape: false }) }}
        </label>
        <NcSelect
          v-model="targetEntityOption"
          :options="availableTargetOptions"
          label="label"
          track-by="id"
          :input-label="t('sheetmusic', 'Keep {entity}', { entity: singular }, { escape: false })"
          :placeholder="t('sheetmusic', 'Select {entity} to keep…', { entity: singular }, { escape: false })"
          :class="$style.select" />
      </div>

      <div :class="$style.swapContainer">
        <NcButton
          size="small"
          variant="secondary"
          :disabled="!canSwap"
          :title="t('sheetmusic', 'Swap kept and duplicate')"
          @click="swapEntities">
          <template #icon>
            <SwapVertical :size="18" />
          </template>
          {{ t('sheetmusic', 'Swap') }}
        </NcButton>
      </div>

      <div :class="$style.field">
        <label :class="$style.fieldLabel">
          {{ t('sheetmusic', 'Merge and remove duplicate {entities}', { entities: plural.toLowerCase() }, { escape: false }) }}
        </label>
        <NcSelect
          v-model="duplicateEntityOptions"
          :options="availableDuplicateOptions"
          :multiple="true"
          :close-on-select="false"
          label="label"
          track-by="id"
          :input-label="t('sheetmusic', 'Merge and remove duplicate {entities}', { entities: plural.toLowerCase() }, { escape: false })"
          :placeholder="t('sheetmusic', 'Select duplicates to merge…')"
          :class="$style.select" />
      </div>

      <div v-if="summaryText" :class="$style.summaryBox">
        <p :class="$style.summaryText">{{ summaryText }}</p>
      </div>

      <p v-if="errorMessage" :class="$style.error" role="alert">
        {{ errorMessage }}
      </p>
    </div>

    <template #actions>
      <NcButton :disabled="isSubmitting" @click="handleClose">
        {{ t('sheetmusic', 'Cancel') }}
      </NcButton>
      <NcButton
        variant="primary"
        :disabled="!canSubmit || isSubmitting"
        @click="submitMerge">
        <template v-if="isSubmitting" #icon>
          <NcLoadingIcon :size="18" />
        </template>
        {{ t('sheetmusic', 'Merge {entity}', { entity: singular }, { escape: false }) }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import type { EntityType, FilterEntity } from '../types/song'
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { extractApiErrorMessage } from '../utils/api'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import SwapVertical from 'vue-material-design-icons/SwapVertical.vue'
import { apiPost } from '../api/client'

export interface EntityOption {
  id: number
  name: string
  label: string
  songCount: number
}

const props = defineProps<{
  open: boolean
  selectedLibrary: number
  entityType: EntityType
  singular: string
  plural: string
  entities: FilterEntity[]
  entitySongCounts: Map<number, number>
  initialEntity?: FilterEntity | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'merged', libraryData: any): void
}>()

const targetEntityOption = ref<EntityOption | null>(null)
const duplicateEntityOptions = ref<EntityOption[]>([])
const isSubmitting = ref(false)
const errorMessage = ref('')

function entityName(entity: FilterEntity): string {
  return 'title' in entity ? entity.title : entity.name
}

function formatOption(entity: FilterEntity): EntityOption {
  const name = entityName(entity)
  const count = props.entitySongCounts.get(entity.id) ?? 0
  const unit = count === 1
    ? t('sheetmusic', 'song')
    : t('sheetmusic', 'songs')
  return {
    id: entity.id,
    name,
    label: `${name} (${count} ${unit})`,
    songCount: count,
  }
}

const allOptions = computed<EntityOption[]>(() => {
  return props.entities.map(formatOption)
})

const availableTargetOptions = computed<EntityOption[]>(() => {
  const duplicateIds = new Set(duplicateEntityOptions.value.map(d => d.id))
  return allOptions.value.filter(opt => !duplicateIds.has(opt.id))
})

const availableDuplicateOptions = computed<EntityOption[]>(() => {
  const targetId = targetEntityOption.value?.id
  return allOptions.value.filter(opt => opt.id !== targetId)
})

const canSwap = computed(() => {
  return duplicateEntityOptions.value.length === 1 && targetEntityOption.value !== null
})

function swapEntities() {
  if (!canSwap.value) return
  const currentTarget = targetEntityOption.value
  const currentDuplicate = duplicateEntityOptions.value[0]
  targetEntityOption.value = currentDuplicate
  duplicateEntityOptions.value = currentTarget ? [currentTarget] : []
}

const canSubmit = computed(() => {
  return targetEntityOption.value !== null && duplicateEntityOptions.value.length > 0 && !isSubmitting.value
})

const summaryText = computed(() => {
  if (!targetEntityOption.value || duplicateEntityOptions.value.length === 0) {
    return ''
  }
  const duplicateNames = duplicateEntityOptions.value.map(d => `"${d.name}"`).join(', ')
  const targetName = `"${targetEntityOption.value.name}"`

  return t(
    'sheetmusic',
    'Songs linked to {duplicates} will be reassigned to {target}. Afterwards, the duplicate {entities} will be deleted.',
    {
      duplicates: duplicateNames,
      target: targetName,
      entities: duplicateEntityOptions.value.length === 1 ? props.singular : props.plural.toLowerCase(),
    },
    { escape: false },
  )
})

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      errorMessage.value = ''
      isSubmitting.value = false
      if (props.initialEntity) {
        targetEntityOption.value = null
        duplicateEntityOptions.value = [formatOption(props.initialEntity)]
      } else {
        targetEntityOption.value = null
        duplicateEntityOptions.value = []
      }
    }
  },
  { immediate: true },
)

function handleClose() {
  if (isSubmitting.value) return
  emit('close')
}

async function submitMerge() {
  if (!canSubmit.value || !targetEntityOption.value) return
  isSubmitting.value = true
  errorMessage.value = ''

  try {
    const keepId = targetEntityOption.value.id
    const duplicateIds = duplicateEntityOptions.value.map(d => d.id)
    const response = await apiPost('/api/entities/merge' as any, {
      libraryId: props.selectedLibrary,
      entityType: props.entityType,
      keepId,
      duplicateIds,
    })

    const data = (response as any)?.data?.ocs?.data
    emit('merged', data?.libraryData)
    showSuccess(
      t('sheetmusic', 'Merged duplicates into "{name}".', {
        name: targetEntityOption.value.name,
      }, { escape: false }),
    )
    emit('close')
  } catch (err: unknown) {
    const msg = extractApiErrorMessage(err, t('sheetmusic', 'Could not merge {entities}.', { entities: props.plural.toLowerCase() }, { escape: false }))
    errorMessage.value = msg
    showError(msg)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<style module>
.dialogContent {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 320px;
  max-width: 520px;
}

.description {
  margin: 0;
  color: var(--color-text-maxcontrast);
  font-size: 0.95rem;
  line-height: 1.4;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.fieldLabel {
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--color-main-text);
}

.select {
  width: 100%;
}

.swapContainer {
  display: flex;
  justify-content: center;
  margin: -4px 0;
}

.summaryBox {
  padding: 12px;
  border-radius: var(--border-radius-large, 8px);
  background-color: var(--color-background-hover);
  border: 1px solid var(--color-border);
}

.summaryText {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.4;
}

.error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.9rem;
}
</style>
