<template>
  <ViewHeader
    :title="standaloneCreate ? (duplicateSourceSong ? t('sheetmusic', 'Duplicate: {title}', { title: duplicateSourceSong.title }) : t('sheetmusic', 'Create Song')) : songIdInUrl !== null ? (isModifyPdfInUrl ? (openSong?.title ? t('sheetmusic', 'Modify PDF: {title}', { title: openSong.title }) : t('sheetmusic', 'Modify PDF')) : (isEditInUrl ? (openSong?.title ? t('sheetmusic', 'Edit: {title}', { title: openSong.title }) : t('sheetmusic', 'Edit song')) : (openSong?.title ?? t('sheetmusic', 'Songs')))) : t('sheetmusic', 'Songs')"
    :show-back="songIdInUrl !== null || standaloneCreate"
    :create-label="songIdInUrl === null && !standaloneCreate ? t('sheetmusic', 'New song') : undefined"
    :import-label="songIdInUrl === null && !standaloneCreate ? t('sheetmusic', 'Import') : undefined"
    :import-nextcloud-label="songIdInUrl === null && !standaloneCreate ? t('sheetmusic', 'Import from Nextcloud') : undefined"
    :filter-bar-visible="songIdInUrl === null && !standaloneCreate ? filterBarVisible : undefined"
    :show-filter-toggle="songIdInUrl === null && !standaloneCreate"
    @back="goBackToSongs"
    @create="startNewSong"
    @import="startImport"
    @import-nextcloud="startImportNextcloud"
    @toggle-filters="toggleFilterBar">
    <div v-if="songIdInUrl === null && !standaloneCreate" :class="$style.headerControls">
      <SongColumnToggle v-model:visible-columns="visibleColumns" />
      <SongSortControl v-model:sort-key="sortKey" v-model:sort-order="sortOrder" />
      <SongViewToggle v-model:view-mode="viewMode" />
    </div>
    <template v-else-if="isEditInUrl && openSong">
      <NcButton
        v-if="isEditInUrl && openSong"
        variant="error"
        :title="t('sheetmusic', 'Delete song')"
        :aria-label="t('sheetmusic', 'Delete song')"
        @click="songEditorRef?.promptDelete()">
        <template #icon>
          <DeleteIcon :size="20" />
        </template>
      </NcButton>
      <NcButton
        v-if="isEditInUrl && openSong"
        variant="secondary"
        :title="t('sheetmusic', 'Save without closing')"
        :aria-label="t('sheetmusic', 'Save without closing')"
        @click="songEditorRef?.saveSong(false)">
        <template #icon>
          <ContentSaveOutlineIcon :size="20" />
        </template>
      </NcButton>
      <NcButton
        v-if="isEditInUrl && openSong"
        variant="primary"
        :title="t('sheetmusic', 'Save and close')"
        :aria-label="t('sheetmusic', 'Save and close')"
        @click="songEditorRef?.saveSong(true)">
        <template #icon>
          <CheckIcon :size="20" />
        </template>
      </NcButton>
    </template>
    <template v-else-if="standaloneCreate">
      <NcButton
        variant="secondary"
        :title="t('sheetmusic', 'Cancel')"
        :aria-label="t('sheetmusic', 'Cancel')"
        @click="goBackToSongs">
        <template #icon>
          <CloseIcon :size="20" />
        </template>
      </NcButton>
      <NcButton
        variant="primary"
        :title="t('sheetmusic', 'Create song')"
        :aria-label="t('sheetmusic', 'Create song')"
        @click="createSongEditorRef?.saveSong(true)">
        <template #icon>
          <CheckIcon :size="20" />
        </template>
      </NcButton>
    </template>
    <template v-else-if="!standaloneCreate && !isEditInUrl && !isModifyPdfInUrl">
      <NcButton
        v-if="songViewerRef?.selectedFile"
        variant="secondary"
        :title="t('sheetmusic', 'Open in external viewer')"
        :aria-label="t('sheetmusic', 'Open in external viewer')"
        @click="songViewerRef?.openInExternalViewer">
        <template #icon>
          <OpenInNewIcon :size="20" />
        </template>
      </NcButton>
      <NcButton
        v-if="openSong && songViewerRef?.selectedFile?.path?.toLowerCase().endsWith('.pdf')"
        variant="secondary"
        :title="t('sheetmusic', 'Modify PDF (rotate, crop, split)')"
        :aria-label="t('sheetmusic', 'Modify PDF (rotate, crop, split)')"
        @click="openSong && openModifyPdf(openSong, songViewerRef?.selectedFile?.id)">
        <template #icon>
          <FileDocumentEditOutline :size="20" />
        </template>
      </NcButton>
      <NcButton
        v-if="openSong"
        variant="secondary"
        :title="t('sheetmusic', 'Duplicate song')"
        :aria-label="t('sheetmusic', 'Duplicate song')"
        @click="duplicateSong(openSong)">
        <template #icon>
          <ContentCopyIcon :size="20" />
        </template>
      </NcButton>
      <NcButton
        variant="secondary"
        :title="t('sheetmusic', 'Edit song')"
        :aria-label="t('sheetmusic', 'Edit song')"
        @click="openSong && openSongEdit(openSong)">
        <template #icon>
          <PencilIcon :size="20" />
        </template>
      </NcButton>
      <NcButton
        variant="error"
        :title="t('sheetmusic', 'Delete song')"
        :aria-label="t('sheetmusic', 'Delete song')"
        @click="songViewerRef?.promptDelete">
        <template #icon>
          <DeleteIcon :size="20" />
        </template>
      </NcButton>
    </template>
  </ViewHeader>
  <!-- Import reuses the create editor: the picked PDF is read for its metadata and
       staged as the new song's file. -->
  <input
    ref="importInput"
    type="file"
    hidden
    @change="handleImportFile">
  <!-- The URL opens one song full screen ("?song=12"), for viewing it or - with the
       edit flag - for editing it; without a song the list is shown. -->
  <div v-if="songIdInUrl !== null" :class="$style.fullscreenArea">
    <SongEditor v-if="isEditInUrl"
                ref="songEditorRef"
                :song="openSong"
                :library-id="selectedLibrary"
                :composers="composers"
                :books="books"
                :collections="collections"
                :artists="artists"
                :genres="genres"
                :keys="keys"
                :custom-groups="customGroups"
                :years="years"
                :signatures="signatures"
                :source-types="sourceTypes"
                :instruments="instruments"
                @saved="handleSaved"
                @updated="handleLibraryDataUpdate"
                @deleted="handleDeleted"
                @cancel="goBackToSongs" />
    <SongPdfModifier
      v-else-if="isModifyPdfInUrl && openSong"
      :song="openSong"
      :file-id="route.query.fileId as string"
      :library-id="selectedLibrary"
      :composers="composers"
      :books="books"
      :collections="collections"
      :artists="artists"
      :genres="genres"
      :keys="keys"
      :custom-groups="customGroups"
      :years="years"
      :signatures="signatures"
      :source-types="sourceTypes"
      :instruments="instruments"
      @saved="handlePdfModifierSaved"
      @cancel="closeModifyPdf" />
    <SongViewer
      v-else
      ref="songViewerRef"
      :key="openSong ? `${openSong.id}-${openSong.lastModified ?? ''}` : 'no-song'"
      :hide-header="true"
      :song="openSong"
      :library-id="selectedLibrary"
      :composers="composers"
      :books="books"
      :collections="collections"
      :artists="artists"
      :genres="genres"
      :keys="keys"
      :custom-groups="customGroups"
      :years="years"
      :signatures="signatures"
      :source-types="sourceTypes"
      :instruments="instruments"
      @back="goBackToSongs"
      @modify-pdf="openModifyPdf"
      @saved="handleSaved"
      @updated="handleLibraryDataUpdate"
      @deleted="handleDeleted" />
  </div>
  <div v-else :class="[$style.songArea, standaloneCreate && $style.createSongArea]">
    <div v-if="!standaloneCreate && selectedCount > 0" :class="$style.selectionBanner">
      <span :class="$style.selectionCount">{{ t('sheetmusic', '{count} songs selected', { count: selectedCount }) }}</span>
      <NcButton
        v-if="selectedCount === 2"
        size="small"
        variant="secondary"
        :title="t('sheetmusic', 'Compare & Merge selected 2 songs')"
        :aria-label="t('sheetmusic', 'Compare & Merge selected 2 songs')"
        @click="openCompareMergeDialog">
        <template #icon>
          <MergeIcon :size="18" />
        </template>
        {{ t('sheetmusic', 'Compare / Merge') }}
      </NcButton>
      <NcButton
        size="small"
        variant="primary"
        :title="t('sheetmusic', 'Edit selected ({count})', { count: selectedCount })"
        :aria-label="t('sheetmusic', 'Edit selected ({count})', { count: selectedCount })"
        @click="isBatchEditorOpen = true">
        <template #icon>
          <PencilIcon :size="18" />
        </template>
      </NcButton>
      <NcButton
        size="small"
        variant="error"
        :title="t('sheetmusic', 'Delete selected ({count})', { count: selectedCount })"
        :aria-label="t('sheetmusic', 'Delete selected ({count})', { count: selectedCount })"
        @click="isBatchDeleteDialogOpen = true">
        <template #icon>
          <DeleteIcon :size="18" />
        </template>
      </NcButton>
      <NcButton
        size="small"
        :title="t('sheetmusic', 'Clear selection')"
        :aria-label="t('sheetmusic', 'Clear selection')"
        @click="clearSelection">
        <template #icon>
          <CloseIcon :size="18" />
        </template>
      </NcButton>
    </div>

    <div v-if="!standaloneCreate && filterBarVisible" :class="$style.filterBarWrapper"><SongFilterBar
      v-model:search-title="searchTitle"
      v-model:without-files="withoutFiles"
      v-model:selected-composers="selectedComposers"
      v-model:selected-books="selectedBooks"
      v-model:selected-collections="selectedCollections"
      v-model:selected-artists="selectedArtists"
      v-model:selected-genres="selectedGenres"
      v-model:selected-keys="selectedKeys"
      v-model:selected-custom-groups="selectedCustomGroups"
      v-model:selected-years="selectedYears"
      v-model:selected-signatures="selectedSignatures"
      v-model:selected-source-types="selectedSourceTypes"
      v-model:selected-instruments="selectedInstruments"
      :composers="composers"
      :books="books"
      :collections="collections"
      :artists="artists"
      :genres="genres"
      :keys="keys"
      :custom-groups="customGroups"
      :years="years"
      :signatures="signatures"
      :source-types="sourceTypes"
      :instruments="instruments"
      @clear="clearFilters"/>
    </div>
    <SongEditor v-if="isCreatingSong"
                ref="createSongEditorRef"
                :song="null"
                :library-id="selectedLibrary"
                :hide-actions="standaloneCreate"
                :composers="composers"
                :books="books"
                :collections="collections"
                :artists="artists"
                :genres="genres"
                :keys="keys"
                :custom-groups="customGroups"
                :years="years"
                :signatures="signatures"
                :source-types="sourceTypes"
                :instruments="instruments"
                :duplicate-song="duplicateSourceSong"
                :prefill="importPrefill"
                :imported-file="importedFile"
                :imported-nextcloud-node="importedNextcloudNode"
                @saved="handleSaved"
                @cancel="isCreatingSong = false" />
    <div v-if="!standaloneCreate && !isLoading && !fileError && songs.length === 0" :class="$style.emptyNotice">
      {{ t('sheetmusic', 'No songs found in this library.') }}
    </div>
    <div v-else-if="!standaloneCreate && !isLoading && !fileError && songs.length > 0 && filteredSongs.length === 0" :class="$style.emptyNotice">
      {{ t('sheetmusic', 'No songs match the selected filters.') }}
    </div>
    <template v-else-if="!standaloneCreate">
      <SongTableView
        v-if="viewMode === 'table'"
        :songs="filteredSongs"
        :selected-song-ids="selectedSongIds"
        :is-filtered="isFiltered"
        :visible-columns="visibleColumns"
        v-model:sort-key="sortKey"
        v-model:sort-order="sortOrder"
        @view-song="openSongView"
        @edit-song="openSongEdit"
        @filter-entity="toggleFilter"
        @toggle-select-song="toggleSelect"
        @select-all="selectAll" />
      <SongCardView
        v-else-if="viewMode === 'cards'"
        :songs="sortedSongs"
        :selected-song-ids="selectedSongIds"
        :is-filtered="isFiltered"
        :visible-columns="visibleColumns"
        @view-song="openSongView"
        @edit-song="openSongEdit"
        @filter-entity="toggleFilter"
        @toggle-select-song="toggleSelect" />
      <SongSimpleListView
        v-else
        :songs="sortedSongs"
        :selected-song-ids="selectedSongIds"
        :is-filtered="isFiltered"
        :visible-columns="visibleColumns"
        @view-song="openSongView"
        @edit-song="openSongEdit"
        @filter-entity="toggleFilter"
        @toggle-select-song="toggleSelect" />
    </template>
  </div>

  <BatchSongEditorDialog
    v-model:open="isBatchEditorOpen"
    :selected-count="selectedCount"
    :composers="composers"
    :books="books"
    :collections="collections"
    :artists="artists"
    :genres="genres"
    :keys="keys"
    :custom-groups="customGroups"
    :years="years"
    :signatures="signatures"
    :source-types="sourceTypes"
    :instruments="instruments"
    @apply="handleApplyBatchUpdate" />

  <BatchDeleteSongsDialog
    v-model:open="isBatchDeleteDialogOpen"
    :library-id="selectedLibrary"
    :song-ids="selectedSongIds"
    @deleted="handleBatchDeleted" />

  <SongMergeDialog
    v-if="isMergeDialogOpen && mergeIssue && currentLibraryData"
    v-model:open="isMergeDialogOpen"
    :issue="mergeIssue"
    :library-data="currentLibraryData"
    :is-merging="isMerging"
    :error="mergeError"
    @merge="executeMerge"
    @resolve-both="executeResolveBoth" />
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { FilePickerClosed, getFilePickerBuilder, showError, showInfo, showSuccess } from '@nextcloud/dialogs';
import { describeRequestFailure } from '../utils/api';
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import CheckIcon from 'vue-material-design-icons/Check.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue';
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue';
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue';
import DeleteIcon from 'vue-material-design-icons/Delete.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import PencilIcon from 'vue-material-design-icons/Pencil.vue';
import SongEditor from '../components/SongEditor.vue';
import SongFilterBar from '../components/SongFilterBar.vue';
import SongViewer from '../components/SongViewer.vue';
import SongPdfModifier from '../components/song-pdf/SongPdfModifier.vue';
import ViewHeader from '../components/ViewHeader.vue';
import BatchSongEditorDialog, { type BatchUpdatePayload } from '../components/song-editor/BatchSongEditorDialog.vue';
import BatchDeleteSongsDialog from '../components/BatchDeleteSongsDialog.vue';
import SongMergeDialog, { type MergedSongData, type ResolveBothSongData } from '../components/song-editor/SongMergeDialog.vue';
import type { Artist, Song, Composer, Book, Collection, CustomGroup, Genre, IntegrityIssue, Key, LibraryData, Signature, SongPrefill, SourceType, Year } from '../types/song';
import { DEFAULT_VISIBLE_COLUMNS, type SongColumn } from '../types/song';
import { sortSongs, type SortKey, type SortOrder } from '../utils/song-formatter';
import { useSongFilters } from '../composables/useSongFilters';
import { useSongSelection } from '../composables/useSongSelection';
import { useLibraryData } from '../composables/useLibraryData';
import { apiPost } from '../api/client';
import SongColumnToggle from './songs/SongColumnToggle.vue';
import SongSortControl from './songs/SongSortControl.vue';
import SongViewToggle, { type ViewMode } from './songs/SongViewToggle.vue';
import SongTableView from './songs/SongTableView.vue';
import SongCardView from './songs/SongCardView.vue';
import SongSimpleListView from './songs/SongSimpleListView.vue';

