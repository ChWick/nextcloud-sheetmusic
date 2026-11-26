<script setup lang="ts">
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue'
import type { PDFDocumentProxy } from 'pdfjs-dist'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import type { Bookmark } from '../../types/song'
import {
	LINK_LABEL_PRESETS,
	type LinkMarkerStyle,
} from '../../utils/annotationLinks'
import { fetchCachedBinaryFile } from '../../utils/file-binary'
import { defaultFileBinaryCache } from '../../utils/file-cache'
import { destroyPdfJsDocument, loadPdfJsDocument, renderPdfPageToCanvas } from '../../utils/pdfjs'

export interface SideBySideLinkResult {
	sourcePageIndex: number
	sourceX: number
	sourceY: number
	targetPageIndex: number
	targetX: number
	targetY: number
	label: string
	linkStyle: LinkMarkerStyle
	createReverse: boolean
}

const props = withDefaults(defineProps<{
	open: boolean
	filePath: string
	totalPages: number
	initialSourcePageIndex?: number
	initialSourceX?: number
	initialSourceY?: number
	initialTargetPageIndex?: number
	initialTargetX?: number
	initialTargetY?: number
	initialLabel?: string
	initialLinkStyle?: LinkMarkerStyle
	initialCreateReverse?: boolean
	showReverseOption?: boolean
	bookmarks?: Bookmark[]
}>(), {
	initialSourcePageIndex: 0,
	initialSourceX: 0.5,
	initialSourceY: 0.5,
	initialTargetPageIndex: 0,
	initialTargetX: 0.5,
	initialTargetY: 0.2,
	initialLabel: '',
	initialLinkStyle: 'box',
	initialCreateReverse: false,
	showReverseOption: true,
	bookmarks: () => [],
})

const emit = defineEmits<{
	close: []
	save: [result: SideBySideLinkResult]
}>()

const sourcePageIdx = ref(0)
const sourceX = ref(0.5)
const sourceY = ref(0.5)
const targetPageIdx = ref(0)
const targetX = ref(0.5)
const targetY = ref(0.2)
const label = ref('')
const linkStyle = ref<LinkMarkerStyle>('box')
const createReverse = ref(false)

const sourceCanvasRef = ref<HTMLCanvasElement | null>(null)
const targetCanvasRef = ref<HTMLCanvasElement | null>(null)
const pdfDoc = shallowRef<PDFDocumentProxy | null>(null)
const effectiveTotalPages = computed(() => Math.max(1, pdfDoc.value?.numPages ?? props.totalPages))

async function renderPane(canvas: HTMLCanvasElement | null, pageIdx: number) {
	const doc = pdfDoc.value
	if (!doc || !canvas) return
	const clampedPage = Math.min(Math.max(1, pageIdx + 1), doc.numPages)
	try {
		const page = await doc.getPage(clampedPage)
		const baseVp = page.getViewport({ scale: 1 })
		const targetWidth = 360
		const scale = targetWidth / Math.max(1, baseVp.width)
		const vp = page.getViewport({ scale })
		canvas.style.width = `${Math.round(vp.width)}px`
		canvas.style.height = `${Math.round(vp.height)}px`
		await renderPdfPageToCanvas(page, canvas, vp, { dpr: Math.min(window.devicePixelRatio || 1, 2) })
	} catch {
		// Ignore transient PDF render errors in picker
	}
}

async function ensurePdfLoaded() {
	if (!props.filePath) return
	if (pdfDoc.value) {
		await Promise.all([
			renderPane(sourceCanvasRef.value, sourcePageIdx.value),
			renderPane(targetCanvasRef.value, targetPageIdx.value),
		])
		return
	}
	try {
		const bytes = await fetchCachedBinaryFile(props.filePath, defaultFileBinaryCache)
		if (!bytes) return
		pdfDoc.value = await loadPdfJsDocument(bytes.slice())
		await Promise.all([
			renderPane(sourceCanvasRef.value, sourcePageIdx.value),
			renderPane(targetCanvasRef.value, targetPageIdx.value),
		])
	} catch {
		// Fallback to blank clickable canvases if PDF fails to load
	}
}

