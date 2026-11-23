<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, EntityType, LibraryData, Song } from './types/song'
import type { Library } from './types/library.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { pickSingleNextcloudNode } from './utils/nextcloudFilePicker'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { describeRequestFailure } from './utils/api'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import AppNavigationSidebar from './components/AppNavigationSidebar.vue'
import ConfirmDeleteDialog from './components/ConfirmDeleteDialog.vue'
import LibraryPickerDialog from './components/LibraryPickerDialog.vue'
import SongsView from './components/SongsView.vue'
import SetlistsView from './components/SetlistsView.vue'
import SublibrariesView from './components/SublibrariesView.vue'
import SharesView from './components/SharesView.vue'
import IntegrityView from './components/IntegrityView.vue'
import ToolsView from './components/ToolsView.vue'
import EntitiesView from './components/EntitiesView.vue'
import TrashView from './components/TrashView.vue'
import LibrarySettingsDialog from './components/LibrarySettingsDialog.vue'
import ShareDialog from './components/ShareDialog.vue'
import ShareTargetDialog, { type ShareImportResult } from './components/ShareTargetDialog.vue'
import OfflineSetlistsView from './components/setlists/OfflineSetlistsView.vue'
import NotFoundState from './components/NotFoundState.vue'
import AppUpdateBanner from './components/AppUpdateBanner.vue'
import { consumeOfflineReturnState, useOnlineStatus } from './composables/useOnlineStatus'
import { useProgressPolling } from './composables/useProgressPolling'
import { clearShareTargetParam, consumePendingShare, getShareTokenParam, hasShareTargetParam } from './utils/shareTarget'
import { initOfflineSetlistsAutoSync } from './utils/offlineSetlists'

/** Views of a library: the song view, one view per song filter, integrity audit, and tools. */
type ViewName = 'songs' | 'new-song' | 'setlists' | 'sublibraries' | 'shares' | 'integrity' | 'tools' | 'trash' | EntityType
const VIEW_NAMES: ViewName[] = ['songs', 'new-song', 'setlists', 'composers', 'artists', 'books', 'collections', 'genres', 'keys', 'customgroups', 'years', 'signatures', 'sourcetypes', 'instruments', 'arrangers', 'sublibraries', 'shares', 'integrity', 'tools', 'trash']

/*** State ***/
const { isOnline, setSimulatedOffline } = useOnlineStatus()
const isAddDialogOpen = ref(false)
const isImportDialogOpen = ref(false)
const isShareTargetDialogOpen = ref(false)
const pendingShareFiles = ref<File[]>([])
const pendingShareTitle = ref('')
const shareImportRefreshKey = ref(0)
const isSettingsDialogOpen = ref(false)
const isShareDialogOpen = ref(false)
const importPdfMetadata = ref(true)
const selectedFolder = ref<INode>()
const selectedDbFile = ref<INode>()
const addError = ref('')
const importError = ref('')
const isAddingLibrary = ref(false)
const isImportingLibrary = ref(false)
const {
  progress: importProgress,
  start: startImportPolling,
  stop: stopProgressPolling,
} = useProgressPolling({ intervalMs: 400 })

function startProgressPolling(progressId: string) {
  startImportPolling(progressId, t('sheetmusic', 'Starting import...'))
}
const isPublicShare = Boolean(loadState('sheetmusic', 'isPublicShare', false))
const publicSharingToken = loadState('sheetmusic', 'publicSharingToken', '')
const publicLibrary = loadState('sheetmusic', 'publicLibrary', null) as Library | null

const libraries = ref<Library[]>([])
const hasLoadedLibraries = ref(false)
const selectedLibrary = ref<number>(-1)
const selectedView = ref<ViewName>('songs')
const fileError = ref('') // used for dialogs and library load errors
const isDeleteDialogOpen = ref(false)
const isDeletingLibrary = ref(false)
const deleteError = ref('')

/** Currently selected library object */
const currentLibrary = computed(
  () => libraries.value.find(library => library.id === selectedLibrary.value),
)

/** Path of the currently selected library, used to name it in the delete dialog */
const selectedLibraryPath = computed(
  () => currentLibrary.value?.path ?? '',
)

