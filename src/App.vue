<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, EntityType, LibraryData, Song } from './types/song'
import type { Library } from './types/library.ts'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { describeRequestFailure } from './utils/api'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import FolderPlusOutline from 'vue-material-design-icons/FolderPlusOutline.vue'
import DatabaseImport from 'vue-material-design-icons/DatabaseImport.vue'
import FolderMusicOutline from 'vue-material-design-icons/FolderMusicOutline.vue'
import Music from 'vue-material-design-icons/Music.vue'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import AccountMusic from 'vue-material-design-icons/AccountMusic.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import BookIcon from 'vue-material-design-icons/Book.vue'
import FolderMultiple from 'vue-material-design-icons/FolderMultiple.vue'
import DramaMasks from 'vue-material-design-icons/DramaMasks.vue'
import MusicClefTreble from 'vue-material-design-icons/MusicClefTreble.vue'
import TagIcon from 'vue-material-design-icons/Tag.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Metronome from 'vue-material-design-icons/Metronome.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import GuitarAcoustic from 'vue-material-design-icons/GuitarAcoustic.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import SongsView from './components/SongsView.vue'
import SetlistsView from './components/SetlistsView.vue'
import SublibrariesView from './components/SublibrariesView.vue'
import IntegrityView from './components/IntegrityView.vue'
import ToolsView from './components/ToolsView.vue'
import EntitiesView from './components/EntitiesView.vue'
import TrashView from './components/TrashView.vue'
import LibrarySettingsDialog from './components/LibrarySettingsDialog.vue'

/** Views of a library: the song view, one view per song filter, integrity audit, and tools. */
type ViewName = 'songs' | 'new-song' | 'setlists' | 'sublibraries' | 'integrity' | 'tools' | 'trash' | EntityType
const VIEW_NAMES: ViewName[] = ['songs', 'new-song', 'setlists', 'composers', 'artists', 'books', 'collections', 'genres', 'keys', 'customgroups', 'years', 'signatures', 'sourcetypes', 'instruments', 'arrangers', 'sublibraries', 'integrity', 'tools', 'trash']

interface ImportProgress {
  status: 'running' | 'completed' | 'error'
  stage: string
  current: number
  total: number
  percent: number
  message: string
}

/*** State ***/
const isAddDialogOpen = ref(false)
const isImportDialogOpen = ref(false)
const isSettingsDialogOpen = ref(false)
const importPdfMetadata = ref(true)
const selectedFolder = ref<INode>()
const selectedDbFile = ref<INode>()
const addError = ref('')
const importError = ref('')
const isAddingLibrary = ref(false)
const isImportingLibrary = ref(false)
const importProgress = ref<ImportProgress | null>(null)
let progressPollingTimer: ReturnType<typeof setInterval> | null = null

function startProgressPolling(progressId: string) {
  stopProgressPolling()
  importProgress.value = {
    status: 'running',
    stage: 'starting',
    current: 0,
    total: 0,
    percent: 0,
    message: t('sheetmusic', 'Starting import...'),
  }
  progressPollingTimer = setInterval(async () => {
    try {
      const url = generateOcsUrl('/apps/sheetmusic/api/libraries/progress/' + encodeURIComponent(progressId))
      const res = await axios.get(url)
      const data = res?.data?.ocs?.data
      if (data && typeof data === 'object') {
        importProgress.value = {
          status: data.status,
          stage: data.stage,
          current: Number(data.current ?? 0),
          total: Number(data.total ?? 0),
          percent: Number(data.percent ?? 0),
          message: String(data.message ?? ''),
        }
        if (data.status === 'completed' || data.status === 'error') {
          stopProgressPolling()
        }
      }
    } catch {
      // Ignore transient polling errors
    }
  }, 400)
}

