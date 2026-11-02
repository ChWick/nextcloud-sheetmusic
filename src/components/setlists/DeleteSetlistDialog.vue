<template>
  <NcDialog
    v-if="open"
    :name="t('sheetmusic', 'Delete setlist')"
    size="normal"
    @close="emit('close')">
    <p>
      {{ t('sheetmusic', 'Are you sure you want to delete "{name}"?', { name: setlistName }) }}
    </p>
    <p>{{ t('sheetmusic', 'Songs and bookmarks in your library will not be deleted.') }}</p>
    <p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
    <template #actions>
      <NcButton @click="emit('close')">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="error"
        :disabled="isDeleting"
        @click="emit('delete')">
        {{ t('sheetmusic', 'Delete setlist') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
  open: boolean
  setlistName: string
  isDeleting: boolean
  error: string
}>()

const emit = defineEmits<{
  'close': []
  'delete': []
}>()
</script>

<style module>
.error {
  color: var(--color-error);
  font-size: 0.85rem;
}
</style>
