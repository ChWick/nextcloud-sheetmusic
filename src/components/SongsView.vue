<template>
  <div :class="isViewingSong && $style.viewerShell">
  <ViewHeader
    :title="headerTitle"
    :show-back="songIdInUrl !== null || standaloneCreate"
    :create-label="songIdInUrl === null && !standaloneCreate && canWrite ? t('sheetmusic', 'New song') : undefined"
    :import-label="songIdInUrl === null && !standaloneCreate && canWrite ? t('sheetmusic', 'Import') : undefined"
    :import-nextcloud-label="songIdInUrl === null && !standaloneCreate && canWrite ? t('sheetmusic', 'Import from Nextcloud') : undefined"
    :filter-bar-visible="songIdInUrl === null && !standaloneCreate ? filterBarVisible : undefined"
    :show-filter-toggle="songIdInUrl === null && !standaloneCreate"
    @back="goBackToSongs"
    @create="startNewSong"
    @import="startImport"
    @import-nextcloud="startImportNextcloud"
    @toggle-filters="toggleFilterBar">
    <div v-if="songIdInUrl === null && !standaloneCreate" :class="$style.headerControls">
      <SongListDisplayMenu
        v-model:visible-columns="visibleColumns"
        v-model:sort-key="sortKey"
        v-model:sort-order="sortOrder"
        v-model:view-mode="viewMode" />
    </div>
    <SongViewHeaderButtons
      v-else
      :is-edit-in-url="isEditInUrl"
      :open-song="openSong"
      :standalone-create="standaloneCreate"
      :is-modify-pdf-in-url="isModifyPdfInUrl"
      :can-write="canWrite"
      @delete-edit="songEditorRef?.promptDelete()"
      @save-edit="(close) => songEditorRef?.saveSong(close)"
      @cancel-create="goBackToSongs"
      @save-create="createSongEditorRef?.saveSong(true)"
      @open-history="openSong && (isHistoryModalOpen = true)"
      @duplicate="openSong && duplicateSong(openSong)"
      @edit="openSong && openSongEdit(openSong)"
      @delete-view="songViewerRef?.promptDelete()" />
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
  <div v-if="songIdInUrl !== null" :class="[$style.fullscreenArea, isViewingSong && $style.fullscreenAreaViewer]">
    <SongEditor v-if="isEditInUrl"
                ref="songEditorRef"
                :song="openSong"
                :library-id="selectedLibrary"
                v-bind="entityListsProps"
                @saved="handleSaved"
                @updated="handleLibraryDataUpdate"
                @deleted="handleDeleted"
                @cancel="goBackToSongs" />
    <SongPdfModifier
      v-else-if="isModifyPdfInUrl && openSong"
      :song="openSong"
      :file-id="route.query.fileId as string"
      :library-id="selectedLibrary"
      :initial-mode="(route.query.pdfMode as 'rotate' | 'split' | 'crop' | 'enhance' | 'bookmarks' | undefined)"
      :initial-fullscreen="route.query.fullscreen === '1'"
      :auto-pdf-metadata="autoPdfMetadata"
      v-bind="entityListsProps"
      @saved="handlePdfModifierSaved"
      @cancel="closeModifyPdf" />
    <SongViewer
      v-else
      ref="songViewerRef"
      :key="openSong ? `${openSong.id}-${openSong.lastModified ?? ''}` : 'no-song'"
      :hide-header="true"
      :song="openSong"
      :library-id="selectedLibrary"
      :can-write="canWrite"
      :initial-fullscreen="route.query.fullscreen === '1'"
      v-bind="entityListsProps"
      @back="goBackToSongs"
      @modify-pdf="openModifyPdf"
      @saved="handleSaved"
      @updated="handleLibraryDataUpdate"
      @deleted="handleDeleted" />
  </div>
  <div v-else :class="[$style.songArea, standaloneCreate && $style.createSongArea]">
    <div v-if="!standaloneCreate && selectedCount > 0" :class="$style.selectionBanner">
      <span :class="$style.selectionCount">{{ t('sheetmusic', '{count} songs selected', { count: selectedCount }, { escape: false }) }}</span>
      <NcButton
        v-if="canWrite && selectedCount === 2"
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
        v-if="canWrite"
        size="small"
        variant="primary"
        :title="t('sheetmusic', 'Edit selected ({count})', { count: selectedCount }, { escape: false })"
        :aria-label="t('sheetmusic', 'Edit selected ({count})', { count: selectedCount }, { escape: false })"
        @click="isBatchEditorOpen = true">
        <template #icon>
          <PencilIcon :size="18" />
        </template>
      </NcButton>
      <NcButton
        v-if="canWrite"
        size="small"
        variant="error"
        :title="t('sheetmusic', 'Delete selected ({count})', { count: selectedCount }, { escape: false })"
        :aria-label="t('sheetmusic', 'Delete selected ({count})', { count: selectedCount }, { escape: false })"
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
      v-model:selected-arrangers="selectedArrangers"
      v-model:selected-sublibraries="selectedSublibraries"
      v-bind="entityListsProps"
      @clear="clearFilters"/>
    </div>
    <SongEditor v-if="isCreatingSong"
                ref="createSongEditorRef"
                :song="null"
                :library-id="selectedLibrary"
                :hide-actions="standaloneCreate"
                v-bind="entityListsProps"
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
        :songs="paginatedSongs"
        :selected-song-ids="selectedSongIds"
        :is-filtered="isFiltered"
        :visible-columns="visibleColumns"
        :can-write="canWrite"
        v-bind="entityListsProps"
        v-model:sort-key="sortKey"
        v-model:sort-order="sortOrder"
        @view-song="openSongView"
        @edit-song="openSongEdit"
        @inline-update-song="handleInlineUpdateSong"
        @filter-entity="toggleFilter"
        @toggle-select-song="toggleSelect"
        @select-all="selectAll" />
      <SongCardView
        v-else-if="viewMode === 'cards'"
        :songs="paginatedSongs"
        :selected-song-ids="selectedSongIds"
        :is-filtered="isFiltered"
        :visible-columns="visibleColumns"
        :can-write="canWrite"
        @view-song="openSongView"
        @edit-song="openSongEdit"
        @inline-update-song="handleInlineUpdateSong"
        @filter-entity="toggleFilter"
        @toggle-select-song="toggleSelect" />
      <SongSimpleListView
        v-else
        :songs="paginatedSongs"
        :selected-song-ids="selectedSongIds"
        :is-filtered="isFiltered"
        :visible-columns="visibleColumns"
        :can-write="canWrite"
        @view-song="openSongView"
        @edit-song="openSongEdit"
        @inline-update-song="handleInlineUpdateSong"
        @filter-entity="toggleFilter"
        @toggle-select-song="toggleSelect" />

      <SongPagination
        v-if="filteredSongs.length > 0"
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        :total-items="filteredSongs.length" />
    </template>
  </div>
  </div>

  <BatchSongEditorDialog
    v-model:open="isBatchEditorOpen"
    :selected-count="selectedCount"
    :is-applying="isApplyingBatchUpdate"
    :progress-percent="batchUpdateProgressPercent"
    :progress-message="batchUpdateProgressMessage"
    v-bind="entityListsProps"
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

  <SongHistoryModal
    v-if="openSong && isHistoryModalOpen"
    v-model:open="isHistoryModalOpen"
    :song-id="openSong.id"
    :song-title="openSong.title"
    @reverted="handleLibraryDataUpdate" />
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { loadState } from '@nextcloud/initial-state';
import { describeRequestFailure } from '../utils/api';
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import DeleteIcon from 'vue-material-design-icons/Delete.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';
import PencilIcon from 'vue-material-design-icons/Pencil.vue';
import SongEditor from '../components/SongEditor.vue';
import SongFilterBar from '../components/SongFilterBar.vue';
import SongViewer from '../components/SongViewer.vue';
import SongPdfModifier from '../components/song-pdf/SongPdfModifier.vue';
import ViewHeader from '../components/ViewHeader.vue';
import SongViewHeaderButtons from './songs/SongViewHeaderButtons.vue';
import BatchSongEditorDialog, { type BatchUpdatePayload } from '../components/song-editor/BatchSongEditorDialog.vue';
import BatchDeleteSongsDialog from '../components/BatchDeleteSongsDialog.vue';
import SongMergeDialog from '../components/song-editor/SongMergeDialog.vue';
import SongHistoryModal from '../components/SongHistoryModal.vue';
import type { Song, LibraryData, SongPrefill } from '../types/song';
import { DEFAULT_VISIBLE_COLUMNS, type SongColumn } from '../types/song';
import { sortSongs, type SortKey, type SortOrder } from '../utils/song-formatter';
import { useSongFilters } from '../composables/useSongFilters';
import { useSongSelection } from '../composables/useSongSelection';
import { useLibraryData } from '../composables/useLibraryData';
import { useSongImport } from '../composables/useSongImport';
import { useSongMerge } from '../composables/useSongMerge';
import { useProgressPolling } from '../composables/useProgressPolling';
import { apiGet, apiPost } from '../api/client';
import SongListDisplayMenu from './songs/SongListDisplayMenu.vue';
import type { ViewMode } from './songs/song-view-columns';
import SongTableView from './songs/SongTableView.vue';
import SongCardView from './songs/SongCardView.vue';
import SongSimpleListView from './songs/SongSimpleListView.vue';
import SongPagination, { type PageSize } from './songs/SongPagination.vue';
import { executeInlineSongUpdate } from './songs/song-inline-update';
import type { Library } from '../types/library';