function stopProgressPolling() {
  if (progressPollingTimer !== null) {
    clearInterval(progressPollingTimer)
    progressPollingTimer = null
  }
}
const isPublicShare = Boolean(loadState('sheetmusic', 'isPublicShare', false))
const publicSharingToken = loadState('sheetmusic', 'publicSharingToken', '')
const publicLibrary = loadState('sheetmusic', 'publicLibrary', null) as Library | null

const libraries = ref<Library[]>([])
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
    return `${library.path} (${t('sheetmusic', 'shared by {owner}', { owner })})${ro}`
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
    return
  }
  try {
    const response = await apiGet('/api/libraries')
    libraries.value = response.data.ocs.data.libraries
  } catch (error) {
    fileError.value = describeRequestFailure(error, t('sheetmusic', 'Could not load your MobileSheets libraries.'))
    showError(fileError.value)
  }
}

/*** Initial load of libraries list ***/
onMounted(async () => {
  await loadLibraries()
  const activeRouteLibrary = route.params.library as string | undefined

  if (isPublicShare && publicLibrary) {
    selectedLibrary.value = publicLibrary.id
    if (!activeRouteLibrary || activeRouteLibrary !== String(publicLibrary.id)) {
      navigateToLibrary(publicLibrary.id)
    }
  } else if (libraries.value.length > 0 && !activeRouteLibrary) {
    // Only set a default if the user landed on the root page matching no library
    navigateToLibrary(libraries.value[0].id)
  }
})

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
  const picker = getFilePickerBuilder(t('sheetmusic', 'Select a folder for your sheet music library'))
    .setMultiSelect(false)
    .allowDirectories(true)
    .setFilter(node => node.type === 'folder')
    .setCanPick(node => node.type === 'folder')
    .setButtonFactory(([node]) => [{
      label: node ? t('sheetmusic', 'Select folder {name}', { name: node.basename }) : t('sheetmusic', 'Select folder'),
      callback: () => {},
      disabled: !node || node.type !== 'folder',
      variant: 'primary',
    }])
    .build()
  try {
    ;[selectedFolder.value] = await picker.pickNodes()
  } catch (error) {
    if (!(error instanceof FilePickerClosed)) {
      addError.value = t('sheetmusic', 'Could not select a folder.')
      showError(addError.value)
    }
  }
}

async function openDbPicker() {
  importError.value = ''
  const picker = getFilePickerBuilder(t('sheetmusic', 'Select a MobileSheets database file'))
    .setMultiSelect(false)
    .setFilter(node => node.type === 'folder' || node.basename.toLowerCase().endsWith('.db'))
    .setCanPick(node => node.type === 'file' && node.basename.toLowerCase().endsWith('.db'))
    .setButtonFactory(([node]) => [{
      label: node ? t('sheetmusic', 'Select {name}', { name: node.basename }) : t('sheetmusic', 'Select .db file'),
      callback: () => {},
      disabled: !node || node.type !== 'file' || !node.basename.toLowerCase().endsWith('.db'),
      variant: 'primary',
    }])
    .build()
  try {
    ;[selectedDbFile.value] = await picker.pickNodes()
  } catch (error) {
    if (!(error instanceof FilePickerClosed)) {
      importError.value = t('sheetmusic', 'Could not select a database file.')
      showError(importError.value)
    }
  }
}

