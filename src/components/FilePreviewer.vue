<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { openFileExternally } from '../utils/file'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import FilePreviewerToolbar from './file-preview/FilePreviewerToolbar.vue'

const props = withDefaults(
	defineProps<{
		filePath: string
		showExternalButton?: boolean
		hideToolbar?: boolean
	}>(),
	{
		showExternalButton: true,
		hideToolbar: false,
	},
)

GlobalWorkerOptions.workerSrc = pdfWorker

const pdfContainer = ref<HTMLElement>()
const isLoading = ref(false)
const errorMessage = ref('')
let currentLoadId = 0
let currentRenderId = 0

const loadedPdf = shallowRef<any>(null)
const totalPages = ref(1)
const currentPage = ref(1)

type PageLayoutMode = 'continuous' | 'single' | 'double'
type ZoomMode = 'fit-width' | 'fit-page' | 'custom'

const pageLayout = ref<PageLayoutMode>('continuous')
const zoomMode = ref<ZoomMode>('fit-width')
const customScale = ref(1.0)
const effectiveScale = ref(1.0)
const rotation = ref(0)
const imageScale = ref(1.0)

const zoomSteps = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0, 4.0]

const isPdfFile = computed(() => {
	const path = props.filePath.split('?')[0].toLowerCase()
	return path.endsWith('.pdf')
})

const isImageFile = computed(() => {
	const path = props.filePath.split('?')[0].toLowerCase()
	return path.endsWith('.png') || path.endsWith('.jpg') || path.endsWith('.jpeg') || path.endsWith('.webp') || path.endsWith('.gif') || path.endsWith('.svg') || path.endsWith('.bmp')
})

const isUnsupportedFile = computed(() => {
	return !isPdfFile.value && !isImageFile.value
})

const currentPageDisplay = computed(() => {
	if (pageLayout.value === 'double') {
		const next = Math.min(currentPage.value + 1, totalPages.value)
		return currentPage.value === next ? `${currentPage.value}` : `${currentPage.value}–${next}`
	}
	if (pageLayout.value === 'continuous') {
		return `1–${totalPages.value || 1}`
	}
	return `${currentPage.value}`
})

const zoomSelectValue = computed(() => {
	if (zoomMode.value === 'fit-width') return 'fit-width'
	if (zoomMode.value === 'fit-page') return 'fit-page'
	const matches = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0].some((val) => Math.abs(val - customScale.value) < 0.01)
	return matches ? String(customScale.value) : 'custom'
})

const isCustomZoom = computed(() => {
	if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') return false
	return ![0.5, 0.75, 1.0, 1.25, 1.5, 2.0].some((val) => Math.abs(val - customScale.value) < 0.01)
})

const imageStyle = computed(() => ({
	transform: `rotate(${rotation.value}deg) scale(${imageScale.value})`,
	transformOrigin: 'center center',
	transition: 'transform 0.15s ease-out',
}))

function openInExternalViewer() {
	if (!props.filePath) return
	openFileExternally(props.filePath)
}

function prevPage() {
	if (pageLayout.value === 'double') {
		currentPage.value = Math.max(1, currentPage.value - 2)
	} else {
		currentPage.value = Math.max(1, currentPage.value - 1)
	}
	renderCurrentView()
}

function nextPage() {
	if (pageLayout.value === 'double') {
		if (currentPage.value + 2 <= totalPages.value) {
			currentPage.value += 2
		} else if (currentPage.value + 1 <= totalPages.value) {
			currentPage.value += 1
		}
	} else {
		currentPage.value = Math.min(totalPages.value, currentPage.value + 1)
	}
	renderCurrentView()
}

function setPageLayout(mode: PageLayoutMode) {
	if (pageLayout.value === mode) return
	pageLayout.value = mode
	renderCurrentView()
}

function setZoomMode(mode: ZoomMode) {
	zoomMode.value = mode
	if (isImageFile.value) {
		imageScale.value = mode === 'fit-width' ? 1.0 : 0.75
		return
	}
	renderCurrentView()
}

