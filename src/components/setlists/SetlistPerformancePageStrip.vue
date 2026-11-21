<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { defaultFileBinaryCache } from '../../utils/file-cache'
import { isPdfFile } from '../../utils/file'
import { parsePageOrder } from '../../utils/page-order'
import { cleanupPdfJsPage, destroyPdfJsDocument, loadPdfJsDocument, releaseChildCanvases, renderPdfPageToCanvas } from '../../utils/pdfjs'

export interface SetlistStripSong {
	itemIndex: number
	title: string
	bookmarkName?: string | null
	filePath: string
	pageOrder?: string | null
	pageCount: number
}

const props = defineProps<{
	open: boolean
	songs: SetlistStripSong[]
	currentItemIndex: number
	currentPage: number
}>()

const emit = defineEmits<{
	select: [payload: { itemIndex: number; page: number }]
	close: []
	pauseAutoHide: []
	resumeAutoHide: []
}>()

const stripRef = ref<HTMLElement | null>(null)
const resolvedPageCounts = ref<Record<number, number>>({})
const pdfCache = new Map<string, Promise<any | null>>()
const renderedKeys = new Set<string>()
let observer: IntersectionObserver | null = null

let isPointerDragging = false
let pointerStartX = 0
let pointerStartScrollLeft = 0
let didDragScroll = false

function onStripWheel(event: WheelEvent) {
	const el = stripRef.value
	if (!el) return
	const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY
	if (delta !== 0) {
		event.preventDefault()
		el.scrollLeft += delta
	}
}

function onStripPointerDown(event: PointerEvent) {
	if (event.pointerType === 'touch') return
	const el = stripRef.value
	if (!el) return
	isPointerDragging = true
	didDragScroll = false
	pointerStartX = event.clientX
	pointerStartScrollLeft = el.scrollLeft
	window.addEventListener('pointermove', onStripPointerMove)
	window.addEventListener('pointerup', onStripPointerUp, { once: true })
	window.addEventListener('pointercancel', onStripPointerUp, { once: true })
}

function onStripPointerMove(event: PointerEvent) {
	if (!isPointerDragging || !stripRef.value) return
	const dx = event.clientX - pointerStartX
	if (Math.abs(dx) > 5) {
		didDragScroll = true
	}
	if (didDragScroll) {
		stripRef.value.scrollLeft = pointerStartScrollLeft - dx
	}
}

function onStripPointerUp() {
	isPointerDragging = false
	window.removeEventListener('pointermove', onStripPointerMove)
	window.removeEventListener('pointerup', onStripPointerUp)
	window.removeEventListener('pointercancel', onStripPointerUp)
	if (didDragScroll) {
		setTimeout(() => {
			didDragScroll = false
		}, 0)
	}
}

function onSelectThumb(itemIndex: number, page: number) {
	if (didDragScroll) return
	emit('select', { itemIndex, page })
}

const totalSetlistPages = computed(() => {
	return props.songs.reduce((sum, s) => sum + getSongPageCount(s), 0)
})

function getSongPageCount(song: SetlistStripSong): number {
	const count = resolvedPageCounts.value[song.itemIndex] ?? song.pageCount
	return Math.max(1, count)
}

function getPdfDocument(filePath: string): Promise<any | null> {
	if (!isPdfFile(filePath)) {
		return Promise.resolve(null)
	}
	const existing = pdfCache.get(filePath)
	if (existing) return existing

	const promise = (async () => {
		try {
			let data = defaultFileBinaryCache.get(filePath)
			if (!data) {
				const response = await axios.get(filePath, { responseType: 'arraybuffer' })
				data = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
				if (data) {
					defaultFileBinaryCache.set(filePath, data)
				}
			}
			if (!data) return null
			return await loadPdfJsDocument(data.slice())
		} catch {
			return null
		}
	})()

	pdfCache.set(filePath, promise)
	return promise
}

async function renderThumbnailForCanvas(canvas: HTMLCanvasElement, song: SetlistStripSong, logicalPage: number) {
	const key = `${song.itemIndex}-${song.filePath}-${logicalPage}`
	if (renderedKeys.has(key)) return
	renderedKeys.add(key)

	const pdf = await getPdfDocument(song.filePath)
	if (!pdf || !props.open) return

	const ordered = parsePageOrder(song.pageOrder, pdf.numPages)
	if (ordered.length > 0 && resolvedPageCounts.value[song.itemIndex] !== ordered.length) {
		resolvedPageCounts.value = {
			...resolvedPageCounts.value,
			[song.itemIndex]: ordered.length,
		}
	}

	const idx = Math.min(Math.max(1, logicalPage), ordered.length) - 1
	const physicalPage = ordered[idx] || 1
	let page: any = null
	try {
		page = await pdf.getPage(physicalPage)
		const baseViewport = page.getViewport({ scale: 1.0 })
		const scale = 88 / Math.max(1, baseViewport.width)
		const viewport = page.getViewport({ scale })
		await renderPdfPageToCanvas(page, canvas, viewport, { applyCssSize: false })
	} catch {
		// Ignore thumbnail render error
	} finally {
		cleanupPdfJsPage(page)
	}
}

function cleanupObserver() {
	if (observer) {
		observer.disconnect()
		observer = null
	}
}

