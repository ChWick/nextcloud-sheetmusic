<script setup lang="ts">
import type { Artist, Book, Collection, Composer, LibraryData, Song } from '../types/song'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { computed, nextTick, ref, watch } from 'vue'
import SongEditor from './SongEditor.vue'

const props = withDefaults(
	defineProps<{
		song?: Song | null
		libraryId?: number
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
	}>(),
	{
		song: null,
		libraryId: -1,
		composers: () => [],
		books: () => [],
		collections: () => [],
		artists: () => [],
	},
)

const emit = defineEmits<{
	saved: [data: LibraryData]
}>()

GlobalWorkerOptions.workerSrc = pdfWorker

const pdfContainer = ref<HTMLElement>()
const isLoading = ref(false)
const errorMessage = ref('')
const isEditing = ref(false)
let currentLoadId = 0

const songComposers = computed(() => {
	if (!props.song?.composerIds || props.song.composerIds.length === 0) {
		return []
	}
	return props.composers.filter((c) => props.song?.composerIds?.includes(c.id))
})

const songBooks = computed(() => {
	if (!props.song?.bookIds || props.song.bookIds.length === 0) {
		return []
	}
	return props.books.filter((b) => props.song?.bookIds?.includes(b.id))
})

const songCollections = computed(() => {
	if (!props.song?.collectionIds || props.song.collectionIds.length === 0) {
		return []
	}
	return props.collections.filter((c) => props.song?.collectionIds?.includes(c.id))
})

const songArtists = computed(() => {
	if (!props.song?.artistIds || props.song.artistIds.length === 0) {
		return []
	}
	return props.artists.filter((a) => props.song?.artistIds?.includes(a.id))
})

function handleSaved(data: LibraryData) {
	isEditing.value = false
	emit('saved', data)
}

watch(
	() => props.song,
	async (newSong) => {
		isEditing.value = false
		const loadId = ++currentLoadId
		errorMessage.value = ''

		await nextTick()
		if (pdfContainer.value) {
			pdfContainer.value.replaceChildren()
		}

		if (!newSong) {
			isLoading.value = false
			return
		}

		const filePath = newSong.files?.[0]?.path
		if (!filePath) {
			isLoading.value = false
			errorMessage.value = t('mobilesheetsmanager', 'No file available for this song.')
			return
		}

		isLoading.value = true

		try {
			const response = await axios.get(filePath, {
				responseType: 'arraybuffer',
			})

			if (loadId !== currentLoadId) {
				return
			}

			const pdf = await getDocument({ data: response.data }).promise

			if (loadId !== currentLoadId) {
				return
			}

			await nextTick()
			if (!pdfContainer.value || loadId !== currentLoadId) {
				return
			}

			pdfContainer.value.replaceChildren()

			for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
				if (loadId !== currentLoadId) {
					return
				}
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
			if (loadId === currentLoadId) {
				errorMessage.value = t('mobilesheetsmanager', 'Could not load the selected song.')
			}
		} finally {
			if (loadId === currentLoadId) {
				isLoading.value = false
			}
		}
	},
	{ immediate: true },
)
</script>

<template>
	<div :class="$style.container">
		<header v-if="song" :class="$style.header">
			<div :class="$style.headerTop">
				<h3 :class="$style.title">{{ song.title }}</h3>
				<NcButton
					v-if="!isEditing"
					variant="secondary"
					@click="isEditing = true">
					{{ t('mobilesheetsmanager', 'Edit song') }}
				</NcButton>
			</div>

			<div v-if="!isEditing && (songComposers.length > 0 || songBooks.length > 0 || songCollections.length > 0)" :class="$style.metadata">
				<div v-if="songComposers.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Composer:') }}</span>
					<span>{{ songComposers.map((c) => c.name).join(', ') }}</span>
				</div>
				<div v-if="songBooks.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Book:') }}</span>
					<span>{{ songBooks.map((b) => b.title).join(', ') }}</span>
				</div>
				<div v-if="songCollections.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Collection:') }}</span>
					<span>{{ songCollections.map((c) => c.name).join(', ') }}</span>
				</div>
				<div v-if="songArtists.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Artist:') }}</span>
					<span>{{ songArtists.map((a) => a.name).join(', ') }}</span>
				</div>
			</div>
		</header>

		<!-- Song Editor View -->
		<SongEditor
			v-if="song && isEditing"
			:song="song"
			:library-id="libraryId"
			:composers="composers"
			:books="books"
			:collections="collections"
            :artists="artists"
			@saved="handleSaved"
			@cancel="isEditing = false" />

		<!-- PDF Viewer View -->
		<template v-else>
			<div v-if="isLoading" :class="$style.loading">
				<NcLoadingIcon :size="32" :name="t('mobilesheetsmanager', 'Loading song...')" />
			</div>

			<p v-if="errorMessage" :class="$style.error" role="alert">
				{{ errorMessage }}
			</p>

			<div
				v-show="song && !isLoading && !errorMessage"
				ref="pdfContainer"
				:class="$style.pdfViewer"
				:aria-label="song?.title" />

			<div v-if="!song" :class="$style.emptyState">
				<p>{{ t('mobilesheetsmanager', 'Select a song to view.') }}</p>
			</div>
		</template>
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	min-width: 0;
}

.header {
	margin-bottom: 16px;
}

.headerTop {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	margin-bottom: 8px;
}

.title {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 600;
}

.metadata {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.metaItem {
	display: inline-flex;
	gap: 4px;
}

.metaLabel {
	font-weight: 600;
}

.loading {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 200px;
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

.emptyState {
	padding: 32px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error);
	margin: 16px 0;
}
</style>