watch(
	() => props.open,
	(isOpen) => {
		if (!isOpen) return
		sourcePageIdx.value = Math.max(0, props.initialSourcePageIndex)
		sourceX.value = props.initialSourceX
		sourceY.value = props.initialSourceY
		targetPageIdx.value = Math.max(0, props.initialTargetPageIndex)
		targetX.value = props.initialTargetX
		targetY.value = props.initialTargetY
		label.value = props.initialLabel
		linkStyle.value = props.initialLinkStyle
		createReverse.value = props.initialCreateReverse
		void ensurePdfLoaded()
	},
	{ immediate: true },
)

watch(sourcePageIdx, (idx) => {
	if (props.open) void renderPane(sourceCanvasRef.value, idx)
})

watch(targetPageIdx, (idx) => {
	if (props.open) void renderPane(targetCanvasRef.value, idx)
})

onUnmounted(() => {
	if (pdfDoc.value) {
		void destroyPdfJsDocument(pdfDoc.value)
		pdfDoc.value = null
	}
})

function onPaneClick(which: 'source' | 'target', event: MouseEvent) {
	const el = event.currentTarget as HTMLElement | null
	if (!el) return
	const rect = el.getBoundingClientRect()
	if (rect.width <= 0 || rect.height <= 0) return
	const nx = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
	const ny = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height))
	if (which === 'source') {
		sourceX.value = nx
		sourceY.value = ny
	} else {
		targetX.value = nx
		targetY.value = ny
	}
}

function applyBookmark(event: Event) {
	const val = (event.target as HTMLSelectElement).value
	if (!val) return
	const bm = props.bookmarks[Number.parseInt(val, 10)]
	if (!bm) return
	targetPageIdx.value = Math.max(0, Math.min(effectiveTotalPages.value - 1, bm.pageNum - 1))
	if (!label.value.trim()) {
		label.value = bm.name
	}
}

function handleSave() {
	emit('save', {
		sourcePageIndex: sourcePageIdx.value,
		sourceX: sourceX.value,
		sourceY: sourceY.value,
		targetPageIndex: targetPageIdx.value,
		targetX: targetX.value,
		targetY: targetY.value,
		label: label.value.trim(),
		linkStyle: linkStyle.value,
		createReverse: createReverse.value,
	})
}
</script>