// Props from parent (App.vue)
const props = withDefaults(defineProps<{ selectedLibrary: number; standaloneCreate?: boolean }>(), {
  standaloneCreate: false,
});

const songViewerRef = ref<InstanceType<typeof SongViewer> | null>(null);
const songEditorRef = ref<InstanceType<typeof SongEditor> | null>(null);
const createSongEditorRef = ref<InstanceType<typeof SongEditor> | null>(null);

const viewMode = ref<ViewMode>(
  (localStorage.getItem('sheetmusic_song_view_mode') ?? localStorage.getItem('mobilesheetsmanager_song_view_mode')) as ViewMode || 'table'
);

watch(viewMode, (newMode) => {
  localStorage.setItem('sheetmusic_song_view_mode', newMode);
});

const sortKey = ref<SortKey>(
  (localStorage.getItem('sheetmusic_song_sort_key') ?? localStorage.getItem('mobilesheetsmanager_song_sort_key')) as SortKey || 'title'
);

const sortOrder = ref<SortOrder>(
  (localStorage.getItem('sheetmusic_song_sort_order') ?? localStorage.getItem('mobilesheetsmanager_song_sort_order')) as SortOrder || 'asc'
);

watch(sortKey, (newKey) => {
  localStorage.setItem('sheetmusic_song_sort_key', newKey);
});

