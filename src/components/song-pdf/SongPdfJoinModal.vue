<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import ViewAgendaOutline from 'vue-material-design-icons/ViewAgendaOutline.vue'
import ViewSplitVertical from 'vue-material-design-icons/ViewSplitVertical.vue'
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
		:name="t('mobilesheetsmanager', 'Join Two Pages into a Single Page')"
		@update:open="emit('update:open', $event)">
		<div :class="$style.dialogBody">
			<div :class="$style.contentLayout">
				<!-- Left Column: Controls & Configuration -->
				<div :class="$style.controlsColumn">
					<!-- Page Selection -->
					<div :class="$style.sectionGroup">
						<label :class="$style.sectionLabel">{{ t('mobilesheetsmanager', 'Select Pages to Join:') }}</label>
						<div :class="$style.pageSelectors">
							<div :class="$style.selectorItem">
								<span :class="$style.selectorItemLabel">{{ t('mobilesheetsmanager', 'First Page (Top / Left):') }}</span>
								<select v-model.number="pageA" :class="$style.select">
									<option v-for="p in totalPages" :key="p" :value="p" :disabled="p === pageB">
										{{ t('mobilesheetsmanager', 'Page {p}', { p }) }}
										{{ pageCrops[p] ? `(${t('mobilesheetsmanager', 'Cropped')})` : '' }}
									</option>
								</select>
							</div>

							<div :class="$style.selectorItem">
								<span :class="$style.selectorItemLabel">{{ t('mobilesheetsmanager', 'Second Page (Bottom / Right):') }}</span>
								<select v-model.number="pageB" :class="$style.select">
									<option v-for="p in totalPages" :key="p" :value="p" :disabled="p === pageA">
										{{ t('mobilesheetsmanager', 'Page {p}', { p }) }}
										{{ pageCrops[p] ? `(${t('mobilesheetsmanager', 'Cropped')})` : '' }}
									</option>
								</select>
							</div>
						</div>
					</div>

					<!-- Layout Orientation -->
					<div :class="$style.sectionGroup">
						<label :class="$style.sectionLabel">{{ t('mobilesheetsmanager', 'Layout Orientation:') }}</label>
						<div :class="$style.layoutToggle">
							<button
								type="button"
								:class="[$style.toggleBtn, layout === 'vertical' && $style.toggleBtnActive]"
								@click="layout = 'vertical'">
								<ViewAgendaOutline :size="18" />
								<span>{{ t('mobilesheetsmanager', 'Vertical (Stacked Top / Bottom)') }}</span>
							</button>
							<button
								type="button"
								:class="[$style.toggleBtn, layout === 'horizontal' && $style.toggleBtnActive]"
								@click="layout = 'horizontal'">
								<ViewSplitVertical :size="18" />
								<span>{{ t('mobilesheetsmanager', 'Horizontal (Side by Side)') }}</span>
							</button>
						</div>
					</div>

					<!-- Fit Status Banner -->
					<div :class="$style.sectionGroup">
						<div v-if="fitInfo.fitsOnStandardPage" :class="$style.fitBannerSuccess">
							<CheckCircle :size="20" />
							<div>
								<strong>{{ t('mobilesheetsmanager', 'Pages fit on a single page!') }}</strong>
								<div :class="$style.fitBannerSub">
									{{ t('mobilesheetsmanager', 'Combined {dir}: {pct}% of standard page size.', {
										dir: layout === 'vertical' ? t('mobilesheetsmanager', 'height') : t('mobilesheetsmanager', 'width'),
										pct: layout === 'vertical' ? fitInfo.heightPercentage : fitInfo.widthPercentage,
									}) }}
								</div>
							</div>
						</div>
						<div v-else :class="$style.fitBannerWarning">
							<AlertCircle :size="20" />
							<div>
								<strong>{{ t('mobilesheetsmanager', 'Content exceeds standard page size ({pct}%).', {
									pct: layout === 'vertical' ? fitInfo.heightPercentage : fitInfo.widthPercentage,
								}) }}</strong>
								<div :class="$style.fitBannerSub">
									{{ scaleToFit ? t('mobilesheetsmanager', 'Content will be scaled to fit within a single standard page.') : t('mobilesheetsmanager', 'Page canvas will expand to accommodate both pages.') }}
								</div>
							</div>
						</div>
					</div>

					<!-- Sizing Options & Alignment -->
					<div :class="$style.sectionGroup">
						<div :class="$style.sectionHeaderRow">
							<label :class="$style.sectionLabel">{{ t('mobilesheetsmanager', 'Page Sizing & Alignment:') }}</label>
							<div :class="$style.alignQuickButtons">
								<button
									type="button"
									:class="[$style.quickAlignBtn, isSizeAligned && $style.quickAlignBtnActive]"
									:title="layout === 'vertical' ? t('mobilesheetsmanager', 'Scale narrower page to match wider page width') : t('mobilesheetsmanager', 'Scale shorter page to match taller page height')"
									data-test="align-sizes-btn"
									@click="alignSizesToWider">
									{{ layout === 'vertical' ? t('mobilesheetsmanager', 'Align Widths') : t('mobilesheetsmanager', 'Align Heights') }}
								</button>
								<button
									type="button"
									:class="$style.resetScaleBtn"
									:disabled="scaleA === 1.0 && scaleB === 1.0"
									:title="t('mobilesheetsmanager', 'Reset both pages to 100%')"
									data-test="reset-sizes-btn"
									@click="resetScales">
									{{ t('mobilesheetsmanager', 'Reset 100%') }}
								</button>
							</div>
						</div>

						<!-- Alignment Status Pill -->
						<div :class="$style.alignmentStatusRow">
							<span v-if="isSizeAligned" :class="$style.statusPillSuccess" data-test="aligned-status">
								<CheckCircle :size="14" />
								{{ layout === 'vertical' ? t('mobilesheetsmanager', 'Widths aligned ({w} pt)', { w: scaledDimA.width }) : t('mobilesheetsmanager', 'Heights aligned ({h} pt)', { h: scaledDimA.height }) }}
							</span>
							<span v-else :class="$style.statusPillDiff" data-test="diff-status">
								{{ layout === 'vertical'
									? t('mobilesheetsmanager', 'Width diff: Page {pA} is {wA} pt vs Page {pB} is {wB} pt', { pA: pageA, wA: scaledDimA.width, pB: pageB, wB: scaledDimB.width })
									: t('mobilesheetsmanager', 'Height diff: Page {pA} is {hA} pt vs Page {pB} is {hB} pt', { pA: pageA, hA: scaledDimA.height, pB: pageB, hB: scaledDimB.height }) }}
							</span>
						</div>

						<!-- Page A Scale Control -->
						<div :class="$style.scaleControlRow">
							<div :class="$style.scaleControlHeader">
								<span :class="$style.scalePageName">{{ t('mobilesheetsmanager', 'Page {p} Size:', { p: pageA }) }}</span>
								<span :class="$style.scaleDimInfo">{{ scaledDimA.width }} × {{ scaledDimA.height }} pt ({{ Math.round(scaleA * 100) }}%)</span>
							</div>
							<div :class="$style.sliderRow">
								<input
									v-model.number="scaleA"
									type="range"
									min="0.5"
									max="2.0"
									step="0.01"
									:class="$style.scaleSlider"
									data-test="scale-a-slider" />
								<div :class="$style.scaleInputGroup">
									<input
										:value="Math.round(scaleA * 100)"
										type="number"
										min="50"
										max="200"
										:class="$style.scaleNumberInput"
										data-test="scale-a-input"
										@input="scaleA = Math.max(0.5, Math.min(2.0, (Number(($event.target as HTMLInputElement).value) || 100) / 100))" />
									<span :class="$style.percentSign">%</span>
								</div>
								<button
									type="button"
									:class="$style.matchOtherBtn"
									:title="t('mobilesheetsmanager', 'Scale Page {pA} to match Page {pB}', { pA: pageA, pB: pageB })"
									data-test="match-b-btn"
									@click="matchPageB">
									{{ t('mobilesheetsmanager', 'Match p. {p}', { p: pageB }) }}
								</button>
							</div>
						</div>

						<!-- Page B Scale Control -->
						<div :class="$style.scaleControlRow">
							<div :class="$style.scaleControlHeader">
								<span :class="$style.scalePageName">{{ t('mobilesheetsmanager', 'Page {p} Size:', { p: pageB }) }}</span>
								<span :class="$style.scaleDimInfo">{{ scaledDimB.width }} × {{ scaledDimB.height }} pt ({{ Math.round(scaleB * 100) }}%)</span>
							</div>
							<div :class="$style.sliderRow">
								<input
									v-model.number="scaleB"
									type="range"
									min="0.5"
									max="2.0"
									step="0.01"
									:class="$style.scaleSlider"
									data-test="scale-b-slider" />
								<div :class="$style.scaleInputGroup">
									<input
										:value="Math.round(scaleB * 100)"
										type="number"
										min="50"
										max="200"
										:class="$style.scaleNumberInput"
										data-test="scale-b-input"
										@input="scaleB = Math.max(0.5, Math.min(2.0, (Number(($event.target as HTMLInputElement).value) || 100) / 100))" />
									<span :class="$style.percentSign">%</span>
								</div>
								<button
									type="button"
									:class="$style.matchOtherBtn"
									:title="t('mobilesheetsmanager', 'Scale Page {pB} to match Page {pA}', { pA: pageA, pB: pageB })"
									data-test="match-a-btn"
									@click="matchPageA">
									{{ t('mobilesheetsmanager', 'Match p. {p}', { p: pageA }) }}
								</button>
							</div>
						</div>

						<!-- Sizing Checkboxes -->
						<div :class="$style.checkboxRow">
							<label :class="$style.checkboxLabel">
								<input v-model="fitToStandardPage" type="checkbox" />
								<span>{{ t('mobilesheetsmanager', 'Fit onto standard page dimensions') }}</span>
							</label>
						</div>
						<div v-if="fitToStandardPage" :class="$style.checkboxRow">
							<label :class="$style.checkboxLabel">
								<input v-model="scaleToFit" type="checkbox" />
								<span>{{ t('mobilesheetsmanager', 'Scale down content if it exceeds the page') }}</span>
							</label>
						</div>
					</div>
				</div>

				<!-- Right Column: Interactive Live Preview -->
				<div :class="$style.previewColumn">
					<div :class="$style.previewTitle">{{ t('mobilesheetsmanager', 'Preview Joined Page:') }}</div>
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
								<span :class="$style.slotTag">{{ t('mobilesheetsmanager', 'p. {p}', { p: pageA }) }}</span>
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
								<span :class="$style.slotTag">{{ t('mobilesheetsmanager', 'p. {p}', { p: pageB }) }}</span>
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
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>

				<NcButton
					variant="primary"
					:title="t('mobilesheetsmanager', 'Join pages {a} and {b} into one page', { a: pageA, b: pageB })"
					@click="handleJoin">
					<template #icon>
						<TableMergeCells :size="18" />
					</template>
					{{ t('mobilesheetsmanager', 'Join Pages') }}
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

.sectionGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sectionLabel {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.pageSelectors {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.selectorItem {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.selectorItemLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.select {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.layoutToggle {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.toggleBtn {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;
	transition: all 0.15s ease;
	text-align: left;
}

.toggleBtnActive {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.fitBannerSuccess {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 14px;
	background-color: #ecfdf5;
	border: 1px solid #10b981;
	border-radius: 6px;
	color: #065f46;
	font-size: 13px;
}

.fitBannerWarning {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 14px;
	background-color: #fffbeb;
	border: 1px solid #f59e0b;
	border-radius: 6px;
	color: #92400e;
	font-size: 13px;
}

.fitBannerSub {
	font-size: 11px;
	margin-top: 2px;
	opacity: 0.9;
}

.checkboxRow {
	display: flex;
	align-items: center;
}

.checkboxLabel {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	cursor: pointer;
}

.dialogFooter {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	margin-top: 8px;
}

.sectionHeaderRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.alignQuickButtons {
	display: flex;
	align-items: center;
	gap: 6px;
}

.quickAlignBtn {
	font-size: 11px;
	padding: 3px 8px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	font-weight: 600;
	transition: all 0.15s ease;
}

.quickAlignBtn:hover {
	background-color: var(--color-background-hover);
}

.quickAlignBtnActive {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
}

.resetScaleBtn {
	font-size: 11px;
	padding: 3px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: all 0.15s ease;
}

.resetScaleBtn:hover:not(:disabled) {
	background-color: var(--color-background-hover);
}

.resetScaleBtn:disabled {
	opacity: 0.5;
	cursor: default;
}

.alignmentStatusRow {
	margin: 2px 0 4px;
}

.statusPillSuccess {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	color: #047857;
	font-weight: 500;
}

.statusPillDiff {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.scaleControlRow {
	display: flex;
	flex-direction: column;
	gap: 4px;
	background-color: var(--color-background-hover);
	padding: 6px 10px;
	border-radius: 6px;
	margin-bottom: 4px;
}

.scaleControlHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 12px;
}

.scalePageName {
	font-weight: 600;
	color: var(--color-main-text);
}

.scaleDimInfo {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.sliderRow {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
}

.scaleSlider {
	flex: 1;
	cursor: pointer;
	height: 5px;
	accent-color: var(--color-primary-element, #0082c9);
}

.scaleInputGroup {
	display: flex;
	align-items: center;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 2px 6px;
	width: 62px;
}

.scaleNumberInput {
	width: 34px;
	border: none;
	background: transparent;
	font-size: 12px;
	text-align: right;
	color: var(--color-main-text);
	outline: none;
}

.percentSign {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: 2px;
}

.matchOtherBtn {
	font-size: 11px;
	padding: 3px 6px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	white-space: nowrap;
}

.matchOtherBtn:hover {
	background-color: var(--color-background-hover);
}
</style>