function formatLibraryOption(library: Library): string {
  if (library.isShared) {
    const owner = library.ownerDisplayName || library.ownerId || t('sheetmusic', 'unknown')
    const ro = library.canWrite === false ? ` [${t('sheetmusic', 'Read-only')}]` : ''
    return `${library.path} (${t('sheetmusic', 'shared by {owner}', { owner }, { escape: false })})${ro}`
  }
  return library.path
}

/*** Router ***/
const router = useRouter()
const route = useRoute()

/** Navigation helpers **/
function navigateToLibrary(library: string | number) {
  const libraryId = typeof library === 'string' ? parseInt(library, 10) : library
  router.push({ name: 'LibraryView', params: { library: libraryId, view: 'songs' }, query: {} })
}
function goToView(view: ViewName) {
  if (selectedLibrary.value < 0) return
  router.push({ name: 'LibraryView', params: { library: selectedLibrary.value, view } })
}
function handleOpenSublibraries() {
  isSettingsDialogOpen.value = false
  goToView('sublibraries')
}

const isUnknownRoute = computed(() =>
  route.name === 'NotFound'
  || (route.name === 'LibraryView' && Boolean(route.params.view) && !(VIEW_NAMES as string[]).includes(String(route.params.view))),
)

const isLibraryNotFound = computed(() => {
  if (route.name !== 'LibraryView') return false
  if (!Number.isInteger(selectedLibrary.value) || selectedLibrary.value <= 0) return true
  return hasLoadedLibraries.value && !fileError.value && !currentLibrary.value
})

function navigateHome() {
  if (currentLibrary.value) {
    navigateToLibrary(currentLibrary.value.id)
  } else if (libraries.value.length > 0) {
    navigateToLibrary(libraries.value[0].id)
  } else {
    router.push({ name: 'Home' })
  }
}

/*** Route watcher – sync state with URL ***/
watch(
  () => route.params,
  params => {
    const lib = Array.isArray(params.library) ? parseInt(params.library[0]) : parseInt(params.library)
    const view = (params.view as string) ?? 'songs'
    if (lib) {
      selectedLibrary.value = lib
      selectedView.value = (VIEW_NAMES as string[]).includes(view) ? view as ViewName : 'songs'
    } else {
      selectedLibrary.value = -1
      selectedView.value = 'songs'
    }
  },
  { immediate: true }
)

import { apiDelete, apiGet, apiPost } from './api/client'

/*** Libraries ***/
/** Load the user's libraries - also used to refresh the list after adding or removing one */
async function loadLibraries() {
  if (isPublicShare && publicLibrary) {
    libraries.value = [publicLibrary]
    hasLoadedLibraries.value = true
    return
  }
  if (!isOnline.value) {
    return
  }
  try {
    const response = await apiGet('/api/libraries')
    libraries.value = response.data.ocs.data.libraries
  } catch (error) {
    fileError.value = describeRequestFailure(error, t('sheetmusic', 'Could not load your MobileSheets libraries.'))
    showError(fileError.value)
  } finally {
    hasLoadedLibraries.value = true
  }
}

watch(isOnline, async (online, wasOnline) => {
  if (!online || isPublicShare) {
    return
  }
  if (libraries.value.length === 0) {
    await loadLibraries()
  }
  const returnState = !wasOnline ? consumeOfflineReturnState() : null
  if (returnState && returnState.libraryId > 0 && returnState.setlistId > 0) {
    await router.push({
      name: 'LibraryView',
      params: { library: returnState.libraryId, view: 'setlists' },
      query: {
        setlist: String(returnState.setlistId),
        ...(returnState.isPlaying ? { play: '1', item: String(returnState.itemIndex) } : {}),
      },
    })
    return
  }
  const activeRouteLibrary = route.params.library as string | undefined
  if (libraries.value.length > 0 && !activeRouteLibrary) {
    navigateToLibrary(libraries.value[0].id)
  }
})

let stopOfflineAutoSync: (() => void) | null = null

