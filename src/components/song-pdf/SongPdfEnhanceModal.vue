<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PageNavControls from '../PageNavControls.vue'
import ZoomControls from '../ZoomControls.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'

import SongPdfEnhanceStack from './SongPdfEnhanceStack.vue'
import type { ImageBuffer, ScanOperation, ScanOpType } from './scan-types'
import {
	applyPipeline,
	downsampleImageBuffer,
	imageBufferToImageData,
	imageDataToImageBuffer,
} from './scan-enhance-engine'
import { resolvePageRenderScale } from './pdf-enhance-engine'
import { ScanHistoryManager } from './scan-history'
import type { StorageAdapter } from '../../utils/settings-store'
import { generateDefaultOpId } from './filter-registry'
import { usePdfEnhanceSettings } from './usePdfEnhanceSettings'

const props = withDefaults(
	defineProps<{
		open: boolean
		pageNum: number
		totalPages: number
		pdf: any
		initialOperations?: ScanOperation[] | null
		rotation?: number
		storageAdapter?: StorageAdapter
	}>(),
	{
		initialOperations: null,
		rotation: 0,
		storageAdapter: undefined,
	},
)

const emit = defineEmits<{
	'update:open': [value: boolean]
	'apply': [operations: ScanOperation[], applyTo: 'current' | 'all']
	'change-page': [newPage: number]
}>()

const canvasRef = ref<HTMLCanvasElement>()
const previewPaneRef = ref<HTMLElement>()
const isLoadingPage = ref(false)
const showOriginal = ref(false)

// Native PDF rendered dimensions & DPI
const canvasWidth = ref(0)
const canvasHeight = ref(0)
const pageDpi = ref(0)

// Preview container dimensions for fitting portrait and landscape pages
const paneWidth = ref(0)
const paneHeight = ref(0)

// Zoom and pan state
const MIN_ZOOM = 0.5
const MAX_ZOOM = 8.0
const ZOOM_STEPS = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0, 2.5, 3.0, 4.0, 6.0, 8.0]
const zoomLevel = ref(1.0)
const panX = ref(0)
const panY = ref(0)
const isPanning = ref(false)
let panStartX = 0
let panStartY = 0

// Cached rendered raw page image buffer before enhancement, and enhanced buffer at native DPI
let originalBuffer: ImageBuffer | null = null
let enhancedBuffer: ImageBuffer | null = null

// PDF enhancement settings composable
const {
	getInitialOperations,
	getInitialScope,
	saveSettings,
	createOperation,
} = usePdfEnhanceSettings({ storageAdapter: props.storageAdapter })

// Operation stack and undo/redo
const operations = ref<ScanOperation[]>([])
const historyManager = new ScanHistoryManager()
const canUndo = ref(false)
const canRedo = ref(false)

const applyScope = ref<'current' | 'all'>(getInitialScope())

function measurePreviewPane() {
	if (!previewPaneRef.value) return
	const w = previewPaneRef.value.clientWidth
	const h = previewPaneRef.value.clientHeight
	if (w > 0) paneWidth.value = w
	if (h > 0) paneHeight.value = h
}

const baseDisplayDimensions = computed(() => {
	const availW = paneWidth.value > 60 ? paneWidth.value - 16 : 960
	const availH = paneHeight.value > 60 ? paneHeight.value - 16 : 780
	const w = canvasWidth.value || 400
	const h = canvasHeight.value || 560
	const fitRatio = Math.min(availW / w, availH / h)
	return {
		width: Math.max(1, Math.round(w * fitRatio)),
		height: Math.max(1, Math.round(h * fitRatio)),
	}
})

const canvasWrapperStyle = computed(() => ({
	width: `${Math.max(1, Math.round(baseDisplayDimensions.value.width * zoomLevel.value))}px`,
	height: `${Math.max(1, Math.round(baseDisplayDimensions.value.height * zoomLevel.value))}px`,
	transform: `translate(${panX.value}px, ${panY.value}px)`,
}))