// Props from parent (App.vue)
const props = withDefaults(defineProps<{
  selectedLibrary: number;
  libraries?: Library[];
  standaloneCreate?: boolean;
}>(), {
  libraries: () => [],
  standaloneCreate: false,
});

const isPublicShare = Boolean(loadState('sheetmusic', 'isPublicShare', false));
const currentLibrary = computed(() => props.libraries.find(lib => lib.id === props.selectedLibrary));
const canWrite = computed(() => {
  if (isPublicShare) {
    return false;
  }
  return currentLibrary.value ? (currentLibrary.value.canWrite ?? true) : true;
});
const autoPdfMetadata = computed(() => currentLibrary.value?.autoPdfMetadata ?? true);

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
  arrangers,
  sublibraries,
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

const headerTitle = computed(() => {
  if (props.standaloneCreate) {
    return duplicateSourceSong.value
      ? t('sheetmusic', 'Duplicate: {title}', { title: duplicateSourceSong.value.title }, { escape: false })
      : t('sheetmusic', 'Create Song');
  }
  if (songIdInUrl.value !== null) {
    if (isModifyPdfInUrl.value) {
      return openSong.value?.title
        ? t('sheetmusic', 'Modify PDF: {title}', { title: openSong.value.title }, { escape: false })
        : t('sheetmusic', 'Modify PDF');
    }
    if (isEditInUrl.value) {
      return openSong.value?.title
        ? t('sheetmusic', 'Edit: {title}', { title: openSong.value.title }, { escape: false })
        : t('sheetmusic', 'Edit song');
    }
    return openSong.value?.title ?? t('sheetmusic', 'Songs');
  }
  return t('sheetmusic', 'Songs');
});

