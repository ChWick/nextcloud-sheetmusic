<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import ContentCut from 'vue-material-design-icons/ContentCut.vue'
import type { PageCrop } from './crop-types'

const props = defineProps<{
	open: boolean
	pageNum: number
	totalPages: number
	pdf: any
	initialCrop?: PageCrop | null
	allPagesCount: number
	rotation?: number
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'apply': [crop: PageCrop, applyTo: 'current' | 'all']
	'change-page': [newPage: number]
}>()

const canvasRef = ref<HTMLCanvasElement>()
const overlayRef = ref<HTMLDivElement>()
const isLoadingPage = ref(false)

// Display & coordinate mapping
const canvasWidth = ref(0)
const canvasHeight = ref(0)

// Normalized crop box: fractions [0..1] relative to displayed canvas width & height
// cropLeft, cropTop, cropWidth, cropHeight
const cropBox = ref<PageCrop>({
	left: 0,
	top: 0,
	width: 1,
	height: 1,
})

const applyScope = ref<'current' | 'all'>('current')

// Drag / resize state
type DragHandle = 'move' | 'nw' | 'ne' | 'se' | 'sw' | 'n' | 'e' | 's' | 'w' | 'create' | null
const activeDrag = ref<DragHandle>(null)
let startX = 0
let startY = 0
let startBox: PageCrop = { left: 0, top: 0, width: 1, height: 1 }

const isCustomCropped = computed(() => {
	const c = cropBox.value
	return (
		c.left > 0.005 ||
		c.top > 0.005 ||
		c.width < 0.995 ||
		c.height < 0.995
	)
})

// Visual overlay box style (in pixels relative to overlayRef)
const overlayBoxStyle = computed(() => {
	const w = canvasWidth.value
	const h = canvasHeight.value
	const left = Math.round(cropBox.value.left * w)
	const top = Math.round(cropBox.value.top * h)
	const width = Math.round(cropBox.value.width * w)
	const height = Math.round(cropBox.value.height * h)

	return {
		left: `${left}px`,
		top: `${top}px`,
		width: `${width}px`,
		height: `${height}px`,
	}
})

// Crop box margins in % for feedback display
const cropSummary = computed(() => {
	const top = Math.round(cropBox.value.top * 100)
	const right = Math.round((1 - (cropBox.value.left + cropBox.value.width)) * 100)
	const bottom = Math.round((1 - (cropBox.value.top + cropBox.value.height)) * 100)
	const left = Math.round(cropBox.value.left * 100)
	return { top, right, bottom, left }
})

async function renderPage() {
	if (!props.pdf || !canvasRef.value) return
	isLoadingPage.value = true

	try {
		const page = await props.pdf.getPage(props.pageNum)
		// We use a high resolution viewport to ensure clear text when cropping
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: props.rotation || 0 })
		// Fit inside viewport (max width ~560px, max height ~620px)
		const maxWidth = 560
		const maxHeight = 620
		const scale = Math.min(maxWidth / unscaledViewport.width, maxHeight / unscaledViewport.height, 2.0)
		const viewport = page.getViewport({ scale, rotation: props.rotation || 0 })

		const canvas = canvasRef.value
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		canvasWidth.value = canvas.width
		canvasHeight.value = canvas.height

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
	} catch (e) {
		console.warn(`Failed to render crop preview for page ${props.pageNum}:`, e)
	} finally {
		isLoadingPage.value = false
	}
}

function resetCrop() {
	cropBox.value = {
		left: 0,
		top: 0,
		width: 1,
		height: 1,
	}
}

function applyPreset(marginPercent: number) {
	const m = marginPercent / 100
	cropBox.value = {
		left: m,
		top: m,
		width: 1 - 2 * m,
		height: 1 - 2 * m,
	}
}

function handlePointerDown(handle: DragHandle, e: PointerEvent) {
	e.preventDefault()
	e.stopPropagation()
	if (!overlayRef.value) return

	activeDrag.value = handle
	const rect = overlayRef.value.getBoundingClientRect()
	startX = (e.clientX - rect.left) / rect.width
	startY = (e.clientY - rect.top) / rect.height
	startBox = { ...cropBox.value }

	if (handle === 'create') {
		// Start a new rectangle from pointer position
		cropBox.value = {
			left: Math.max(0, Math.min(1, startX)),
			top: Math.max(0, Math.min(1, startY)),
			width: 0,
			height: 0,
		}
	}

	window.addEventListener('pointermove', onPointerMove)
	window.addEventListener('pointerup', onPointerUp)
}