function onZoomSelect(event: Event) {
	const val = (event.target as HTMLSelectElement).value
	if (val === 'fit-width' || val === 'fit-page') {
		setZoomMode(val)
	} else if (val !== 'custom') {
		const scale = parseFloat(val)
		if (!Number.isNaN(scale) && scale > 0) {
			zoomMode.value = 'custom'
			customScale.value = scale
			if (isImageFile.value) {
				imageScale.value = scale
			} else {
				renderCurrentView()
			}
		}
	}
}

function zoomIn() {
	if (isImageFile.value) {
		imageScale.value = Math.min(4.0, +(imageScale.value + 0.25).toFixed(2))
		return
	}
	const current = effectiveScale.value
	const next = zoomSteps.find((s) => s > current + 0.05) ?? Math.min(4.0, +(current + 0.25).toFixed(2))
	zoomMode.value = 'custom'
	customScale.value = next
	renderCurrentView()
}

function zoomOut() {
	if (isImageFile.value) {
		imageScale.value = Math.max(0.25, +(imageScale.value - 0.25).toFixed(2))
		return
	}
	const current = effectiveScale.value
	const prev = [...zoomSteps].reverse().find((s) => s < current - 0.05) ?? Math.max(0.25, +(current - 0.25).toFixed(2))
	zoomMode.value = 'custom'
	customScale.value = prev
	renderCurrentView()
}

function rotateClockwise() {
	rotation.value = (rotation.value + 90) % 360
	if (isPdfFile.value) {
		renderCurrentView()
	}
}

async function renderCurrentView() {
	if (!loadedPdf.value || !pdfContainer.value) return

	const renderId = ++currentRenderId
	const pdf = loadedPdf.value
	const total = pdf.numPages

	await nextTick()
	if (!pdfContainer.value || renderId !== currentRenderId) return

	pdfContainer.value.replaceChildren()

	let pagesToRender: number[] = []
	if (pageLayout.value === 'single') {
		pagesToRender = [Math.min(Math.max(1, currentPage.value), total)]
	} else if (pageLayout.value === 'double') {
		const start = Math.min(Math.max(1, currentPage.value), total)
		pagesToRender = [start]
		if (start + 1 <= total) {
			pagesToRender.push(start + 1)
		}
	} else {
		pagesToRender = Array.from({ length: total }, (_, i) => i + 1)
	}

	for (const pageNumber of pagesToRender) {
		if (renderId !== currentRenderId) return

		const page = await pdf.getPage(pageNumber)
		const pageRotation = (((page.rotate ?? 0) + rotation.value) % 360 + 360) % 360
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: pageRotation })

		let scale = 1.25
		if (zoomMode.value === 'fit-width') {
			const containerW = (pdfContainer.value?.clientWidth && pdfContainer.value.clientWidth > 100)
				? pdfContainer.value.clientWidth - 48
				: 800
			const targetW = pageLayout.value === 'double' ? (containerW - 24) / 2 : containerW
			scale = targetW / unscaledViewport.width
		} else if (zoomMode.value === 'fit-page') {
			const containerH = (pdfContainer.value?.clientHeight && pdfContainer.value.clientHeight > 100)
				? pdfContainer.value.clientHeight - 48
				: 700
			scale = containerH / unscaledViewport.height
		} else {
			scale = customScale.value
		}

		scale = Math.max(0.2, Math.min(scale, 4.0))
		effectiveScale.value = scale

		const viewport = page.getViewport({ scale, rotation: pageRotation })
		const canvas = document.createElement('canvas')
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		canvas.className = 'mobilesheetsmanager-pdf-page'
		pdfContainer.value?.append(canvas)

		const canvasContext = canvas.getContext('2d')
		if (canvasContext) {
			canvasContext.fillStyle = '#ffffff'
			canvasContext.fillRect(0, 0, canvas.width, canvas.height)
			await page.render({
				canvasContext,
				viewport,
				background: 'rgb(255, 255, 255)',
			}).promise
		}
	}
}

