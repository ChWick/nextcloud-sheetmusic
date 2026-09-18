<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Book, Collection, Composer, LibraryData, Song } from './types/song'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, onMounted, ref } from 'vue'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SongFilterBar from './components/SongFilterBar.vue'
import SongViewer from './components/SongViewer.vue'

const isDialogOpen = ref(false)
const selectedFile = ref<INode>()
const libraries = ref<string[]>([])
const selectedLibrary = ref<string>()

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

const filteredSongs = computed(() => {
	const selectedComposerIds = new Set(selectedComposers.value.map((c) => c.id))
	const selectedBookIds = new Set(selectedBooks.value.map((b) => b.id))
	const selectedCollectionIds = new Set(selectedCollections.value.map((c) => c.id))

	return songs.value.filter((song) => {
		if (selectedComposerIds.size > 0) {
			const matchComposer = song.composerIds?.some((id) => selectedComposerIds.has(id))
			if (!matchComposer) {
				return false
			}
		}
		if (selectedBookIds.size > 0) {
			const matchBook = song.bookIds?.some((id) => selectedBookIds.has(id))
			if (!matchBook) {
				return false
			}
		}
		if (selectedCollectionIds.size > 0) {
			const matchCollection = song.collectionIds?.some((id) => selectedCollectionIds.has(id))
			if (!matchCollection) {
				return false
			}
		}
		return true
	})
})

function handleLibraryDataUpdate(data: LibraryData) {
	songs.value = data.songs ?? []
	composers.value = data.composers ?? []
	books.value = data.books ?? []
	collections.value = data.collections ?? []

	if (selectedSong.value) {
		const currentId = selectedSong.value.id
		const updatedSong = songs.value.find((s) => s.id === currentId)
		if (updatedSong) {
			selectedSong.value = updatedSong
		}
	}
}

async function selectLibrary(library: string) {
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

function selectSong(song: Song) {
	selectedSong.value = song
}

onMounted(async () => {
	try {
		const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/libraries'))
		libraries.value = response.data.ocs.data.libraries
	} catch {
		fileError.value = t('mobilesheetsmanager', 'Could not load your MobileSheets libraries.')
	}
})

function openDialog() {
	selectedFile.value = undefined
	fileError.value = ''
	isDialogOpen.value = true
}

async function openFilePicker() {
	fileError.value = ''

	const picker = getFilePickerBuilder(t('mobilesheetsmanager', 'Select a MobileSheets file'))
		.setMultiSelect(false)
		.setFilter((node) => node.type !== 'file' || node.basename.toLowerCase().endsWith('.db'))
		.setCanPick((node) => node.type === 'file' && node.basename.toLowerCase().endsWith('.db'))
		.setButtonFactory(([node]) => [{
			label: node
				? t('mobilesheetsmanager', 'Select {file}', { file: node.basename })
				: t('mobilesheetsmanager', 'Select a .db file'),
			callback: () => {},
			disabled: !node || node.type !== 'file' || !node.basename.toLowerCase().endsWith('.db'),
			variant: 'primary',
		}])
		.build()

	try {
		[selectedFile.value] = await picker.pickNodes()
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
		<NcAppNavigation :aria-label="t('mobilesheetsmanager', 'MobileSheets libraries')">
			<ul :class="$style.libraryList">
				<li v-for="library in libraries" :key="library" :class="$style.libraryItem">
					<button type="button" :class="$style.libraryButton" :aria-pressed="selectedLibrary === library" @click="selectLibrary(library)">
						{{ library }}
					</button>
				</li>
			</ul>

			<template #footer>
				<NcAppNavigationItem
					:name="t('mobilesheetsmanager', 'Add MobileSheets Library')"
					@click="openDialog">
					<template #icon>
						<span aria-hidden="true">+</span>
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent :class="$style.content">
			<h2>{{ selectedLibrary ?? t('mobilesheetsmanager', 'MobileSheets Manager') }}</h2>
			<template v-if="selectedLibrary">
				<SongFilterBar
					v-model:selected-composers="selectedComposers"
					v-model:selected-books="selectedBooks"
					v-model:selected-collections="selectedCollections"
					:composers="composers"
					:books="books"
					:collections="collections" />

				<div :class="$style.songArea">
					<ul :class="$style.songList">
						<li v-for="song in filteredSongs" :key="song.id">
							<button type="button" :class="$style.songButton" :aria-pressed="selectedSong?.id === song.id" @click="selectSong(song)">{{ song.title }}</button>
						</li>
						<li v-if="!isLoadingSongs && !fileError && songs.length === 0">{{ t('mobilesheetsmanager', 'No songs found in this library.') }}</li>
						<li v-else-if="!isLoadingSongs && !fileError && songs.length > 0 && filteredSongs.length === 0">{{ t('mobilesheetsmanager', 'No songs match the selected filters.') }}</li>
					</ul>
					<SongViewer
						:song="selectedSong"
						:library-path="selectedLibrary"
						:composers="composers"
						:books="books"
						:collections="collections"
						@saved="handleLibraryDataUpdate" />
				</div>
			</template>
			<p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
		</NcAppContent>

		<NcDialog
			v-if="isDialogOpen"
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
.libraryList {
	margin: 0;
	padding: 0;
}

.libraryItem {
	word-break: break-word;
}

.libraryButton {
	width: 100%;
	padding: 8px 16px;
	border: 0;
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.libraryButton[aria-pressed='true'] {
	background: var(--color-background-hover);
}

.content {
	margin: 16px;
}

.songArea {
	display: grid;
	grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
	gap: 24px;
	align-items: start;
}

.songList {
	margin: 0;
	padding: 0;
	list-style: none;
}

.songList li {
	border-bottom: 1px solid var(--color-border);
}

.songButton {
	width: 100%;
	padding: 10px 0;
	border: 0;
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.songButton[aria-pressed='true'] {
	background: var(--color-background-hover);
}

@media (max-width: 800px) {
	.songArea {
		grid-template-columns: 1fr;
	}
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
}

.error {
	color: var(--color-error);
}
</style>
