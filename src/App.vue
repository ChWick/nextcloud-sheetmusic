<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, LibraryData, Song } from './types/song'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SongFilterBar from './components/SongFilterBar.vue'
import SongViewer from './components/SongViewer.vue'

/*** State ***/
const isDialogOpen = ref(false)
const selectedFile = ref<INode>()
const libraries = ref<string[]>([])
const selectedLibrary = ref<string>('')

const songs = ref<Song[]>([])
const composers = ref<Composer[]>([])
const books = ref<Book[]>([])
const collections = ref<Collection[]>([])

const selectedComposers = ref<Composer[]>([])
const selectedBooks = ref<Book[]>([])
const selectedCollections = ref<Collection[]>([])

const selectedSong = ref<Song>()
const fileError = ref('')
const isLoadingSongs = ref(false)
const selectedView = ref<'songs' | 'files'>('songs')

/*** Router ***/
const router = useRouter()
const route = useRoute()

/*** Computed ***/
const filteredSongs = computed(() => {
  const selectedComposerIds = new Set(selectedComposers.value.map(c => c.id))
  const selectedBookIds = new Set(selectedBooks.value.map(b => b.id))
  const selectedCollectionIds = new Set(selectedCollections.value.map(c => c.id))

  return songs.value.filter(song => {
    if (selectedComposerIds.size > 0) {
      const match = song.composerIds?.some(id => selectedComposerIds.has(id))
      if (!match) return false
    }
    if (selectedBookIds.size > 0) {
      const match = song.bookIds?.some(id => selectedBookIds.has(id))
      if (!match) return false
    }
    if (selectedCollectionIds.size > 0) {
      const match = song.collectionIds?.some(id => selectedCollectionIds.has(id))
      if (!match) return false
    }
    return true
  })
})

/*** Helper Functions ***/
function handleLibraryDataUpdate(data: LibraryData) {
  songs.value = data.songs ?? []
  composers.value = data.composers ?? []
  books.value = data.books ?? []
  collections.value = data.collections ?? []

  if (selectedSong.value) {
    const updated = songs.value.find(s => s.id === selectedSong.value?.id)
    if (updated) selectedSong.value = updated
  }
}

/** Load library data (no navigation) */
async function loadLibraryData(library: string) {
  selectedLibrary.value = library
  songs.value = []
  composers.value = []
  books.value = []
  collections.value = []
  selectedComposers.value = []
  selectedBooks.value = []
  selectedCollections.value = []
  selectedSong.value = undefined
  fileError.value = ''
  isLoadingSongs.value = true

  try {
    const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/songs'), {
      params: { path: library },
    })
    handleLibraryDataUpdate(response.data.ocs.data as LibraryData)
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not load the songs in this library.')
  } finally {
    isLoadingSongs.value = false
  }
}

/** Navigation helpers */
function navigateToLibrary(library: string) {
  router.push({ name: 'LibraryView', params: { library, view: 'songs' } })
}

function goToView(view: 'songs' | 'files') {
  if (!selectedLibrary.value) return
  router.push({ name: 'LibraryView', params: { library: selectedLibrary.value, view } })
}

function selectSong(song: Song) {
  selectedSong.value = song
}

/*** Route watcher – sync state with URL ***/
watch(
  () => route.params,
  params => {
    const lib = params.library as string | undefined
    const view = (params.view as string) ?? 'songs'
    if (lib) {
      if (lib !== selectedLibrary.value) loadLibraryData(lib)
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
        <SongFilterBar v-model:selected-composers="selectedComposers"
                       v-model:selected-books="selectedBooks"
                       v-model:selected-collections="selectedCollections"
                       :composers="composers"
                       :books="books"
                       :collections="collections" />

        <template v-if="selectedView === 'songs'">
          <div :class="$style.songArea">
            <ul :class="$style.songList">
              <li v-for="song in filteredSongs" :key="song.id">
                <button type="button"
                        :class="$style.songButton"
                        :aria-pressed="selectedSong?.id === song.id"
                        @click="selectSong(song)">
                  {{ song.title }}
                </button>
              </li>
              <li v-if="!isLoadingSongs && !fileError && songs.length === 0">
                {{ t('mobilesheetsmanager', 'No songs found in this library.') }}
              </li>
              <li v-else-if="!isLoadingSongs && !fileError && songs.length > 0 && filteredSongs.length === 0">
                {{ t('mobilesheetsmanager', 'No songs match the selected filters.') }}
              </li>
            </ul>
            <SongViewer :song="selectedSong"
                        :library-path="selectedLibrary"
                        :composers="composers"
                        :books="books"
                        :collections="collections"
                        @saved="handleLibraryDataUpdate" />
          </div>
        </template>
        <template v-else>
          <div :class="$style.filesArea">
            {{ t('mobilesheetsmanager', 'Files view placeholder') }}
          </div>
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
