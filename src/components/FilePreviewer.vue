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

import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'

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
	const path = props.filePath.toLowerCase()
	return path.endsWith('.pdf')
})

const isImageFile = computed(() => {
	const path = props.filePath.toLowerCase()
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
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: rotation.value })

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

		const viewport = page.getViewport({ scale, rotation: rotation.value })
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
		<div v-if="!hideToolbar && !isLoading && !errorMessage && (isPdfFile || isImageFile)" :class="$style.toolbar">
			<div :class="$style.toolbarGroup">
				<template v-if="isPdfFile">
					<NcButton
						variant="secondary"
						size="small"
						:disabled="currentPage <= 1 || pageLayout === 'continuous'"
						:title="t('mobilesheetsmanager', 'Previous page')"
						:aria-label="t('mobilesheetsmanager', 'Previous page')"
						@click="prevPage">
						<template #icon>
							<ChevronLeft :size="20" />
						</template>
					</NcButton>
					<span :class="$style.pageIndicator">
						{{ t('mobilesheetsmanager', 'Page {current} of {total}', { current: currentPageDisplay, total: totalPages || 1 }) }}
					</span>
					<NcButton
						variant="secondary"
						size="small"
						:disabled="currentPage >= totalPages || pageLayout === 'continuous'"
						:title="t('mobilesheetsmanager', 'Next page')"
						:aria-label="t('mobilesheetsmanager', 'Next page')"
						@click="nextPage">
						<template #icon>
							<ChevronRight :size="20" />
						</template>
					</NcButton>
				</template>
			</div>

			<div v-if="isPdfFile" :class="$style.toolbarGroup">
				<div :class="$style.segmentedGroup">
					<NcButton
						size="small"
						:variant="pageLayout === 'continuous' ? 'primary' : 'secondary'"
						:title="t('mobilesheetsmanager', 'Continuous scroll (All pages)')"
						:aria-label="t('mobilesheetsmanager', 'Continuous scroll (All pages)')"
						@click="setPageLayout('continuous')">
						<template #icon>
							<ViewStream :size="18" />
						</template>
					</NcButton>
					<NcButton
						size="small"
						:variant="pageLayout === 'single' ? 'primary' : 'secondary'"
						:title="t('mobilesheetsmanager', 'Single page')"
						:aria-label="t('mobilesheetsmanager', 'Single page')"
						@click="setPageLayout('single')">
						<template #icon>
							<ViewDay :size="18" />
						</template>
					</NcButton>
					<NcButton
						size="small"
						:variant="pageLayout === 'double' ? 'primary' : 'secondary'"
						:title="t('mobilesheetsmanager', 'Two pages side-by-side')"
						:aria-label="t('mobilesheetsmanager', 'Two pages side-by-side')"
						@click="setPageLayout('double')">
						<template #icon>
							<ViewColumn :size="18" />
						</template>
					</NcButton>
				</div>
			</div>

			<div :class="$style.toolbarGroup">
				<NcButton
					variant="secondary"
					size="small"
					:title="t('mobilesheetsmanager', 'Zoom out')"
					:aria-label="t('mobilesheetsmanager', 'Zoom out')"
					@click="zoomOut">
					<template #icon>
						<MagnifyMinus :size="18" />
					</template>
				</NcButton>

				<div :class="$style.zoomSelector">
					<select
						:value="zoomSelectValue"
						:aria-label="t('mobilesheetsmanager', 'Zoom level')"
						:class="$style.zoomDropdown"
						@change="onZoomSelect">
						<option value="fit-width">{{ t('mobilesheetsmanager', 'Fit width') }}</option>
						<option value="fit-page">{{ t('mobilesheetsmanager', 'Fit page') }}</option>
						<option value="0.5">50%</option>
						<option value="0.75">75%</option>
						<option value="1.0">100%</option>
						<option value="1.25">125%</option>
						<option value="1.5">150%</option>
						<option value="2.0">200%</option>
						<option v-if="isCustomZoom" value="custom">
							{{ Math.round((isImageFile ? imageScale : effectiveScale) * 100) }}%
						</option>
					</select>
				</div>

				<NcButton
					variant="secondary"
					size="small"
					:title="t('mobilesheetsmanager', 'Zoom in')"
					:aria-label="t('mobilesheetsmanager', 'Zoom in')"
					@click="zoomIn">
					<template #icon>
						<MagnifyPlus :size="18" />
					</template>
				</NcButton>

				<NcButton
					variant="secondary"
					size="small"
					:title="t('mobilesheetsmanager', 'Fit to width')"
					:aria-label="t('mobilesheetsmanager', 'Fit to width')"
					@click="setZoomMode('fit-width')">
					<template #icon>
						<FitToScreenOutline :size="18" />
					</template>
				</NcButton>

				<NcButton
					variant="secondary"
					size="small"
					:title="t('mobilesheetsmanager', 'Rotate clockwise')"
					:aria-label="t('mobilesheetsmanager', 'Rotate clockwise')"
					@click="rotateClockwise">
					<template #icon>
						<RotateRight :size="18" />
					</template>
				</NcButton>

				<NcButton
					v-if="showExternalButton"
					variant="secondary"
					size="small"
					:title="t('mobilesheetsmanager', 'Open in external viewer')"
					:aria-label="t('mobilesheetsmanager', 'Open in external viewer')"
					@click="openInExternalViewer">
					<template #icon>
						<OpenInNewIcon :size="18" />
					</template>
				</NcButton>
			</div>
		</div>

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

.toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px;
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	gap: 12px;
	flex-wrap: wrap;
}

.toolbarGroup {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pageIndicator {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	padding: 0 6px;
}

.segmentedGroup {
	display: flex;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	overflow: hidden;
}

.zoomSelector {
	display: flex;
	align-items: center;
}

.zoomDropdown {
	height: 34px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
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
