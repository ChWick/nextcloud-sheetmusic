<template>
  <ViewHeader :title="t('mobilesheetsmanager', 'Files')" />
  <div :class="$style.filesArea">
    <section :class="$style.toolCard">
      <h3 :class="$style.toolTitle">{{ t('mobilesheetsmanager', 'Sync file paths and metadata') }}</h3>
      <p :class="$style.toolIntro">
        {{ t('mobilesheetsmanager', 'Checks the whole library: every file is moved to the path its song’s naming rules produce, and every attached PDF gets its song’s document properties - title, composers, artists, books and collections - so it can be imported back into MobileSheets. The report lists everything that was checked, with links to the files that need attention.') }}
      </p>
      <ul :class="$style.toolPoints">
        <li>{{ t('mobilesheetsmanager', 'File paths: moved to their naming-rule location; missing files are reported.') }}</li>
        <li>{{ t('mobilesheetsmanager', 'Document properties: refreshed from the song data; files that already match are left untouched.') }}</li>
        <li>{{ t('mobilesheetsmanager', 'Hash codes and file facts: kept in step with both, so MobileSheets sees the changes.') }}</li>
      </ul>
      <div :class="$style.toolActions">
        <NcButton variant="primary"
                  :disabled="isRunning || selectedLibrary < 0"
                  @click="runSync">
          {{ isRunning ? t('mobilesheetsmanager', 'Syncing…') : t('mobilesheetsmanager', 'Check and sync now') }}
        </NcButton>
        <span v-if="selectedLibrary < 0" :class="$style.hint">
          {{ t('mobilesheetsmanager', 'Select a library first.') }}
        </span>
      </div>
    </section>
  </div>

  <NcDialog v-if="isDialogOpen"
            :name="t('mobilesheetsmanager', 'Sync report')"
            size="large"
            @close="closeDialog">
    <div v-if="isRunning" :class="$style.running">
      {{ t('mobilesheetsmanager', 'Syncing file paths and metadata…') }}
    </div>
    <template v-else>
      <p v-if="runError" :class="$style.runError" role="alert">{{ runError }}</p>
      <template v-else>
        <div :class="$style.stats">
          <span :class="$style.chip">{{ t('mobilesheetsmanager', '{count} files checked', { count: stats.files }) }}</span>
          <span v-if="stats.moved" :class="$style.chip">
            {{ t('mobilesheetsmanager', '{count} moved', { count: stats.moved }) }}
          </span>
          <span v-if="stats.updated" :class="$style.chip">
            {{ t('mobilesheetsmanager', '{count} document properties updated', { count: stats.updated }) }}
          </span>
          <span v-if="stats.current" :class="$style.chip">
            {{ t('mobilesheetsmanager', '{count} already up to date', { count: stats.current }) }}
          </span>
          <span v-if="stats.warnings" :class="[$style.chip, $style.warningChip]">
            ⚠ {{ t('mobilesheetsmanager', '{count} warnings', { count: stats.warnings }) }}
          </span>
          <span v-if="stats.errors" :class="[$style.chip, $style.errorChip]">
            ✖ {{ t('mobilesheetsmanager', '{count} errors', { count: stats.errors }) }}
          </span>
        </div>
        <p v-if="stats.warnings === 0 && stats.errors === 0" :class="$style.allGood">
          {{ t('mobilesheetsmanager', 'Everything is up to date: file paths and metadata.') }}
        </p>
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

        <ul :class="$style.log">
          <li v-for="(entry, index) in visibleLog"
              :key="index"
              :class="[$style.entry, $style[entry.level]]">
            <span :class="$style.badge" aria-hidden="true">{{ badgeFor(entry.level) }}</span>
            <div :class="$style.entryBody">
              <div :class="$style.entryHead">
                <button v-if="entry.songId > 0"
                        type="button"
                        :class="$style.songLink"
                        :title="t('mobilesheetsmanager', 'Open this song in the editor')"
                        @click="openSong(entry)">
                  {{ entry.songTitle || '#' + entry.songId }}
                </button>
                <strong v-else>{{ t('mobilesheetsmanager', 'Library') }}</strong>
                <code v-if="displayPath(entry)" :class="$style.path">{{ displayPath(entry) }}</code>
              </div>
              <p :class="$style.message">{{ messageFor(entry) }}</p>
            </div>
            <a v-if="fileLink(entry)"
               :href="fileLink(entry)"
               :class="$style.openLink"
               target="_blank"
               rel="noopener">
              {{ t('mobilesheetsmanager', 'Open in Files') }}
            </a>
          </li>
        </ul>
        <p v-if="visibleLog.length === 0">{{ t('mobilesheetsmanager', 'No matching entries.') }}</p>
      </template>
    </template>
    <div :class="$style.dialogActions">
      <NcButton @click="closeDialog">{{ t('mobilesheetsmanager', 'Close') }}</NcButton>
    </div>
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateOcsUrl, generateUrl } from '@nextcloud/router';
import { useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcDialog from '@nextcloud/vue/components/NcDialog';
import ViewHeader from './ViewHeader.vue';
import type { FileLogEntry, FileLogLevel } from '../types/song';

const props = defineProps<{
  selectedLibrary: number;
}>();

const router = useRouter();

/** Sort order of the levels, so a filter keeps everything at or above it. */
const LEVEL_ORDER: Record<FileLogLevel, number> = {
  info: 0,
  warning: 1,
  error: 2,
};

/**
 * The localized wording of every outcome code the sync tool reports.
 *
 * The codes are the stable interface between the run and this report: they are
 * what the server sends, and what turns into the user's language here. An
 * unknown code falls back to the message that came with it, so an older client
 * still shows something sensible.
 */
const CODE_MESSAGES: Record<string, string> = {
  'path-moved': t('mobilesheetsmanager', 'File moved to its naming-rule path'),
  'path-current': t('mobilesheetsmanager', 'File is already at its naming-rule path'),
  'no-naming-rule': t('mobilesheetsmanager', 'The song has no composer, artist or collection, so the file keeps its name'),
  'source-outside-library': t('mobilesheetsmanager', 'The stored path does not point into your files'),
  'source-missing': t('mobilesheetsmanager', 'The file recorded in the library database does not exist'),
  'path-persist-failed': t('mobilesheetsmanager', 'The library database rejected the move; the file was moved back'),
  'path-failed': t('mobilesheetsmanager', 'The file could not be moved'),
  'metadata-updated': t('mobilesheetsmanager', 'Document properties updated'),
  'metadata-current': t('mobilesheetsmanager', 'Document properties are already up to date'),
  'not-a-pdf': t('mobilesheetsmanager', 'Not a PDF - nothing to update'),
  'metadata-file-missing': t('mobilesheetsmanager', 'The file does not exist, so its document properties could not be checked'),
  'metadata-not-a-file': t('mobilesheetsmanager', 'The recorded path is not a file'),
  'metadata-unreadable': t('mobilesheetsmanager', 'The file could not be read, so its document properties could not be checked'),
  'metadata-write-failed': t('mobilesheetsmanager', 'The document properties could not be written'),
  'metadata-facts-failed': t('mobilesheetsmanager', 'The document properties were written, but the library database was not refreshed'),
  'metadata-failed': t('mobilesheetsmanager', 'The document properties could not be updated'),
  'hashcodes-unreadable': t('mobilesheetsmanager', 'The MobileSheets hash codes file could not be read'),
  'hashcodes-save-failed': t('mobilesheetsmanager', 'The MobileSheets hash codes file could not be saved'),
};

const isDialogOpen = ref(false);
const isRunning = ref(false);
const log = ref<FileLogEntry[]>([]);
const runError = ref('');
const filter = ref<FileLogLevel>('info');

const visibleLog = computed(() =>
  log.value.filter((entry) => LEVEL_ORDER[entry.level] >= LEVEL_ORDER[filter.value]),
);

/** The counts the report's chips are built from. */
const stats = computed(() => {
  const files = new Set(
    log.value.filter((entry) => entry.songId > 0).map((entry) => `${entry.songId}|${entry.filePath}`),
  ).size;
  const byLevel = (level: FileLogLevel) => log.value.filter((entry) => entry.level === level).length;
  const byCode = (codes: string[]) => log.value.filter((entry) => codes.includes(entry.code)).length;

  return {
    files,
    moved: byCode(['path-moved']),
    updated: byCode(['metadata-updated']),
    current: byCode(['path-current', 'metadata-current']),
    warnings: byLevel('warning'),
    errors: byLevel('error'),
  };
});

/** Run the sync tool and show its report. */
async function runSync() {
  isDialogOpen.value = true;
  isRunning.value = true;
  log.value = [];
  runError.value = '';
  filter.value = 'info';

  try {
    const response = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/sync-library'), {
      libraryId: props.selectedLibrary,
    });

    log.value = response.data.ocs.data.log ?? [];
  } catch (error) {
    console.error('Failed to sync file paths and metadata:', error);
    const serverMessage = axios.isAxiosError(error)
      ? error.response?.data?.ocs?.data?.message
      : undefined;
    runError.value = serverMessage
      ?? t('mobilesheetsmanager', 'The sync run failed. Please try again.');
  } finally {
    isRunning.value = false;
  }
}

