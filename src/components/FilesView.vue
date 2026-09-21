<template>
  <div :class="$style.filesArea">
    {{ t('mobilesheetsmanager', 'Files view placeholder') }}
    <button type="button" @click="regenerateFilePaths">{{ t('mobilesheetsmanager', 'Regenerate File Paths') }}</button>
  </div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateOcsUrl } from '@nextcloud/router';

const props = defineProps<{
  selectedLibrary: number;
}>();

async function regenerateFilePaths() {
  try {
    await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/regenerate-file-paths'), {
      libraryId: props.selectedLibrary,
    });

    console.log('File paths regenerated successfully');
  } catch (error) {
    console.error('Failed to regenerate file paths:', error);
  }
}
</script>

<style module>
.filesArea {
  display: grid;
  grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
  gap: 24px;
  align-items: start;
  padding: 16px;
}
</style>