watch(sortOrder, (newOrder) => {
  localStorage.setItem('sheetmusic_song_sort_order', newOrder);
});

const visibleColumns = ref<SongColumn[]>(
  (() => {
    const stored = localStorage.getItem('sheetmusic_song_visible_columns') ?? localStorage.getItem('mobilesheetsmanager_song_visible_columns');
    if (!stored) return DEFAULT_VISIBLE_COLUMNS;
    try {
      const parsed = JSON.parse(stored);
      if (Array.isArray(parsed) && parsed.length > 0) return parsed;
    } catch {
      // fallback
    }
    return DEFAULT_VISIBLE_COLUMNS;
  })()
);

watch(visibleColumns, (newCols) => {
  localStorage.setItem('sheetmusic_song_visible_columns', JSON.stringify(newCols));
}, { deep: true });

const {
  songs,
  composers,
  books,
  collections,
  artists,
  genres,
  keys,
  customGroups,
  years,
  signatures,
  sourceTypes,
  instruments,
  fileError,
  isLoading,
  handleLibraryDataUpdate,
  loadLibraryData,
} = useLibraryData();

// The open song lives in the URL query ("?song=12") like the filters do, so the
// browser history restores it and going back returns to the song list.
const route = useRoute();
const router = useRouter();

/** Id of the song the URL opens; null while the song list is shown. */
const songIdInUrl = computed(() => {
  const raw = route.query.song;
  const value = Array.isArray(raw) ? raw[0] : raw;
  const id = Number(value);
  return value === undefined || value === null || !Number.isInteger(id) || id <= 0 ? null : id;
});

