<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import Tune from 'vue-material-design-icons/Tune.vue'

import type { AdaptiveThresholdOp, GaussianOp, GrayLevelMappingOp, ImageBuffer, ScanOperation } from './scan-types'
import {
	applyPipeline,
	createImageBuffer,
	imageBufferToImageData,
	imageDataToImageBuffer,
} from './scan-enhance-engine'
import { ScanHistoryManager } from './scan-history'

const props = defineProps<{
	open: boolean
	pageNum: number
	totalPages: number
	pdf: any
	initialOperations?: ScanOperation[] | null
	rotation?: number
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'apply': [operations: ScanOperation[], applyTo: 'current' | 'all']
	'change-page': [newPage: number]
}>()

const canvasRef = ref<HTMLCanvasElement>()
const isLoadingPage = ref(false)
const showOriginal = ref(false)

// Display sizing
const canvasWidth = ref(0)
const canvasHeight = ref(0)

// Cached rendered raw page image buffer before enhancement
let originalBuffer: ImageBuffer | null = null

// Operation stack and undo/redo
const operations = ref<ScanOperation[]>([])
const historyManager = new ScanHistoryManager()
const canUndo = ref(false)
const canRedo = ref(false)

const applyScope = ref<'current' | 'all'>('all')

function syncHistoryState() {
	canUndo.value = historyManager.canUndo()
	canRedo.value = historyManager.canRedo()
}

function recordHistory() {
	historyManager.push(operations.value)
	syncHistoryState()
}

// Generate unique ID for operations
let opCounter = 0
function generateOpId(): string {
	return `op-${Date.now()}-${++opCounter}`
}

// Default presets
function applyPreset(preset: 'standard' | 'contrast' | 'colorClean' | 'binarize') {
	let newOps: ScanOperation[] = []
	if (preset === 'standard') {
		newOps = [
			{ id: generateOpId(), type: 'gaussian', size: 3, enabled: true },
			{ id: generateOpId(), type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 215, colorMode: 'grayscale', enabled: true },
			{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 25, delta: 12, blackValue: 0, whiteValue: 255, enabled: true },
		]
	} else if (preset === 'contrast') {
		newOps = [
			{ id: generateOpId(), type: 'grayLevelMapping', lowerCutoff: 35, upperCutoff: 220, colorMode: 'color', enabled: true },
		]
	} else if (preset === 'colorClean') {
		newOps = [
			{ id: generateOpId(), type: 'gaussian', size: 3, enabled: true },
			{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 25, delta: 15, blackValue: 0, whiteValue: 255, colorMode: 'preserveColor', enabled: true },
		]
	} else if (preset === 'binarize') {
		newOps = [
			{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 21, delta: 10, blackValue: 0, whiteValue: 255, colorMode: 'binarize', enabled: true },
		]
	}

	operations.value = newOps
	recordHistory()
	renderEnhancedPreview()
}

// Adding operations
function addOperation(type: 'gaussian' | 'grayLevelMapping' | 'adaptiveThreshold') {
	let newOp: ScanOperation
	if (type === 'gaussian') {
		newOp = { id: generateOpId(), type: 'gaussian', size: 3, enabled: true }
	} else if (type === 'grayLevelMapping') {
		newOp = { id: generateOpId(), type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 220, colorMode: 'color', enabled: true }
	} else {
		newOp = { id: generateOpId(), type: 'adaptiveThreshold', windowSize: 25, delta: 10, blackValue: 0, whiteValue: 255, colorMode: 'binarize', enabled: true }
	}

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

// Rendering
async function renderPage() {
	if (!props.pdf || !canvasRef.value) return
	isLoadingPage.value = true

	try {
		const page = await props.pdf.getPage(props.pageNum)
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: props.rotation || 0 })
		const maxWidth = 580
		const maxHeight = 620
		const scale = Math.min(maxWidth / unscaledViewport.width, maxHeight / unscaledViewport.height, 2.0)
		const viewport = page.getViewport({ scale, rotation: props.rotation || 0 })

		const canvas = canvasRef.value
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		canvasWidth.value = canvas.width
		canvasHeight.value = canvas.height

		const ctx = canvas.getContext('2d')
		if (ctx) {
			ctx.fillStyle = '#ffffff'
			ctx.fillRect(0, 0, canvas.width, canvas.height)
			await page.render({
				canvasContext: ctx,
				viewport,
				background: 'rgb(255, 255, 255)',
			}).promise

			const rawImageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
			originalBuffer = imageDataToImageBuffer(rawImageData)
			renderEnhancedPreview()
		}
	} catch (e) {
		console.warn(`Failed to render enhance preview for page ${props.pageNum}:`, e)
	} finally {
		isLoadingPage.value = false
	}
}

