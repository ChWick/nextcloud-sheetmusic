<template>
  <div :class="$style.filesArea">
    {{ t('mobilesheetsmanager', 'Files view placeholder') }}
  </div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
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
