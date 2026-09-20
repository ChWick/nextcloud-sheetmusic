<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, LibraryData, Song } from './types/song'
import type { Library } from './types/library.ts'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SongsView from './components/SongsView.vue'
import FilesView from './components/FilesView.vue'

/*** State ***/
const isDialogOpen = ref(false)
const selectedFile = ref<INode>()
const libraries = ref<Library[]>([])
const selectedLibrary = ref<number>(-1)
const selectedView = ref<'songs' | 'files'>('songs')
const fileError = ref('') // used for dialogs and library load errors

/*** Router ***/
const router = useRouter()
const route = useRoute()

/** Navigation helpers **/
function navigateToLibrary(library: string | number) {
  const libraryId = typeof library === 'string' ? parseInt(library, 10) : library
  router.push({ name: 'LibraryView', params: { library: libraryId, view: 'songs' } })
}
function goToView(view: 'songs' | 'files') {
  if (selectedLibrary.value < 0) return
  console.log('Navigating to view', view, 'for library', selectedLibrary.value, view)
  router.push({ name: 'LibraryView', params: { library: selectedLibrary.value, view } })
}

/*** Route watcher – sync state with URL ***/
watch(
  () => route.params,
  params => {
    console.log('params.library', params.library)
    const lib = Array.isArray(params.library) ? parseInt(params.library[0]) : parseInt(params.library)
    const view = (params.view as string) ?? 'songs'
    console.log('Route changed, library:', lib, 'view:', view)
    if (lib) {
      selectedLibrary.value = lib
      selectedView.value = view as any
    } else {
      selectedLibrary.value = -1
      selectedView.value = 'songs'
    }
  },
  { immediate: true }
)

/*** Initial load of libraries list ***/
onMounted(async () => {
  try {
    const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/libraries'))
    libraries.value = response.data.ocs.data.libraries

    console.log("all libraries loaded:", libraries.value)
    
    // FIX: Look at the live route params directly before forcing a default choice!
    const activeRouteLibrary = route.params.library as string | undefined

    if (libraries.value.length > 0 && !activeRouteLibrary) {
      // Only set a default if the user landed on the root page matching no library
      console.log('No library selected in route, defaulting to first library', libraries.value[0])
      navigateToLibrary(libraries.value[0].id)
    }
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not load your MobileSheets libraries.')
  }
})

/*** Dialog helpers ***/
function openDialog() {
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
  try {
    const response = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/libraries'), {
      path: selectedFile.value.path,
    })
    if (!libraries.value.includes(response.data.ocs.data.path)) {
      libraries.value.push(response.data.ocs.data.path)
    }
    selectedFile.value = undefined
    isDialogOpen.value = false
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not save the MobileSheets library.')
  }
}
</script>

<template>
  <NcContent app-name="mobilesheetsmanager">
    <!-- Sidebar (navigation) -->
    <NcAppNavigation :aria-label="t('mobilesheetsmanager', 'MobileSheets libraries')">
      <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Library')">
        <select v-model="selectedLibrary" @change="navigateToLibrary(selectedLibrary)" class="$style.librarySelect">
          <option disabled value="-1">{{ t('mobilesheetsmanager', 'Select library') }}</option>
          <option v-for="library in libraries" :key="library.id" :value="library.id">{{ library.path }}</option>
        </select>
      </NcAppNavigationItem>

      <!-- view toggle – only for the selected library -->
      <template v-if="selectedLibrary >= 0">
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Songs')" :active="selectedView === 'songs'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'songs' } }" " />
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Files')" :active="selectedView === 'files'" :to="{ name: 'LibraryView', params: { library: selectedLibrary, view: 'files' } }" " />
      </template>

      <template #footer>
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Add MobileSheets Library')" @click="openDialog">
          <template #icon><span aria-hidden="true">+</span></template>
        </NcAppNavigationItem>
      </template>
    </NcAppNavigation>

    <!-- Main content -->
    <NcAppContent :class="$style.content">
      <template v-if="selectedLibrary">
        <template v-if="selectedView === 'songs'">
  <SongsView :selectedLibrary="selectedLibrary" />
</template>
<template v-else>
  <FilesView :selectedLibrary="selectedLibrary" />
</template>
      </template>

      <p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
    </NcAppContent>

    <NcDialog v-if="isDialogOpen"
              :name="t('mobilesheetsmanager', 'Add MobileSheets Library')"
              @close="isDialogOpen = false">
      <p>{{ t('mobilesheetsmanager', 'Select a MobileSheets .db file from your Nextcloud files.') }}</p>
      <NcButton variant="primary" @click="openFilePicker">
        {{ selectedFile?.path ?? t('mobilesheetsmanager', 'Choose .db file from Nextcloud') }}
      </NcButton>
      <p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
      <div :class="$style.dialogActions">
        <NcButton @click="isDialogOpen = false">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="primary" :disabled="!selectedFile" @click="addLibrary">
          {{ t('mobilesheetsmanager', 'Add library') }}
        </NcButton>
      </div>
    </NcDialog>
  </NcContent>
</template>

<style module>
.libraryList { margin: 0; padding: 0; }
.libraryItem { word-break: break-word; }
.libraryButton { width: 100%; padding: 8px 16px; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.libraryButton[aria-pressed='true'] { background: var(--color-background-hover); }
.librarySelect { width: 100%; padding: 8px; margin: 8px 0; }
.content { margin: 16px; }
.songArea { display: grid; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); gap: 24px; align-items: start; }
.songList { margin: 0; padding: 0; list-style: none; }
.songList li { border-bottom: 1px solid var(--color-border); }
.songButton { width: 100%; padding: 10px 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.songButton[aria-pressed='true'] { background: var(--color-background-hover); }
@media (max-width: 800px) { .songArea { grid-template-columns: 1fr; } }
.dialogActions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
.error { color: var(--color-error); }
.viewToggle { display: flex; gap: 8px; margin: 12px 0; }
</style>