function zoomIn() {
	const current = zoomLevel.value
	const next = ZOOM_STEPS.find((s) => s > current + 0.01) ?? Math.min(MAX_ZOOM, +(current * 1.25).toFixed(2))
	zoomLevel.value = Math.min(MAX_ZOOM, next)
}

function zoomOut() {
	const current = zoomLevel.value
	const prev = [...ZOOM_STEPS].reverse().find((s) => s < current - 0.01) ?? Math.max(MIN_ZOOM, +(current / 1.25).toFixed(2))
	zoomLevel.value = Math.max(MIN_ZOOM, prev)
	if (zoomLevel.value <= 1.0) {
		panX.value = 0
		panY.value = 0
	}
}

function resetZoom() {
	zoomLevel.value = 1.0
	panX.value = 0
	panY.value = 0
}

function handleWheel(e: WheelEvent) {
	const oldZoom = zoomLevel.value
	const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15
	const newZoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(oldZoom * factor * 100) / 100))
	if (newZoom === oldZoom) return

	if (previewPaneRef.value) {
		const rect = previewPaneRef.value.getBoundingClientRect()
		if (rect.width > 0 && rect.height > 0) {
			const cx = e.clientX - (rect.left + rect.width / 2)
			const cy = e.clientY - (rect.top + rect.height / 2)
			const ratio = newZoom / oldZoom
			panX.value = Math.round(cx - (cx - panX.value) * ratio)
			panY.value = Math.round(cy - (cy - panY.value) * ratio)
		}
	}

	zoomLevel.value = newZoom
	if (newZoom <= 1.0) {
		panX.value = 0
		panY.value = 0
	}
}

function startPan(e: MouseEvent) {
	if (e.button !== 0) return
	isPanning.value = true
	panStartX = e.clientX - panX.value
	panStartY = e.clientY - panY.value
}

function onMouseMove(e: MouseEvent) {
	if (!isPanning.value) return
	panX.value = Math.round(e.clientX - panStartX)
	panY.value = Math.round(e.clientY - panStartY)
}

function endPan() {
	if (!isPanning.value) return
	isPanning.value = false
}

function syncHistoryState() {
	canUndo.value = historyManager.canUndo()
	canRedo.value = historyManager.canRedo()
}

function recordHistory() {
	historyManager.push(operations.value)
	syncHistoryState()
}

// Generate unique ID for operations
function generateOpId(): string {
	return generateDefaultOpId()
}

// Default presets
function applyPreset(preset: 'standard' | 'contrast' | 'colorClean' | 'binarize') {
	let newOps: ScanOperation[] = []
	if (preset === 'standard') {
		newOps = [
			{ id: generateOpId(), type: 'gaussian', size: 3, enabled: true },
			{ id: generateOpId(), type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 215, colorMode: 'grayscale', enabled: true },
			{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 25, delta: 12, blackValue: 0, whiteValue: 255, softness: 15, enabled: true },
		]
	} else if (preset === 'contrast') {
		newOps = [
			{ id: generateOpId(), type: 'grayLevelMapping', lowerCutoff: 35, upperCutoff: 220, colorMode: 'color', enabled: true },
		]
	} else if (preset === 'colorClean') {
		newOps = [
			{ id: generateOpId(), type: 'gaussian', size: 3, enabled: true },
			{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 25, delta: 15, blackValue: 0, whiteValue: 255, colorMode: 'preserveColor', softness: 15, enabled: true },
		]
	} else if (preset === 'binarize') {
		newOps = [
			{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 21, delta: 10, blackValue: 0, whiteValue: 255, colorMode: 'binarize', softness: 0, enabled: true },
		]
	}

	operations.value = newOps
	recordHistory()
	renderEnhancedPreview()
}

// Adding operations
function addOperation(type: ScanOpType) {
	const newOp = createOperation(type, undefined, generateOpId)
	operations.value.push(newOp)
	recordHistory()
	renderEnhancedPreview()
}