/*** Initial load of libraries list ***/
onMounted(async () => {
  await loadLibraries()
  const activeRouteLibrary = route.params.library as string | undefined

  if (isPublicShare && publicLibrary) {
    selectedLibrary.value = publicLibrary.id
    if (!activeRouteLibrary || activeRouteLibrary !== String(publicLibrary.id)) {
      navigateToLibrary(publicLibrary.id)
    }
  } else if (libraries.value.length > 0 && !activeRouteLibrary && route.name !== 'NotFound') {
    // Only set a default if the user landed on the root page matching no library
    navigateToLibrary(libraries.value[0].id)
  }

  if (!isPublicShare) {
    stopOfflineAutoSync = initOfflineSetlistsAutoSync()
  }

  if (!isPublicShare && hasShareTargetParam()) {
    const shareToken = getShareTokenParam()
    clearShareTargetParam()
    const pending = await consumePendingShare(shareToken)
    if (pending && pending.files.length > 0) {
      pendingShareFiles.value = pending.files
      pendingShareTitle.value = pending.title
      isShareTargetDialogOpen.value = true
    }
  }
})

function closeShareTargetDialog() {
  isShareTargetDialogOpen.value = false
  pendingShareFiles.value = []
  pendingShareTitle.value = ''
}

function handleShareTargetImported(result: ShareImportResult) {
  closeShareTargetDialog()
  shareImportRefreshKey.value += 1
  if (result.targetType === 'setlist-file' || result.targetType === 'new-setlist') {
    router.push({
      name: 'LibraryView',
      params: { library: result.libraryId, view: 'setlists' },
      query: result.setlistId ? { setlist: String(result.setlistId) } : {},
    })
  } else {
    router.push({
      name: 'LibraryView',
      params: { library: result.libraryId, view: 'songs' },
      query: result.songId ? { song: String(result.songId) } : {},
    })
  }
}

/*** Dialog helpers ***/
function openAddDialog(event?: Event) {
  event?.preventDefault()
  selectedFolder.value = undefined
  importPdfMetadata.value = true
  addError.value = ''
  importProgress.value = null
  stopProgressPolling()
  isAddDialogOpen.value = true
}

function openImportDialog(event?: Event) {
  event?.preventDefault()
  selectedDbFile.value = undefined
  importError.value = ''
  importProgress.value = null
  stopProgressPolling()
  isImportDialogOpen.value = true
}

async function openFolderPicker() {
  addError.value = ''
  try {
    const picked = await pickSingleNextcloudNode({
      title: t('sheetmusic', 'Select a folder for your sheet music library'),
      allowDirectories: true,
      filter: node => node.type === 'folder',
      canPick: node => node.type === 'folder',
      selectLabel: node => t('sheetmusic', 'Select folder {name}', { name: node.basename }, { escape: false }),
      emptyLabel: t('sheetmusic', 'Select folder'),
    })
    if (picked) {
      selectedFolder.value = picked
    }
  } catch {
    addError.value = t('sheetmusic', 'Could not select a folder.')
    showError(addError.value)
  }
}

async function openDbPicker() {
  importError.value = ''
  try {
    const picked = await pickSingleNextcloudNode({
      title: t('sheetmusic', 'Select a MobileSheets database file'),
      filter: node => node.type === 'folder' || node.basename.toLowerCase().endsWith('.db'),
      canPick: node => node.type === 'file' && node.basename.toLowerCase().endsWith('.db'),
      selectLabel: node => t('sheetmusic', 'Select {name}', { name: node.basename }, { escape: false }),
      emptyLabel: t('sheetmusic', 'Select .db file'),
    })
    if (picked) {
      selectedDbFile.value = picked
    }
  } catch {
    importError.value = t('sheetmusic', 'Could not select a database file.')
    showError(importError.value)
  }
}