async function loadAndRenderFile() {
	const loadId = ++currentLoadId
	errorMessage.value = ''
	loadedPdf.value = null
	totalPages.value = 1
	currentPage.value = 1
	rotation.value = 0

	await nextTick()
	if (pdfContainer.value) {
		pdfContainer.value.replaceChildren()
	}

	if (!props.filePath) {
		isLoading.value = false
		errorMessage.value = t('mobilesheetsmanager', 'No file available.')
		return
	}

	if (!isPdfFile.value) {
		isLoading.value = false
		return
	}

	isLoading.value = true

	try {
		const response = await axios.get(props.filePath, {
			responseType: 'arraybuffer',
			params: { t: Date.now() },
			headers: {
				'Cache-Control': 'no-cache',
				Pragma: 'no-cache',
			},
		})

		if (loadId !== currentLoadId) return

		const pdfData = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: pdfData,
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

		if (loadId !== currentLoadId) return

		loadedPdf.value = pdf
		totalPages.value = pdf.numPages
		currentPage.value = 1

		await renderCurrentView()
	} catch (error) {
		console.error('Failed to load or render PDF:', error)
		if (loadId === currentLoadId) {
			errorMessage.value = t('mobilesheetsmanager', 'Could not load the selected file.')
		}
	} finally {
		if (loadId === currentLoadId) {
			isLoading.value = false
		}
	}
}

function handleResize() {
	if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') {
		renderCurrentView()
	}
}

onMounted(() => {
	window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
	window.removeEventListener('resize', handleResize)
})

watch(() => props.filePath, loadAndRenderFile, { immediate: true })

defineExpose({
	openInExternalViewer,
})
</script>

<template>
	<div :class="$style.wrapper">
		<!-- PDF / Image Viewer Toolbar -->
		<FilePreviewerToolbar
			v-if="!hideToolbar && !isLoading && !errorMessage && (isPdfFile || isImageFile)"
			:is-pdf="isPdfFile"
			:current-page="currentPage"
			:total-pages="totalPages"
			:current-page-display="currentPageDisplay"
			:page-layout="pageLayout"
			:zoom-select-value="zoomSelectValue"
			:is-custom-zoom="isCustomZoom"
			:display-scale="isImageFile ? imageScale : effectiveScale"
			:show-external-button="showExternalButton"
			@previous-page="prevPage"
			@next-page="nextPage"
			@page-layout="setPageLayout"
			@zoom-select="onZoomSelect"
			@zoom-in="zoomIn"
			@zoom-out="zoomOut"
			@fit-width="setZoomMode('fit-width')"
			@rotate="rotateClockwise"
			@open-external="openInExternalViewer" />

		<div v-if="isLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" :name="t('mobilesheetsmanager', 'Loading file...')" />
		</div>

		<p v-if="errorMessage" :class="$style.error" role="alert">
			{{ errorMessage }}
		</p>

		<div v-if="!isLoading && !errorMessage && isImageFile" :class="$style.imageViewer">
			<img
				:src="filePath"
				:alt="filePath"
				:style="imageStyle"
				:class="$style.previewImage">
		</div>

		<div
			v-show="!isLoading && !errorMessage && isPdfFile"
			ref="pdfContainer"
			:class="[$style.pdfContainer, $style[`layout-${pageLayout}`]]" />

		<div v-if="!isLoading && !errorMessage && isUnsupportedFile" :class="$style.unsupported">
			<p>{{ t('mobilesheetsmanager', 'Preview is not available for this file type.') }}</p>
			<NcButton
				v-if="showExternalButton"
				variant="primary"
				@click="openInExternalViewer">
				<template #icon>
					<OpenInNewIcon :size="18" />
				</template>
				{{ t('mobilesheetsmanager', 'Open File Externally') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.wrapper {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	flex: 1;
	min-height: 0;
}

.loading {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 48px;
}

.error {
	padding: 16px;
	color: var(--color-error);
}

.imageViewer {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
	overflow: auto;
	flex: 1;
}

.previewImage {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

.pdfContainer {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 24px;
	overflow: auto;
	flex: 1;
	background-color: var(--color-background-dark, #f5f5f5);
}

.pdfContainer.layout-double {
	flex-direction: row;
	flex-wrap: wrap;
	justify-content: center;
}

.unsupported {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 16px;
	gap: 16px;
	color: var(--color-text-maxcontrast);
}
</style>
