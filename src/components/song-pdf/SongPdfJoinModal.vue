<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import SongPdfJoinControls from './SongPdfJoinControls.vue'
import type { PageCrop } from './crop-types'
import {
	computeJoinFitInfo,
	getEffectivePageDimensions,
	type JoinLayout,
} from './pdf-join-engine'

const props = withDefaults(
	defineProps<{
		open: boolean
		pdf: any
		totalPages: number
		initialPageA?: number
		initialPageB?: number
		pageCrops?: Record<number, PageCrop>
		pageRotations?: number[]
	}>(),
	{
		initialPageA: 1,
		initialPageB: 2,
		pageCrops: () => ({}),
		pageRotations: () => [],
	},
)

const emit = defineEmits<{
	'update:open': [value: boolean]
	'join': [options: {
		pageA: number
		pageB: number
		layout: JoinLayout
		fitToStandardPage: boolean
		scaleToFit: boolean
		gap: number
		scaleA: number
		scaleB: number
	}]
}>()

const pageA = ref(props.initialPageA || 1)
const pageB = ref(props.initialPageB || Math.min(2, props.totalPages))
const layout = ref<JoinLayout>('vertical')
const fitToStandardPage = ref(true)
const scaleToFit = ref(true)
const gap = ref(12)

const scaleA = ref(1.0)
const scaleB = ref(1.0)

const canvasARef = ref<HTMLCanvasElement>()
const canvasBRef = ref<HTMLCanvasElement>()
const isRenderingPreview = ref(false)

const pageDimA = ref({ width: 595.28, height: 841.89 })
const pageDimB = ref({ width: 595.28, height: 841.89 })
const stdDim = ref({ width: 595.28, height: 841.89 })

const cropA = computed(() => props.pageCrops?.[pageA.value])
const cropB = computed(() => props.pageCrops?.[pageB.value])
const rotA = computed(() => props.pageRotations?.[pageA.value - 1] ?? 0)
const rotB = computed(() => props.pageRotations?.[pageB.value - 1] ?? 0)

const effectiveDimA = computed(() =>
	getEffectivePageDimensions(pageDimA.value.width, pageDimA.value.height, cropA.value, rotA.value),
)
const effectiveDimB = computed(() =>
	getEffectivePageDimensions(pageDimB.value.width, pageDimB.value.height, cropB.value, rotB.value),
)

const scaledDimA = computed(() => ({
	width: Math.round(effectiveDimA.value.width * scaleA.value),
	height: Math.round(effectiveDimA.value.height * scaleA.value),
}))

const scaledDimB = computed(() => ({
	width: Math.round(effectiveDimB.value.width * scaleB.value),
	height: Math.round(effectiveDimB.value.height * scaleB.value),
}))

const isSizeAligned = computed(() => {
	if (layout.value === 'vertical') {
		return Math.abs(scaledDimA.value.width - scaledDimB.value.width) <= 1
	} else {
		return Math.abs(scaledDimA.value.height - scaledDimB.value.height) <= 1
	}
})

function alignSizesToWider() {
	if (effectiveDimA.value.width <= 0 || effectiveDimB.value.width <= 0) return
	if (layout.value === 'vertical') {
		if (effectiveDimA.value.width >= effectiveDimB.value.width) {
			scaleA.value = 1.0
			scaleB.value = Math.round((effectiveDimA.value.width / effectiveDimB.value.width) * 100) / 100
		} else {
			scaleB.value = 1.0
			scaleA.value = Math.round((effectiveDimB.value.width / effectiveDimA.value.width) * 100) / 100
		}
	} else {
		if (effectiveDimA.value.height >= effectiveDimB.value.height) {
			scaleA.value = 1.0
			scaleB.value = Math.round((effectiveDimA.value.height / effectiveDimB.value.height) * 100) / 100
		} else {
			scaleB.value = 1.0
			scaleA.value = Math.round((effectiveDimB.value.height / effectiveDimA.value.height) * 100) / 100
		}
	}
}