async function addLibrary() {
  if (!selectedFolder.value) {
    addError.value = t('sheetmusic', 'Please select a folder first.')
    showError(addError.value)
    return
  }
  const addedPath = selectedFolder.value.path
  isAddingLibrary.value = true
  addError.value = ''
  const progressId = 'import-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
  startProgressPolling(progressId)
  try {
    const response = await apiPost('/api/libraries' as any, {
      path: addedPath,
      import_mobilesheets: false,
      importPdfMetadata: importPdfMetadata.value,
      progressId,
    } as any)
    stopProgressPolling()
    selectedFolder.value = undefined
    isAddDialogOpen.value = false
    importProgress.value = null

    const prevIds = new Set(libraries.value.map(library => library.id))
    await loadLibraries()

    const targetPath = (response as any)?.data?.ocs?.data?.path ?? addedPath
    const normalizedTargetPath = targetPath !== '/' ? targetPath.replace(/\/+$/, '') : targetPath
    const normalizedAddedPath = addedPath !== '/' ? addedPath.replace(/\/+$/, '') : addedPath
    const newlyAdded = libraries.value.find(library => !prevIds.has(library.id))
    const addedLibrary = newlyAdded ?? libraries.value.find(library =>
      library.path === targetPath ||
      library.path === normalizedTargetPath ||
      library.path === addedPath ||
      library.path === normalizedAddedPath ||
      (library.path && normalizedTargetPath && library.path.toLowerCase() === normalizedTargetPath.toLowerCase())
    )
    if (addedLibrary) {
      navigateToLibrary(addedLibrary.id)
    }
    showSuccess(t('sheetmusic', 'Library added successfully.'))
  } catch (error) {
    stopProgressPolling()
    addError.value = describeRequestFailure(error, t('sheetmusic', 'Could not save the library.'))
    showError(addError.value)
  } finally {
    isAddingLibrary.value = false
    stopProgressPolling()
  }
}