/** The open song, resolved against the loaded library data. */
const openSong = computed(() => songs.value.find(song => song.id === songIdInUrl.value) ?? null);

const duplicateSourceId = computed(() => {
  const raw = route.query.duplicate;
  const value = Array.isArray(raw) ? raw[0] : raw;
  const id = Number(value);
  return value === undefined || value === null || !Number.isInteger(id) || id <= 0 ? null : id;
});
const duplicateSourceSong = computed(() =>
  props.standaloneCreate && duplicateSourceId.value !== null
    ? songs.value.find(song => song.id === duplicateSourceId.value) ?? null
    : null,
);

/** The URL asks for the editor of the open song ("?song=12&edit=1"). */
const isEditInUrl = computed(() => route.query.edit !== undefined && route.query.edit !== null);

/** The URL asks for the PDF modifier view ("?song=12&modifyPdf=1"). */
const isModifyPdfInUrl = computed(() => route.query.modifyPdf !== undefined && route.query.modifyPdf !== null);

// The filter bar reads and writes its selections in the URL query, so the browser
// history restores them when going back or forward.
const { searchTitle, withoutFiles, selectedComposers, selectedBooks, selectedCollections, selectedArtists, selectedGenres, selectedKeys, selectedCustomGroups, selectedYears, selectedSignatures, selectedSourceTypes, selectedInstruments, filterSongs, clearFilters, filterBarVisible, toggleFilterBar, isFiltered, toggleFilter } =
  useSongFilters(() => ({ composers: composers.value, books: books.value, collections: collections.value, artists: artists.value, genres: genres.value, keys: keys.value, customGroups: customGroups.value, years: years.value, signatures: signatures.value, sourceTypes: sourceTypes.value, instruments: instruments.value }));

