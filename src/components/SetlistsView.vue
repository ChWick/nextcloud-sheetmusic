<template>
  <ViewHeader
    :title="t('sheetmusic', 'Setlists')"
    :create-label="t('sheetmusic', 'New setlist')"
    @create="openCreateDialog" />

  <div :class="$style.setlistsArea">
    <!-- Left column: Setlists list -->
    <div :class="$style.masterColumn">
      <div :class="$style.searchWrapper">
        <NcTextField
          v-model="searchQuery"
          :placeholder="t('sheetmusic', 'Search setlists…')"
          :label="t('sheetmusic', 'Search setlists')"
          :class="$style.searchInput" />
      </div>

      <div :class="$style.listColumn">
        <ul :class="$style.setlistsList">
          <li
            v-for="setlist in visibleSetlists"
            :key="setlist.id"
            :class="[$style.setlistRow, selectedSetlist?.id === setlist.id && $style.selectedRow]">
            <button
              type="button"
              :class="$style.setlistButton"
              :title="t('sheetmusic', 'Open setlist {name}', { name: setlist.name })"
              @click="selectSetlist(setlist)">
              <PlaylistMusic :size="20" :class="$style.setlistIcon" />
              <span :class="$style.setlistName">{{ setlist.name }}</span>
            </button>
            <span :class="$style.itemCountBadge">{{ setlist.itemCount ?? 0 }}</span>
            <div :class="$style.rowActions">
              <NcButton
                size="small"
                :title="t('sheetmusic', 'Rename setlist')"
                :aria-label="t('sheetmusic', 'Rename setlist')"
                @click="openRenameDialog(setlist)">
                <template #icon>
                  <PencilIcon :size="18" />
                </template>
              </NcButton>
              <NcButton
                variant="error"
                size="small"
                :title="t('sheetmusic', 'Delete setlist')"
                :aria-label="t('sheetmusic', 'Delete setlist')"
                @click="openDeleteDialog(setlist)">
                <template #icon>
                  <DeleteIcon :size="18" />
                </template>
              </NcButton>
            </div>
          </li>
          <li v-if="!isLoading && visibleSetlists.length === 0" :class="$style.emptyMessage">
            {{ searchQuery.trim() && setlists.length > 0
              ? t('sheetmusic', 'No setlists match your search.')
              : t('sheetmusic', 'No setlists yet. Click "New setlist" to create one.') }}
          </li>
        </ul>
        <p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>
      </div>
    </div>

    <!-- Right column: Selected setlist items -->
    <div :class="$style.detailColumn">
      <template v-if="selectedSetlist">
        <div :class="$style.detailHeader">
          <div :class="$style.detailTitleArea">
            <h2 :class="$style.detailTitle">{{ selectedSetlist.name }}</h2>
            <span :class="$style.detailSubtitle">
              {{ t('sheetmusic', '{count} items', { count: selectedSetlistItems.length }) }}
              <span v-if="totalDurationFormatted"> • {{ totalDurationFormatted }}</span>
            </span>
          </div>
          <NcButton
            variant="primary"
            @click="openAddItemDialog">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('sheetmusic', 'Add Song / Bookmark') }}
          </NcButton>
        </div>

        <div v-if="isLoadingItems" :class="$style.loadingArea">
          <NcLoadingIcon :size="32" />
        </div>

        <div v-else-if="selectedSetlistItems.length === 0" :class="$style.emptyDetail">
          <PlaylistPlay :size="48" :class="$style.emptyDetailIcon" />
          <p>{{ t('sheetmusic', 'This setlist is currently empty.') }}</p>
          <p :class="$style.emptyDetailHint">
            {{ t('sheetmusic', 'Add songs or specific bookmarks (movements) to build your performance list.') }}
          </p>
          <NcButton variant="primary" @click="openAddItemDialog">
            <template #icon>
              <PlusIcon :size="20" />
            </template>
            {{ t('sheetmusic', 'Add first item') }}
          </NcButton>
        </div>

        <div v-else :class="$style.itemsTableContainer">
          <table :class="$style.itemsTable">
            <thead>
              <tr>
                <th :class="$style.colPos">#</th>
                <th :class="$style.colOrder">{{ t('sheetmusic', 'Order') }}</th>
                <th :class="$style.colTitle">{{ t('sheetmusic', 'Song / Bookmark') }}</th>
                <th :class="$style.colDuration">{{ t('sheetmusic', 'Duration') }}</th>
                <th :class="$style.colActions">{{ t('sheetmusic', 'Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(item, index) in selectedSetlistItems"
                :key="item.id ?? index"
                :class="$style.itemRow">
                <td :class="$style.colPos">
                  <span :class="$style.posBadge">{{ index + 1 }}</span>
                </td>
                <td :class="$style.colOrder">
                  <div :class="$style.reorderButtons">
                    <NcButton
                      size="small"
                      :disabled="index === 0"
                      :title="t('sheetmusic', 'Move up')"
                      :aria-label="t('sheetmusic', 'Move up')"
                      @click="moveItem(index, index - 1)">
                      <template #icon>
                        <ArrowUpIcon :size="16" />
                      </template>
                    </NcButton>
                    <NcButton
                      size="small"
                      :disabled="index === selectedSetlistItems.length - 1"
                      :title="t('sheetmusic', 'Move down')"
                      :aria-label="t('sheetmusic', 'Move down')"
                      @click="moveItem(index, index + 1)">
                      <template #icon>
                        <ArrowDownIcon :size="16" />
                      </template>
                    </NcButton>
                  </div>
                </td>
                <td :class="$style.colTitle">
                  <div :class="$style.titleWrapper">
                    <button
                      type="button"
                      :class="$style.songTitleBtn"
                      :title="t('sheetmusic', 'Open in song viewer')"
                      @click="openItemSong(item)">
                      {{ item.songTitle || t('sheetmusic', 'Untitled song') }}
                    </button>
                    <span
                      v-if="item.bookmarkName"
                      :class="$style.bookmarkBadge"
                      :title="t('sheetmusic', 'Bookmark page {page}', { page: item.pageNum ?? 1 })">
                      <BookmarkMusicOutline :size="14" :class="$style.bookmarkIcon" />
                      {{ item.bookmarkName }}
                      <span v-if="item.pageNum" :class="$style.bookmarkPage"> (p. {{ item.pageNum }})</span>
                    </span>
                  </div>
                </td>
                <td :class="$style.colDuration">
                  {{ item.duration ? formatDuration(item.duration) : '—' }}
                </td>
                <td :class="$style.colActions">
                  <NcButton
                    variant="error"
                    size="small"
                    :title="t('sheetmusic', 'Remove from setlist')"
                    :aria-label="t('sheetmusic', 'Remove from setlist')"
                    @click="removeItem(item)">
                    <template #icon>
                      <DeleteIcon :size="16" />
                    </template>
                  </NcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <div v-else :class="$style.noSelectionArea">
        <PlaylistMusic :size="64" :class="$style.noSelectionIcon" />
        <p>{{ t('sheetmusic', 'Select a setlist to view and manage its contents.') }}</p>
      </div>
    </div>
  </div>

  <!-- Create Setlist Dialog -->
  <NcDialog
    v-if="isCreateDialogOpen"
    :name="t('sheetmusic', 'New setlist')"
    size="normal"
    @close="closeCreateDialog">
    <p>{{ t('sheetmusic', 'Enter a name for the new setlist:') }}</p>
    <NcTextField
      v-model="newSetlistName"
      :label="t('sheetmusic', 'Name')"
      :placeholder="t('sheetmusic', 'e.g. Autumn Recital 2026')"
      @keyup.enter="handleCreateSetlist" />
    <p v-if="createError" :class="$style.error" role="alert">{{ createError }}</p>
    <template #actions>
      <NcButton @click="closeCreateDialog">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="primary"
        :disabled="isCreating || !newSetlistName.trim()"
        @click="handleCreateSetlist">
        {{ t('sheetmusic', 'Create setlist') }}
      </NcButton>
    </template>
  </NcDialog>

  <!-- Rename Setlist Dialog -->
  <NcDialog
    v-if="isRenameDialogOpen"
    :name="t('sheetmusic', 'Rename setlist')"
    size="normal"
    @close="closeRenameDialog">
    <p>{{ t('sheetmusic', 'Enter a new name for this setlist:') }}</p>
    <NcTextField
      v-model="renameSetlistName"
      :label="t('sheetmusic', 'Name')"
      @keyup.enter="handleRenameSetlist" />
    <p v-if="renameError" :class="$style.error" role="alert">{{ renameError }}</p>
    <template #actions>
      <NcButton @click="closeRenameDialog">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="primary"
        :disabled="isRenaming || !renameSetlistName.trim()"
        @click="handleRenameSetlist">
        {{ t('sheetmusic', 'Save') }}
      </NcButton>
    </template>
  </NcDialog>

  <!-- Delete Setlist Dialog -->
  <NcDialog
    v-if="isDeleteDialogOpen"
    :name="t('sheetmusic', 'Delete setlist')"
    size="normal"
    @close="closeDeleteDialog">
    <p>
      {{ t('sheetmusic', 'Are you sure you want to delete "{name}"?', { name: setlistToDelete?.name ?? '' }) }}
    </p>
    <p>{{ t('sheetmusic', 'Songs and bookmarks in your library will not be deleted.') }}</p>
    <p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
    <template #actions>
      <NcButton @click="closeDeleteDialog">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="error"
        :disabled="isDeleting"
        @click="handleDeleteSetlist">
        {{ t('sheetmusic', 'Delete setlist') }}
      </NcButton>
    </template>
  </NcDialog>

  <!-- Add Item to Setlist Dialog -->
  <NcDialog
    v-if="isAddItemDialogOpen"
    :name="t('sheetmusic', 'Add to setlist')"
    size="normal"
    @close="closeAddItemDialog">
    <div :class="$style.addItemContent">
      <p>{{ t('sheetmusic', 'Select a song from your library:') }}</p>
      <NcTextField
        v-model="songSearchQuery"
        :placeholder="t('sheetmusic', 'Filter songs…')"
        :label="t('sheetmusic', 'Filter songs')"
        :class="$style.songSearchInput" />

      <div :class="$style.songSelectionList">
        <div
          v-for="song in filteredAvailableSongs"
          :key="song.id"
          :class="[$style.songChoiceRow, selectedSongToAdd?.id === song.id && $style.songChoiceSelected]"
          @click="selectSongToAdd(song)">
          <div :class="$style.songChoiceInfo">
            <span :class="$style.songChoiceTitle">{{ song.title }}</span>
            <span v-if="song.bookmarks && song.bookmarks.length > 0" :class="$style.songChoiceBookmarkCount">
              {{ t('sheetmusic', '{count} bookmarks', { count: song.bookmarks.length }) }}
            </span>
          </div>
        </div>
        <div v-if="filteredAvailableSongs.length === 0" :class="$style.noSongsMatch">
          {{ t('sheetmusic', 'No songs match your search.') }}
        </div>
      </div>

      <!-- If song selected: Option for Whole Song vs Specific Bookmark -->
      <div v-if="selectedSongToAdd" :class="$style.targetOptionArea">
        <h4>{{ t('sheetmusic', 'Include:') }}</h4>
        <div :class="$style.radioOption">
          <label>
            <input
              v-model="selectedTargetType"
              type="radio"
              value="entire_song" />
            <strong>{{ t('sheetmusic', 'Entire Song') }}</strong> ({{ selectedSongToAdd.title }})
          </label>
        </div>

        <div v-if="selectedSongToAdd.bookmarks && selectedSongToAdd.bookmarks.length > 0" :class="$style.radioOption">
          <label>
            <input
              v-model="selectedTargetType"
              type="radio"
              value="bookmark" />
            <strong>{{ t('sheetmusic', 'Specific Bookmark / Movement') }}</strong>
          </label>
          <div v-if="selectedTargetType === 'bookmark'" :class="$style.bookmarkDropdown">
            <select v-model="selectedBookmarkId" :class="$style.selectBookmark">
              <option :value="null" disabled>
                {{ t('sheetmusic', 'Select a bookmark…') }}
              </option>
              <option
                v-for="bm in selectedSongToAdd.bookmarks"
                :key="bm.id"
                :value="bm.id">
                {{ bm.name }} (p. {{ bm.pageNum }}{{ bm.endPage ? `–${bm.endPage}` : '' }})
              </option>
            </select>
          </div>
        </div>
        <p v-else :class="$style.noBookmarksNote">
          <em>{{ t('sheetmusic', 'This song has no bookmarks defined.') }}</em>
        </p>
      </div>

      <p v-if="addItemError" :class="$style.error" role="alert">{{ addItemError }}</p>
    </div>

    <template #actions>
      <NcButton @click="closeAddItemDialog">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="primary"
        :disabled="isAddingItem || !canSubmitAddItem"
        @click="handleAddItem">
        {{ t('sheetmusic', 'Add to setlist') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ViewHeader from './ViewHeader.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import PlaylistPlay from 'vue-material-design-icons/PlaylistPlay.vue'
import BookmarkMusicOutline from 'vue-material-design-icons/BookmarkMusicOutline.vue'
import type { Setlist, SetlistItem, Song } from '../types/song'
import {
  fetchSetlists,
  fetchSetlist,
  createSetlist,
  updateSetlist,
  deleteSetlist,
  addSetlistItem,
  removeSetlistItem,
  reorderSetlistItems,
} from '../api/setlists'
import { useLibraryData } from '../composables/useLibraryData'
import { formatDuration } from '../utils/song-formatter'

const props = defineProps<{
  selectedLibrary: number
}>()

const router = useRouter()
const { songs, loadLibraryData } = useLibraryData()

const setlists = ref<Setlist[]>([])
const selectedSetlist = ref<Setlist | null>(null)
const selectedSetlistItems = ref<SetlistItem[]>([])

const isLoading = ref(false)
const isLoadingItems = ref(false)
const errorMessage = ref('')
const searchQuery = ref('')

// Create Dialog
const isCreateDialogOpen = ref(false)
const newSetlistName = ref('')
const isCreating = ref(false)
const createError = ref('')

// Rename Dialog
const isRenameDialogOpen = ref(false)
const setlistToRename = ref<Setlist | null>(null)
const renameSetlistName = ref('')
const isRenaming = ref(false)
const renameError = ref('')

// Delete Dialog
const isDeleteDialogOpen = ref(false)
const setlistToDelete = ref<Setlist | null>(null)
const isDeleting = ref(false)
const deleteError = ref('')

// Add Item Dialog
const isAddItemDialogOpen = ref(false)
const songSearchQuery = ref('')
const selectedSongToAdd = ref<Song | null>(null)
const selectedTargetType = ref<'entire_song' | 'bookmark'>('entire_song')
const selectedBookmarkId = ref<number | null>(null)
const isAddingItem = ref(false)
const addItemError = ref('')

const visibleSetlists = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return setlists.value
  return setlists.value.filter(s => s.name.toLowerCase().includes(q))
})

const filteredAvailableSongs = computed(() => {
  const q = songSearchQuery.value.trim().toLowerCase()
  if (!q) return songs.value
  return songs.value.filter(s => s.title.toLowerCase().includes(q))
})

const canSubmitAddItem = computed(() => {
  if (!selectedSongToAdd.value) return false
  if (selectedTargetType.value === 'bookmark' && !selectedBookmarkId.value) return false
  return true
})

const totalDurationFormatted = computed(() => {
  const totalSeconds = selectedSetlistItems.value.reduce((sum, item) => sum + (item.duration ?? 0), 0)
  if (totalSeconds <= 0) return ''
  return formatDuration(totalSeconds)
})

async function loadSetlists() {
  if (props.selectedLibrary <= 0) return
  isLoading.value = true
  errorMessage.value = ''
  try {
    const list = await fetchSetlists(props.selectedLibrary)
    setlists.value = list
    if (selectedSetlist.value) {
      const refreshed = list.find(s => s.id === selectedSetlist.value?.id)
      if (refreshed) {
        selectedSetlist.value = refreshed
      } else {
        selectedSetlist.value = list.length > 0 ? list[0] : null
      }
    } else if (list.length > 0) {
      selectedSetlist.value = list[0]
    }
    if (selectedSetlist.value) {
      await loadCurrentSetlistItems()
    } else {
      selectedSetlistItems.value = []
    }
  } catch (err: unknown) {
    errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Failed to load setlists')
  } finally {
    isLoading.value = false
  }
}

async function loadCurrentSetlistItems() {
  if (!selectedSetlist.value || props.selectedLibrary <= 0) return
  isLoadingItems.value = true
  try {
    const full = await fetchSetlist(props.selectedLibrary, selectedSetlist.value.id)
    selectedSetlistItems.value = full.items ?? []
    if (full.itemCount !== undefined) {
      selectedSetlist.value.itemCount = full.itemCount
    } else {
      selectedSetlist.value.itemCount = selectedSetlistItems.value.length
    }
  } catch (err: unknown) {
    errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Failed to load setlist items')
  } finally {
    isLoadingItems.value = false
  }
}

function selectSetlist(setlist: Setlist) {
  selectedSetlist.value = setlist
  loadCurrentSetlistItems()
}

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
    const created = await createSetlist(props.selectedLibrary, name)
    closeCreateDialog()
    await loadSetlists()
    const found = setlists.value.find(s => s.id === created.id)
    if (found) {
      selectSetlist(found)
    }
  } catch (err: unknown) {
    createError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not create setlist')
  } finally {
    isCreating.value = false
  }
}

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
    await updateSetlist(props.selectedLibrary, setlistToRename.value.id, { name })
    closeRenameDialog()
    await loadSetlists()
  } catch (err: unknown) {
    renameError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not rename setlist')
  } finally {
    isRenaming.value = false
  }
}

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
    const id = setlistToDelete.value.id
    await deleteSetlist(props.selectedLibrary, id)
    closeDeleteDialog()
    if (selectedSetlist.value?.id === id) {
      selectedSetlist.value = null
      selectedSetlistItems.value = []
    }
    await loadSetlists()
  } catch (err: unknown) {
    deleteError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not delete setlist')
  } finally {
    isDeleting.value = false
  }
}

