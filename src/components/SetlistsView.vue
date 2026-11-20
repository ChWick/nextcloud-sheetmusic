<template>
  <ViewHeader
    :title="t('sheetmusic', 'Setlists')"
    :create-label="canWrite ? t('sheetmusic', 'New setlist') : undefined"
    @create="openCreateDialog" />

  <div :class="$style.setlistsArea">
    <SetlistsSidebar
      v-model:search-query="searchQuery"
      :visible-setlists="visibleSetlists"
      :total-setlists-count="setlists.length"
      :selected-setlist="selectedSetlist"
      :is-loading="isLoading"
      :error-message="errorMessage"
      :can-write="canWrite"
      :offline-setlist-ids="offlineSetlistIds"
      :syncing-offline-setlist-ids="syncingOfflineSetlistIds"
      @select="selectSetlist"
      @rename="openRenameDialog"
      @delete="openDeleteDialog" />

    <div :class="$style.detailColumn">
      <template v-if="selectedSetlist">
        <SetlistDetailHeader
          :name="selectedSetlist.name"
          :item-count="selectedSetlistItems.length"
          :total-duration-formatted="totalDurationFormatted"
          :can-write="canWrite"
          :offline-supported="offlineSupported"
          :is-offline="isSelectedSetlistOffline"
          :is-syncing-offline="isSelectedSetlistSyncingOffline"
          :offline-progress-text="selectedSetlistOfflineProgressText"
          @add-item="openAddItemDialog"
          @play="openPerformanceMode(0)"
          @toggle-offline="toggleSelectedSetlistOffline" />

        <SetlistFilesSection
          v-if="canWrite || selectedSetlistFiles.length > 0"
          :files="selectedSetlistFiles"
          :can-write="canWrite"
          :is-uploading="isUploadingFile"
          :upload-error="fileUploadError"
          @upload="handleUploadSetlistFiles"
          @attach="handleAttachNextcloudFile"
          @preview="openFilePreview"
          @remove="handleRemoveSetlistFile" />

        <SetlistItemsTable
          :items="selectedSetlistItems"
          :available-songs="songs"
          :is-loading="isLoadingItems"
          :can-write="canWrite"
          @move="moveItem"
          @open-song="openItemSong"
          @update-bookmark="handleUpdateItemBookmark"
          @remove="removeItem"
          @add-item="openAddItemDialog" />
      </template>

      <NotFoundState
        v-else-if="isSetlistNotFound"
        :title="t('sheetmusic', 'Setlist not found')"
        :description="t('sheetmusic', 'The requested setlist does not exist or may have been deleted.')"
        :action-label="t('sheetmusic', 'Show all setlists')"
        test-id="setlist-not-found"
        @action="clearSetlistQuery" />

      <div v-else :class="$style.noSelectionArea">
        <PlaylistMusic :size="64" :class="$style.noSelectionIcon" />
        <p>{{ t('sheetmusic', 'Select a setlist to view and manage its contents.') }}</p>
      </div>
    </div>
  </div>

  <CreateSetlistDialog
    v-model:name="newSetlistName"
    :open="isCreateDialogOpen"
    :is-creating="isCreating"
    :error="createError"
    @close="closeCreateDialog"
    @create="handleCreateSetlist" />

  <RenameSetlistDialog
    v-model:name="renameSetlistName"
    :open="isRenameDialogOpen"
    :is-renaming="isRenaming"
    :error="renameError"
    @close="closeRenameDialog"
    @rename="handleRenameSetlist" />

  <DeleteSetlistDialog
    :open="isDeleteDialogOpen"
    :setlist-name="setlistToDelete?.name ?? ''"
    :is-deleting="isDeleting"
    :error="deleteError"
    @close="closeDeleteDialog"
    @delete="handleDeleteSetlist" />

  <AddSetlistItemDialog
    :open="isAddItemDialogOpen"
    :available-songs="songs"
    :is-adding="isAddingItem"
    :error="addItemError"
    @close="closeAddItemDialog"
    @add="handleAddItem" />

  <SetlistPerformanceViewer
    v-if="isPerformanceMode && selectedSetlist && selectedSetlistItems.length > 0"
    :setlist-id="selectedSetlist.id"
    :setlist-name="selectedSetlist.name"
    :items="selectedSetlistItems"
    :files="selectedSetlistFiles"
    :songs="songs"
    :library-id="selectedLibrary"
    :initial-index="performanceStartIndex"
    @close="isPerformanceMode = false" />

  <SetlistFilePreviewDialog
    v-if="previewingFile && selectedSetlist"
    :library-id="selectedLibrary"
    :setlist-id="selectedSetlist.id"
    :file="previewingFile"
    :can-write="canWrite"
    @close="previewingFile = null" />
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
  getOfflineSetlistMap,
  isOfflineSetlistsSupported,
  removeSetlistOffline,
  syncSetlistOffline,
} from '../utils/offlineSetlists'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import ViewHeader from './ViewHeader.vue'
import NotFoundState from './NotFoundState.vue'
import SetlistsSidebar from './setlists/SetlistsSidebar.vue'
import SetlistDetailHeader from './setlists/SetlistDetailHeader.vue'
import SetlistFilesSection from './setlists/SetlistFilesSection.vue'
import SetlistFilePreviewDialog from './setlists/SetlistFilePreviewDialog.vue'
import SetlistItemsTable from './setlists/SetlistItemsTable.vue'
import CreateSetlistDialog from './setlists/CreateSetlistDialog.vue'
import RenameSetlistDialog from './setlists/RenameSetlistDialog.vue'
import DeleteSetlistDialog from './setlists/DeleteSetlistDialog.vue'
import AddSetlistItemDialog from './setlists/AddSetlistItemDialog.vue'
import SetlistPerformanceViewer from './setlists/SetlistPerformanceViewer.vue'
import type { Setlist, SetlistFile, SetlistItem } from '../types/song'
import { useLibraryData } from '../composables/useLibraryData'
import { useSetlists } from '../composables/useSetlists'