async function submitLibraryCreation(params: {
  addedPath: string
  importMobilesheets: boolean
  importPdfMetadata?: boolean
  fallbackTargetPath: string
  onSuccessReset: () => void
  onError: (msg: string) => void
  onBusyChange: (busy: boolean) => void
  successMessage: string
  errorFallback: string
}) {
  params.onBusyChange(true)
  params.onError('')
  const progressId = 'import-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
  startProgressPolling(progressId)
  try {
    const response = await apiPost('/api/libraries' as any, {
      path: params.addedPath,
      import_mobilesheets: params.importMobilesheets,
      ...(params.importPdfMetadata !== undefined ? { importPdfMetadata: params.importPdfMetadata } : {}),
      progressId,
    } as any)
    stopProgressPolling()
    params.onSuccessReset()
    importProgress.value = null

    const prevIds = new Set(libraries.value.map(library => library.id))
    await loadLibraries()

    const targetPath = (response as any)?.data?.ocs?.data?.path ?? params.fallbackTargetPath
    const normalizedTargetPath = targetPath !== '/' ? targetPath.replace(/\/+$/, '') : targetPath
    const normalizedAddedPath = params.addedPath !== '/' ? params.addedPath.replace(/\/+$/, '') : params.addedPath
    const newlyAdded = libraries.value.find(library => !prevIds.has(library.id))
    const addedLibrary = newlyAdded ?? libraries.value.find(library =>
      library.path === targetPath ||
      library.path === normalizedTargetPath ||
      library.path === params.addedPath ||
      library.path === normalizedAddedPath ||
      (library.path && normalizedTargetPath && library.path.toLowerCase() === normalizedTargetPath.toLowerCase())
    )
    if (addedLibrary) {
      navigateToLibrary(addedLibrary.id)
    }
    showSuccess(params.successMessage)
  } catch (error) {
    stopProgressPolling()
    const msg = describeRequestFailure(error, params.errorFallback)
    params.onError(msg)
    showError(msg)
  } finally {
    params.onBusyChange(false)
    stopProgressPolling()
  }
}

async function addLibrary() {
  if (!selectedFolder.value) {
    addError.value = t('sheetmusic', 'Please select a folder first.')
    showError(addError.value)
    return
  }
  const addedPath = selectedFolder.value.path
  await submitLibraryCreation({
    addedPath,
    importMobilesheets: false,
    importPdfMetadata: importPdfMetadata.value,
    fallbackTargetPath: addedPath,
    onSuccessReset: () => {
      selectedFolder.value = undefined
      isAddDialogOpen.value = false
    },
    onError: (msg) => { addError.value = msg },
    onBusyChange: (busy) => { isAddingLibrary.value = busy },
    successMessage: t('sheetmusic', 'Library added successfully.'),
    errorFallback: t('sheetmusic', 'Could not save the library.'),
  })
}

async function importLibrary() {
  if (!selectedDbFile.value) {
    importError.value = t('sheetmusic', 'Please select a MobileSheets .db file first.')
    showError(importError.value)
    return
  }
  const addedPath = selectedDbFile.value.path
  await submitLibraryCreation({
    addedPath,
    importMobilesheets: true,
    fallbackTargetPath: addedPath.includes('/') ? addedPath.substring(0, addedPath.lastIndexOf('/')) || '/' : addedPath,
    onSuccessReset: () => {
      selectedDbFile.value = undefined
      isImportDialogOpen.value = false
    },
    onError: (msg) => { importError.value = msg },
    onBusyChange: (busy) => { isImportingLibrary.value = busy },
    successMessage: t('sheetmusic', 'MobileSheets library imported successfully.'),
    errorFallback: t('sheetmusic', 'Could not import the MobileSheets library.'),
  })
}

/*** Deleting a library ***/
function openDeleteDialog() {
  if (selectedLibrary.value < 0) return
  deleteError.value = ''
  isDeleteDialogOpen.value = true
}
function closeDeleteDialog() {
  isDeleteDialogOpen.value = false
  deleteError.value = ''
}
/**
 * Remove the currently selected library from the list. Only the library entry is
 * removed on the server, its files stay in the Nextcloud files.
 */
async function deleteLibrary() {
  if (selectedLibrary.value < 0) return
  isDeletingLibrary.value = true
  try {
    await apiDelete('/api/libraries/{id}', {
      pathParams: { id: selectedLibrary.value },
    })

    closeDeleteDialog()
    await loadLibraries()
    showSuccess(t('sheetmusic', 'Library deleted successfully.'))

    // Switch to another library, or back to the empty state when this was the last one
    const nextLibrary = libraries.value[0]
    if (nextLibrary) {
      navigateToLibrary(nextLibrary.id)
    } else {
      selectedLibrary.value = -1
      router.push({ name: 'Home' })
    }
  } catch (error) {
    deleteError.value = describeRequestFailure(error, t('sheetmusic', 'Could not delete the library.'))
    showError(deleteError.value)
  } finally {
    isDeletingLibrary.value = false
  }
}
</script>