const { selectedSongIds, selectedCount, isSelected, toggleSelect, selectAll, isAllSelected, clearSelection } = useSongSelection();
const isBatchEditorOpen = ref(false);
const isBatchDeleteDialogOpen = ref(false);

// Compare & Merge dialog state
const isMergeDialogOpen = ref(false);
const isMerging = ref(false);
const mergeError = ref('');
const mergeIssue = ref<IntegrityIssue | null>(null);

const currentLibraryData = computed<LibraryData>(() => ({
  composers: composers.value,
  books: books.value,
  collections: collections.value,
  artists: artists.value,
  genres: genres.value,
  keys: keys.value,
  customGroups: customGroups.value,
  years: years.value,
  signatures: signatures.value,
  sourceTypes: sourceTypes.value,
  instruments: instruments.value,
  songs: songs.value,
}));

function openCompareMergeDialog() {
  if (selectedSongIds.value.length !== 2) return;
  const songA = songs.value.find(s => s.id === selectedSongIds.value[0]);
  const songB = songs.value.find(s => s.id === selectedSongIds.value[1]);
  if (!songA || !songB) return;

  mergeIssue.value = {
    type: 'identical_song',
    severity: 'info',
    message: t('sheetmusic', 'Comparing 2 selected songs for merge or conflict resolution.'),
    songId: songA.id,
    songTitle: songA.title,
    fixable: true,
    details: {
      songIds: [songA.id, songB.id],
      exactDuplicate: false,
    },
  };
  mergeError.value = '';
  isMergeDialogOpen.value = true;
}

