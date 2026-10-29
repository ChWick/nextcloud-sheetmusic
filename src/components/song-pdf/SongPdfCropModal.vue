<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
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
import SongPdfCropOverlay from './SongPdfCropOverlay.vue'
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

const isCustomCropped = computed(() => {
	const c = cropBox.value
	return (
		c.left > 0.005 ||
		c.top > 0.005 ||
		c.width < 0.995 ||
		c.height < 0.995
	)
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
			canvasContext.imageSmoothingEnabled = true
			canvasContext.imageSmoothingQuality = 'high'
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
					<SongPdfCropOverlay
						v-model:crop-box="cropBox"
						:canvas-width="canvasWidth"
						:canvas-height="canvasHeight"
						:is-custom-cropped="isCustomCropped" />
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