const entityListsProps = computed(() => ({
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
  arrangers: arrangers.value,
  sublibraries: sublibraries.value,
}));

// The filter bar reads and writes its selections in the URL query, so the browser
// history restores them when going back or forward.
const { searchTitle, withoutFiles, selectedComposers, selectedBooks, selectedCollections, selectedArtists, selectedGenres, selectedKeys, selectedCustomGroups, selectedYears, selectedSignatures, selectedSourceTypes, selectedInstruments, selectedArrangers, selectedSublibraries, filterSongs, clearFilters, filterBarVisible, toggleFilterBar, isFiltered, toggleFilter } =
  useSongFilters(() => entityListsProps.value);

const { selectedSongIds, selectedCount, isSelected, toggleSelect, selectAll, isAllSelected, clearSelection } = useSongSelection();
const isBatchEditorOpen = ref(false);
const isApplyingBatchUpdate = ref(false);
const {
  progress: batchProgress,
  start: startBatchPolling,
  stop: stopBatchProgressPolling,
} = useProgressPolling({
  preserveEmptyMessage: true,
  fetchProgress: async (progressId) => {
    const res = await apiGet('/api/libraries/progress/{progressId}', {
      pathParams: { progressId },
    });
    return res?.data?.ocs?.data as Record<string, unknown> | undefined;
  },
});