async function importLibrary() {
  if (!selectedDbFile.value) {
    importError.value = t('sheetmusic', 'Please select a MobileSheets .db file first.')
    showError(importError.value)
    return
  }
  const addedPath = selectedDbFile.value.path
  isImportingLibrary.value = true
  importError.value = ''
  const progressId = 'import-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
  startProgressPolling(progressId)
  try {
    const response = await apiPost('/api/libraries' as any, {
      path: addedPath,
      import_mobilesheets: true,
      progressId,
    } as any)
    stopProgressPolling()
    selectedDbFile.value = undefined
    isImportDialogOpen.value = false
    importProgress.value = null

    const prevIds = new Set(libraries.value.map(library => library.id))
    await loadLibraries()

    const targetPath = (response as any)?.data?.ocs?.data?.path ?? (addedPath.includes('/') ? addedPath.substring(0, addedPath.lastIndexOf('/')) || '/' : addedPath)
    const normalizedTargetPath = targetPath !== '/' ? targetPath.replace(/\/+$/, '') : targetPath
    const normalizedAddedPath = addedPath !== '/' ? addedPath.replace(/\/+$/, '') : addedPath
    const newlyAdded = libraries.value.find(library => !prevIds.has(library.id))
    const addedLibrary = newlyAdded ?? libraries.value.find(library =>
      library.path === targetPath ||
      library.path === normalizedTargetPath ||
      library.path === addedPath ||
      library.path === normalizedAddedPath ||
      (library.path && normalizedTargetPath && library.path.toLowerCase() === normalizedTargetPath.toLowerCase())
    )
    if (addedLibrary) {
      navigateToLibrary(addedLibrary.id)
    }
    showSuccess(t('sheetmusic', 'MobileSheets library imported successfully.'))
  } catch (error) {
    stopProgressPolling()
    importError.value = describeRequestFailure(error, t('sheetmusic', 'Could not import the MobileSheets library.'))
    showError(importError.value)
  } finally {
    isImportingLibrary.value = false
    stopProgressPolling()
  }
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
  <div id="sheetmusic">
    <NcContent app-name="sheetmusic">
      <!-- Sidebar (navigation) -->
      <NcAppNavigation :aria-label="t('sheetmusic', 'Sheet Music libraries')">
        <NcAppNavigationItem :name="t('sheetmusic', 'Library')">
          <template #icon>
            <FolderMusicOutline :size="20" />
          </template>
          <span v-if="isPublicShare" :class="$style.librarySelect" style="display: flex; align-items: center; font-weight: 500; padding: 0 8px;">
            {{ publicLibrary?.path ?? selectedLibraryPath }}
          </span>
          <select v-else v-model="selectedLibrary" :class="$style.librarySelect" @change="navigateToLibrary(selectedLibrary)">
            <option disabled value="-1">{{ t('sheetmusic', 'Select library') }}</option>
            <option v-for="library in libraries" :key="library.id" :value="library.id">{{ formatLibraryOption(library) }}</option>
          </select>
        </NcAppNavigationItem>

        <!-- view toggle – only for the selected library; choosing one starts without filters -->
        <template v-if="selectedLibrary >= 0">
          <NcAppNavigationItem :name="t('sheetmusic', 'Songs')" :active="selectedView === 'songs' || selectedView === 'new-song'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'songs' } }">
            <template #icon>
              <Music :size="20" />
            </template>
          </NcAppNavigationItem>
          <li role="separator" :class="$style.navSeparator" aria-hidden="true" />
          <NcAppNavigationItem :name="t('sheetmusic', 'Setlists')" :active="selectedView === 'setlists'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'setlists' } }">
            <template #icon>
              <PlaylistMusic :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Composers')" :active="selectedView === 'composers'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'composers' } }">
            <template #icon>
              <AccountMusic :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Artists')" :active="selectedView === 'artists'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'artists' } }">
            <template #icon>
              <AccountStar :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Books')" :active="selectedView === 'books'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'books' } }">
            <template #icon>
              <BookIcon :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Collections')" :active="selectedView === 'collections'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'collections' } }">
            <template #icon>
              <FolderMultiple :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Genres')" :active="selectedView === 'genres'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'genres' } }">
            <template #icon>
              <DramaMasks :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Keys')" :active="selectedView === 'keys'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'keys' } }">
            <template #icon>
              <MusicClefTreble :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Custom Groups')" :active="selectedView === 'customgroups'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'customgroups' } }">
            <template #icon>
              <TagIcon :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Years')" :active="selectedView === 'years'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'years' } }">
            <template #icon>
              <Calendar :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Time Signatures')" :active="selectedView === 'signatures'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'signatures' } }">
            <template #icon>
              <Metronome :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Source Types')" :active="selectedView === 'sourcetypes'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'sourcetypes' } }">
            <template #icon>
              <FileDocumentOutline :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Instruments')" :active="selectedView === 'instruments'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'instruments' } }">
            <template #icon>
              <GuitarAcoustic :size="20" />
            </template>
          </NcAppNavigationItem>
          <NcAppNavigationItem :name="t('sheetmusic', 'Arrangers')" :active="selectedView === 'arrangers'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'arrangers' } }">
            <template #icon>
              <AccountMusic :size="20" />
            </template>
          </NcAppNavigationItem>
          <li role="separator" :class="$style.navSeparator" aria-hidden="true" />
          <NcAppNavigationItem :name="t('sheetmusic', 'Sub-Libraries')" :active="selectedView === 'sublibraries'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'sublibraries' } }">
            <template #icon>
              <FolderSyncOutline :size="20" />
            </template>
          </NcAppNavigationItem>
          <template v-if="!isPublicShare">
            <NcAppNavigationItem :name="t('sheetmusic', 'Integrity Audit')" :active="selectedView === 'integrity'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'integrity' } }">
              <template #icon>
                <ShieldCheck :size="20" />
              </template>
            </NcAppNavigationItem>
            <NcAppNavigationItem :name="t('sheetmusic', 'Tools')" :active="selectedView === 'tools'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'tools' } }">
              <template #icon>
                <Wrench :size="20" />
              </template>
            </NcAppNavigationItem>
            <li role="separator" :class="$style.navSeparator" aria-hidden="true" />
            <NcAppNavigationItem :name="t('sheetmusic', 'Trash')" :active="selectedView === 'trash'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'trash' } }">
              <template #icon>
                <TrashCanOutline :size="20" />
              </template>
            </NcAppNavigationItem>
          </template>

        </template>

        <template v-if="!isPublicShare" #footer>
          <div :class="$style.footerArea">
            <div :class="$style.footerRow">
              <NcActions
                :class="$style.footerActionMenu"
                :menu-name="t('sheetmusic', 'Add library')"
                placement="top-start">
                <template #icon>
                  <Plus :size="20" />
                </template>
                <NcActionButton @click="openAddDialog">
                  <template #icon>
                    <FolderPlusOutline :size="20" />
                  </template>
                  {{ t('sheetmusic', 'Add Sheet Music library') }}
                </NcActionButton>
                <NcActionButton @click="openImportDialog">
                  <template #icon>
                    <DatabaseImport :size="20" />
                  </template>
                  {{ t('sheetmusic', 'Import MobileSheets library') }}
                </NcActionButton>
              </NcActions>
              <NcButton v-if="selectedLibrary >= 0"
                        type="button"
                        variant="tertiary"
                        :title="t('sheetmusic', 'Library Settings')"
                        :aria-label="t('sheetmusic', 'Library Settings')"
                        :class="$style.settingsButton"
                        @click="isSettingsDialogOpen = true">
                <template #icon>
                  <CogIcon :size="20" />
                </template>
              </NcButton>
            </div>
          </div>
        </template>
      </NcAppNavigation>

      <!-- Main content -->
      <NcAppContent>
        <template v-if="selectedLibrary >= 0">
          <SongsView v-if="selectedView === 'songs' || selectedView === 'new-song'"
                     :selectedLibrary="selectedLibrary"
                     :libraries="libraries"
                     :standalone-create="selectedView === 'new-song'" />
          <SetlistsView v-else-if="selectedView === 'setlists'"
                        :selected-library="selectedLibrary"
                        :can-write="!isPublicShare && (currentLibrary?.canWrite ?? true)" />
          <SublibrariesView v-else-if="selectedView === 'sublibraries'"
                            :selected-library="selectedLibrary"
                            :libraries="libraries" />
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

    <NcDialog v-if="isAddDialogOpen"
              :name="t('sheetmusic', 'Add Sheet Music Library')"
              size="normal"
              @close="!isAddingLibrary && (isAddDialogOpen = false)">
      <p>{{ t('sheetmusic', 'Select a folder from your Nextcloud files. Sheet music files will be imported.') }}</p>
      <NcButton variant="primary" :disabled="isAddingLibrary" @click="openFolderPicker">
        {{ selectedFolder?.path ?? t('sheetmusic', 'Choose folder from Nextcloud') }}
      </NcButton>
      <div :class="$style.dialogOptionRow">
        <NcCheckboxRadioSwitch
          v-model="importPdfMetadata"
          type="switch"
          :disabled="isAddingLibrary">
          {{ t('sheetmusic', 'Import metadata from PDF files') }}
        </NcCheckboxRadioSwitch>
      </div>
      <div v-if="isAddingLibrary && importProgress" :class="$style.progressContainer">
        <NcProgressBar :value="importProgress.percent"
                       size="medium"
                       :show-value="true"
                       :error="importProgress.status === 'error'" />
        <p :class="$style.progressMessage">{{ importProgress.message }}</p>
      </div>
      <p v-if="addError" :class="$style.error" role="alert">{{ addError }}</p>
      <template #actions>
        <NcButton :disabled="isAddingLibrary" @click="isAddDialogOpen = false">{{ t('sheetmusic', 'Cancel') }}</NcButton>
        <NcButton variant="primary" :disabled="!selectedFolder || isAddingLibrary" @click="addLibrary">
          {{ t('sheetmusic', 'Add library') }}
        </NcButton>
      </template>
    </NcDialog>

    <NcDialog v-if="isImportDialogOpen"
              :name="t('sheetmusic', 'Import MobileSheets Library')"
              size="normal"
              @close="!isImportingLibrary && (isImportDialogOpen = false)">
      <p>{{ t('sheetmusic', 'Select a mobilesheets.db file from your Nextcloud files to import your existing songs and metadata.') }}</p>
      <NcButton variant="primary" :disabled="isImportingLibrary" @click="openDbPicker">
        {{ selectedDbFile?.path ?? t('sheetmusic', 'Choose mobilesheets.db from Nextcloud') }}
      </NcButton>
      <div v-if="isImportingLibrary && importProgress" :class="$style.progressContainer">
        <NcProgressBar :value="importProgress.percent"
                       size="medium"
                       :show-value="true"
                       :error="importProgress.status === 'error'" />
        <p :class="$style.progressMessage">{{ importProgress.message }}</p>
      </div>
      <p v-if="importError" :class="$style.error" role="alert">{{ importError }}</p>
      <template #actions>
        <NcButton :disabled="isImportingLibrary" @click="isImportDialogOpen = false">{{ t('sheetmusic', 'Cancel') }}</NcButton>
        <NcButton variant="primary" :disabled="!selectedDbFile || isImportingLibrary" @click="importLibrary">
          {{ t('sheetmusic', 'Import library') }}
        </NcButton>
      </template>
    </NcDialog>

    <NcDialog v-if="isDeleteDialogOpen"
              :name="t('sheetmusic', 'Delete library')"
              size="normal"
              @close="closeDeleteDialog">
      <p>{{ t('sheetmusic', 'Remove "{path}" from your libraries?', { path: selectedLibraryPath }) }}</p>
      <p>{{ t('sheetmusic', 'Only the library entry is removed. The MobileSheets database and all files it references stay in your Nextcloud files, so you can add the library again whenever you want.') }}</p>
      <p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
      <template #actions>
        <NcButton @click="closeDeleteDialog">{{ t('sheetmusic', 'Cancel') }}</NcButton>
        <NcButton variant="error" :disabled="isDeletingLibrary" @click="deleteLibrary">
          {{ t('sheetmusic', 'Delete library') }}
        </NcButton>
      </template>
    </NcDialog>

    <LibrarySettingsDialog
      v-if="isSettingsDialogOpen && selectedLibrary >= 0"
      :selected-library="selectedLibrary"
      :libraries="libraries"
      @close="isSettingsDialogOpen = false"
      @delete="openDeleteDialog"
      @update:libraries="libraries = $event"
      @open-sublibraries="handleOpenSublibraries" />
  </div>
</template>

<style module>
.footerArea {
  display: flex;
  flex-direction: column;
  width: 100%;
}
.footerRow {
  display: flex;
  align-items: center;
  width: 100%;
}
.footerActionMenu {
  flex: 1;
  min-width: 0;
}
.footerActionMenu :global(.action-item__menutoggle) {
  width: 100%;
  justify-content: flex-start;
  padding: 10px 16px;
  box-sizing: border-box;
}
.settingsButton {
  margin-inline-end: 8px;
  flex-shrink: 0;
}
.libraryList { margin: 0; padding: 0; }
.libraryItem { word-break: break-word; }
.libraryButton { width: 100%; padding: 8px 16px; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.libraryButton[aria-pressed='true'] { background: var(--color-background-hover); }
.librarySelect { width: 100%; padding: 8px; margin: 8px 0; }
.navSeparator {
  list-style: none;
  border-top: 1px solid var(--color-border);
  margin: 4px 8px;
}
.contentError { margin: 16px; color: var(--color-error); }
.songArea { display: grid; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); gap: 24px; align-items: start; }
.songList { margin: 0; padding: 0; list-style: none; }
.songList li { border-bottom: 1px solid var(--color-border); }
.songButton { width: 100%; padding: 10px 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.songButton[aria-pressed='true'] { background: var(--color-background-hover); }
@media (max-width: 800px) { .songArea { grid-template-columns: 1fr; } }
.dialogActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
.error { color: var(--color-error); }
.viewToggle { display: flex; gap: 8px; margin: 12px 0; }
.progressContainer {
  margin-top: 16px;
  width: 100%;
}
.dialogOptionRow {
  margin-top: 16px;
  display: flex;
  align-items: center;
}
.progressMessage {
  margin-top: 8px;
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
}
</style>