const props = withDefaults(
  defineProps<{
    selectedLibrary: number
    canWrite?: boolean
  }>(),
  {
    canWrite: true,
  },
)

const route = useRoute()
const router = useRouter()
const { songs, loadLibraryData } = useLibraryData()

const {
  setlists,
  selectedSetlist,
  selectedSetlistItems,
  selectedSetlistFiles,
  isLoading,
  isLoadingItems,
  errorMessage,
  searchQuery,
  visibleSetlists,
  totalDurationFormatted,
  loadSetlists,
  selectSetlist,
  createSetlist,
  renameSetlist,
  deleteSetlist,
  addItem,
  updateItemBookmark,
  removeItem,
  moveItem,
  uploadFile,
  attachFile,
  removeFile,
} = useSetlists(() => props.selectedLibrary)

const isUploadingFile = ref(false)
const fileUploadError = ref('')
const previewingFile = ref<SetlistFile | null>(null)

async function handleUploadSetlistFiles(files: File[]) {
  isUploadingFile.value = true
  fileUploadError.value = ''
  try {
    for (const file of files) {
      await uploadFile(file)
    }
  } catch (err: unknown) {
    fileUploadError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not upload file')
  } finally {
    isUploadingFile.value = false
  }
}

async function handleAttachNextcloudFile(payload: { sourcePath: string; action: 'copy' | 'move' }) {
  isUploadingFile.value = true
  fileUploadError.value = ''
  try {
    await attachFile(payload.sourcePath, payload.action)
  } catch (err: unknown) {
    fileUploadError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not attach file from Nextcloud')
  } finally {
    isUploadingFile.value = false
  }
}

function openFilePreview(file: SetlistFile) {
  previewingFile.value = file
}

async function handleRemoveSetlistFile(fileId: number) {
  fileUploadError.value = ''
  try {
    await removeFile(fileId)
    if (previewingFile.value?.id === fileId) {
      previewingFile.value = null
    }
  } catch (err: unknown) {
    fileUploadError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not remove file')
  }
}

function handleUpdateItemBookmark(item: SetlistItem, bookmarkId: number | null) {
  if (item.id) {
    updateItemBookmark(item.id, bookmarkId)
  }
}

const isPerformanceMode = ref(false)
const performanceStartIndex = ref(0)

function openPerformanceMode(startIndex = 0) {
  if (selectedSetlistItems.value.length === 0) return
  performanceStartIndex.value = startIndex
  isPerformanceMode.value = true
}

// Create Dialog
const isCreateDialogOpen = ref(false)
const newSetlistName = ref('')
const isCreating = ref(false)
const createError = ref('')

function openCreateDialog() {
  newSetlistName.value = ''
  createError.value = ''
  isCreateDialogOpen.value = true
}