function renderEnhancedPreview() {
	if (!canvasRef.value || !originalBuffer) return
	const ctx = canvasRef.value.getContext('2d')
	if (!ctx) return

	if (showOriginal.value || operations.value.filter((op) => op.enabled).length === 0) {
		const origImgData = imageBufferToImageData(originalBuffer)
		ctx.putImageData(origImgData, 0, 0)
		return
	}

	const enhanced = applyPipeline(originalBuffer, operations.value)
	const enhancedImgData = imageBufferToImageData(enhanced)
	ctx.putImageData(enhancedImgData, 0, 0)
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

onMounted(() => {
	window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
	window.removeEventListener('keydown', handleKeydown)
})

watch(
	() => props.open,
	async (isOpen) => {
		if (isOpen) {
			const initial = Array.isArray(props.initialOperations)
				? JSON.parse(JSON.stringify(props.initialOperations))
				: [
					{ id: generateOpId(), type: 'gaussian', size: 3, enabled: true },
					{ id: generateOpId(), type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 215, colorMode: 'grayscale', enabled: true },
					{ id: generateOpId(), type: 'adaptiveThreshold', windowSize: 25, delta: 12, blackValue: 0, whiteValue: 255, enabled: true },
				]
			operations.value = initial
			historyManager.clear(initial)
			syncHistoryState()
			await nextTick()
			renderPage()
		}
	},
	{ immediate: true },
)

watch(
	() => [props.pageNum, props.rotation],
	() => {
		if (props.open) {
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
		:name="t('sheetmusic', 'Enhance Scan Quality — Page {page} of {total}', { page: pageNum, total: totalPages })"
		@update:open="emit('update:open', $event)">
		<div :class="$style.dialogBody">
			<!-- Header Toolbar -->
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
						:variant="showOriginal ? 'primary' : 'secondary'"
						:title="t('sheetmusic', 'Toggle between original and enhanced preview')"
						@click="toggleShowOriginal">
						<template #icon>
							<EyeOffOutline v-if="showOriginal" :size="16" />
							<EyeOutline v-else :size="16" />
						</template>
						{{ showOriginal ? t('sheetmusic', 'Viewing Original') : t('sheetmusic', 'Compare Original') }}
					</NcButton>

					<NcButton
						size="small"
						variant="secondary"
						:disabled="!canUndo"
						:title="t('sheetmusic', 'Undo (Ctrl+Z)')"
						@click="handleUndo">
						<template #icon>
							<Undo :size="16" />
						</template>
					</NcButton>

					<NcButton
						size="small"
						variant="secondary"
						:disabled="!canRedo"
						:title="t('sheetmusic', 'Redo (Ctrl+Y)')"
						@click="handleRedo">
						<template #icon>
							<Redo :size="16" />
						</template>
					</NcButton>

					<NcButton
						size="small"
						variant="tertiary"
						:title="t('sheetmusic', 'Clear all operations')"
						@click="resetAll">
						<template #icon>
							<RestoreIcon :size="16" />
						</template>
						{{ t('sheetmusic', 'Reset') }}
					</NcButton>
				</div>
			</div>

			<!-- Main Content Area: Left Preview Canvas, Right Operations Stack -->
			<div :class="$style.workspace">
				<!-- Left: Preview Canvas -->
				<div :class="$style.previewPane">
					<div v-if="isLoadingPage" :class="$style.loadingOverlay">
						<NcLoadingIcon :size="36" />
						<span>{{ t('sheetmusic', 'Rendering page preview…') }}</span>
					</div>
					<div :class="$style.canvasWrapper">
						<canvas ref="canvasRef" :class="$style.pageCanvas" />
					</div>
				</div>

				<!-- Right: Operations Stack Panel -->
				<div :class="$style.stackPane">
					<div :class="$style.stackHeader">
						<span :class="$style.stackTitle">
							<Tune :size="18" />
							{{ t('sheetmusic', 'Operation Stack ({count})', { count: operations.length }) }}
						</span>
						<!-- Presets -->
						<div :class="$style.presetBar">
							<span :class="$style.subLabel">{{ t('sheetmusic', 'Presets:') }}</span>
							<button type="button" :class="$style.presetChip" @click="applyPreset('standard')">
								{{ t('sheetmusic', 'Clean Sheet') }}
							</button>
							<button type="button" :class="$style.presetChip" @click="applyPreset('colorClean')">
								{{ t('sheetmusic', 'Color Notes') }}
							</button>
							<button type="button" :class="$style.presetChip" @click="applyPreset('contrast')">
								{{ t('sheetmusic', 'Curves Only') }}
							</button>
							<button type="button" :class="$style.presetChip" @click="applyPreset('binarize')">
								{{ t('sheetmusic', 'B&W') }}
							</button>
						</div>
					</div>

					<!-- Operation Cards List -->
					<div :class="$style.operationsList">
						<div
							v-for="(op, idx) in operations"
							:key="op.id"
							:class="[$style.opCard, !op.enabled && $style.opCardDisabled]">
							<!-- Op Card Header -->
							<div :class="$style.opHeader">
								<div :class="$style.opTitleRow">
									<input
										type="checkbox"
										:checked="op.enabled"
										:title="t('sheetmusic', 'Toggle operation')"
										@change="toggleOperation(idx)" />
									<strong :class="$style.opName">
										{{ idx + 1 }}.
										{{
											op.type === 'gaussian'
												? t('sheetmusic', 'Gaussian Filter')
												: op.type === 'grayLevelMapping'
													? t('sheetmusic', 'Gray Level Mapping (Curves)')
													: t('sheetmusic', 'Adaptive Thresholding')
										}}
									</strong>
								</div>

								<div :class="$style.opControls">
									<button
										type="button"
										:class="$style.iconBtn"
										:disabled="idx === 0"
										:title="t('sheetmusic', 'Move up')"
										@click="moveOperation(idx, 'up')">
										<ArrowUp :size="16" />
									</button>
									<button
										type="button"
										:class="$style.iconBtn"
										:disabled="idx === operations.length - 1"
										:title="t('sheetmusic', 'Move down')"
										@click="moveOperation(idx, 'down')">
										<ArrowDown :size="16" />
									</button>
									<button
										type="button"
										:class="[$style.iconBtn, $style.deleteBtn]"
										:title="t('sheetmusic', 'Remove operation')"
										@click="removeOperation(idx)">
										<DeleteOutline :size="16" />
									</button>
								</div>
							</div>

							<!-- Op Parameters -->
							<div :class="$style.opParams">
								<!-- Gaussian Filter Parameters -->
								<template v-if="op.type === 'gaussian'">
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'Filter Kernel Size (px):') }}
											<strong>{{ (op as GaussianOp).size }}</strong>
										</label>
										<input
											v-model.number="(op as GaussianOp).size"
											type="range"
											min="1"
											max="15"
											step="2"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
								</template>

								<!-- Gray Level Mapping Parameters -->
								<template v-else-if="op.type === 'grayLevelMapping'">
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'Black Cutoff (Lower):') }}
											<strong>{{ (op as GrayLevelMappingOp).lowerCutoff }}</strong>
										</label>
										<input
											v-model.number="(op as GrayLevelMappingOp).lowerCutoff"
											type="range"
											min="0"
											max="255"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'White Cutoff (Upper):') }}
											<strong>{{ (op as GrayLevelMappingOp).upperCutoff }}</strong>
										</label>
										<input
											v-model.number="(op as GrayLevelMappingOp).upperCutoff"
											type="range"
											min="0"
											max="255"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">{{ t('sheetmusic', 'Color Mode:') }}</label>
										<select
											v-model="(op as GrayLevelMappingOp).colorMode"
											:class="$style.select"
											@change="renderEnhancedPreview(); recordHistory()">
											<option value="color">{{ t('sheetmusic', 'Preserve Color Tones') }}</option>
											<option value="grayscale">{{ t('sheetmusic', 'Convert to Grayscale') }}</option>
										</select>
									</div>
								</template>

								<!-- Adaptive Thresholding Parameters -->
								<template v-else-if="op.type === 'adaptiveThreshold'">
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'Neighborhood Window Size:') }}
											<strong>{{ (op as AdaptiveThresholdOp).windowSize }}</strong>
										</label>
										<input
											v-model.number="(op as AdaptiveThresholdOp).windowSize"
											type="range"
											min="3"
											max="61"
											step="2"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'Mean Offset Delta:') }}
											<strong>{{ (op as AdaptiveThresholdOp).delta }}</strong>
										</label>
										<input
											v-model.number="(op as AdaptiveThresholdOp).delta"
											type="range"
											min="-20"
											max="40"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'Custom Black Limit (Ink):') }}
											<strong>{{ (op as AdaptiveThresholdOp).blackValue }}</strong>
										</label>
										<input
											v-model.number="(op as AdaptiveThresholdOp).blackValue"
											type="range"
											min="0"
											max="100"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">
											{{ t('sheetmusic', 'Custom White Limit (Paper):') }}
											<strong>{{ (op as AdaptiveThresholdOp).whiteValue }}</strong>
										</label>
										<input
											v-model.number="(op as AdaptiveThresholdOp).whiteValue"
											type="range"
											min="150"
											max="255"
											:class="$style.slider"
											@input="renderEnhancedPreview"
											@change="recordHistory" />
									</div>
									<div :class="$style.paramRow">
										<label :class="$style.paramLabel">{{ t('sheetmusic', 'Output Mode:') }}</label>
										<select
											v-model="(op as AdaptiveThresholdOp).colorMode"
											:class="$style.select"
											@change="renderEnhancedPreview(); recordHistory()">
											<option value="binarize">{{ t('sheetmusic', 'Binarize (Black & White)') }}</option>
											<option value="preserveColor">{{ t('sheetmusic', 'Whiten Paper, Keep Colored Notes') }}</option>
										</select>
									</div>
								</template>
							</div>
						</div>

						<div v-if="operations.length === 0" :class="$style.emptyStack">
							<span>{{ t('sheetmusic', 'No operations stacked. Click "+ Add Operation" below or select a preset.') }}</span>
						</div>
					</div>

					<!-- Add Operation Buttons -->
					<div :class="$style.addOpBar">
						<span :class="$style.subLabel">{{ t('sheetmusic', 'Add Step:') }}</span>
						<NcButton size="small" variant="secondary" @click="addOperation('gaussian')">
							<template #icon><Plus :size="16" /></template>
							{{ t('sheetmusic', 'Gaussian Filter') }}
						</NcButton>
						<NcButton size="small" variant="secondary" @click="addOperation('grayLevelMapping')">
							<template #icon><Plus :size="16" /></template>
							{{ t('sheetmusic', 'Curves / Levels') }}
						</NcButton>
						<NcButton size="small" variant="secondary" @click="addOperation('adaptiveThreshold')">
							<template #icon><Plus :size="16" /></template>
							{{ t('sheetmusic', 'Adaptive Threshold') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Dialog Footer Actions -->
			<div :class="$style.dialogFooter">
				<div :class="$style.scopeSelection">
					<span :class="$style.scopeLabel">{{ t('sheetmusic', 'Apply to:') }}</span>
					<label :class="$style.radioLabel">
						<input v-model="applyScope" type="radio" value="all" />
						{{ t('sheetmusic', 'All Pages ({total})', { total: totalPages }) }}
					</label>
					<label :class="$style.radioLabel">
						<input v-model="applyScope" type="radio" value="current" />
						{{ t('sheetmusic', 'Current Page Only (Page {page})', { page: pageNum }) }}
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
.dialogBody {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
	min-width: 900px;
	max-width: 1100px;
}

.toolBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.pageNav {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pageIndicator {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.toolActions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.workspace {
	display: grid;
	grid-template-columns: 1fr 380px;
	gap: 16px;
	min-height: 520px;
	max-height: 640px;
}

.previewPane {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #1e1e1e;
	border-radius: var(--border-radius-large);
	overflow: hidden;
	padding: 12px;
}

.canvasWrapper {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
}

.pageCanvas {
	display: block;
	max-width: 100%;
	max-height: 580px;
	object-fit: contain;
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

.stackPane {
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	overflow: hidden;
}

.stackHeader {
	display: flex;
	flex-direction: column;
	gap: 6px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.stackTitle {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 700;
	font-size: 14px;
}

.presetBar {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.subLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-right: 4px;
}

.presetChip {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 2px 8px;
	font-size: 11px;
	cursor: pointer;
	color: var(--color-main-text);
}

.presetChip:hover {
	background: var(--color-primary-light);
	color: var(--color-primary);
}

.operationsList {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-right: 4px;
}

.opCard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.opCardDisabled {
	opacity: 0.55;
}

.opHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.opTitleRow {
	display: flex;
	align-items: center;
	gap: 6px;
}

.opName {
	font-size: 13px;
	color: var(--color-main-text);
}

.opControls {
	display: flex;
	align-items: center;
	gap: 2px;
}

.iconBtn {
	background: transparent;
	border: none;
	cursor: pointer;
	padding: 2px 4px;
	border-radius: 4px;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
}

.iconBtn:hover:not(:disabled) {
	background: var(--color-border);
	color: var(--color-main-text);
}

.iconBtn:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.deleteBtn:hover {
	color: var(--color-error);
}

.opParams {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-top: 4px;
	border-top: 1px solid var(--color-border-maxcontrast);
}

.paramRow {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.paramLabel {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	display: flex;
	justify-content: space-between;
}

.slider {
	width: 100%;
	height: 4px;
	cursor: pointer;
}

.select {
	padding: 4px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	font-size: 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.emptyStack {
	padding: 24px 12px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.addOpBar {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.dialogFooter {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.scopeSelection {
	display: flex;
	align-items: center;
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