const batchUpdateProgressPercent = computed(() => batchProgress.value?.percent ?? 0);
const batchUpdateProgressMessage = computed(() => batchProgress.value?.message ?? '');

function startBatchProgressPolling(progressId: string, count: number) {
  startBatchPolling(progressId, t('sheetmusic', 'Updating {count} songs…', { count }, { escape: false }));
}

const isBatchDeleteDialogOpen = ref(false);

const currentLibraryData = computed<LibraryData>(() => ({
  ...entityListsProps.value,
  songs: songs.value,
}));

const isHistoryModalOpen = ref(false);

const {
  isMergeDialogOpen,
  isMerging,
  mergeError,
  mergeIssue,
  openCompareMergeDialog,
  executeMerge,
  executeResolveBoth,
} = useSongMerge({
  selectedLibrary: computed(() => props.selectedLibrary),
  selectedSongIds,
  songs,
  onMerged: handleLibraryDataUpdate,
  onClearSelection: clearSelection,
  reloadLibrary: loadLibraryData,
});

async function handleInlineUpdateSong(song: Song, updates: Record<string, any>) {
  await executeInlineSongUpdate(
    props.selectedLibrary,
    song,
    updates,
    currentLibraryData.value,
    handleLibraryDataUpdate,
  );
}

async function handleApplyBatchUpdate(payload: BatchUpdatePayload) {
  if (selectedSongIds.value.length === 0 || isApplyingBatchUpdate.value) return;
  const count = selectedSongIds.value.length;
  const progressId = `batch-edit-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
  isApplyingBatchUpdate.value = true;
  startBatchProgressPolling(progressId, count);
  try {
    const response = await apiPost('/api/songs-batch-update', {
      libraryId: props.selectedLibrary,
      songIds: selectedSongIds.value,
      progressId,
      ...payload,
    } as any);
    batchUpdateProgressPercent.value = 100;
    isBatchEditorOpen.value = false;
    clearSelection();
    if (response.data?.ocs?.data) {
      handleLibraryDataUpdate(response.data.ocs.data);
    }
    showSuccess(t('sheetmusic', 'Successfully updated {count} songs.', { count }, { escape: false }));
  } catch (error) {
    console.error('Failed to batch update songs:', error);
    showError(describeRequestFailure(error, t('sheetmusic', 'Failed to update selected songs.')));
  } finally {
    stopBatchProgressPolling();
    isApplyingBatchUpdate.value = false;
  }
}

function handleBatchDeleted(data: LibraryData | undefined, payload: number[] | number) {
  isBatchDeleteDialogOpen.value = false;
  const deletedSongIds = Array.isArray(payload) ? payload : [];
  const count = Array.isArray(payload) ? payload.length : payload;
  const shouldGoBack = songIdInUrl.value !== null && deletedSongIds.includes(songIdInUrl.value);
  clearSelection();
  if (data) {
    handleLibraryDataUpdate(data);
  } else {
    loadLibraryData(props.selectedLibrary);
  }
  showSuccess(t('sheetmusic', 'Successfully deleted {count} songs.', { count }, { escape: false }));
  if (shouldGoBack) {
    goBackToSongs();
  }
}

/** The editor is open for a song that does not exist yet. */
const isCreatingSong = ref(props.standaloneCreate);

const {
  importInput,
  importPrefill,
  importedFile,
  importedNextcloudNode,
  clearImportState,
  startImport,
  startImportNextcloud,
  handleImportFile,
} = useSongImport({
  onSongReady: openNewSong,
});

// Computed filtered list
const filteredSongs = computed(() => filterSongs(songs.value));
const sortedSongs = computed(() => sortSongs(filteredSongs.value, sortKey.value, sortOrder.value));

const DEFAULT_PAGE_SIZE: PageSize = 50;

const pageSize = ref<PageSize>((() => {
  try {
    const saved = localStorage.getItem('sheetmusic_songs_page_size');
    if (saved === 'all') return 'all';
    if (saved) {
      const num = parseInt(saved, 10);
      if (!isNaN(num) && num > 0) return num;
    }
  } catch {
    // fallback
  }
  return DEFAULT_PAGE_SIZE;
})());

watch(pageSize, (newSize) => {
  try {
    localStorage.setItem('sheetmusic_songs_page_size', String(newSize));
  } catch {
    // ignore
  }
});

const currentPage = ref(1);

// When search, filter selections, sorting, or library change, reset page to 1
watch([searchTitle, withoutFiles, selectedComposers, selectedBooks, selectedCollections, selectedArtists, selectedGenres, selectedKeys, selectedCustomGroups, selectedYears, selectedSignatures, selectedSourceTypes, selectedInstruments, selectedArrangers, selectedSublibraries, () => props.selectedLibrary], () => {
  currentPage.value = 1;
});

watch([sortKey, sortOrder], () => {
  currentPage.value = 1;
});

// Clamp current page if total filtered items shrink
watch([filteredSongs, pageSize], () => {
  const maxPage = pageSize.value === 'all' ? 1 : Math.max(1, Math.ceil(filteredSongs.value.length / (pageSize.value as number)));
  if (currentPage.value > maxPage) {
    currentPage.value = maxPage;
  }
});

const paginatedSongs = computed(() => {
  if (pageSize.value === 'all') {
    return sortedSongs.value;
  }
  const size = typeof pageSize.value === 'number' ? pageSize.value : 50;
  const start = (currentPage.value - 1) * size;
  return sortedSongs.value.slice(start, start + size);
});

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
function openModifyPdf(song: Song, fileId?: string | number, mode?: string, fullscreen?: boolean) {
  isCreatingSong.value = false;
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: {
      ...queryWithoutOpenSong(),
      song: String(song.id),
      modifyPdf: '1',
      ...(fileId ? { fileId: String(fileId) } : {}),
      ...(mode ? { pdfMode: mode } : {}),
      ...(fullscreen ? { fullscreen: '1' } : {}),
    },
  });
}

function isEditorFullscreenActive(): boolean {
  return (typeof document !== 'undefined' && (
    Boolean(document.fullscreenElement)
    || document.body.classList.contains('sheetmusic-pdf-editor-fullscreen')
  )) || route.query.fullscreen === '1';
}

function returnToSongFromModifier(songId: number, fullscreen: boolean) {
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: {
      ...queryWithoutOpenSong(),
      song: String(songId),
      ...(fullscreen ? { fullscreen: '1' } : {}),
    },
  });
}

/** Close the PDF modifier and return to viewing the song. */
function closeModifyPdf() {
  if (!openSong.value) {
    goBackToSongs();
    return;
  }
  returnToSongFromModifier(openSong.value.id, isEditorFullscreenActive());
}

function handlePdfModifierSaved(data: LibraryData, songId?: number) {
  const fullscreen = isEditorFullscreenActive();
  handleLibraryDataUpdate(data);
  if (!songId) {
    goBackToSongs();
    return;
  }
  const targetSong = songs.value.find(s => s.id === songId)
    ?? data.songs?.find(s => s.id === songId);
  if (targetSong && !fullscreen) {
    openSongView(targetSong);
    return;
  }
  returnToSongFromModifier(songId, fullscreen);
}

const isViewingSong = computed(() =>
  songIdInUrl.value !== null && !isEditInUrl.value && !isModifyPdfInUrl.value,
)

/** The query without the open song and its edit flag; the filters stay. */
function queryWithoutOpenSong() {
  const query = { ...route.query };
  delete query.song;
  delete query.edit;
  delete query.duplicate;
  delete query.modifyPdf;
  delete query.fileId;
  delete query.pdfMode;
  delete query.fullscreen;
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
  clearImportState();
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
  clearImportState();
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'new-song' },
    query: { ...queryWithoutOpenSong(), duplicate: String(song.id) },
  });
}

/** Leaving the create editor drops whatever an import staged for it. */
watch(isCreatingSong, (creating) => {
  if (!creating) {
    clearImportState();
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
    showSuccess(t('sheetmusic', 'Song "{title}" deleted successfully.', { title: songTitle }, { escape: false }));
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

.viewerShell {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.viewerShell > :first-child {
  flex-shrink: 0;
}

.fullscreenArea {
  margin: 0;
  padding: 8px 16px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.fullscreenAreaViewer {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  padding-bottom: 0;
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
