<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { cleanupPdfJsPage, renderPdfPageToCanvas } from '../../utils/pdfjs'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import Grid from 'vue-material-design-icons/Grid.vue'
import SongPdfRotateControls from './SongPdfRotateControls.vue'
import type { PageCrop } from './crop-types'

const props = defineProps<{
	open: boolean
	pageNum: number
	totalPages: number
	pdf: any
	initialAngle?: number
	initialCropToOriginalSize?: boolean
	allPagesCount: number
	crop?: PageCrop | null
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'apply': [angle: number, scope: 'current' | 'all', cropToOriginalSize: boolean]
	'change-page': [newPage: number]
}>()

const canvasRef = ref<HTMLCanvasElement>()
const isLoadingPage = ref(false)

const canvasWidth = ref(0)
const canvasHeight = ref(0)

// Current angle in degrees (clockwise in UI, can be any decimal or integer)
const currentAngle = ref(0)
const cropToOriginalSize = ref(false)
const applyScope = ref<'current' | 'all'>('current')
const showGrid = ref(true)

// Sync initial angle on open or page change
watch(
	() => [props.open, props.pageNum, props.initialAngle, props.initialCropToOriginalSize],
	() => {
		if (props.open) {
			currentAngle.value = props.initialAngle ?? 0
			cropToOriginalSize.value = props.initialCropToOriginalSize ?? false
			applyScope.value = 'current'
			nextTick(() => {
				renderPage()
			})
		}
	},
	{ immediate: true },
)

function adjustAngle(delta: number) {
	const raw = currentAngle.value + delta
	// Round to 1 decimal place to prevent floating point noise
	currentAngle.value = Math.round(raw * 10) / 10
}

function setAngle(val: number) {
	currentAngle.value = Math.round(val * 10) / 10
}

function resetAngle() {
	currentAngle.value = 0
}

async function renderPage() {
	if (!props.pdf || !canvasRef.value) return
	isLoadingPage.value = true

	let page: any = null
	try {
		page = await props.pdf.getPage(props.pageNum)
		// Render unrotated page scaled to fit the preview stage (up to 480x380)
		const unscaledViewport = page.getViewport({ scale: 1.0 })
		const maxWidth = 480
		const maxHeight = 380
		const scale = Math.min(maxWidth / unscaledViewport.width, maxHeight / unscaledViewport.height)
		const viewport = page.getViewport({ scale })

		const canvas = canvasRef.value
		const { cssWidth, cssHeight } = await renderPdfPageToCanvas(page, canvas, viewport)
		canvasWidth.value = cssWidth
		canvasHeight.value = cssHeight
	} catch (err) {
		console.error('Failed to render page for rotation preview:', err)
	} finally {
		cleanupPdfJsPage(page)
		isLoadingPage.value = false
	}
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

function handleClose() {
	emit('update:open', false)
}

function handleApply() {
	emit('apply', currentAngle.value, applyScope.value, cropToOriginalSize.value)
	emit('update:open', false)
}

onMounted(() => {
	if (props.open) {
		renderPage()
	}
})
</script>

<template>
	<NcDialog
		:open="open"
		size="large"
		:can-close="true"
		:name="t('sheetmusic', 'Rotate Page {page} of {total}', { page: pageNum, total: totalPages }, { escape: false })"
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
						{{ t('sheetmusic', 'Page {page} of {total}', { page: pageNum, total: totalPages }, { escape: false }) }}
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
						:variant="showGrid ? 'primary' : 'tertiary'"
						:title="t('sheetmusic', 'Toggle alignment grid for staff deskewing')"
						@click="showGrid = !showGrid">
						<template #icon>
							<Grid :size="16" />
						</template>
						{{ t('sheetmusic', 'Alignment Grid') }}
					</NcButton>
					<NcButton
						size="small"
						variant="tertiary"
						:disabled="currentAngle === 0"
						:title="t('sheetmusic', 'Reset angle to 0°')"
						@click="resetAngle">
						<template #icon>
							<RestoreIcon :size="16" />
						</template>
						{{ t('sheetmusic', 'Reset to 0°') }}
					</NcButton>
				</div>
			</div>

			<!-- Main Preview Workspace -->
			<div :class="$style.workspace">
				<div
					v-if="isLoadingPage"
					:class="$style.loadingOverlay">
					<NcLoadingIcon :size="36" />
				</div>

				<!-- Fixed Viewport with Rotation & Optional Grid -->
				<div :class="$style.previewStage">
					<div
						:class="[$style.pageFrame, cropToOriginalSize && $style.pageFrameCropped]"
						:style="canvasWidth && canvasHeight ? { width: `${canvasWidth}px`, height: `${canvasHeight}px` } : undefined">
						<div
							:class="$style.rotatedContent"
							:style="{
								transform: `rotate(${currentAngle}deg)`,
								transition: 'transform 0.05s ease-out'
							}">
							<canvas ref="canvasRef" :class="$style.pageCanvas" />
						</div>
					</div>

					<!-- Alignment Grid Overlay -->
					<div
						v-if="showGrid"
						:class="$style.gridOverlay"
						data-test="alignment-grid"
						aria-hidden="true">
						<div
							v-for="i in 18"
							:key="`h-${i}`"
							:class="$style.gridLineHorizontal"
							:style="{ top: `${(i * 100) / 19}%` }" />
						<div
							v-for="i in 6"
							:key="`v-${i}`"
							:class="$style.gridLineVertical"
							:style="{ left: `${(i * 100) / 7}%` }" />
					</div>
				</div>
			</div>

			<!-- Angle Adjustments Control Panel -->
			<SongPdfRotateControls
				v-model:angle="currentAngle"
				v-model:crop-to-original-size="cropToOriginalSize"
				@adjust-angle="adjustAngle"
				@reset-angle="resetAngle" />

			<!-- Footer Settings & Actions -->
			<div :class="$style.footer">
				<div :class="$style.scopeSelector">
					<span :class="$style.scopeLabel">{{ t('sheetmusic', 'Apply rotation to:') }}</span>
					<label :class="$style.radioOption">
						<input
							v-model="applyScope"
							type="radio"
							value="current"
							name="rotateScope">
						<span>{{ t('sheetmusic', 'Page {page} only', { page: pageNum }, { escape: false }) }}</span>
					</label>
					<label :class="$style.radioOption">
						<input
							v-model="applyScope"
							type="radio"
							value="all"
							name="rotateScope">
						<span>{{ t('sheetmusic', 'All pages ({count})', { count: allPagesCount }, { escape: false }) }}</span>
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
						{{ t('sheetmusic', 'Apply Rotation') }}
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
	max-height: 86vh;
	gap: 12px;
	padding: 8px 16px 16px;
	box-sizing: border-box;
	overflow-y: auto;
}