function openAddItemDialog() {
  songSearchQuery.value = ''
  selectedSongToAdd.value = null
  selectedTargetType.value = 'entire_song'
  selectedBookmarkId.value = null
  addItemError.value = ''
  isAddItemDialogOpen.value = true
}

function closeAddItemDialog() {
  isAddItemDialogOpen.value = false
  selectedSongToAdd.value = null
  addItemError.value = ''
}

function selectSongToAdd(song: Song) {
  selectedSongToAdd.value = song
  if (song.bookmarks && song.bookmarks.length > 0) {
    selectedBookmarkId.value = song.bookmarks[0].id ?? null
  } else {
    selectedTargetType.value = 'entire_song'
    selectedBookmarkId.value = null
  }
}

async function handleAddItem() {
  if (!selectedSetlist.value || !selectedSongToAdd.value || isAddingItem.value) return
  isAddingItem.value = true
  addItemError.value = ''
  try {
    const bookmarkId = selectedTargetType.value === 'bookmark' ? selectedBookmarkId.value : null
    await addSetlistItem(props.selectedLibrary, selectedSetlist.value.id, selectedSongToAdd.value.id, bookmarkId)
    closeAddItemDialog()
    await loadCurrentSetlistItems()
    // Refresh count in master list
    const found = setlists.value.find(s => s.id === selectedSetlist.value?.id)
    if (found) {
      found.itemCount = selectedSetlistItems.value.length
    }
  } catch (err: unknown) {
    addItemError.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not add item to setlist')
  } finally {
    isAddingItem.value = false
  }
}

