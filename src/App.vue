<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, EntityType, LibraryData, Song } from './types/song'
import type { Library } from './types/library.ts'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SongsView from './components/SongsView.vue'
import IntegrityView from './components/IntegrityView.vue'
import EntitiesView from './components/EntitiesView.vue'

/** Views of a library: the song view, one view per song filter, and integrity audit. */
type ViewName = 'songs' | 'new-song' | 'integrity' | EntityType
const VIEW_NAMES: ViewName[] = ['songs', 'new-song', 'composers', 'artists', 'books', 'collections', 'genres', 'keys', 'customgroups', 'years', 'signatures', 'sourcetypes', 'integrity']

/*** State ***/
const isDialogOpen = ref(false)
const selectedFile = ref<INode>()
const libraries = ref<Library[]>([])
const selectedLibrary = ref<number>(-1)
const selectedView = ref<ViewName>('songs')
const fileError = ref('') // used for dialogs and library load errors
const isDeleteDialogOpen = ref(false)
const isDeletingLibrary = ref(false)
const deleteError = ref('')

/** Path of the currently selected library, used to name it in the delete dialog */
const selectedLibraryPath = computed(
  () => libraries.value.find(library => library.id === selectedLibrary.value)?.path ?? '',
)

/*** Router ***/
const router = useRouter()
const route = useRoute()

/** Navigation helpers **/
function navigateToLibrary(library: string | number) {
  const libraryId = typeof library === 'string' ? parseInt(library, 10) : library
  router.push({ name: 'LibraryView', params: { library: libraryId, view: 'songs' } })
}
function goToView(view: ViewName) {
  if (selectedLibrary.value < 0) return
  router.push({ name: 'LibraryView', params: { library: selectedLibrary.value, view } })
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
  try {
    const response = await apiGet('/api/libraries')
    libraries.value = response.data.ocs.data.libraries
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not load your MobileSheets libraries.')
  }
}

/*** Initial load of libraries list ***/
onMounted(async () => {
  await loadLibraries()
  const activeRouteLibrary = route.params.library as string | undefined

  if (libraries.value.length > 0 && !activeRouteLibrary) {
    // Only set a default if the user landed on the root page matching no library
    navigateToLibrary(libraries.value[0].id)
  }
})

/*** Dialog helpers ***/
function openDialog(event?: Event) {
  event?.preventDefault()
  selectedFile.value = undefined
  fileError.value = ''
  isDialogOpen.value = true
}
async function openFilePicker() {
  fileError.value = ''
  const picker = getFilePickerBuilder(t('mobilesheetsmanager', 'Select a MobileSheets file'))
    .setMultiSelect(false)
    .setFilter(node => node.type !== 'file' || node.basename.toLowerCase().endsWith('.db'))
    .setCanPick(node => node.type === 'file' && node.basename.toLowerCase().endsWith('.db'))
    .setButtonFactory(([node]) => [{
      label: node ? t('mobilesheetsmanager', 'Select {file}', { file: node.basename }) : t('mobilesheetsmanager', 'Select a .db file'),
      callback: () => {},
      disabled: !node || node.type !== 'file' || !node.basename.toLowerCase().endsWith('.db'),
      variant: 'primary',
    }])
    .build()
  try {
    ;[selectedFile.value] = await picker.pickNodes()
  } catch (error) {
    if (!(error instanceof FilePickerClosed)) {
      fileError.value = t('mobilesheetsmanager', 'Could not select a MobileSheets file.')
    }
  }
}
async function addLibrary() {
  if (!selectedFile.value) {
    fileError.value = t('mobilesheetsmanager', 'Please select a .db file first.')
    return
  }
  const addedPath = selectedFile.value.path
  try {
    await apiPost('/api/libraries', {
      path: addedPath,
    })
    selectedFile.value = undefined
    isDialogOpen.value = false

    // Reload instead of adding the path locally: only the endpoint knows the id of
    // the new library entry, and the list may have changed in another tab.
    await loadLibraries()

    const addedLibrary = libraries.value.find(library => library.path === addedPath)
    if (addedLibrary && selectedLibrary.value < 0) {
      navigateToLibrary(addedLibrary.id)
    }
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not save the MobileSheets library.')
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

    // Switch to another library, or back to the empty state when this was the last one
    const nextLibrary = libraries.value[0]
    if (nextLibrary) {
      navigateToLibrary(nextLibrary.id)
    } else {
      selectedLibrary.value = -1
      router.push({ name: 'Home' })
    }
  } catch {
    deleteError.value = t('mobilesheetsmanager', 'Could not delete the library.')
  } finally {
    isDeletingLibrary.value = false
  }
}
</script>