async function executeMerge(payload: {
  keepId: number
  droppedId: number
  merged: Record<string, unknown>
}) {
  isMerging.value = true;
  mergeError.value = '';
  try {
    const response = await axios.post(
      `/ocs/v2.php/apps/sheetmusic/api/songs/merge`,
      {
        libraryId: props.selectedLibrary,
        keepId: payload.keepId,
        droppedId: payload.droppedId,
        merged: payload.merged,
      },
      { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
    );
    const targetSong = songs.value.find(s => s.id === payload.targetSongId);
    isMergeDialogOpen.value = false;
    mergeIssue.value = null;
    clearSelection();
    if (response.data?.ocs?.data) {
      handleLibraryDataUpdate(response.data.ocs.data);
    } else {
      await loadLibraryData(props.selectedLibrary);
    }
    const targetTitle = targetSong?.title ?? '';
    if (targetTitle) {
      showSuccess(t('sheetmusic', 'Songs merged successfully into "{title}".', { title: targetTitle }));
    } else {
      showSuccess(t('sheetmusic', 'Songs merged successfully.'));
    }
  } catch (error) {
    console.error('Failed to merge songs:', error);
    const serverMessage = axios.isAxiosError(error)
      ? error.response?.data?.ocs?.data?.message ?? error.response?.data?.message
      : undefined;
    mergeError.value = serverMessage
      ?? t('sheetmusic', 'Failed to merge songs. Please try again.');
    showError(mergeError.value);
  } finally {
    isMerging.value = false;
  }
}

async function executeResolveBoth(payload: {
  songA: ResolveBothSongData
  songB: ResolveBothSongData
}) {
  if (props.selectedLibrary < 0) return;

  isMerging.value = true;
  mergeError.value = '';
  try {
    const response = await apiPost('/api/songs/resolve-both', {
      libraryId: props.selectedLibrary,
      songA: payload.songA,
      songB: payload.songB,
    });

    isMergeDialogOpen.value = false;
    mergeIssue.value = null;
    clearSelection();
    if (response.data?.ocs?.data) {
      handleLibraryDataUpdate(response.data.ocs.data);
    } else {
      await loadLibraryData(props.selectedLibrary);
    }
    showSuccess(t('sheetmusic', 'Both songs saved successfully.'));
  } catch (error: any) {
    console.error('Failed to resolve both songs:', error);
    const serverMessage = error?.response?.data?.ocs?.data?.message
      ?? error?.response?.data?.message
      ?? error?.message;
    mergeError.value = serverMessage
      ?? t('sheetmusic', 'Failed to save updated songs. Please try again.');
    showError(mergeError.value);
  } finally {
    isMerging.value = false;
  }
}

async function handleApplyBatchUpdate(payload: BatchUpdatePayload) {
  if (selectedSongIds.value.length === 0) return;
  const count = selectedSongIds.value.length;
  try {
    const response = await apiPost('/api/songs-batch-update', {
      libraryId: props.selectedLibrary,
      songIds: selectedSongIds.value,
      ...payload,
    });
    isBatchEditorOpen.value = false;
    clearSelection();
    if (response.data?.ocs?.data) {
      handleLibraryDataUpdate(response.data.ocs.data);
    }
    showSuccess(t('sheetmusic', 'Successfully updated {count} songs.', { count }));
  } catch (error) {
    console.error('Failed to batch update songs:', error);
    showError(describeRequestFailure(error, t('sheetmusic', 'Failed to update selected songs.')));
  }
}

function handleBatchDeleted(data: LibraryData | undefined, count: number) {
  isBatchDeleteDialogOpen.value = false;
  clearSelection();
  if (data) {
    handleLibraryDataUpdate(data);
  } else {
    loadLibraryData(props.selectedLibrary);
  }
  showSuccess(t('sheetmusic', 'Successfully deleted {count} songs.', { count }));
}

/** The editor is open for a song that does not exist yet. */
const isCreatingSong = ref(props.standaloneCreate);
/** Metadata read from the PDF an import picked, prefilled into the new song. */
const importPrefill = ref<SongPrefill | null>(null);
/** The PDF an import picked from local computer, staged as the new song's file. */
const importedFile = ref<File | null>(null);
/** The PDF an import picked from Nextcloud internal storage, staged as the new song's file attachment. */
const importedNextcloudNode = ref<{ path: string; name: string } | null>(null);
const importInput = ref<HTMLInputElement>();

// Computed filtered list
const filteredSongs = computed(() => filterSongs(songs.value));
const sortedSongs = computed(() => sortSongs(filteredSongs.value, sortKey.value, sortOrder.value));

// Watch for library prop changes
watch(() => props.selectedLibrary, (newLib) => {
  if (newLib) loadLibraryData(newLib);
}, { immediate: true });

watch(() => props.standaloneCreate, (standaloneCreate) => {
  isCreatingSong.value = standaloneCreate;
}, { immediate: true });

/**
 * View a song full screen. It becomes one history entry - the filters of the list
 * stay in the URL - so the browser back button returns to the song list as well.
 */
function openSongView(song: Song) {
  // Opening a song leaves the editor for a new song behind, like pressing Cancel.
  isCreatingSong.value = false;
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: { ...queryWithoutOpenSong(), song: String(song.id) },
  });
}

