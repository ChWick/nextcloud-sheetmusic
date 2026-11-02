import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import type { Setlist, SetlistItem } from '../types/song'
import {
  fetchSetlists,
  fetchSetlist,
  createSetlist as apiCreateSetlist,
  updateSetlist as apiUpdateSetlist,
  deleteSetlist as apiDeleteSetlist,
  addSetlistItem as apiAddSetlistItem,
  updateSetlistItem as apiUpdateSetlistItem,
  removeSetlistItem as apiRemoveSetlistItem,
  reorderSetlistItems as apiReorderSetlistItems,
} from '../api/setlists'
import { formatDuration } from '../utils/song-formatter'

export function useSetlists(getLibraryId: () => number) {
  const setlists = ref<Setlist[]>([])
  const selectedSetlist = ref<Setlist | null>(null)
  const selectedSetlistItems = ref<SetlistItem[]>([])

  const isLoading = ref(false)
  const isLoadingItems = ref(false)
  const errorMessage = ref('')
  const searchQuery = ref('')

  const visibleSetlists = computed(() => {
    const q = searchQuery.value.trim().toLowerCase()
    if (!q) return setlists.value
    return setlists.value.filter(s => s.name.toLowerCase().includes(q))
  })

  const totalDurationFormatted = computed(() => {
    const totalSeconds = selectedSetlistItems.value.reduce((sum, item) => sum + (item.duration ?? 0), 0)
    if (totalSeconds <= 0) return ''
    return formatDuration(totalSeconds)
  })

  async function loadCurrentSetlistItems() {
    const libraryId = getLibraryId()
    if (!selectedSetlist.value || libraryId <= 0) return
    isLoadingItems.value = true
    try {
      const full = await fetchSetlist(libraryId, selectedSetlist.value.id)
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

  async function loadSetlists() {
    const libraryId = getLibraryId()
    if (libraryId <= 0) return
    isLoading.value = true
    errorMessage.value = ''
    try {
      const list = await fetchSetlists(libraryId)
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

  function selectSetlist(setlist: Setlist) {
    selectedSetlist.value = setlist
    loadCurrentSetlistItems()
  }

  async function createSetlist(name: string): Promise<Setlist> {
    const libraryId = getLibraryId()
    const created = await apiCreateSetlist(libraryId, name)
    await loadSetlists()
    const found = setlists.value.find(s => s.id === created.id)
    if (found) {
      selectSetlist(found)
    }
    return created
  }

  async function renameSetlist(id: number, name: string): Promise<void> {
    const libraryId = getLibraryId()
    await apiUpdateSetlist(libraryId, id, { name })
    await loadSetlists()
  }

  async function deleteSetlist(id: number): Promise<void> {
    const libraryId = getLibraryId()
    await apiDeleteSetlist(libraryId, id)
    if (selectedSetlist.value?.id === id) {
      selectedSetlist.value = null
      selectedSetlistItems.value = []
    }
    await loadSetlists()
  }

  async function addItem(songId: number, bookmarkId: number | null): Promise<void> {
    const libraryId = getLibraryId()
    if (!selectedSetlist.value) return
    await apiAddSetlistItem(libraryId, selectedSetlist.value.id, songId, bookmarkId)
    await loadCurrentSetlistItems()
    const found = setlists.value.find(s => s.id === selectedSetlist.value?.id)
    if (found) {
      found.itemCount = selectedSetlistItems.value.length
    }
  }

  async function updateItemBookmark(itemId: number, bookmarkId: number | null): Promise<void> {
    const libraryId = getLibraryId()
    if (!selectedSetlist.value) return
    try {
      const updated = await apiUpdateSetlistItem(libraryId, selectedSetlist.value.id, itemId, { bookmarkId })
      const index = selectedSetlistItems.value.findIndex(it => it.id === itemId)
      if (index !== -1 && updated) {
        selectedSetlistItems.value.splice(index, 1, updated)
      }
    } catch (err: unknown) {
      errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not update bookmark')
      await loadCurrentSetlistItems()
    }
  }

  async function removeItem(item: SetlistItem): Promise<void> {
    const libraryId = getLibraryId()
    if (!selectedSetlist.value || !item.id) return
    try {
      await apiRemoveSetlistItem(libraryId, selectedSetlist.value.id, item.id)
      await loadCurrentSetlistItems()
      const found = setlists.value.find(s => s.id === selectedSetlist.value?.id)
      if (found) {
        found.itemCount = selectedSetlistItems.value.length
      }
    } catch (err: unknown) {
      errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not remove item')
    }
  }

  async function moveItem(fromIndex: number, toIndex: number): Promise<void> {
    const libraryId = getLibraryId()
    if (!selectedSetlist.value) return
    if (fromIndex < 0 || fromIndex >= selectedSetlistItems.value.length) return
    if (toIndex < 0 || toIndex >= selectedSetlistItems.value.length) return

    const items = [...selectedSetlistItems.value]
    const [moved] = items.splice(fromIndex, 1)
    items.splice(toIndex, 0, moved)
    selectedSetlistItems.value = items

    const itemIds = items.map(it => it.id).filter((id): id is number => typeof id === 'number')
    try {
      await apiReorderSetlistItems(libraryId, selectedSetlist.value.id, itemIds)
    } catch (err: unknown) {
      errorMessage.value = err instanceof Error ? err.message : t('sheetmusic', 'Could not reorder items')
      await loadCurrentSetlistItems()
    }
  }

  return {
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
    loadCurrentSetlistItems,
    selectSetlist,
    createSetlist,
    renameSetlist,
    deleteSetlist,
    addItem,
    updateItemBookmark,
    removeItem,
    moveItem,
  }
}
