<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SongPdfCropToolbar from './SongPdfCropToolbar.vue'
import SongPdfCropWorkspace from './SongPdfCropWorkspace.vue'
import SongPdfCropFooter from './SongPdfCropFooter.vue'
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

const workspaceCompRef = ref<InstanceType<typeof SongPdfCropWorkspace>>()
const isLoadingPage = ref(false)

// Display & coordinate mapping
const canvasWidth = ref(0)
const canvasHeight = ref(0)

// Zoom levels
const MIN_ZOOM = 0.5
const MAX_ZOOM = 3.0
const zoomLevel = ref(1.0)

function zoomIn() {
	zoomLevel.value = Math.min(MAX_ZOOM, Math.round((zoomLevel.value + 0.25) * 100) / 100)
}

function zoomOut() {
	zoomLevel.value = Math.max(MIN_ZOOM, Math.round((zoomLevel.value - 0.25) * 100) / 100)
}

function zoomReset() {
	zoomLevel.value = 1.0
}

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

async function renderPage() {
	if (!props.pdf) return
	isLoadingPage.value = true

	try {
		const page = await props.pdf.getPage(props.pageNum)
		// We use a high resolution viewport to ensure clear text when cropping
		const effectiveRotation = (((page.rotate ?? 0) + (props.rotation || 0)) % 360 + 360) % 360
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: effectiveRotation })

		// Dynamic bounds based on available workspace area to make the preview large and clear
		let availableWidth = 850
		let availableHeight = 850
		const wsEl = workspaceCompRef.value?.workspaceRef
		if (wsEl) {
			const rect = wsEl.getBoundingClientRect()
			if (rect.width > 64) {
				availableWidth = Math.max(220, Math.floor(rect.width - 32))
			}
			if (rect.height > 64) {
				availableHeight = Math.max(220, Math.floor(rect.height - 32))
			}
		} else if (typeof window !== 'undefined') {
			availableWidth = Math.max(220, Math.floor(window.innerWidth * 0.85))
			availableHeight = Math.max(220, Math.floor(window.innerHeight * 0.68))
		}

		const scale = Math.min(availableWidth / unscaledViewport.width, availableHeight / unscaledViewport.height, 3.0)
		const viewport = page.getViewport({ scale, rotation: effectiveRotation })

		const canvas = workspaceCompRef.value?.canvasRef
		if (canvas) {
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

function handlePageChange(newPage: number) {
	if (newPage >= 1 && newPage <= props.totalPages) {
		zoomReset()
		emit('change-page', newPage)
	}
}

let resizeObserver: ResizeObserver | null = null

onMounted(() => {
	const wsEl = workspaceCompRef.value?.workspaceRef
	if (typeof ResizeObserver !== 'undefined' && wsEl) {
		let lastWidth = 0
		let lastHeight = 0
		resizeObserver = new ResizeObserver((entries) => {
			const entry = entries[0]
			if (!entry) return
			const { width, height } = entry.contentRect
			if (Math.abs(width - lastWidth) > 30 || Math.abs(height - lastHeight) > 30) {
				lastWidth = width
				lastHeight = height
				if (props.open) {
					void renderPage()
				}
			}
		})
		resizeObserver.observe(wsEl)
	}
})

onUnmounted(() => {
	resizeObserver?.disconnect()
	resizeObserver = null
})

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
		:class="$style.cropModal"
		:name="t('sheetmusic', 'Crop Page {page} of {total}', { page: pageNum, total: totalPages })"
		@update:open="emit('update:open', $event)">
		<div :class="$style.dialogBody">
			<!-- Header Toolbar (Page Nav, Zoom, Presets) -->
			<SongPdfCropToolbar
				:page-num="pageNum"
				:total-pages="totalPages"
				:zoom-level="zoomLevel"
				:min-zoom="MIN_ZOOM"
				:max-zoom="MAX_ZOOM"
				:is-custom-cropped="isCustomCropped"
				@change-page="handlePageChange"
				@zoom-in="zoomIn"
				@zoom-out="zoomOut"
				@zoom-reset="zoomReset"
				@apply-preset="applyPreset"
				@reset-crop="resetCrop" />

			<!-- Main Crop Workspace (with Zoom & Pan) -->
			<SongPdfCropWorkspace
				ref="workspaceCompRef"
				v-model:crop-box="cropBox"
				:canvas-width="canvasWidth"
				:canvas-height="canvasHeight"
				:zoom-level="zoomLevel"
				:is-loading-page="isLoadingPage"
				:is-custom-cropped="isCustomCropped"
				@zoom-change="zoomLevel = $event" />

			<!-- Footer Settings & Actions -->
			<SongPdfCropFooter
				v-model:apply-scope="applyScope"
				:page-num="pageNum"
				:all-pages-count="allPagesCount"
				@cancel="handleClose"
				@apply="handleApply" />
		</div>
	</NcDialog>
</template>

<style module>
.cropModal :global(.modal-container),
.cropModal :global(.dialog) {
	width: 95vw !important;
	max-width: 1600px !important;
}

.cropModal :global(.dialog__name) {
	margin-block: 0 4px;
	font-size: 17px;
	min-height: 32px;
	line-height: 32px;
}

.dialogBody {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 4px 12px 12px;
	width: 100%;
	height: calc(92vh - 60px);
	min-height: 520px;
	max-height: 94vh;
	box-sizing: border-box;
	overflow: hidden;
}

@media (max-width: 768px) {
	.cropModal :global(.modal-container),
	.cropModal :global(.dialog) {
		width: 98vw !important;
	}

	.dialogBody {
		height: calc(96vh - 50px);
		min-height: 360px;
		padding: 4px 8px 8px;
		gap: 8px;
	}
}
</style>