.toolBar {
	display: flex;
	flex-shrink: 0;
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
	font-weight: 500;
	min-width: 110px;
	text-align: center;
}

.toolActions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.workspace {
	position: relative;
	display: flex;
	flex: 1 1 auto;
	justify-content: center;
	align-items: center;
	height: 412px;
	min-height: 240px;
	max-height: 440px;
	overflow: hidden;
	background-color: #2b2b2b;
	border-radius: var(--border-radius, 6px);
	padding: 16px;
	box-sizing: border-box;
}

.loadingOverlay {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: rgba(0, 0, 0, 0.45);
	z-index: 10;
}

.previewStage {
	position: relative;
	display: flex;
	justify-content: center;
	align-items: center;
	width: 100%;
	height: 100%;
}

.pageFrame {
	position: relative;
	display: flex;
	justify-content: center;
	align-items: center;
	flex-shrink: 0;
	transition: all 0.15s ease-out;
}

.pageFrameCropped {
	overflow: hidden;
	background-color: #ffffff;
	outline: 2px solid var(--color-primary-element, #0082c9);
	outline-offset: -1px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
}

.rotatedContent {
	display: flex;
	justify-content: center;
	align-items: center;
	transform-origin: center center;
	width: 100%;
	height: 100%;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
}

.pageCanvas {
	display: block;
	width: 100%;
	height: 100%;
	background-color: #ffffff;
}

.gridOverlay {
	position: absolute;
	inset: 0;
	pointer-events: none;
	overflow: hidden;
}

.gridLineHorizontal {
	position: absolute;
	left: 0;
	right: 0;
	height: 1px;
	background-color: rgba(59, 130, 246, 0.45);
	border-top: 1px dashed rgba(59, 130, 246, 0.35);
}

.gridLineVertical {
	position: absolute;
	top: 0;
	bottom: 0;
	width: 1px;
	background-color: rgba(59, 130, 246, 0.25);
}

.footer {
	display: flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: space-between;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.scopeSelector {
	display: flex;
	align-items: center;
	gap: 12px;
}

.scopeLabel {
	font-size: 13px;
	font-weight: 500;
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
}
</style>