function removeOperation(index: number) {
	operations.value.splice(index, 1)
	recordHistory()
	renderEnhancedPreview()
}

function moveOperation(index: number, direction: 'up' | 'down') {
	const target = direction === 'up' ? index - 1 : index + 1
	if (target < 0 || target >= operations.value.length) return
	const item = operations.value.splice(index, 1)[0]
	operations.value.splice(target, 0, item)
	recordHistory()
	renderEnhancedPreview()
}

function toggleOperation(index: number) {
	operations.value[index].enabled = !operations.value[index].enabled
	recordHistory()
	renderEnhancedPreview()
}

function handleUndo() {
	const prev = historyManager.undo()
	if (prev !== null) {
		operations.value = prev
		syncHistoryState()
		renderEnhancedPreview()
	}
}

function handleRedo() {
	const next = historyManager.redo()
	if (next !== null) {
		operations.value = next
		syncHistoryState()
		renderEnhancedPreview()
	}
}

function resetAll() {
	operations.value = []
	recordHistory()
	renderEnhancedPreview()
}

// Rendering at native PDF resolution (preserving identical DPI)
async function renderPage() {
	if (!props.pdf || !canvasRef.value) return
	isLoadingPage.value = true
	measurePreviewPane()

	try {
		const page = await props.pdf.getPage(props.pageNum)
		const effectiveRotation = (((page.rotate ?? 0) + (props.rotation || 0)) % 360 + 360) % 360
		const scale = await resolvePageRenderScale(page)
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: effectiveRotation })
		const viewport = page.getViewport({ scale, rotation: effectiveRotation })

		const vpScaled = Math.abs(viewport.width - unscaledViewport.width * scale) < 2
		const targetW = vpScaled
			? Math.max(1, Math.round(viewport.width))
			: Math.max(1, Math.round(unscaledViewport.width * scale))
		const targetH = vpScaled
			? Math.max(1, Math.round(viewport.height))
			: Math.max(1, Math.round(unscaledViewport.height * scale))

		const canvas = canvasRef.value
		canvas.width = targetW
		canvas.height = targetH
		canvas.dataset.nativeWidth = String(targetW)
		canvas.dataset.nativeHeight = String(targetH)
		canvasWidth.value = targetW
		canvasHeight.value = targetH
		pageDpi.value = Math.round(scale * 72)

		measurePreviewPane()

		const srcCanvas = document.createElement('canvas')
		srcCanvas.width = targetW
		srcCanvas.height = targetH
		const srcCtx = srcCanvas.getContext('2d')
		if (srcCtx) {
			srcCtx.imageSmoothingEnabled = true
			srcCtx.imageSmoothingQuality = 'high'
			srcCtx.fillStyle = '#ffffff'
			srcCtx.fillRect(0, 0, srcCanvas.width, srcCanvas.height)
			await page.render({
				canvasContext: srcCtx,
				viewport,
				background: 'rgb(255, 255, 255)',
			}).promise

			const rawImageData = srcCtx.getImageData(0, 0, srcCanvas.width, srcCanvas.height)
			originalBuffer = imageDataToImageBuffer(rawImageData)
			renderEnhancedPreview()
		}
	} catch (e) {
		console.warn(`Failed to render enhance preview for page ${props.pageNum}:`, e)
	} finally {
		isLoadingPage.value = false
	}
}