<template>
	<Teleport to="body">
		<div
			v-if="open"
			:class="$style.backdrop"
			role="dialog"
			aria-modal="true"
			:aria-label="t('sheetmusic', 'Side-by-side link picker')"
			data-test="link-picker-modal"
			@keydown.esc.prevent="emit('close')">
			<div :class="$style.modal">
				<header :class="$style.header">
					<strong>{{ t('sheetmusic', 'Link Point Picker') }}</strong>
					<span :class="$style.hint">
						{{ t('sheetmusic', 'Click left page for source, right page for exact destination') }}
					</span>
					<button
						type="button"
						:class="$style.closeBtn"
						:aria-label="t('sheetmusic', 'Close')"
						@click="emit('close')">
						<CloseIcon :size="20" />
					</button>
				</header>

				<div :class="$style.panes">
					<!-- Source Page Pane -->
					<section :class="$style.pane" data-test="link-picker-source-pane">
						<div :class="$style.paneBar">
							<span :class="$style.paneBadgeSource">{{ t('sheetmusic', 'Source') }}</span>
							<div :class="$style.pager">
								<button
									type="button"
									:class="$style.pageBtn"
									:disabled="sourcePageIdx <= 0"
									:aria-label="t('sheetmusic', 'Previous source page')"
									data-test="link-picker-source-prev"
									@click="sourcePageIdx = Math.max(0, sourcePageIdx - 1)">
									<ChevronLeftIcon :size="18" />
								</button>
								<span data-test="link-picker-source-page">
									{{ t('sheetmusic', 'Page {page} / {total}', { page: sourcePageIdx + 1, total: effectiveTotalPages }) }}
								</span>
								<button
									type="button"
									:class="$style.pageBtn"
									:disabled="sourcePageIdx >= effectiveTotalPages - 1"
									:aria-label="t('sheetmusic', 'Next source page')"
									data-test="link-picker-source-next"
									@click="sourcePageIdx = Math.min(effectiveTotalPages - 1, sourcePageIdx + 1)">
									<ChevronRightIcon :size="18" />
								</button>
							</div>
						</div>
						<div
							:class="$style.canvasWrap"
							data-test="link-picker-source-surface"
							@click="onPaneClick('source', $event)">
							<canvas ref="sourceCanvasRef" :class="$style.canvas" width="360" height="480" />
							<span
								:class="$style.sourcePin"
								:style="{ left: `${sourceX * 100}%`, top: `${sourceY * 100}%` }"
								data-test="link-picker-source-pin" />
						</div>
					</section>

					<!-- Target Page Pane -->
					<section :class="$style.pane" data-test="link-picker-target-pane">
						<div :class="$style.paneBar">
							<span :class="$style.paneBadgeTarget">{{ t('sheetmusic', 'Target') }}</span>
							<div :class="$style.pager">
								<button
									type="button"
									:class="$style.pageBtn"
									:disabled="targetPageIdx <= 0"
									:aria-label="t('sheetmusic', 'Previous target page')"
									data-test="link-picker-target-prev"
									@click="targetPageIdx = Math.max(0, targetPageIdx - 1)">
									<ChevronLeftIcon :size="18" />
								</button>
								<span data-test="link-picker-target-page">
									{{ t('sheetmusic', 'Page {page} / {total}', { page: targetPageIdx + 1, total: effectiveTotalPages }) }}
								</span>
								<button
									type="button"
									:class="$style.pageBtn"
									:disabled="targetPageIdx >= effectiveTotalPages - 1"
									:aria-label="t('sheetmusic', 'Next target page')"
									data-test="link-picker-target-next"
									@click="targetPageIdx = Math.min(effectiveTotalPages - 1, targetPageIdx + 1)">
									<ChevronRightIcon :size="18" />
								</button>
							</div>
							<select
								v-if="bookmarks.length > 0"
								:class="$style.bmSelect"
								:aria-label="t('sheetmusic', 'Jump target to bookmark')"
								data-test="link-picker-bookmark-select"
								@change="applyBookmark">
								<option value="">{{ t('sheetmusic', 'Bookmark…') }}</option>
								<option
									v-for="(bm, idx) in bookmarks"
									:key="`${bm.name}-${idx}`"
									:value="String(idx)">
									{{ bm.name }} (p. {{ bm.pageNum }})
								</option>
							</select>
						</div>
						<div
							:class="$style.canvasWrap"
							data-test="link-picker-target-surface"
							@click="onPaneClick('target', $event)">
							<canvas ref="targetCanvasRef" :class="$style.canvas" width="360" height="480" />
							<span
								:class="$style.targetPin"
								:style="{ left: `${targetX * 100}%`, top: `${targetY * 100}%` }"
								data-test="link-picker-target-pin" />
						</div>
					</section>
				</div>

				<footer :class="$style.footer">
					<div :class="$style.footerControls">
						<input
							v-model="label"
							type="text"
							:placeholder="t('sheetmusic', 'Button label (optional for circle)')"
							:aria-label="t('sheetmusic', 'Link label')"
							:class="$style.labelInput"
							data-test="link-picker-label-input">
						<div :class="$style.presetGroup">
							<button
								v-for="preset in LINK_LABEL_PRESETS"
								:key="preset"
								type="button"
								:class="[$style.chip, label === preset && $style.chipActive]"
								@click="label = preset">
								{{ preset }}
							</button>
						</div>
						<div :class="$style.styleToggle">
							<button
								type="button"
								:class="[$style.chip, linkStyle === 'box' && $style.chipActive]"
								data-test="link-picker-style-box"
								@click="linkStyle = 'box'">
								{{ t('sheetmusic', 'Box') }}
							</button>
							<button
								type="button"
								:class="[$style.chip, linkStyle === 'circle' && $style.chipActive]"
								data-test="link-picker-style-circle"
								@click="linkStyle = 'circle'">
								{{ t('sheetmusic', 'Circle') }}
							</button>
						</div>
						<label v-if="showReverseOption" :class="$style.reverseLabel">
							<input
								v-model="createReverse"
								type="checkbox"
								data-test="link-picker-reverse-checkbox">
							<span>{{ t('sheetmusic', 'Create reverse link') }}</span>
						</label>
					</div>

					<div :class="$style.footerActions">
						<NcButton variant="tertiary" @click="emit('close')">
							{{ t('sheetmusic', 'Cancel') }}
						</NcButton>
						<NcButton variant="primary" data-test="link-picker-save" @click="handleSave">
							{{ t('sheetmusic', 'Save Link') }}
						</NcButton>
					</div>
				</footer>
			</div>
		</div>
	</Teleport>