function onPointerMove(e: PointerEvent) {
	if (!activeDrag.value || !overlayRef.value) return
	const rect = overlayRef.value.getBoundingClientRect()
	const curX = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
	const curY = Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height))
	const dx = curX - startX
	const dy = curY - startY

	const minDim = 0.05 // Minimum 5% width & height

	if (activeDrag.value === 'create') {
		const left = Math.min(startX, curX)
		const top = Math.min(startY, curY)
		const width = Math.max(minDim, Math.abs(curX - startX))
		const height = Math.max(minDim, Math.abs(curY - startY))
		cropBox.value = {
			left: Math.max(0, Math.min(1 - minDim, left)),
			top: Math.max(0, Math.min(1 - minDim, top)),
			width: Math.min(1 - left, width),
			height: Math.min(1 - top, height),
		}
		return
	}

	if (activeDrag.value === 'move') {
		let newLeft = startBox.left + dx
		let newTop = startBox.top + dy

		if (newLeft < 0) newLeft = 0
		if (newTop < 0) newTop = 0
		if (newLeft + startBox.width > 1) newLeft = 1 - startBox.width
		if (newTop + startBox.height > 1) newTop = 1 - startBox.height

		cropBox.value = {
			...cropBox.value,
			left: newLeft,
			top: newTop,
		}
		return
	}

	let { left, top, width, height } = startBox
	const right = left + width
	const bottom = top + height

	if (activeDrag.value.includes('w')) {
		left = Math.min(right - minDim, Math.max(0, startBox.left + dx))
		width = right - left
	}
	if (activeDrag.value.includes('e')) {
		const newRight = Math.max(left + minDim, Math.min(1, right + dx))
		width = newRight - left
	}
	if (activeDrag.value.includes('n')) {
		top = Math.min(bottom - minDim, Math.max(0, startBox.top + dy))
		height = bottom - top
	}
	if (activeDrag.value.includes('s')) {
		const newBottom = Math.max(top + minDim, Math.min(1, bottom + dy))
		height = newBottom - top
	}

	cropBox.value = { left, top, width, height }
}

function onPointerUp() {
	activeDrag.value = null
	window.removeEventListener('pointermove', onPointerMove)
	window.removeEventListener('pointerup', onPointerUp)

	// Ensure sanitized box
	const b = cropBox.value
	cropBox.value = {
		left: Math.max(0, Math.min(0.95, b.left)),
		top: Math.max(0, Math.min(0.95, b.top)),
		width: Math.max(0.05, Math.min(1 - b.left, b.width)),
		height: Math.max(0.05, Math.min(1 - b.top, b.height)),
	}
}

function handleApply() {
	emit('apply', cropBox.value, applyScope.value)
	emit('update:open', false)
}

function handleClose() {
	emit('update:open', false)
}

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

watch(
	() => [props.open, props.pageNum, props.rotation],
	async ([isOpen]) => {
		if (isOpen) {
			if (props.initialCrop) {
				cropBox.value = { ...props.initialCrop }
			} else {
				resetCrop()
			}
			await nextTick()
			renderPage()
		}
	},
	{ immediate: true },
)

onUnmounted(() => {
	window.removeEventListener('pointermove', onPointerMove)
	window.removeEventListener('pointerup', onPointerUp)
})
</script>