function paintDisplayCanvas() {
	const canvas = canvasRef.value
	if (!canvas || !originalBuffer) return
	const ctx = canvas.getContext('2d')
	if (!ctx) return

	const activeOps = operations.value.filter((op) => op.enabled)
	const sourceBuffer = (showOriginal.value || activeOps.length === 0)
		? originalBuffer
		: (enhancedBuffer ?? originalBuffer)

	const dpr = typeof window !== 'undefined' ? Math.min(2, Math.max(1, window.devicePixelRatio || 1)) : 1
	const targetDisplayW = Math.max(1, Math.round(baseDisplayDimensions.value.width * zoomLevel.value * dpr))
	const targetDisplayH = Math.max(1, Math.round(baseDisplayDimensions.value.height * zoomLevel.value * dpr))

	if (targetDisplayW < sourceBuffer.width || targetDisplayH < sourceBuffer.height) {
		const outW = Math.min(sourceBuffer.width, targetDisplayW)
		const outH = Math.min(sourceBuffer.height, targetDisplayH)
		const downsampled = downsampleImageBuffer(sourceBuffer, outW, outH)
		canvas.width = downsampled.width
		canvas.height = downsampled.height
		ctx.putImageData(imageBufferToImageData(downsampled), 0, 0)
	} else {
		canvas.width = sourceBuffer.width
		canvas.height = sourceBuffer.height
		ctx.putImageData(imageBufferToImageData(sourceBuffer), 0, 0)
	}
}

function renderEnhancedPreview() {
	if (!canvasRef.value || !originalBuffer) return
	const activeOps = operations.value.filter((op) => op.enabled)
	if (!showOriginal.value && activeOps.length > 0) {
		enhancedBuffer = applyPipeline(originalBuffer, operations.value)
	} else {
		enhancedBuffer = null
	}
	paintDisplayCanvas()
}

function toggleShowOriginal() {
	showOriginal.value = !showOriginal.value
	renderEnhancedPreview()
}

// Page pager
function prevPage() {
	if (props.pageNum > 1) {
		emit('change-page', props.pageNum - 1)
	}
}

function nextPage() {
	if (props.pageNum < props.totalPages) {
		emit('change-page', props.pageNum + 1)
	}
}

function handleApply() {
	saveSettings(operations.value, applyScope.value)
	emit('apply', operations.value, applyScope.value)
	emit('update:open', false)
}

function handleKeydown(e: KeyboardEvent) {
	if (!props.open) return
	if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
		e.preventDefault()
		if (e.shiftKey) {
			handleRedo()
		} else {
			handleUndo()
		}
	} else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
		e.preventDefault()
		handleRedo()
	}
}

let resizeObserver: ResizeObserver | null = null

function handleResize() {
	if (props.open) {
		measurePreviewPane()
	}
}

watch(previewPaneRef, (el) => {
	resizeObserver?.disconnect()
	if (el && typeof ResizeObserver !== 'undefined') {
		resizeObserver = new ResizeObserver(() => {
			measurePreviewPane()
		})
		resizeObserver.observe(el)
	}
})

onMounted(() => {
	window.addEventListener('keydown', handleKeydown)
	window.addEventListener('mousemove', onMouseMove)
	window.addEventListener('mouseup', endPan)
	window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
	resizeObserver?.disconnect()
	resizeObserver = null
	window.removeEventListener('keydown', handleKeydown)
	window.removeEventListener('mousemove', onMouseMove)
	window.removeEventListener('mouseup', endPan)
	window.removeEventListener('resize', handleResize)
})

watch(
	() => props.open,
	async (isOpen) => {
		if (isOpen) {
			resetZoom()
			const initial = getInitialOperations(props.initialOperations, generateOpId)
			operations.value = initial
			applyScope.value = getInitialScope()
			historyManager.clear(initial)
			syncHistoryState()
			await nextTick()
			measurePreviewPane()
			renderPage()
		}
	},
	{ immediate: true },
)

watch(
	() => [zoomLevel.value, baseDisplayDimensions.value.width, baseDisplayDimensions.value.height],
	() => {
		if (props.open && originalBuffer) {
			paintDisplayCanvas()
		}
	},
)

watch(
	() => [props.pageNum, props.rotation],
	async () => {
		if (props.open) {
			await nextTick()
			measurePreviewPane()
			renderPage()
		}
	},
)
</script>

