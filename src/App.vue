<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, LibraryData, Song } from './types/song'

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
const libraries = ref<string[]>([])
const selectedLibrary = ref<string>('')
const selectedView = ref<'songs' | 'files'>('songs')
const fileError = ref('') // used for dialogs and library load errors

/*** Router ***/
const router = useRouter()
const route = useRoute()

/** Navigation helpers **/
function navigateToLibrary(library: string) {
  router.push({ name: 'LibraryView', params: { library, view: 'songs' } })
}
function goToView(view: 'songs' | 'files') {
  if (!selectedLibrary.value) return
  router.push({ name: 'LibraryView', params: { library: selectedLibrary.value, view } })
}

/*** Route watcher – sync state with URL ***/
watch(
  () => route.params,
  params => {
    const lib = params.library as string | undefined
    const view = (params.view as string) ?? 'songs'
    if (lib) {
      selectedLibrary.value = lib
      selectedView.value = view as any
    } else {
      selectedLibrary.value = ''
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
    // Auto‑select first library; SongsView will load its songs
    if (libraries.value.length > 0 && !selectedLibrary.value) {
      selectedLibrary.value = libraries.value[0]
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
          <option disabled value="">{{ t('mobilesheetsmanager', 'Select library') }}</option>
          <option v-for="library in libraries" :key="library" :value="library">{{ library }}</option>
        </select>
      </NcAppNavigationItem>

      <!-- view toggle – only for the selected library -->
      <template v-if="selectedLibrary">
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Songs')" :active="selectedView === 'songs'" @click="goToView('songs')" />
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Files')" :active="selectedView === 'files'" @click="goToView('files')" />
      </template>

      <template #footer>
        <NcAppNavigationItem :name="t('mobilesheetsmanager', 'Add MobileSheets Library')" @click="openDialog">
          <template #icon><span aria-hidden="true">+</span></template>
        </NcAppNavigationItem>
      </template>
    </NcAppNavigation>

    <!-- Main content -->
    <NcAppContent :class="$style.content">
      <h2>{{ selectedLibrary ?? t('mobilesheetsmanager', 'MobileSheets Manager') }}</h2>
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