/** Edit a song right away: the URL opens its editor instead of the song view. */
function openSongEdit(song: Song) {
  isCreatingSong.value = false;
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: { ...queryWithoutOpenSong(), song: String(song.id), edit: '1' },
  });
}

/** Open the PDF modifier for a song. */
function openModifyPdf(song: Song, fileId?: string | number) {
  isCreatingSong.value = false;
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: {
      ...queryWithoutOpenSong(),
      song: String(song.id),
      modifyPdf: '1',
      ...(fileId ? { fileId: String(fileId) } : {}),
    },
  });
}

/** Close the PDF modifier and return to viewing the song. */
function closeModifyPdf() {
  if (openSong.value) {
    router.push({
      name: 'LibraryView',
      params: { library: props.selectedLibrary, view: 'songs' },
      query: { ...queryWithoutOpenSong(), song: String(openSong.value.id) },
    });
  } else {
    goBackToSongs();
  }
}

function handlePdfModifierSaved(data: LibraryData, songId?: number) {
  handleLibraryDataUpdate(data);
  if (songId) {
    const targetSong = songs.value.find(s => s.id === songId)
      ?? data.songs?.find(s => s.id === songId);
    if (targetSong) {
      openSongView(targetSong);
      return;
    }
    router.push({
      name: 'LibraryView',
      params: { library: props.selectedLibrary, view: 'songs' },
      query: { ...queryWithoutOpenSong(), song: String(songId) },
    });
  } else {
    goBackToSongs();
  }
}

/** The query without the open song and its edit flag; the filters stay. */
function queryWithoutOpenSong() {
  const query = { ...route.query };
  delete query.song;
  delete query.edit;
  delete query.duplicate;
  delete query.modifyPdf;
  delete query.fileId;
  return query;
}

/** Return to the song list, which the URL keeps exactly as it was. */
function goBackToSongs() {
  if (props.standaloneCreate) {
    router.push({
      name: 'LibraryView',
      params: { library: props.selectedLibrary, view: 'songs' },
      query: queryWithoutOpenSong(),
    });
    return;
  }

  if (window.history.state?.back) {
    router.back();
  } else {
    // A shared URL opened the song directly, so the list is entered anew.
    router.push({ query: queryWithoutOpenSong() });
  }
}

/** Open the editor for a song that does not exist yet. Cancelling leaves no trace. */
function startNewSong() {
  // A plain create starts empty; anything an import staged before is dropped.
  importPrefill.value = null;
  importedFile.value = null;
  importedNextcloudNode.value = null;
  openNewSong();
}

/** Open the create editor. */
function openNewSong() {
  isCreatingSong.value = true;
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'new-song' },
    query: queryWithoutOpenSong(),
  });
}

/** Open the new-song editor prefilled with the selected song, without its attached files. */
function duplicateSong(song: Song) {
  isCreatingSong.value = true;
  importPrefill.value = null;
  importedFile.value = null;
  importedNextcloudNode.value = null;
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'new-song' },
    query: { ...queryWithoutOpenSong(), duplicate: String(song.id) },
  });
}

/** Open the file picker: the PDF picked here seeds a new song with its metadata. */
function startImport() {
  importInput.value?.click();
}

