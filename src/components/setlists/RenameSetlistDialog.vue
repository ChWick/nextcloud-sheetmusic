<template>
  <NcDialog
    v-if="open"
    :name="t('sheetmusic', 'Rename setlist')"
    size="normal"
    @close="emit('close')">
    <p>{{ t('sheetmusic', 'Enter a new name for this setlist:') }}</p>
    <NcTextField
      :model-value="name"
      :label="t('sheetmusic', 'Name')"
      @update:model-value="emit('update:name', $event)"
      @keyup.enter="handleRename" />
    <p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
    <template #actions>
      <NcButton @click="emit('close')">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="primary"
        :disabled="isRenaming || !name.trim()"
        @click="handleRename">
        {{ t('sheetmusic', 'Save') }}
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
  isRenaming: boolean
  error: string
}>()

const emit = defineEmits<{
  'close': []
  'update:name': [value: string]
  'rename': []
}>()

function handleRename() {
  if (!props.isRenaming && props.name.trim()) {
    emit('rename')
  }
}
</script>

<style module>
.error {
  color: var(--color-error);
  font-size: 0.85rem;
}
</style>