function closeCreateDialog() {
  isCreateDialogOpen.value = false
  createError.value = ''
}

async function handleCreateSetlist() {
  const name = newSetlistName.value.trim()
  if (!name || isCreating.value) return
  isCreating.value = true
  createError.value = ''
  try {
    await createSetlist(name)
    closeCreateDialog()
  } catch (err: unknown) {
    createError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not create setlist')
  } finally {
    isCreating.value = false
  }
}

// Rename Dialog
const isRenameDialogOpen = ref(false)
const setlistToRename = ref<Setlist | null>(null)
const renameSetlistName = ref('')
const isRenaming = ref(false)
const renameError = ref('')

function openRenameDialog(setlist: Setlist) {
  setlistToRename.value = setlist
  renameSetlistName.value = setlist.name
  renameError.value = ''
  isRenameDialogOpen.value = true
}

function closeRenameDialog() {
  isRenameDialogOpen.value = false
  setlistToRename.value = null
  renameError.value = ''
}

async function handleRenameSetlist() {
  if (!setlistToRename.value || isRenaming.value) return
  const name = renameSetlistName.value.trim()
  if (!name) return
  isRenaming.value = true
  renameError.value = ''
  try {
    await renameSetlist(setlistToRename.value.id, name)
    closeRenameDialog()
  } catch (err: unknown) {
    renameError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not rename setlist')
  } finally {
    isRenaming.value = false
  }
}

// Delete Dialog
const isDeleteDialogOpen = ref(false)
const setlistToDelete = ref<Setlist | null>(null)
const isDeleting = ref(false)
const deleteError = ref('')

function openDeleteDialog(setlist: Setlist) {
  setlistToDelete.value = setlist
  deleteError.value = ''
  isDeleteDialogOpen.value = true
}

function closeDeleteDialog() {
  isDeleteDialogOpen.value = false
  setlistToDelete.value = null
  deleteError.value = ''
}

async function handleDeleteSetlist() {
  if (!setlistToDelete.value || isDeleting.value) return
  isDeleting.value = true
  deleteError.value = ''
  try {
    const deletedId = setlistToDelete.value.id
    await deleteSetlist(deletedId)
    await removeSetlistOffline(props.selectedLibrary, deletedId)
    refreshOfflineMap()
    closeDeleteDialog()
  } catch (err: unknown) {
    deleteError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not delete setlist')
  } finally {
    isDeleting.value = false
  }
}

// Offline setlist availability
const offlineSupported = isOfflineSetlistsSupported()
const offlineMap = ref(getOfflineSetlistMap())
const syncingProgressBySetlistId = ref<Record<number, string>>({})

function refreshOfflineMap() {
  offlineMap.value = getOfflineSetlistMap()
}

const offlineSetlistIds = computed(() =>
  Object.values(offlineMap.value)
    .filter((entry) => entry.libraryId === props.selectedLibrary)
    .map((entry) => entry.setlistId),
)

const syncingOfflineSetlistIds = computed(() =>
  Object.keys(syncingProgressBySetlistId.value).map(Number),
)

const isSelectedSetlistOffline = computed(() =>
  Boolean(selectedSetlist.value && offlineSetlistIds.value.includes(selectedSetlist.value.id)),
)

const isSelectedSetlistSyncingOffline = computed(() =>
  Boolean(selectedSetlist.value && selectedSetlist.value.id in syncingProgressBySetlistId.value),
)

const selectedSetlistOfflineProgressText = computed(() =>
  selectedSetlist.value ? (syncingProgressBySetlistId.value[selectedSetlist.value.id] ?? '') : '',
)

async function toggleSelectedSetlistOffline() {
  if (!selectedSetlist.value) return
  const setlistId = selectedSetlist.value.id
  if (setlistId in syncingProgressBySetlistId.value) return

  if (offlineSetlistIds.value.includes(setlistId)) {
    await removeSetlistOffline(props.selectedLibrary, setlistId)
    refreshOfflineMap()
    showSuccess(t('sheetmusic', 'Removed setlist from offline storage.'))
    return
  }

  syncingProgressBySetlistId.value = {
    ...syncingProgressBySetlistId.value,
    [setlistId]: t('sheetmusic', 'Saving offline…'),
  }
  try {
    await syncSetlistOffline({
      libraryId: props.selectedLibrary,
      setlistId,
      songs: songs.value,
      onProgress: (done, total) => {
        syncingProgressBySetlistId.value = {
          ...syncingProgressBySetlistId.value,
          [setlistId]: `${done}/${total}`,
        }
      },
    })
    refreshOfflineMap()
    showSuccess(t('sheetmusic', 'Setlist is now available offline.'))
  } catch {
    showError(t('sheetmusic', 'Could not cache all setlist files for offline use.'))
  } finally {
    const next = { ...syncingProgressBySetlistId.value }
    delete next[setlistId]
    syncingProgressBySetlistId.value = next
  }
}

