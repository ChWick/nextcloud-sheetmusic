<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import type { PDFDocumentProxy, PDFPageProxy } from 'pdfjs-dist'
import { translate as t } from '@nextcloud/l10n'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import type { Bookmark } from '../../types/song'
import type { LinkMarkerStyle } from '../../utils/annotationLinks'
import { cleanupPdfJsPage, releaseCanvas, renderPdfPageToCanvas } from '../../utils/pdfjs'

const props = withDefaults(defineProps<{
	role: 'source' | 'target'
	pdfDoc: PDFDocumentProxy | null
	pageIndex: number
	totalPages: number
	pinX: number
	pinY: number
	pinStyle: LinkMarkerStyle
	pinLabel: string
	bookmarks?: Bookmark[]
}>(), {
	bookmarks: () => [],
})

const emit = defineEmits<{
	'update:pageIndex': [pageIndex: number]
	'pick-point': [point: { x: number; y: number }]
	'select-bookmark': [bookmark: Bookmark]
}>()

const canvasRef = ref<HTMLCanvasElement | null>(null)
let renderGeneration = 0

async function renderPage() {
	const doc = props.pdfDoc
	const canvas = canvasRef.value
	if (!doc || !canvas) return
	const gen = ++renderGeneration
	const clampedPage = Math.min(Math.max(1, props.pageIndex + 1), doc.numPages)
	let page: PDFPageProxy | null = null
	try {
		page = await doc.getPage(clampedPage)
		if (gen !== renderGeneration) return
		const baseVp = page.getViewport({ scale: 1 })
		const scale = 360 / Math.max(1, baseVp.width)
		const vp = page.getViewport({ scale })
		canvas.style.width = `${Math.round(vp.width)}px`
		canvas.style.height = `${Math.round(vp.height)}px`
		await renderPdfPageToCanvas(page, canvas, vp, { dpr: Math.min(window.devicePixelRatio || 1, 2) })
	} catch {
		// Ignore transient PDF render errors in picker
	} finally {
		cleanupPdfJsPage(page)
	}
}

watch(
	[() => props.pdfDoc, () => props.pageIndex, canvasRef],
	() => {
		void renderPage()
	},
	{ immediate: true },
)

onUnmounted(() => {
	renderGeneration++
	releaseCanvas(canvasRef.value)
})

function onSurfaceClick(event: MouseEvent) {
	const el = event.currentTarget as HTMLElement | null
	if (!el) return
	const rect = el.getBoundingClientRect()
	if (rect.width <= 0 || rect.height <= 0) return
	const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
	const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height))
	emit('pick-point', { x, y })
}

function onBookmarkChange(event: Event) {
	const val = (event.target as HTMLSelectElement).value
	if (!val) return
	const bm = props.bookmarks[Number.parseInt(val, 10)]
	if (bm) emit('select-bookmark', bm)
}
</script>

<template>
	<section :class="$style.pane" :data-test="`link-picker-${role}-pane`">
		<div :class="$style.paneBar">
			<span :class="role === 'source' ? $style.paneBadgeSource : $style.paneBadgeTarget">
				{{ role === 'source' ? t('sheetmusic', 'Source') : t('sheetmusic', 'Target') }}
			</span>
			<div :class="$style.pager">
				<button
					type="button"
					:class="$style.pageBtn"
					:disabled="pageIndex <= 0"
					:aria-label="role === 'source' ? t('sheetmusic', 'Previous source page') : t('sheetmusic', 'Previous target page')"
					:data-test="`link-picker-${role}-prev`"
					@click="emit('update:pageIndex', Math.max(0, pageIndex - 1))">
					<ChevronLeftIcon :size="18" />
				</button>
				<span :data-test="`link-picker-${role}-page`">
					{{ t('sheetmusic', 'Page {page} / {total}', { page: pageIndex + 1, total: totalPages }) }}
				</span>
				<button
					type="button"
					:class="$style.pageBtn"
					:disabled="pageIndex >= totalPages - 1"
					:aria-label="role === 'source' ? t('sheetmusic', 'Next source page') : t('sheetmusic', 'Next target page')"
					:data-test="`link-picker-${role}-next`"
					@click="emit('update:pageIndex', Math.min(totalPages - 1, pageIndex + 1))">
					<ChevronRightIcon :size="18" />
				</button>
			</div>
			<select
				v-if="bookmarks.length > 0"
				:class="$style.bmSelect"
				:aria-label="t('sheetmusic', 'Jump target to bookmark')"
				data-test="link-picker-bookmark-select"
				@change="onBookmarkChange">
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
			:data-test="`link-picker-${role}-surface`"
			@click="onSurfaceClick">
			<canvas ref="canvasRef" :class="$style.canvas" width="360" height="480" />
			<span
				:class="[
					role === 'source' ? $style.sourcePin : $style.targetPin,
					pinStyle === 'box' ? $style.pinBox : $style.pinCircle,
				]"
				:style="{ left: `${pinX * 100}%`, top: `${pinY * 100}%` }"
				:data-pin-style="pinStyle"
				:data-test="`link-picker-${role}-pin`">
				{{ pinLabel }}
			</span>
		</div>
	</section>
</template>

<style module>
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
	display: inline-flex;
	align-items: center;
	justify-content: center;
	transform: translate(-50%, -50%);
	pointer-events: none;
	box-sizing: border-box;
	font-size: 10px;
	font-weight: 700;
	line-height: 1;
	white-space: nowrap;
	transition: border-radius 0.12s ease, padding 0.12s ease;
}

.pinCircle {
	min-width: 22px;
	height: 22px;
	padding: 0 4px;
	border-radius: 999px;
}

.pinBox {
	min-width: 44px;
	height: 22px;
	padding: 0 6px;
	border-radius: 4px;
}

.sourcePin {
	border: 2px solid #0082c9;
	background: rgba(0, 130, 201, 0.22);
	color: #0052cc;
}

.targetPin {
	border: 2px solid #e65100;
	background: rgba(230, 81, 0, 0.22);
	color: #bf360c;
}
</style>