/** Open Nextcloud file picker to import a PDF from internal storage. */
async function startImportNextcloud() {
  const picker = getFilePickerBuilder(t('sheetmusic', 'Select a file from Nextcloud'))
    .setMultiSelect(false)
    .setCanPick((node) => node.type === 'file')
    .setButtonFactory(([node]) => [{
      label: node
        ? t('sheetmusic', 'Import {file}', { file: node.basename })
        : t('sheetmusic', 'Import a file'),
      callback: () => {},
      disabled: !node || node.type !== 'file',
      variant: 'primary',
    }])
    .build();

  try {
    const [picked] = await picker.pickNodes();
    if (!picked) return;

    let prefill: SongPrefill | null = null;
    try {
      const response = await apiPost('/api/import-metadata', { path: picked.path });
      prefill = response.data?.ocs?.data ?? null;
    } catch {
      prefill = null;
    }

    importPrefill.value = prefill;
    importedFile.value = null;
    importedNextcloudNode.value = { path: picked.path, name: picked.basename };
    openNewSong();
  } catch (error) {
    if (!(error instanceof FilePickerClosed)) {
      console.error('Failed to import file from Nextcloud:', error);
    }
  }
}

/**
 * Read the picked PDF's document properties and open a new song prefilled from them.
 * The file itself is staged as that song's file. A PDF without readable metadata still
 * imports - the form just starts out empty and the file is attached on save.
 */
async function handleImportFile(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = '';
  if (!file) return;

  let prefill: SongPrefill | null = null;
  try {
    const form = new FormData();
    form.append('file', file);
    const response = await apiPost('/api/import-metadata', form as any);
    prefill = response.data?.ocs?.data ?? null;
  } catch {
    prefill = null;
  }

  importPrefill.value = prefill;
  importedFile.value = file;
  importedNextcloudNode.value = null;
  openNewSong();
}

/** Leaving the create editor drops whatever an import staged for it. */
watch(isCreatingSong, (creating) => {
  if (!creating) {
    importPrefill.value = null;
    importedFile.value = null;
    importedNextcloudNode.value = null;
  }
});

/** The editor saved: refresh the library data and show the song that was saved. */
function handleSaved(data: LibraryData, songId?: number) {
  const wasCreatingSong = isCreatingSong.value;
  isCreatingSong.value = false;
  handleLibraryDataUpdate(data);

  if (songId === undefined) {
    if (wasCreatingSong) {
      router.push({
        name: 'LibraryView',
        params: { library: props.selectedLibrary, view: 'songs' },
        query: queryWithoutOpenSong(),
      });
    }
    return;
  }
  const saved = songs.value.find(song => song.id === songId);
  if (!saved) {
    if (wasCreatingSong) {
      router.push({
        name: 'LibraryView',
        params: { library: props.selectedLibrary, view: 'songs' },
        query: queryWithoutOpenSong(),
      });
    }
    return;
  }
  if (songIdInUrl.value === saved.id) {
    // Saving the open song keeps it open and only drops the edit flag. Replacing
    // keeps the history clean: going back should leave the song, not re-open the
    // editor.
    router.replace({ query: { ...queryWithoutOpenSong(), song: String(saved.id) } });
  } else {
    openSongView(saved);
  }
}

/**
 * The song was deleted. The refreshed data no longer contains it, so the URL has to
 * drop it explicitly: it would otherwise keep the removed song open.
 */
async function handleDeleted(data: LibraryData | undefined, songId: number) {
  const deletedSong = songs.value.find(s => s.id === songId);
  const songTitle = deletedSong?.title ?? openSong.value?.title;
  if (data) {
    handleLibraryDataUpdate(data);
  } else {
    // The response carried no data, e.g. because it was empty; ask the server instead.
    await loadLibraryData(props.selectedLibrary);
  }
  if (songTitle) {
    showSuccess(t('sheetmusic', 'Song "{title}" deleted successfully.', { title: songTitle }));
  } else {
    showSuccess(t('sheetmusic', 'Song deleted successfully.'));
  }
  if (songIdInUrl.value === songId) goBackToSongs();
}
</script>

<style module>
.songArea {
  margin: 16px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
  max-width: calc(100% - 32px);
}
.createSongArea {
  display: block;
}
.songList {
  margin: 0;
  padding: 0;
  list-style: none;
}
.songList li {
  border-bottom: 1px solid var(--color-border);
}
.songRow {
  display: flex;
  align-items: center;
  gap: 8px;
}
.songButton {
  flex: 1;
  min-width: 0;
  padding: 10px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.songButton:hover {
  background: var(--color-background-hover);
}
.filterBarWrapper {
  margin-bottom: 12px;
}

.fullscreenArea {
  margin: 0;
  padding: 8px 16px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.fullscreenHeader {
  display: flex;
}

.selectionBanner {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  background-color: var(--color-background-hover);
  border: 1px solid var(--color-primary-element);
  border-radius: var(--border-radius-large, 8px);
}

.selectionCount {
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--color-main-text);
  margin-right: auto;
}

.headerControls {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