<template>
	<NcDialog
		:open="open"
		size="large"
		:can-close="true"
		:class="$style.enhanceModal"
		:name="t('sheetmusic', 'Enhance Scan Quality — Page {page} of {total}', { page: pageNum, total: totalPages }, { escape: false })"
		@update:open="emit('update:open', $event)">
		<div :class="$style.dialogBody">
			<!-- Header Toolbar -->
			<div :class="$style.toolBar">
				<PageNavControls
					:current-page="pageNum"
					:total-pages="totalPages"
					icon-style="arrow"
					@prev="prevPage"
					@next="nextPage">
					<span
						v-if="pageDpi > 0 && canvasWidth > 0 && canvasHeight > 0"
						:class="$style.dpiBadge"
						data-test="enhance-dpi-badge">
						{{ pageDpi }} DPI · {{ canvasWidth }}×{{ canvasHeight }} px
					</span>
				</PageNavControls>

				<!-- Zoom & Pan Controls -->
				<ZoomControls
					:zoom-level="zoomLevel"
					:min-zoom="MIN_ZOOM"
					:max-zoom="MAX_ZOOM"
					:fit-disabled="zoomLevel === 1 && panX === 0 && panY === 0"
					:fit-title="t('sheetmusic', 'Reset zoom and pan')"
					zoom-out-test-id="enhance-zoom-out"
					zoom-level-test-id="enhance-zoom-level"
					zoom-in-test-id="enhance-zoom-in"
					fit-test-id="enhance-zoom-reset"
					@zoom-out="zoomOut"
					@zoom-in="zoomIn"
					@zoom-reset="resetZoom" />

				<div :class="$style.toolActions">
					<NcButton
						:variant="showOriginal ? 'primary' : 'secondary'"
						:title="t('sheetmusic', 'Toggle between original and enhanced preview')"
						@click="toggleShowOriginal">
						<template #icon>
							<EyeOffOutline v-if="showOriginal" :size="20" />
							<EyeOutline v-else :size="20" />
						</template>
						{{ showOriginal ? t('sheetmusic', 'Viewing Original') : t('sheetmusic', 'Compare Original') }}
					</NcButton>

					<NcButton
						variant="secondary"
						:disabled="!canUndo"
						:title="t('sheetmusic', 'Undo (Ctrl+Z)')"
						:aria-label="t('sheetmusic', 'Undo (Ctrl+Z)')"
						@click="handleUndo">
						<template #icon>
							<Undo :size="20" />
						</template>
					</NcButton>

					<NcButton
						variant="secondary"
						:disabled="!canRedo"
						:title="t('sheetmusic', 'Redo (Ctrl+Y)')"
						:aria-label="t('sheetmusic', 'Redo (Ctrl+Y)')"
						@click="handleRedo">
						<template #icon>
							<Redo :size="20" />
						</template>
					</NcButton>

					<NcButton
						variant="tertiary"
						:title="t('sheetmusic', 'Clear all operations')"
						@click="resetAll">
						<template #icon>
							<RestoreIcon :size="20" />
						</template>
						{{ t('sheetmusic', 'Reset') }}
					</NcButton>
				</div>
			</div>

			<!-- Main Content Area: Left Preview Canvas, Right Operations Stack -->
			<div :class="$style.workspace">
				<!-- Left: Preview Canvas -->
				<div
					ref="previewPaneRef"
					:class="[$style.previewPane, isPanning ? $style.previewPaneGrabbing : $style.previewPaneGrab]"
					data-test="enhance-preview-pane"
					@wheel.prevent="handleWheel"
					@mousedown="startPan"
					@mousemove="onMouseMove"
					@mouseup="endPan">
					<div v-if="isLoadingPage" :class="$style.loadingOverlay">
						<NcLoadingIcon :size="36" />
						<span>{{ t('sheetmusic', 'Rendering page preview…') }}</span>
					</div>
					<div :class="$style.canvasViewport">
						<div
							:class="$style.canvasWrapper"
							:style="canvasWrapperStyle"
							data-test="enhance-canvas-wrapper">
							<canvas ref="canvasRef" :class="$style.pageCanvas" />
						</div>
					</div>
				</div>

				<!-- Right: Operations Stack Panel -->
				<SongPdfEnhanceStack
					:operations="operations"
					@apply-preset="applyPreset"
					@add-operation="addOperation"
					@toggle-operation="toggleOperation"
					@move-operation="moveOperation"
					@remove-operation="removeOperation"
					@input="renderEnhancedPreview"
					@change="recordHistory" />
			</div>

			<!-- Dialog Footer Actions -->
			<div :class="$style.dialogFooter">
				<div :class="$style.scopeSelection">
					<span :class="$style.scopeLabel">{{ t('sheetmusic', 'Apply to:') }}</span>
					<label :class="$style.radioLabel">
						<input v-model="applyScope" type="radio" value="all" />
						{{ t('sheetmusic', 'All Pages ({total})', { total: totalPages }, { escape: false }) }}
					</label>
					<label :class="$style.radioLabel">
						<input v-model="applyScope" type="radio" value="current" />
						{{ t('sheetmusic', 'Current Page Only (Page {page})', { page: pageNum }, { escape: false }) }}
					</label>
				</div>

				<div :class="$style.footerBtns">
					<NcButton variant="tertiary" @click="emit('update:open', false)">
						{{ t('sheetmusic', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="operations.length === 0" @click="handleApply">
						{{ t('sheetmusic', 'Apply Enhancements') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.enhanceModal :global(.modal-wrapper > .modal-container) {
	width: 96vw !important;
	max-width: 1800px !important;
	height: 92vh !important;
	max-height: 92vh !important;
}

.enhanceModal :global(.modal-container__content) {
	height: 100%;
}

.enhanceModal :global(.dialog__name) {
	margin-block: 0 4px;
	font-size: 17px;
	min-height: 32px;
	line-height: 32px;
}

.dialogBody {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 4px 8px 8px;
	width: calc(96vw - 36px);
	max-width: 1760px;
	min-width: 0;
	height: calc(92vh - 54px);
	min-height: 600px;
	max-height: none;
	box-sizing: border-box;
	overflow: hidden;
}

.toolBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px;
	padding: 6px 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	flex-shrink: 0;
	min-width: 0;
}

.pageNav {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pageIndicator {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
	white-space: nowrap;
}

.dpiBadge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	margin-left: 6px;
	border-radius: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	font-weight: 500;
	white-space: nowrap;
}

.zoomControls {
	display: flex;
	align-items: center;
	gap: 4px;
}

.zoomIndicator {
	font-size: 12px;
	font-weight: 600;
	min-width: 42px;
	text-align: center;
	color: var(--color-main-text);
}

.toolActions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.workspace {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(300px, 360px);
	gap: 16px;
	flex: 1;
	width: 100%;
	min-width: 0;
	min-height: 0;
	overflow: hidden;
}

.previewPane {
	position: relative;
	background: #1e1e1e;
	border-radius: var(--border-radius-large);
	overflow: hidden;
	width: 100%;
	height: 100%;
	min-width: 0;
	min-height: 0;
	box-sizing: border-box;
	user-select: none;
}

.previewPaneGrab {
	cursor: grab;
}

.previewPaneGrabbing {
	cursor: grabbing;
}

.canvasViewport {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	padding: 8px;
	box-sizing: border-box;
}

.canvasWrapper {
	position: relative;
	flex-shrink: 0;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
}

.pageCanvas {
	display: block;
	width: 100%;
	height: 100%;
}

.loadingOverlay {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, 0.6);
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	color: #ffffff;
	z-index: 10;
}

.dialogFooter {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	flex-shrink: 0;
	min-width: 0;
}

.scopeSelection {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
}

.scopeLabel {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
}

.radioLabel {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
	cursor: pointer;
}

.footerBtns {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
