<script setup lang="ts">
import type { INode } from '@nextcloud/files'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { nextTick, onMounted, ref } from 'vue'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const isDialogOpen = ref(false)
const selectedFile = ref<INode>()
const libraries = ref<string[]>([])
const selectedLibrary = ref<string>()
const songs = ref<Array<{ id: number, title: string, filePath: string | null }>>([])
const selectedSong = ref<{ id: number, title: string, filePath: string | null }>()
const pdfContainer = ref<HTMLElement>()
const fileError = ref('')
const isLoadingSongs = ref(false)

GlobalWorkerOptions.workerSrc = pdfWorker

async function selectLibrary(library: string) {
	selectedLibrary.value = library
	songs.value = []
	selectedSong.value = undefined
	fileError.value = ''
	isLoadingSongs.value = true

	try {
		const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/songs'), {
			params: { path: library },
		})
		songs.value = response.data.ocs.data.songs
	} catch {
		fileError.value = t('mobilesheetsmanager', 'Could not load the songs in this library.')
	} finally {
		isLoadingSongs.value = false
	}
}

function selectSong(song: { id: number, title: string, filePath: string | null }) {
	selectedSong.value = song
	void loadSongFile(song)
}

async function loadSongFile(song: { id: number, title: string, filePath: string | null }) {
	await nextTick()
	if (!pdfContainer.value) {
		return
	}
	pdfContainer.value.replaceChildren()

	try {
		if (!song.filePath) {
			throw new Error('No file path')
		}
		const response = await axios.get(song.filePath, {
			responseType: 'arraybuffer',
		})
		const pdf = await getDocument({ data: response.data }).promise
		await nextTick()
		for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
			const page = await pdf.getPage(pageNumber)
			const viewport = page.getViewport({ scale: 1.25 })
			const canvas = document.createElement('canvas')
			canvas.width = viewport.width
			canvas.height = viewport.height
			canvas.className = 'mobilesheetsmanager-pdf-page'
			pdfContainer.value?.append(canvas)
			await page.render({ canvas, viewport }).promise
		}
	} catch {
		fileError.value = t('mobilesheetsmanager', 'Could not load the selected song.')
	}
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
			<div v-if="selectedLibrary" :class="$style.songArea">
				<ul :class="$style.songList">
					<li v-for="song in songs" :key="song.id">
						<button type="button" :class="$style.songButton" :aria-pressed="selectedSong?.id === song.id" @click="selectSong(song)">{{ song.title }}</button>
					</li>
					<li v-if="!isLoadingSongs && !fileError && songs.length === 0">{{ t('mobilesheetsmanager', 'No songs found in this library.') }}</li>
				</ul>
				<div v-if="selectedSong" ref="pdfContainer" :class="$style.pdfViewer" :aria-label="selectedSong.title" />
			</div>
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
	margin: 24px 0 0;
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

.pdfViewer {
	width: 100%;
	height: 75vh;
	overflow: auto;
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
	padding: 16px;
}

.pdfViewer :global(.mobilesheetsmanager-pdf-page) {
	display: block;
	max-width: 100%;
	height: auto;
	margin: 0 auto 16px;
	background: white;
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