<template>
  <div class="sheetmusic-app">
    <NcContent app-name="sheetmusic">
      <!-- Sidebar (navigation) -->
      <AppNavigationSidebar
        v-if="isOnline"
        :libraries="libraries"
        :selected-library="selectedLibrary"
        :selected-view="selectedView"
        :current-library="currentLibrary"
        :is-public-share="isPublicShare"
        :public-library-path="publicLibrary?.path"
        :selected-library-path="selectedLibraryPath"
        :format-library-option="formatLibraryOption"
        @select-library="navigateToLibrary"
        @enter-offline="setSimulatedOffline(true)"
        @open-add-library="openAddDialog"
        @open-import-library="openImportDialog"
        @open-share="isShareDialogOpen = true"
        @open-settings="isSettingsDialogOpen = true" />

      <!-- Main content -->
      <NcAppContent>
        <OfflineSetlistsView v-if="!isOnline && !isPublicShare" :libraries="libraries" />
        <NotFoundState
          v-else-if="isUnknownRoute"
          :title="t('sheetmusic', 'Page not found')"
          :description="t('sheetmusic', 'The page you are looking for does not exist.')"
          :action-label="libraries.length > 0 ? t('sheetmusic', 'Back to songs') : undefined"
          test-id="page-not-found"
          @action="navigateHome" />
        <NotFoundState
          v-else-if="isLibraryNotFound"
          :title="t('sheetmusic', 'Library not found')"
          :description="t('sheetmusic', 'The requested library does not exist or you do not have access to it.')"
          :action-label="libraries.length > 0 ? t('sheetmusic', 'Go to library') : undefined"
          test-id="library-not-found"
          @action="navigateHome" />
        <div v-else-if="selectedLibrary >= 0 && !hasLoadedLibraries" :class="$style.loadingLibraries" data-test="library-loading-state">
          <NcLoadingIcon :size="36" />
        </div>
        <template v-else-if="selectedLibrary >= 0 && currentLibrary">
          <SongsView v-if="selectedView === 'songs' || selectedView === 'new-song'"
                     :key="`songs-${shareImportRefreshKey}`"
                     :selectedLibrary="selectedLibrary"
                     :libraries="libraries"
                     :standalone-create="selectedView === 'new-song'" />
          <SetlistsView v-else-if="selectedView === 'setlists'"
                        :key="`setlists-${shareImportRefreshKey}`"
                        :selected-library="selectedLibrary"
                        :can-write="!isPublicShare && (currentLibrary?.canWrite ?? true)" />
          <SublibrariesView v-else-if="selectedView === 'sublibraries'"
                            :selected-library="selectedLibrary"
                            :libraries="libraries" />
          <SharesView v-else-if="selectedView === 'shares'"
                      :selected-library="selectedLibrary"
                      :libraries="libraries"
                      @open-sublibraries="goToView('sublibraries')" />
          <IntegrityView v-else-if="selectedView === 'integrity'" :selectedLibrary="selectedLibrary" />
          <ToolsView v-else-if="selectedView === 'tools'" :selected-library="selectedLibrary" />
          <TrashView v-else-if="selectedView === 'trash'" :selected-library="selectedLibrary" />
          <EntitiesView v-else
                        :key="selectedView"
                        :selectedLibrary="selectedLibrary"
                        :entity-type="selectedView"
                        :can-write="!isPublicShare && (currentLibrary?.canWrite ?? true)" />
        </template>

        <p v-if="fileError" :class="$style.contentError" role="alert">{{ fileError }}</p>
      </NcAppContent>
    </NcContent>

    <LibraryPickerDialog
      :open="isAddDialogOpen"
      :title="t('sheetmusic', 'Add Sheet Music Library')"
      :description="t('sheetmusic', 'Select a folder from your Nextcloud files. Sheet music files will be imported.')"
      :selected-path="selectedFolder?.path"
      :picker-label="t('sheetmusic', 'Choose folder from Nextcloud')"
      :submit-label="t('sheetmusic', 'Add library')"
      :is-submitting="isAddingLibrary"
      :progress="importProgress"
      :error="addError"
      @close="isAddDialogOpen = false"
      @pick="openFolderPicker"
      @submit="addLibrary">
      <div :class="$style.dialogOptionRow">
        <NcCheckboxRadioSwitch
          v-model="importPdfMetadata"
          type="switch"
          :disabled="isAddingLibrary">
          {{ t('sheetmusic', 'Import metadata from PDF files') }}
        </NcCheckboxRadioSwitch>
      </div>
    </LibraryPickerDialog>

    <LibraryPickerDialog
      :open="isImportDialogOpen"
      :title="t('sheetmusic', 'Import MobileSheets Library')"
      :description="t('sheetmusic', 'Select a mobilesheets.db file from your Nextcloud files to import your existing songs and metadata.')"
      :selected-path="selectedDbFile?.path"
      :picker-label="t('sheetmusic', 'Choose mobilesheets.db from Nextcloud')"
      :submit-label="t('sheetmusic', 'Import library')"
      :is-submitting="isImportingLibrary"
      :progress="importProgress"
      :error="importError"
      @close="isImportDialogOpen = false"
      @pick="openDbPicker"
      @submit="importLibrary" />

    <ConfirmDeleteDialog
      :open="isDeleteDialogOpen"
      :title="t('sheetmusic', 'Delete library')"
      :confirm-label="t('sheetmusic', 'Delete library')"
      :is-deleting="isDeletingLibrary"
      :error="deleteError"
      :show-loading-icon="false"
      @close="closeDeleteDialog"
      @confirm="deleteLibrary">
      <p>{{ t('sheetmusic', 'Remove "{path}" from your libraries?', { path: selectedLibraryPath }, { escape: false }) }}</p>
      <p>{{ t('sheetmusic', 'Only the library entry is removed. The MobileSheets database and all files it references stay in your Nextcloud files, so you can add the library again whenever you want.') }}</p>
    </ConfirmDeleteDialog>

    <LibrarySettingsDialog
      v-if="isSettingsDialogOpen"
      :selected-library="selectedLibrary"
      :libraries="libraries"
      @close="isSettingsDialogOpen = false"
      @delete="openDeleteDialog"
      @update:libraries="libraries = $event"
      @open-sublibraries="handleOpenSublibraries" />

    <ShareDialog
      v-if="isShareDialogOpen && selectedLibrary >= 0 && currentLibrary"
      :library-id="selectedLibrary"
      :library-name="selectedLibraryPath"
      :owner-id="currentLibrary.ownerId"
      @close="isShareDialogOpen = false" />

    <ShareTargetDialog
      :open="isShareTargetDialogOpen"
      :files="pendingShareFiles"
      :initial-title="pendingShareTitle"
      :libraries="libraries"
      :selected-library-id="selectedLibrary"
      @close="closeShareTargetDialog"
      @imported="handleShareTargetImported" />

    <AppUpdateBanner />
  </div>
</template>

<style module>
.contentError {
  margin: 16px;
  padding: 14px 16px;
  border: 1px solid var(--color-error);
  border-left-width: 4px;
  border-radius: var(--border-radius-large, 8px);
  background-color: var(--color-background-hover);
  color: var(--color-main-text);
}
.loadingLibraries { display: flex; align-items: center; justify-content: center; min-height: 240px; }
.songArea { display: grid; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); gap: 24px; align-items: start; }
.songList { margin: 0; padding: 0; list-style: none; }
.songList li { border-bottom: 1px solid var(--color-border); }
.songButton { width: 100%; padding: 10px 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.songButton[aria-pressed='true'] { background: var(--color-background-hover); }
@media (max-width: 800px) { .songArea { grid-template-columns: 1fr; } }
.dialogActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
.error { color: var(--color-error); }
.viewToggle { display: flex; gap: 8px; margin: 12px 0; }
.dialogOptionRow {
  margin-top: 16px;
  display: flex;
  align-items: center;
}
</style>