</template>

<style module>
.backdrop {
	position: fixed;
	inset: 0;
	z-index: 100020;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background: rgba(0, 0, 0, 0.55);
}

.modal {
	display: flex;
	flex-direction: column;
	width: min(920px, 96vw);
	max-height: 92vh;
	border-radius: 10px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	box-shadow: 0 12px 36px rgba(0, 0, 0, 0.32);
	overflow: hidden;
}

.header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
}

.hint {
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
	margin-right: auto;
}

button[type='button'].closeBtn {
	margin: 0;
	padding: 4px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.panes {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
	padding: 12px;
	overflow-y: auto;
	background: var(--color-background-hover);
}

.pane {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
}

.paneBar {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 8px;
	width: 100%;
	font-size: 0.82rem;
}

.paneBadgeSource,
.paneBadgeTarget {
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.75rem;
	font-weight: 700;
	color: #ffffff;
}

.paneBadgeSource {
	background: #0082c9;
}

.paneBadgeTarget {
	background: #e65100;
}

.pager {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

button[type='button'].pageBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	margin: 0;
	padding: 2px 4px;
	min-height: 26px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.bmSelect {
	height: 26px;
	font-size: 0.78rem;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.canvasWrap {
	position: relative;
	display: inline-block;
	border: 1px solid var(--color-border-dark, var(--color-border));
	background: #ffffff;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.14);
	cursor: crosshair;
	line-height: 0;
}

.canvas {
	display: block;
	max-width: 100%;
	height: auto;
}

.sourcePin,
.targetPin {
	position: absolute;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	transform: translate(-50%, -50%);
	pointer-events: none;
	box-sizing: border-box;
}

.sourcePin {
	border: 3px solid #0082c9;
	background: rgba(0, 130, 201, 0.28);
}

.targetPin {
	border: 3px solid #e65100;
	background: rgba(230, 81, 0, 0.28);
}

.footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	padding: 10px 14px;
	border-top: 1px solid var(--color-border);
}

.footerControls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.labelInput {
	width: 170px;
	height: 30px;
	padding: 2px 8px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
}

.presetGroup,
.styleToggle {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px;
}

button[type='button'].chip {
	margin: 0;
	padding: 3px 8px;
	min-height: 26px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: 999px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.76rem;
	cursor: pointer;
}

button[type='button'].chipActive {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	font-weight: 600;
}

.reverseLabel {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 0.82rem;
	cursor: pointer;
}

.footerActions {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