// Add Item Dialog
const isAddItemDialogOpen = ref(false)
const isAddingItem = ref(false)
const addItemError = ref('')

function openAddItemDialog() {
  addItemError.value = ''
  isAddItemDialogOpen.value = true
}

function closeAddItemDialog() {
  isAddItemDialogOpen.value = false
  addItemError.value = ''
}

async function handleAddItem(payload: { songId: number, bookmarkId: number | null }) {
  if (!selectedSetlist.value || isAddingItem.value) return
  isAddingItem.value = true
  addItemError.value = ''
  try {
    await addItem(payload.songId, payload.bookmarkId)
    closeAddItemDialog()
  } catch (err: unknown) {
    addItemError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not add item to setlist')
  } finally {
    isAddingItem.value = false
  }
}

function openItemSong(item: SetlistItem) {
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: {
      song: String(item.songId),
      page: item.pageNum ? String(item.pageNum) : undefined,
    },
  })
}

const hasLoadedSetlists = ref(false)

const hasSetlistParamInUrl = computed(() => {
  const raw = route.query?.setlist
  const value = Array.isArray(raw) ? raw[0] : raw
  return value !== undefined && value !== null && value !== ''
})

const isSetlistNotFound = computed(() =>
  hasSetlistParamInUrl.value
  && !selectedSetlist.value
  && hasLoadedSetlists.value
  && !isLoading.value
  && !errorMessage.value,
)

function clearSetlistQuery() {
  if (typeof router.replace === 'function') {
    const nextQuery = { ...route.query }
    delete nextQuery.setlist
    delete nextQuery.play
    delete nextQuery.item
    void router.replace({ query: nextQuery })
  }
}

async function restoreSetlistFromRouteQuery() {
  const targetId = Number(route.query?.setlist)
  if (!Number.isFinite(targetId) || targetId <= 0) {
    return
  }
  const match = setlists.value.find(s => s.id === targetId)
  if (!match) {
    return
  }
  await selectSetlist(match)
  if (route.query?.play === '1' && selectedSetlistItems.value.length > 0) {
    const rawIdx = Number(route.query?.item)
    const itemIndex = Number.isFinite(rawIdx) && rawIdx >= 0 ? rawIdx : 0
    openPerformanceMode(itemIndex)
    if (typeof router.replace === 'function') {
      const nextQuery = { ...route.query }
      delete nextQuery.play
      delete nextQuery.item
      void router.replace({ query: nextQuery })
    }
  }
}

async function loadLibraryAndSetlists(libraryId: number) {
  hasLoadedSetlists.value = false
  loadLibraryData(libraryId)
  await loadSetlists()
  hasLoadedSetlists.value = true
  await restoreSetlistFromRouteQuery()
}

onMounted(async () => {
  await loadLibraryAndSetlists(props.selectedLibrary)
})

watch(() => props.selectedLibrary, async (newLib) => {
  if (newLib > 0) {
    await loadLibraryAndSetlists(newLib)
  }
})

watch(
  () => [route.query?.setlist, route.query?.play, route.query?.item],
  () => {
    if (setlists.value.length > 0) {
      void restoreSetlistFromRouteQuery()
    }
  },
)
</script>

<style module>
.setlistsArea {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 16px;
  height: calc(var(--body-height, calc(100vh - var(--header-height, 50px))) - 70px);
  min-height: 400px;
  padding: 16px;
  box-sizing: border-box;
}

@media (max-width: 768px) {
  .setlistsArea {
    grid-template-columns: 1fr;
    height: auto;
  }
}

.detailColumn {
  display: flex;
  flex-direction: column;
  gap: 16px;
  overflow-y: auto;
  padding-left: 8px;
}

.noSelectionArea {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 16px;
  text-align: center;
  color: var(--color-text-maxcontrast);
}

.noSelectionIcon {
  margin-bottom: 16px;
  opacity: 0.5;
}
</style>
