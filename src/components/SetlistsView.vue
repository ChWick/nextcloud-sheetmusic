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
          @add-item="openAddItemDialog"
          @play="openPerformanceMode(0)" />

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
    :songs="songs"
    :library-id="selectedLibrary"
    :initial-index="performanceStartIndex"
    @close="isPerformanceMode = false" />
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import ViewHeader from './ViewHeader.vue'
import SetlistsSidebar from './setlists/SetlistsSidebar.vue'
import SetlistDetailHeader from './setlists/SetlistDetailHeader.vue'
import SetlistItemsTable from './setlists/SetlistItemsTable.vue'
import CreateSetlistDialog from './setlists/CreateSetlistDialog.vue'
import RenameSetlistDialog from './setlists/RenameSetlistDialog.vue'
import DeleteSetlistDialog from './setlists/DeleteSetlistDialog.vue'
import AddSetlistItemDialog from './setlists/AddSetlistItemDialog.vue'
import SetlistPerformanceViewer from './setlists/SetlistPerformanceViewer.vue'
import type { Setlist, SetlistItem } from '../types/song'
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

const router = useRouter()
const { songs, loadLibraryData } = useLibraryData()

const {
  setlists,
  selectedSetlist,
  selectedSetlistItems,
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
} = useSetlists(() => props.selectedLibrary)

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
    await deleteSetlist(setlistToDelete.value.id)
    closeDeleteDialog()
  } catch (err: unknown) {
    deleteError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not delete setlist')
  } finally {
    isDeleting.value = false
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

onMounted(() => {
  loadLibraryData(props.selectedLibrary)
  loadSetlists()
})

watch(() => props.selectedLibrary, (newLib) => {
  if (newLib > 0) {
    loadLibraryData(newLib)
    loadSetlists()
  }
})
</script>

<style module>
.setlistsArea {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 16px;
  height: calc(100vh - 120px);
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