async function setupStripObserver() {
	cleanupObserver()
	renderedKeys.clear()
	if (!props.open) return
	await nextTick()
	const root = stripRef.value
	if (!root) return

	const activeEl = root.querySelector<HTMLElement>(
		`[data-thumb-key="${props.currentItemIndex}-${props.currentPage}"]`,
	)
	if (activeEl && typeof activeEl.scrollIntoView === 'function') {
		activeEl.scrollIntoView({ inline: 'center', block: 'nearest' })
	}

	const canvases = root.querySelectorAll<HTMLCanvasElement>('canvas[data-item-index]')
	if (typeof IntersectionObserver === 'undefined') {
		for (const canvas of canvases) {
			const itemIndex = Number(canvas.dataset.itemIndex)
			const page = Number(canvas.dataset.page)
			const song = props.songs.find((s) => s.itemIndex === itemIndex)
			if (song && page) {
				renderThumbnailForCanvas(canvas, song, page)
			}
		}
		return
	}

	observer = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				if (!entry.isIntersecting) continue
				const canvas = entry.target as HTMLCanvasElement
				const itemIndex = Number(canvas.dataset.itemIndex)
				const page = Number(canvas.dataset.page)
				const song = props.songs.find((s) => s.itemIndex === itemIndex)
				if (song && page) {
					renderThumbnailForCanvas(canvas, song, page)
				}
				observer?.unobserve(canvas)
			}
		},
		{ root, rootMargin: '0px 300px 0px 300px' },
	)

	canvases.forEach((canvas) => observer?.observe(canvas))
}

watch(
	() => [props.open, props.songs],
	([isOpen]) => {
		if (isOpen) {
			setupStripObserver()
		} else {
			cleanupObserver()
			releaseChildCanvases(stripRef.value)
		}
	},
	{ immediate: true },
)

onUnmounted(() => {
	cleanupObserver()
	releaseChildCanvases(stripRef.value)
	onStripPointerUp()
	for (const promise of pdfCache.values()) {
		void promise.then((pdf) => {
			if (pdf) return destroyPdfJsDocument(pdf)
		})
	}
	pdfCache.clear()
})
</script>

<template>
	<div
		v-if="open"
		:class="$style.stripContainer"
		role="dialog"
		:aria-label="t('sheetmusic', 'Jump to page')"
		@click.stop
		@wheel="onStripWheel"
		@mouseenter="emit('pauseAutoHide')"
		@mouseleave="emit('resumeAutoHide')">
		<div :class="$style.stripHeader">
			<span :class="$style.stripTitle">
				{{ t('sheetmusic', 'Setlist Pages ({total})', { total: totalSetlistPages }) }}
			</span>
			<button
				type="button"
				:class="$style.closeBtn"
				:aria-label="t('sheetmusic', 'Close')"
				@click="emit('close')">
				<CloseIcon :size="16" />
			</button>
		</div>
		<div
			ref="stripRef"
			:class="$style.stripScroll"
			@pointerdown="onStripPointerDown">
			<div
				v-for="song in songs"
				:key="song.itemIndex"
				:class="[$style.songGroup, song.itemIndex === currentItemIndex && $style.songGroupActive]">
				<div :class="$style.songLabel" :title="song.title">
					<span :class="$style.songNumber">{{ song.itemIndex + 1 }}.</span>
					<span :class="$style.songTitle">{{ song.title }}</span>
				</div>
				<div :class="$style.songPages">
					<button
						v-for="page in getSongPageCount(song)"
						:key="page"
						type="button"
						:data-thumb-key="`${song.itemIndex}-${page}`"
						:class="[
							$style.thumbCard,
							song.itemIndex === currentItemIndex && page === currentPage && $style.thumbCardActive,
						]"
						:aria-label="t('sheetmusic', '{song} - Page {page}', { song: song.title, page })"
						@click="onSelectThumb(song.itemIndex, page)">
						<canvas
							:data-item-index="song.itemIndex"
							:data-page="page"
							:class="$style.thumbCanvas" />
						<span :class="$style.pageNum">{{ page }}</span>
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<style module>
.stripContainer {
	position: fixed;
	left: 12px;
	right: 12px;
	bottom: 60px;
	z-index: 100015;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25);
	padding: 8px 12px 10px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.stripHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.stripTitle {
	font-size: 0.82rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.closeBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.closeBtn:hover {
	background-color: var(--color-background-hover);
}

.stripScroll {
	display: flex;
	align-items: stretch;
	gap: 14px;
	overflow-x: auto;
	padding: 4px 2px;
	cursor: grab;
	user-select: none;
}

.stripScroll:active {
	cursor: grabbing;
}

.songGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	flex-shrink: 0;
}

.songGroupActive {
	border-color: var(--color-primary-element);
}

.songLabel {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.78rem;
	font-weight: 600;
	color: var(--color-main-text);
	max-width: 220px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.songNumber {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.songTitle {
	overflow: hidden;
	text-overflow: ellipsis;
}

.songPages {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.thumbCard {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: 3px;
	border: 2px solid transparent;
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	cursor: pointer;
	flex-shrink: 0;
}

.thumbCard:hover {
	border-color: var(--color-border-dark);
}

.thumbCardActive {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.thumbCanvas {
	display: block;
	width: 82px;
	height: 112px;
	object-fit: contain;
	background-color: #ffffff;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.pageNum {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-main-text);
}
</style>