function matchPageA() {
	if (layout.value === 'vertical') {
		if (effectiveDimB.value.width <= 0) return
		scaleB.value = Math.round(((effectiveDimA.value.width * scaleA.value) / effectiveDimB.value.width) * 100) / 100
	} else {
		if (effectiveDimB.value.height <= 0) return
		scaleB.value = Math.round(((effectiveDimA.value.height * scaleA.value) / effectiveDimB.value.height) * 100) / 100
	}
}

function matchPageB() {
	if (layout.value === 'vertical') {
		if (effectiveDimA.value.width <= 0) return
		scaleA.value = Math.round(((effectiveDimB.value.width * scaleB.value) / effectiveDimA.value.width) * 100) / 100
	} else {
		if (effectiveDimA.value.height <= 0) return
		scaleA.value = Math.round(((effectiveDimB.value.height * scaleB.value) / effectiveDimA.value.height) * 100) / 100
	}
}

function resetScales() {
	scaleA.value = 1.0
	scaleB.value = 1.0
}

const fitInfo = computed(() =>
	computeJoinFitInfo(effectiveDimA.value, effectiveDimB.value, stdDim.value, layout.value, gap.value, scaleA.value, scaleB.value),
)

async function renderThumbnails() {
	if (!props.pdf || !props.open) return
	isRenderingPreview.value = true

	try {
		// Load dimensions and render Page A
		const pdfPageA = await props.pdf.getPage(pageA.value)
		const vpA = pdfPageA.getViewport({ scale: 1.0, rotation: rotA.value })
		pageDimA.value = { width: vpA.width, height: vpA.height }
		if (pageA.value === 1) {
			stdDim.value = { width: vpA.width, height: vpA.height }
		}

		if (canvasARef.value) {
			const renderScale = Math.min(1.0, 320 / Math.max(vpA.width, vpA.height))
			const renderVpA = pdfPageA.getViewport({ scale: renderScale, rotation: rotA.value })
			const cA = cropA.value
			if (cA && (cA.left > 0.005 || cA.top > 0.005 || cA.width < 0.995 || cA.height < 0.995)) {
				const offCanvas = typeof document !== 'undefined' ? document.createElement('canvas') : null
				const offCtx = offCanvas?.getContext('2d')
				if (offCtx && offCanvas) {
					offCanvas.width = renderVpA.width
					offCanvas.height = renderVpA.height
					await pdfPageA.render({ canvasContext: offCtx, viewport: renderVpA }).promise
					const sx = Math.round(cA.left * renderVpA.width)
					const sy = Math.round(cA.top * renderVpA.height)
					const sw = Math.round(cA.width * renderVpA.width)
					const sh = Math.round(cA.height * renderVpA.height)
					canvasARef.value.width = sw
					canvasARef.value.height = sh
					const ctxA = canvasARef.value.getContext('2d')
					ctxA?.drawImage(offCanvas, sx, sy, sw, sh, 0, 0, sw, sh)
				} else {
					canvasARef.value.width = renderVpA.width
					canvasARef.value.height = renderVpA.height
					const ctxA = canvasARef.value.getContext('2d')
					if (ctxA) {
						ctxA.clearRect(0, 0, renderVpA.width, renderVpA.height)
						await pdfPageA.render({ canvasContext: ctxA, viewport: renderVpA }).promise
					}
				}
			} else {
				canvasARef.value.width = renderVpA.width
				canvasARef.value.height = renderVpA.height
				const ctxA = canvasARef.value.getContext('2d')
				if (ctxA) {
					ctxA.clearRect(0, 0, renderVpA.width, renderVpA.height)
					await pdfPageA.render({ canvasContext: ctxA, viewport: renderVpA }).promise
				}
			}
		}

		// Load dimensions and render Page B
		const pdfPageB = await props.pdf.getPage(pageB.value)
		const vpB = pdfPageB.getViewport({ scale: 1.0, rotation: rotB.value })
		pageDimB.value = { width: vpB.width, height: vpB.height }

		if (canvasBRef.value) {
			const renderScale = Math.min(1.0, 320 / Math.max(vpB.width, vpB.height))
			const renderVpB = pdfPageB.getViewport({ scale: renderScale, rotation: rotB.value })
			const cB = cropB.value
			if (cB && (cB.left > 0.005 || cB.top > 0.005 || cB.width < 0.995 || cB.height < 0.995)) {
				const offCanvas = typeof document !== 'undefined' ? document.createElement('canvas') : null
				const offCtx = offCanvas?.getContext('2d')
				if (offCtx && offCanvas) {
					offCanvas.width = renderVpB.width
					offCanvas.height = renderVpB.height
					await pdfPageB.render({ canvasContext: offCtx, viewport: renderVpB }).promise
					const sx = Math.round(cB.left * renderVpB.width)
					const sy = Math.round(cB.top * renderVpB.height)
					const sw = Math.round(cB.width * renderVpB.width)
					const sh = Math.round(cB.height * renderVpB.height)
					canvasBRef.value.width = sw
					canvasBRef.value.height = sh
					const ctxB = canvasBRef.value.getContext('2d')
					ctxB?.drawImage(offCanvas, sx, sy, sw, sh, 0, 0, sw, sh)
				} else {
					canvasBRef.value.width = renderVpB.width
					canvasBRef.value.height = renderVpB.height
					const ctxB = canvasBRef.value.getContext('2d')
					if (ctxB) {
						ctxB.clearRect(0, 0, renderVpB.width, renderVpB.height)
						await pdfPageB.render({ canvasContext: ctxB, viewport: renderVpB }).promise
					}
				}
			} else {
				canvasBRef.value.width = renderVpB.width
				canvasBRef.value.height = renderVpB.height
				const ctxB = canvasBRef.value.getContext('2d')
				if (ctxB) {
					ctxB.clearRect(0, 0, renderVpB.width, renderVpB.height)
					await pdfPageB.render({ canvasContext: ctxB, viewport: renderVpB }).promise
				}
			}
		}
	} catch (err) {
		console.warn('Failed to render join preview thumbnails:', err)
	} finally {
		isRenderingPreview.value = false
	}
}