async function removeItem(item: SetlistItem) {
  if (!selectedSetlist.value || !item.id) return
  try {
    await removeSetlistItem(props.selectedLibrary, selectedSetlist.value.id, item.id)
    await loadCurrentSetlistItems()
    const found = setlists.value.find(s => s.id === selectedSetlist.value?.id)
    if (found) {
      found.itemCount = selectedSetlistItems.value.length
    }
  } catch (err: unknown) {
    errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not remove item')
  }
}

async function moveItem(fromIndex: number, toIndex: number) {
  if (!selectedSetlist.value) return
  if (fromIndex < 0 || fromIndex >= selectedSetlistItems.value.length) return
  if (toIndex < 0 || toIndex >= selectedSetlistItems.value.length) return

  const items = [...selectedSetlistItems.value]
  const [moved] = items.splice(fromIndex, 1)
  items.splice(toIndex, 0, moved)
  selectedSetlistItems.value = items

  const itemIds = items.map(it => it.id).filter((id): id is number => typeof id === 'number')
  try {
    await reorderSetlistItems(props.selectedLibrary, selectedSetlist.value.id, itemIds)
  } catch (err: unknown) {
    errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not reorder items')
    await loadCurrentSetlistItems()
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

.masterColumn {
  display: flex;
  flex-direction: column;
  gap: 12px;
  border-right: 1px solid var(--color-border);
  padding-right: 16px;
  overflow-y: auto;
}

.searchWrapper {
  width: 100%;
}

.searchInput {
  width: 100%;
}

.listColumn {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.setlistsList {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.setlistRow {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: var(--border-radius-element, 8px);
  transition: background-color 0.15s ease;
}

.setlistRow:hover {
  background-color: var(--color-background-hover);
}

.selectedRow {
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
}

.setlistButton {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  background: transparent;
  border: 0;
  padding: 4px 0;
  cursor: pointer;
  text-align: start;
  min-width: 0;
  color: inherit;
  font: inherit;
}

.setlistIcon {
  color: var(--color-primary-element, #0082c9);
  flex-shrink: 0;
}

.setlistName {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.itemCountBadge {
  font-size: 0.8rem;
  color: var(--color-text-maxcontrast);
  background-color: var(--color-background-darker);
  padding: 2px 8px;
  border-radius: 12px;
  flex-shrink: 0;
}

.rowActions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.emptyMessage {
  padding: 16px 8px;
  color: var(--color-text-maxcontrast);
  text-align: center;
}

/* Detail column */
.detailColumn {
  display: flex;
  flex-direction: column;
  gap: 16px;
  overflow-y: auto;
  padding-left: 8px;
}

.detailHeader {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 12px;
}

.detailTitleArea {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.detailTitle {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 600;
}

.detailSubtitle {
  font-size: 0.9rem;
  color: var(--color-text-maxcontrast);
}

.loadingArea,
.noSelectionArea,
.emptyDetail {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 16px;
  text-align: center;
  color: var(--color-text-maxcontrast);
}

.noSelectionIcon,
.emptyDetailIcon {
  margin-bottom: 16px;
  opacity: 0.5;
}

.emptyDetailHint {
  font-size: 0.85rem;
  margin-bottom: 16px;
}

/* Items Table */
.itemsTableContainer {
  width: 100%;
  overflow-x: auto;
}

.itemsTable {
  width: 100%;
  border-collapse: collapse;
}

.itemsTable th {
  text-align: start;
  padding: 8px 12px;
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--color-text-maxcontrast);
  border-bottom: 2px solid var(--color-border);
}

.itemRow {
  border-bottom: 1px solid var(--color-border);
  transition: background-color 0.15s ease;
}

.itemRow:hover {
  background-color: var(--color-background-hover);
}

.itemsTable td {
  padding: 10px 12px;
  vertical-align: middle;
}

.colPos {
  width: 48px;
  text-align: center;
}

.posBadge {
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.colOrder {
  width: 80px;
}

.reorderButtons {
  display: flex;
  gap: 2px;
}

.colTitle {
  min-width: 200px;
}

.titleWrapper {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.songTitleBtn {
  background: transparent;
  border: 0;
  padding: 0;
  color: var(--color-primary-element, #0082c9);
  font: inherit;
  font-weight: 500;
  cursor: pointer;
  text-align: start;
}

.songTitleBtn:hover {
  text-decoration: underline;
}

.bookmarkBadge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
  color: var(--color-primary-element, #0082c9);
  border-radius: 12px;
  font-size: 0.8rem;
  font-weight: 500;
}

.bookmarkIcon {
  flex-shrink: 0;
}

.bookmarkPage {
  opacity: 0.85;
}

.colDuration {
  width: 80px;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-maxcontrast);
}

.colActions {
  width: 60px;
  text-align: end;
}

/* Dialog content */
.addItemContent {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: 480px;
  overflow-y: auto;
}

.songSearchInput {
  width: 100%;
}

.songSelectionList {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-element, 8px);
  max-height: 200px;
  overflow-y: auto;
  padding: 4px;
}

.songChoiceRow {
  padding: 8px 12px;
  cursor: pointer;
  border-radius: 4px;
  transition: background-color 0.15s;
}

.songChoiceRow:hover {
  background-color: var(--color-background-hover);
}

.songChoiceSelected {
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
}

.songChoiceInfo {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.songChoiceTitle {
  font-weight: 500;
}

.songChoiceBookmarkCount {
  font-size: 0.75rem;
  color: var(--color-text-maxcontrast);
  background-color: var(--color-background-darker);
  padding: 2px 6px;
  border-radius: 10px;
}

.noSongsMatch {
  padding: 16px;
  text-align: center;
  color: var(--color-text-maxcontrast);
}

.targetOptionArea {
  margin-top: 8px;
  border-top: 1px solid var(--color-border);
  padding-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.targetOptionArea h4 {
  margin: 0 0 4px 0;
  font-size: 0.95rem;
}

.radioOption {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.radioOption label {
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
}

.bookmarkDropdown {
  margin-left: 24px;
}

.selectBookmark {
  width: 100%;
  padding: 6px 10px;
  border-radius: var(--border-radius-element, 6px);
  border: 1px solid var(--color-border);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font: inherit;
}

.noBookmarksNote {
  font-size: 0.85rem;
  color: var(--color-text-maxcontrast);
  margin: 4px 0;
}

.error {
  color: var(--color-error);
  font-size: 0.85rem;
}
</style>