<template>
	<NcDialog
		v-if="open"
		:open="open"
		size="large"
		:can-close="true"
		:name="t('sheetmusic', 'Crop Page {page} of {total}', { page: pageNum, total: totalPages })"
		@update:open="emit('update:open', $event)">
		<div :class="$style.dialogBody">
			<!-- Header / Instruction Bar -->
			<div :class="$style.toolBar">
				<div :class="$style.pageNav">
					<NcButton
						size="small"
						variant="secondary"
						:disabled="pageNum <= 1"
						:title="t('sheetmusic', 'Previous page')"
						@click="prevPage">
						<template #icon>
							<ArrowLeft :size="16" />
						</template>
					</NcButton>
					<span :class="$style.pageIndicator">
						{{ t('sheetmusic', 'Page {page} of {total}', { page: pageNum, total: totalPages }) }}
					</span>
					<NcButton
						size="small"
						variant="secondary"
						:disabled="pageNum >= totalPages"
						:title="t('sheetmusic', 'Next page')"
						@click="nextPage">
						<template #icon>
							<ArrowRight :size="16" />
						</template>
					</NcButton>
				</div>

				<div :class="$style.toolActions">
					<NcButton
						size="small"
						variant="secondary"
						:title="t('sheetmusic', 'Trim 5% margin from all edges')"
						@click="applyPreset(5)">
						{{ t('sheetmusic', 'Trim 5%') }}
					</NcButton>
					<NcButton
						size="small"
						variant="secondary"
						:title="t('sheetmusic', 'Trim 10% margin from all edges')"
						@click="applyPreset(10)">
						{{ t('sheetmusic', 'Trim 10%') }}
					</NcButton>
					<NcButton
						size="small"
						variant="tertiary"
						:disabled="!isCustomCropped"
						:title="t('sheetmusic', 'Reset to full page')"
						@click="resetCrop">
						<template #icon>
							<RestoreIcon :size="16" />
						</template>
						{{ t('sheetmusic', 'Reset to Full Page') }}
					</NcButton>
				</div>
			</div>

			<!-- Main Crop Workspace -->
			<div :class="$style.workspace">
				<div
					v-if="isLoadingPage"
					:class="$style.loadingOverlay">
					<NcLoadingIcon :size="36" />
				</div>

				<div
					:class="$style.canvasWrapper"
					:style="{ width: `${canvasWidth}px`, height: `${canvasHeight}px` }">
					<!-- Rendered PDF Canvas -->
					<canvas ref="canvasRef" :class="$style.pageCanvas" />

					<!-- Interactive Drag & Selection Overlay -->
					<div
						ref="overlayRef"
						:class="$style.interactiveOverlay"
						@pointerdown="handlePointerDown('create', $event)">
						<!-- Darkened Backdrop Mask around the active crop area -->
						<!-- Top shade -->
						<div
							:class="$style.shade"
							:style="{ top: 0, left: 0, right: 0, height: `${cropBox.top * 100}%` }" />
						<!-- Bottom shade -->
						<div
							:class="$style.shade"
							:style="{ top: `${(cropBox.top + cropBox.height) * 100}%`, left: 0, right: 0, bottom: 0 }" />
						<!-- Left shade -->
						<div
							:class="$style.shade"
							:style="{
								top: `${cropBox.top * 100}%`,
								left: 0,
								width: `${cropBox.left * 100}%`,
								height: `${cropBox.height * 100}%`
							}" />
						<!-- Right shade -->
						<div
							:class="$style.shade"
							:style="{
								top: `${cropBox.top * 100}%`,
								left: `${(cropBox.left + cropBox.width) * 100}%`,
								right: 0,
								height: `${cropBox.height * 100}%`
							}" />

						<!-- The Interactive Crop Box -->
						<div
							:class="[$style.cropBox, isCustomCropped && $style.cropBoxActive]"
							:style="overlayBoxStyle"
							@pointerdown.stop="handlePointerDown(isCustomCropped ? 'move' : 'create', $event)">
							<!-- Crop Grid Guidelines (Rule of thirds) -->
							<div :class="$style.gridH1" />
							<div :class="$style.gridH2" />
							<div :class="$style.gridV1" />
							<div :class="$style.gridV2" />

							<!-- Resizing Corner Handles -->
							<div
								:class="[$style.handle, $style.handleNw]"
								@pointerdown.stop="handlePointerDown('nw', $event)" />
							<div
								:class="[$style.handle, $style.handleNe]"
								@pointerdown.stop="handlePointerDown('ne', $event)" />
							<div
								:class="[$style.handle, $style.handleSe]"
								@pointerdown.stop="handlePointerDown('se', $event)" />
							<div
								:class="[$style.handle, $style.handleSw]"
								@pointerdown.stop="handlePointerDown('sw', $event)" />

							<!-- Resizing Edge Handles -->
							<div
								:class="[$style.handle, $style.handleN]"
								@pointerdown.stop="handlePointerDown('n', $event)" />
							<div
								:class="[$style.handle, $style.handleE]"
								@pointerdown.stop="handlePointerDown('e', $event)" />
							<div
								:class="[$style.handle, $style.handleS]"
								@pointerdown.stop="handlePointerDown('s', $event)" />
							<div
								:class="[$style.handle, $style.handleW]"
								@pointerdown.stop="handlePointerDown('w', $event)" />
						</div>
					</div>
				</div>
			</div>

			<!-- Footer Settings & Actions -->
			<div :class="$style.footer">
				<div :class="$style.scopeSelector">
					<span :class="$style.scopeLabel">{{ t('sheetmusic', 'Apply crop to:') }}</span>
					<label :class="$style.radioOption">
						<input
							v-model="applyScope"
							type="radio"
							value="current"
							name="cropScope">
						<span>{{ t('sheetmusic', 'Page {page} only', { page: pageNum }) }}</span>
					</label>
					<label :class="$style.radioOption">
						<input
							v-model="applyScope"
							type="radio"
							value="all"
							name="cropScope">
						<span>{{ t('sheetmusic', 'All pages ({count})', { count: allPagesCount }) }}</span>
					</label>
				</div>

				<div :class="$style.actionButtons">
					<NcButton
						variant="secondary"
						@click="handleClose">
						{{ t('sheetmusic', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						@click="handleApply">
						<template #icon>
							<CheckIcon :size="18" />
						</template>
						{{ t('sheetmusic', 'Apply Crop') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.dialogBody {
	display: flex;
	flex-direction: column;
	max-height: 85vh;
	gap: 12px;
	padding: 8px 16px 16px;
}

.toolBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 4px 8px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
}

.pageNav {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pageIndicator {
	font-size: 13px;
	font-weight: 600;
}

.toolActions {
	display: flex;
	gap: 8px;
}

.workspace {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 380px;
	max-height: 640px;
	background-color: #2b2b2b;
	border-radius: 6px;
	padding: 16px;
	overflow: auto;
}

.loadingOverlay {
	position: absolute;
	inset: 0;
	background-color: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 20;
}

.canvasWrapper {
	position: relative;
	box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
	user-select: none;
	touch-action: none;
}

.pageCanvas {
	display: block;
	width: 100%;
	height: 100%;
}

.interactiveOverlay {
	position: absolute;
	inset: 0;
	cursor: crosshair;
}

.shade {
	position: absolute;
	background-color: rgba(0, 0, 0, 0.55);
	pointer-events: none;
}

.cropBox {
	position: absolute;
	box-sizing: border-box;
	border: 2px dashed #ffffff;
	box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.5);
	cursor: move;
}

.cropBoxActive {
	border: 2px solid var(--color-primary-element, #0082c9);
}

.gridH1,
.gridH2,
.gridV1,
.gridV2 {
	position: absolute;
	pointer-events: none;
	border-color: rgba(255, 255, 255, 0.25);
}

.gridH1 {
	top: 33.33%;
	left: 0;
	right: 0;
	border-top: 1px dashed rgba(255, 255, 255, 0.35);
}

.gridH2 {
	top: 66.66%;
	left: 0;
	right: 0;
	border-top: 1px dashed rgba(255, 255, 255, 0.35);
}

.gridV1 {
	left: 33.33%;
	top: 0;
	bottom: 0;
	border-left: 1px dashed rgba(255, 255, 255, 0.35);
}

.gridV2 {
	left: 66.66%;
	top: 0;
	bottom: 0;
	border-left: 1px dashed rgba(255, 255, 255, 0.35);
}

/* Corner Handles */
.handle {
	position: absolute;
	width: 14px;
	height: 14px;
	background-color: var(--color-primary-element, #0082c9);
	border: 2px solid #ffffff;
	border-radius: 2px;
	box-sizing: border-box;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
}

.handleNw {
	top: -7px;
	left: -7px;
	cursor: nwse-resize;
}

.handleNe {
	top: -7px;
	right: -7px;
	cursor: nesw-resize;
}

.handleSe {
	bottom: -7px;
	right: -7px;
	cursor: nwse-resize;
}

.handleSw {
	bottom: -7px;
	left: -7px;
	cursor: nesw-resize;
}

/* Edge handles */
.handleN {
	top: -7px;
	left: calc(50% - 7px);
	cursor: ns-resize;
}

.handleS {
	bottom: -7px;
	left: calc(50% - 7px);
	cursor: ns-resize;
}

.handleW {
	left: -7px;
	top: calc(50% - 7px);
	cursor: ew-resize;
}

.handleE {
	right: -7px;
	top: calc(50% - 7px);
	cursor: ew-resize;
}

.footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
	flex-wrap: wrap;
	gap: 12px;
}

.scopeSelector {
	display: flex;
	align-items: center;
	gap: 16px;
}

.scopeLabel {
	font-size: 13px;
	font-weight: 600;
}

.radioOption {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	cursor: pointer;
}

.actionButtons {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-left: auto;
}
</style>