watch(
	() => [props.open, pageA.value, pageB.value],
	async ([isOpen]) => {
		if (isOpen) {
			await nextTick()
			renderThumbnails()
		}
	},
	{ immediate: true },
)

watch(
	() => [pageA.value, pageB.value],
	() => {
		scaleA.value = 1.0
		scaleB.value = 1.0
	},
)

watch(
	() => [props.initialPageA, props.initialPageB],
	([initA, initB]) => {
		if (initA) pageA.value = initA
		if (initB) pageB.value = initB
	},
)

function handleJoin() {
	emit('join', {
		pageA: pageA.value,
		pageB: pageB.value,
		layout: layout.value,
		fitToStandardPage: fitToStandardPage.value,
		scaleToFit: scaleToFit.value,
		gap: gap.value,
		scaleA: scaleA.value,
		scaleB: scaleB.value,
	})
	emit('update:open', false)
}

function handleClose() {
	emit('update:open', false)
}
</script>

<template>
	<NcDialog
		v-if="open"
		:open="open"
		size="large"
		:can-close="true"
		:name="t('sheetmusic', 'Join Two Pages into a Single Page')"
		@update:open="emit('update:open', $event)">
		<div :class="$style.dialogBody">
			<div :class="$style.contentLayout">
				<!-- Left Column: Controls & Configuration -->
				<SongPdfJoinControls
					v-model:page-a="pageA"
					v-model:page-b="pageB"
					v-model:layout="layout"
					v-model:scale-a="scaleA"
					v-model:scale-b="scaleB"
					v-model:fit-to-standard-page="fitToStandardPage"
					v-model:scale-to-fit="scaleToFit"
					:total-pages="totalPages"
					:page-crops="pageCrops"
					:fit-info="fitInfo"
					:is-size-aligned="isSizeAligned"
					:scaled-dim-a="scaledDimA"
					:scaled-dim-b="scaledDimB"
					@align-sizes="alignSizesToWider"
					@reset-scales="resetScales"
					@match-page-a="matchPageA"
					@match-page-b="matchPageB" />

				<!-- Right Column: Interactive Live Preview -->
				<div :class="$style.previewColumn">
					<div :class="$style.previewTitle">{{ t('sheetmusic', 'Preview Joined Page:') }}</div>
					<div :class="$style.previewCanvasContainer">
						<div
							:class="[
								$style.previewSheet,
								layout === 'horizontal' ? $style.previewSheetHorizontal : $style.previewSheetVertical
							]">
							<!-- Page A Slot -->
							<div
								:class="$style.previewSlot"
								:style="layout === 'vertical'
									? { width: `${Math.min(100, Math.max(20, (scaledDimA.width / Math.max(scaledDimA.width, scaledDimB.width)) * 100))}%` }
									: { height: `${Math.min(100, Math.max(20, (scaledDimA.height / Math.max(scaledDimA.height, scaledDimB.height)) * 100))}%` }">
								<span :class="$style.slotTag">{{ t('sheetmusic', 'p. {p}', { p: pageA }) }}</span>
								<canvas ref="canvasARef" :class="$style.previewCanvas" />
							</div>

							<!-- Divider Gap -->
							<div :class="layout === 'horizontal' ? $style.previewDividerH : $style.previewDividerV" />

							<!-- Page B Slot -->
							<div
								:class="$style.previewSlot"
								:style="layout === 'vertical'
									? { width: `${Math.min(100, Math.max(20, (scaledDimB.width / Math.max(scaledDimA.width, scaledDimB.width)) * 100))}%` }
									: { height: `${Math.min(100, Math.max(20, (scaledDimB.height / Math.max(scaledDimA.height, scaledDimB.height)) * 100))}%` }">
								<span :class="$style.slotTag">{{ t('sheetmusic', 'p. {p}', { p: pageB }) }}</span>
								<canvas ref="canvasBRef" :class="$style.previewCanvas" />
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Dialog Footer Actions -->
			<div :class="$style.dialogFooter">
				<NcButton variant="secondary" @click="handleClose">
					<template #icon>
						<CloseIcon :size="18" />
					</template>
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>

				<NcButton
					variant="primary"
					:title="t('sheetmusic', 'Join pages {a} and {b} into one page', { a: pageA, b: pageB })"
					@click="handleJoin">
					<template #icon>
						<TableMergeCells :size="18" />
					</template>
					{{ t('sheetmusic', 'Join Pages') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.dialogBody {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 16px 16px;
	min-width: 680px;
	max-width: 820px;
}

.contentLayout {
	display: flex;
	gap: 24px;
	align-items: flex-start;
}

.controlsColumn {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 320px;
}

.previewColumn {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	min-width: 280px;
}

.previewTitle {
	font-size: 13px;
	font-weight: 600;
	margin-bottom: 8px;
	align-self: flex-start;
}

.previewCanvasContainer {
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	width: 100%;
	min-height: 340px;
}

.previewSheet {
	background-color: #ffffff;
	border: 1px solid #d1d5db;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	border-radius: 4px;
	padding: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	max-width: 240px;
	transition: all 0.2s ease;
}

.previewSheetVertical {
	flex-direction: column;
	gap: 8px;
	width: 220px;
}

.previewSheetHorizontal {
	flex-direction: row;
	gap: 8px;
	width: 260px;
}

.previewSlot {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	background-color: #f9fafb;
	border: 1px dashed #cbd5e1;
	border-radius: 2px;
	max-height: 140px;
}

.slotTag {
	position: absolute;
	top: 2px;
	left: 2px;
	background-color: rgba(0, 0, 0, 0.65);
	color: #fff;
	font-size: 9px;
	font-weight: bold;
	padding: 1px 4px;
	border-radius: 3px;
	z-index: 2;
}

.previewCanvas {
	max-width: 100%;
	max-height: 130px;
	object-fit: contain;
	display: block;
}

.previewDividerV {
	width: 100%;
	height: 1px;
	background-color: #e2e8f0;
}

.previewDividerH {
	width: 1px;
	height: 100%;
	background-color: #e2e8f0;
}

.dialogFooter {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	margin-top: 8px;
}
</style>

