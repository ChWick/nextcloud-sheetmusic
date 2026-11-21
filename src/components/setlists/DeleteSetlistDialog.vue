<template>
  <ConfirmDeleteDialog
    :open="open"
    :title="t('sheetmusic', 'Delete setlist')"
    :confirm-label="t('sheetmusic', 'Delete setlist')"
    :is-deleting="isDeleting"
    :error="error"
    :show-loading-icon="false"
    @close="emit('close')"
    @confirm="emit('delete')">
    <p>
      {{ t('sheetmusic', 'Are you sure you want to delete "{name}"?', { name: setlistName }, { escape: false }) }}
    </p>
    <p>{{ t('sheetmusic', 'Songs and bookmarks in your library will not be deleted.') }}</p>
  </ConfirmDeleteDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import ConfirmDeleteDialog from '../ConfirmDeleteDialog.vue'

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
