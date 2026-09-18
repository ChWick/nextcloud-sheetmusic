<script setup lang="ts">
import type { Song } from '../types/song'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
	song?: Song | null
}>()

GlobalWorkerOptions.workerSrc = pdfWorker

const pdfContainer = ref<HTMLElement>()
const isLoading = ref(false)
const errorMessage = ref('')
let currentLoadId = 0

watch(
	() => props.song,
	async (newSong) => {
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

		if (!newSong.filePath) {
			isLoading.value = false
			errorMessage.value = t('mobilesheetsmanager', 'No file available for this song.')
			return
		}

		isLoading.value = true

		try {
			const response = await axios.get(newSong.filePath, {
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
			<h3 :class="$style.title">{{ song.title }}</h3>
		</header>

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
	margin-bottom: 12px;
}

.title {
	margin: 0;
	font-size: 1.2rem;
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
