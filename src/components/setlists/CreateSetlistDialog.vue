<template>
  <NcDialog
    v-if="open"
    :name="t('sheetmusic', 'New setlist')"
    size="normal"
    @close="emit('close')">
    <p>{{ t('sheetmusic', 'Enter a name for the new setlist:') }}</p>
    <NcTextField
      :model-value="name"
      :label="t('sheetmusic', 'Name')"
      :placeholder="t('sheetmusic', 'e.g. Autumn Recital 2026')"
      @update:model-value="emit('update:name', $event)"
      @keyup.enter="handleCreate" />
    <p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
    <template #actions>
      <NcButton @click="emit('close')">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="primary"
        :disabled="isCreating || !name.trim()"
        @click="handleCreate">
        {{ t('sheetmusic', 'Create setlist') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps<{
  open: boolean
  name: string
  isCreating: boolean
  error: string
}>()

const emit = defineEmits<{
  'close': []
  'update:name': [value: string]
  'create': []
}>()

function handleCreate() {
  if (!props.isCreating && props.name.trim()) {
    emit('create')
  }
}
</script>

<style module>
.error {
  color: var(--color-error);
  font-size: 0.85rem;
}
</style>
