<template>
  <ViewHeader :title="t('mobilesheetsmanager', 'Files')" />
  <div :class="$style.filesArea">
    {{ t('mobilesheetsmanager', 'Files view placeholder') }}
    <button type="button" @click="regenerateFilePaths">{{ t('mobilesheetsmanager', 'Regenerate File Paths') }}</button>
  </div>

  <NcDialog
    v-if="isDialogOpen"
    :name="t('mobilesheetsmanager', 'Regenerate File Paths')"
    size="large"
    @close="isDialogOpen = false">
    <div :class="$style.filter">
      <NcButton :variant="filter === 'info' ? 'primary' : 'secondary'" @click="filter = 'info'">
        {{ t('mobilesheetsmanager', 'All') }}
      </NcButton>
      <NcButton :variant="filter === 'warning' ? 'primary' : 'secondary'" @click="filter = 'warning'">
        {{ t('mobilesheetsmanager', 'Warnings and higher') }}
      </NcButton>
      <NcButton :variant="filter === 'error' ? 'primary' : 'secondary'" @click="filter = 'error'">
        {{ t('mobilesheetsmanager', 'Errors only') }}
      </NcButton>
    </div>

    <div :class="$style.log">
      <p v-if="isRunning">{{ t('mobilesheetsmanager', 'Regenerating file paths…') }}</p>
      <template v-else>
        <p>{{ summary }}</p>
        <ul>
          <li v-for="(entry, index) in visibleLog" :key="index" :class="$style[entry.level]">
            {{ entry.songTitle }} — {{ entry.filePath }}: {{ entry.message }}
          </li>
        </ul>
        <p v-if="visibleLog.length === 0">{{ t('mobilesheetsmanager', 'No matching entries.') }}</p>
      </template>
    </div>
    <div :class="$style.dialogActions">
      <NcButton @click="isDialogOpen = false">{{ t('mobilesheetsmanager', 'Close') }}</NcButton>
    </div>
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateOcsUrl } from '@nextcloud/router';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcDialog from '@nextcloud/vue/components/NcDialog';
import ViewHeader from './ViewHeader.vue';
import type { FileLogEntry, FileLogLevel } from '../types/song';

const props = defineProps<{
  selectedLibrary: number;
}>();

const LEVEL_ORDER: Record<FileLogLevel, number> = {
  info: 0,
  warning: 1,
  error: 2,
};

const isDialogOpen = ref(false);
const isRunning = ref(false);
const log = ref<FileLogEntry[]>([]);
const summary = ref('');
const filter = ref<FileLogLevel>('info');

const visibleLog = computed(() =>
  log.value.filter((entry) => LEVEL_ORDER[entry.level] >= LEVEL_ORDER[filter.value]),
);

async function regenerateFilePaths() {
  isDialogOpen.value = true;
  isRunning.value = true;
  log.value = [];
  summary.value = '';
  filter.value = 'info';

  try {
    const response = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/regenerate-file-paths'), {
      libraryId: props.selectedLibrary,
    });

    log.value = response.data.ocs.data.log ?? [];
    summary.value = response.data.ocs.data.message ?? '';
  } catch (error) {
    console.error('Failed to regenerate file paths:', error);
    summary.value = t('mobilesheetsmanager', 'Failed to regenerate file paths');
  } finally {
    isRunning.value = false;
  }
}
</script>

<style module>
.filesArea {
  display: grid;
  grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
  gap: 24px;
  align-items: start;
  margin: 16px;
}

.filter {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.log {
  min-height: 200px;
  max-height: 60vh;
  overflow-y: auto;
}

.log ul {
  margin: 8px 0 0;
  padding-left: 24px;
}

.info {
  color: var(--color-text-maxcontrast);
}

.warning {
  color: var(--color-warning, #b58900);
}

.error {
  color: var(--color-error, #e9322d);
}

.dialogActions {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