function closeDialog() {
  isDialogOpen.value = false;
}

/** The wording of an entry: its code localized, its message as a fallback. */
function messageFor(entry: FileLogEntry): string {
  return CODE_MESSAGES[entry.code] ?? entry.message;
}

/** The glyph shown in front of an entry, matching its level's color. */
function badgeFor(level: FileLogLevel): string {
  return level === 'error' ? '✖' : level === 'warning' ? '⚠' : 'ℹ';
}

/** The stored WebDAV path as it reads below the user folder, or null. */
function relativeFromStored(filePath: string): string | null {
  const match = /^\/remote\.php\/dav\/files\/[^/]+\/(.+)$/.exec(filePath);
  return match ? match[1] : null;
}

/** The entry's file path shown short, without the WebDAV prefix. */
function displayPath(entry: FileLogEntry): string | null {
  return entry.filePath === '' ? null : relativeFromStored(entry.filePath);
}

/**
 * The Files app link for an entry: the file itself when its node id is known,
 * its folder otherwise - which covers files that do not exist (yet), where the
 * folder is exactly where the user has to look.
 */
function fileLink(entry: FileLogEntry): string | null {
  if (entry.fileId !== null && entry.fileId > 0) {
    return generateUrl('/f/{fileId}', { fileId: String(entry.fileId) });
  }

  const relative = relativeFromStored(entry.filePath);
  if (relative === null) {
    return null;
  }

  const separator = relative.lastIndexOf('/');
  const directory = separator === -1 ? '/' : `/${relative.slice(0, separator)}`;

  return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(directory);
}