<template>
  <div id="mobilesheetsmanager">
    <NcContent app-name="mobilesheetsmanager">
      <!-- Sidebar (navigation) -->
      <NcAppNavigation :aria-label="t('mobilesheetsmanager', 'MobileSheets libraries')">
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Library')">
          <select v-model="selectedLibrary" :class="$style.librarySelect" @change="navigateToLibrary(selectedLibrary)">
            <option disabled value="-1">{{ t('mobilesheetsmanager', 'Select library') }}</option>
            <option v-for="library in libraries" :key="library.id" :value="library.id">{{ library.path }}</option>
          </select>
        </NcAppNavigationItem>

        <!-- view toggle – only for the selected library; choosing one starts without filters -->
        <template v-if="selectedLibrary >= 0">
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Songs')" :active="selectedView === 'songs' || selectedView === 'new-song'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'songs' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Composers')" :active="selectedView === 'composers'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'composers' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Artists')" :active="selectedView === 'artists'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'artists' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Books')" :active="selectedView === 'books'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'books' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Collections')" :active="selectedView === 'collections'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'collections' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Genres')" :active="selectedView === 'genres'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'genres' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Keys')" :active="selectedView === 'keys'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'keys' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Custom Groups')" :active="selectedView === 'customgroups'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'customgroups' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Years')" :active="selectedView === 'years'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'years' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Time Signatures')" :active="selectedView === 'signatures'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'signatures' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Source Types')" :active="selectedView === 'sourcetypes'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'sourcetypes' } }" />
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Integrity Audit')" :active="selectedView === 'integrity'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'integrity' } }" />

          <div :class="$style.deleteLibraryArea">
            <NcButton type="button"
                      variant="error"
                      :class="$style.deleteLibraryButton"
                      @click="openDeleteDialog">
              {{ t('mobilesheetsmanager', 'Delete library') }}
            </NcButton>
          </div>
        </template>

        <template #footer>
          <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Add MobileSheets Library')" @click="openDialog">
            <template #icon><span aria-hidden="true">+</span></template>
          </NcAppNavigationItem>
        </template>
      </NcAppNavigation>

      <!-- Main content -->
      <NcAppContent>
        <template v-if="selectedLibrary >= 0">
          <SongsView v-if="selectedView === 'songs' || selectedView === 'new-song'"
                     :selectedLibrary="selectedLibrary"
                     :standalone-create="selectedView === 'new-song'" />
          <IntegrityView v-else-if="selectedView === 'integrity'" :selectedLibrary="selectedLibrary" />
          <EntitiesView v-else
                        :key="selectedView"
                        :selectedLibrary="selectedLibrary"
                        :entity-type="selectedView" />
        </template>

        <p v-if="fileError" :class="$style.contentError" role="alert">{{ fileError }}</p>
      </NcAppContent>
    </NcContent>

    <NcDialog v-if="isDialogOpen"
              :name="t('mobilesheetsmanager', 'Add MobileSheets Library')"
              size="normal"
              @close="isDialogOpen = false">
      <p>{{ t('mobilesheetsmanager', 'Select a MobileSheets .db file from your Nextcloud files.') }}</p>
      <NcButton variant="primary" @click="openFilePicker">
        {{ selectedFile?.path ?? t('mobilesheetsmanager', 'Choose .db file from Nextcloud') }}
      </NcButton>
      <p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
      <template #actions>
        <NcButton @click="isDialogOpen = false">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="primary" :disabled="!selectedFile" @click="addLibrary">
          {{ t('mobilesheetsmanager', 'Add library') }}
        </NcButton>
      </template>
    </NcDialog>

    <NcDialog v-if="isDeleteDialogOpen"
              :name="t('mobilesheetsmanager', 'Delete library')"
              size="normal"
              @close="closeDeleteDialog">
      <p>{{ t('mobilesheetsmanager', 'Remove "{path}" from your libraries?', { path: selectedLibraryPath }) }}</p>
      <p>{{ t('mobilesheetsmanager', 'Only the library entry is removed. The MobileSheets database and all files it references stay in your Nextcloud files, so you can add the library again whenever you want.') }}</p>
      <p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
      <template #actions>
        <NcButton @click="closeDeleteDialog">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="error" :disabled="isDeletingLibrary" @click="deleteLibrary">
          {{ t('mobilesheetsmanager', 'Delete library') }}
        </NcButton>
      </template>
    </NcDialog>
  </div>
</template>

<style module>
.libraryList { margin: 0; padding: 0; }
.libraryItem { word-break: break-word; }
.libraryButton { width: 100%; padding: 8px 16px; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.libraryButton[aria-pressed='true'] { background: var(--color-background-hover); }
.librarySelect { width: 100%; padding: 8px; margin: 8px 0; }
.contentError { margin: 16px; color: var(--color-error); }
.songArea { display: grid; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); gap: 24px; align-items: start; }
.songList { margin: 0; padding: 0; list-style: none; }
.songList li { border-bottom: 1px solid var(--color-border); }
.songButton { width: 100%; padding: 10px 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.songButton[aria-pressed='true'] { background: var(--color-background-hover); }
@media (max-width: 800px) { .songArea { grid-template-columns: 1fr; } }
.dialogActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
.deleteLibraryArea { padding: 8px 12px; }
.deleteLibraryButton { width: 100%; justify-content: center; background-color: var(--color-error, #e9322d) !important; color: var(--color-primary-text, #ffffff) !important; border-color: transparent !important; }
.deleteLibraryButton:hover { opacity: 0.9; }
.error { color: var(--color-error); }
.viewToggle { display: flex; gap: 8px; margin: 12px 0; }
</style>