/** Open the song an entry belongs to in the editor, right where it can be fixed. */
function openSong(entry: FileLogEntry) {
  if (entry.songId <= 0) {
    return;
  }

  closeDialog();
  router.push({
    name: 'LibraryView',
    params: { library: String(props.selectedLibrary), view: 'songs' },
    query: { song: String(entry.songId), edit: '1' },
  });
}
</script>

<style module>
.filesArea {
  display: flex;
  justify-content: center;
  padding: 24px 16px;
}

.toolCard {
  width: min(720px, 100%);
  padding: 24px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large, 12px);
}

.toolTitle {
  margin: 0 0 8px;
  font-size: 1.1rem;
  font-weight: 600;
}

.toolIntro {
  margin: 0;
}

.toolPoints {
  display: grid;
  gap: 4px;
  margin: 12px 0 0;
  padding-left: 20px;
  color: var(--color-text-maxcontrast);
}

.toolActions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 20px;
}

.hint {
  color: var(--color-text-maxcontrast);
}
.running {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 120px;
  color: var(--color-text-maxcontrast);
}

.runError {
  color: var(--color-error);
}

.stats {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.chip {
  padding: 4px 12px;
  border: 1px solid var(--color-border-maxcontrast);
  border-radius: 999px;
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
}

.warningChip {
  border-color: var(--color-warning);
  color: var(--color-warning);
}

.errorChip {
  border-color: var(--color-error);
  color: var(--color-error);
}

.allGood {
  margin: 0 0 12px;
  color: var(--color-success);
}

.filter {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.log {
  display: grid;
  gap: 8px;
  min-height: 200px;
  max-height: 60vh;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.entry {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 10px 12px;
  border: 1px solid var(--color-border);
  border-left-width: 3px;
  border-radius: var(--border-radius, 8px);
}

.entry.info {
  border-left-color: var(--color-border-maxcontrast);
}

.entry.warning {
  border-left-color: var(--color-warning);
}

.entry.error {
  border-left-color: var(--color-error);
}

.badge {
  width: 1.4em;
  flex-shrink: 0;
  text-align: center;
}

.entry.warning .badge {
  color: var(--color-warning);
}

.entry.error .badge {
  color: var(--color-error);
}

.entry.info .badge {
  color: var(--color-text-maxcontrast);
}

.entryBody {
  min-width: 0;
  flex: 1;
}

.entryHead {
  display: flex;
  gap: 8px;
  align-items: baseline;
  flex-wrap: wrap;
}

.songLink {
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-weight: 600;
  text-align: start;
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}

.path {
  font-family: monospace;
  font-size: 0.85em;
  color: var(--color-text-maxcontrast);
  word-break: break-all;
}

.message {
  margin: 2px 0 0;
}

.entry.info .message {
  color: var(--color-text-maxcontrast);
}

.entry.warning .message {
  color: var(--color-warning);
}

.entry.error .message {
  color: var(--color-error);
}

.openLink {
  align-self: center;
  margin-inline-start: auto;
  white-space: nowrap;
  font-size: 0.9em;
}

.dialogActions {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>

};
