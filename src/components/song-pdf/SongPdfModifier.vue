<script setup lang="ts">
import type { Song, SongFile, Book, Composer, Collection, Artist, Genre, Key, CustomGroup, Year, Signature, SourceType, LibraryData } from '../../types/song'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { PDFDocument, degrees } from 'pdf-lib'
import { apiDelete, apiPost } from '../../api/client'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCacheBustedFileUrl, getFileName } from '../../utils/file'
import { describeRequestFailure } from '../../utils/api'
import OriginalSongActionSelector, { type OriginalSongAction } from './OriginalSongActionSelector.vue'

// Icons
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import SongPdfCropModal from './SongPdfCropModal.vue'
import { computeCropBoxForRotation, type PageCrop } from './crop-types'

GlobalWorkerOptions.workerSrc = pdfWorker

export interface SplitSegment {
	id: string
	title: string
	startPage: number
	endPage: number
}

const props = withDefaults(
	defineProps<{
		song: Song
		fileId?: string | number
		libraryId: number
		books?: Book[]
		composers?: Composer[]
		collections?: Collection[]
		artists?: Artist[]
		genres?: Genre[]
		keys?: Key[]
		customGroups?: CustomGroup[]
		years?: Year[]
		signatures?: Signature[]
		sourceTypes?: SourceType[]
	}>(),
	{
		books: () => [],
		composers: () => [],
		collections: () => [],
		artists: () => [],
		genres: () => [],
		keys: () => [],
		customGroups: () => [],
		years: () => [],
		signatures: () => [],
		sourceTypes: () => [],
	},
)

const emit = defineEmits<{
	saved: [data: LibraryData, songId?: number]
	cancel: []
}>()

// Target PDF file
const targetFile = computed<SongFile | undefined>(() => {
	const files = props.song.files ?? []
	if (props.fileId) {
		const found = files.find((f) => String(f.id) === String(props.fileId))
		if (found) return found
	}
	return files.find((f) => f.path.toLowerCase().endsWith('.pdf')) ?? files[0]
})

// State
type Mode = 'rotate' | 'split' | 'crop'
const activeMode = ref<Mode>('rotate')

const isLoading = ref(false)
const isSaving = ref(false)
const errorMessage = ref('')
const totalPages = ref(0)
const originalPdfBytes = shallowRef<Uint8Array | null>(null)
const activePdfDoc = shallowRef<any>(null)

// Page-specific state (1-indexed, size totalPages)
// pageRotations[pageIdx] = 0 | 90 | 180 | 270 (relative rotation added)
const pageRotations = ref<number[]>([])

// Page-specific crop state: Record<pageNum, PageCrop>
const pageCrops = ref<Record<number, PageCrop>>({})

// Crop modal state
const isCropModalOpen = ref(false)
const cropModalPage = ref(1)

// Thumbnails
const thumbnailCanvases = ref<Record<number, HTMLCanvasElement>>({})
const thumbnailContainers = ref<Record<number, HTMLElement | null>>({})

// Split state
export type { OriginalSongAction } from './OriginalSongActionSelector.vue'
const originalSongAction = ref<OriginalSongAction>('keep')
const attachedFilesCount = computed(() => props.song.files?.length ?? 0)
const hasExactlyOneFile = computed(() => attachedFilesCount.value === 1)
const splitSegments = ref<SplitSegment[]>([])
let segmentCounter = 0

// Selection state for quick range assignment
const selectedPages = ref<number[]>([])

// Load PDF bytes and initialize
async function loadPdf() {
	if (!targetFile.value?.path) {
		errorMessage.value = t('mobilesheetsmanager', 'No PDF file found for this song.')
		return
	}

	isLoading.value = true
	errorMessage.value = ''
	pageRotations.value = []
	thumbnailCanvases.value = {}
	selectedPages.value = []

	try {
		const targetUrl = getCacheBustedFileUrl(targetFile.value.path, `${targetFile.value.id}-${props.song.lastModified ?? ''}`)
		const response = await axios.get(targetUrl, {
			responseType: 'arraybuffer',
		})
		const bytes = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : new Uint8Array(response.data)
		// Store an independent clone of bytes so pdfjs worker transfer does not detach the array buffer
		originalPdfBytes.value = bytes.slice()

		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: bytes.slice(),
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

		activePdfDoc.value = pdf
		totalPages.value = pdf.numPages
		pageRotations.value = Array(pdf.numPages).fill(0)
		pageCrops.value = {}

		// Initialize split segments: default with 1 segment covering all pages
		segmentCounter = 1
		splitSegments.value = [
			{
				id: `seg-${segmentCounter}`,
				title: `${props.song.title} - Part 1`,
				startPage: 1,
				endPage: pdf.numPages,
			},
		]

		await nextTick()
		initThumbnailObserver(pdf)
	} catch (err: any) {
		console.error('Failed to load PDF:', err)
		errorMessage.value = t('mobilesheetsmanager', 'Failed to load PDF document: {error}', {
			error: err?.message ?? String(err),
		})
	} finally {
		isLoading.value = false
	}
}

let thumbnailObserver: IntersectionObserver | null = null
const renderedThumbnails = new Set<number>()

function cleanupThumbnailObserver() {
	if (thumbnailObserver) {
		thumbnailObserver.disconnect()
		thumbnailObserver = null
	}
	renderedThumbnails.clear()
}

async function renderSingleThumbnail(pdf: any, pageNum: number) {
	if (renderedThumbnails.has(pageNum)) return
	renderedThumbnails.add(pageNum)

	try {
		const page = await pdf.getPage(pageNum)
		const unscaledViewport = page.getViewport({ scale: 1.0 })
		const targetWidth = 140
		const scale = targetWidth / unscaledViewport.width
		const viewport = page.getViewport({ scale })

		const canvas = document.createElement('canvas')
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		canvas.className = 'mobilesheetsmanager-pdf-thumbnail-canvas'

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
		thumbnailCanvases.value[pageNum] = canvas

		const container = thumbnailContainers.value[pageNum]
		if (container) {
			container.replaceChildren(canvas)
		}
	} catch (e) {
		console.warn(`Could not render thumbnail for page ${pageNum}`, e)
	}
}

function initThumbnailObserver(pdf: any) {
	cleanupThumbnailObserver()

	// If IntersectionObserver is not supported (e.g. basic mock test environment), render first 20 or all
	if (typeof IntersectionObserver === 'undefined') {
		for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
			renderSingleThumbnail(pdf, pageNum)
		}
		return
	}

	thumbnailObserver = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				if (entry.isIntersecting) {
					const pageNum = Number((entry.target as HTMLElement).dataset.pageNum)
					if (pageNum && !renderedThumbnails.has(pageNum)) {
						renderSingleThumbnail(pdf, pageNum)
					}
				}
			}
		},
		{
			rootMargin: '300px 0px 300px 0px', // preload ahead of scrolling
		},
	)

	// Observe all registered containers that are currently attached
	for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
		const container = thumbnailContainers.value[pageNum]
		if (container) {
			container.dataset.pageNum = String(pageNum)
			thumbnailObserver.observe(container)
		}
	}
}

function setContainerRef(pageNum: number, el: any) {
	if (el) {
		thumbnailContainers.value[pageNum] = el as HTMLElement
		el.dataset.pageNum = String(pageNum)
		const existingCanvas = thumbnailCanvases.value[pageNum]
		if (existingCanvas && (!el.hasChildNodes() || el.firstChild !== existingCanvas)) {
			el.replaceChildren(existingCanvas)
		} else if (thumbnailObserver && !renderedThumbnails.has(pageNum)) {
			thumbnailObserver.observe(el)
		}
	}
}

onUnmounted(() => {
	cleanupThumbnailObserver()
})

// Rotation helpers
function rotatePage(pageIndex: number, deltaDeg: number) {
	const current = pageRotations.value[pageIndex] ?? 0
	const next = (current + deltaDeg) % 360
	pageRotations.value[pageIndex] = (next + 360) % 360
}

function rotateAll(deltaDeg: number) {
	for (let i = 0; i < pageRotations.value.length; i++) {
		rotatePage(i, deltaDeg)
	}
}

function resetRotations() {
	for (let i = 0; i < pageRotations.value.length; i++) {
		pageRotations.value[i] = 0
	}
}

const hasAnyRotation = computed(() => {
	return pageRotations.value.some((rot) => rot !== 0)
})

// Split management
function addSegment() {
	segmentCounter++
	const lastSeg = splitSegments.value[splitSegments.value.length - 1]
	let nextStart = 1
	if (lastSeg && lastSeg.endPage < totalPages.value) {
		nextStart = lastSeg.endPage + 1
	} else if (lastSeg) {
		nextStart = Math.min(lastSeg.endPage, totalPages.value)
	}
	splitSegments.value.push({
		id: `seg-${segmentCounter}`,
		title: `${props.song.title} - Part ${splitSegments.value.length + 1}`,
		startPage: nextStart,
		endPage: Math.min(nextStart, totalPages.value),
	})
}

function removeSegment(index: number) {
	if (splitSegments.value.length <= 1) return
	splitSegments.value.splice(index, 1)
}

function assignSelectedPagesToSegment(segment: SplitSegment) {
	if (selectedPages.value.length === 0) return
	const sorted = [...selectedPages.value].sort((a, b) => a - b)
	segment.startPage = sorted[0]
	segment.endPage = sorted[sorted.length - 1]
	selectedPages.value = []
}

function togglePageSelection(pageNum: number) {
	const idx = selectedPages.value.indexOf(pageNum)
	if (idx >= 0) {
		selectedPages.value.splice(idx, 1)
	} else {
		selectedPages.value.push(pageNum)
	}
}

function getSegmentsForPage(pageNum: number): SplitSegment[] {
	return splitSegments.value.filter((s) => pageNum >= s.startPage && pageNum <= s.endPage)
}

// Color palette for segments
const segmentColors = [
	'#3b82f6', // blue
	'#10b981', // emerald
	'#f59e0b', // amber
	'#8b5cf6', // purple
	'#ec4899', // pink
	'#06b6d4', // cyan
	'#84cc16', // lime
	'#f97316', // orange
]

function getSegmentColor(segIndex: number): string {
	return segmentColors[segIndex % segmentColors.length]
}

// Crop helpers
function openCropModal(pageNum: number) {
	cropModalPage.value = pageNum
	isCropModalOpen.value = true
}

function handleApplyCrop(crop: PageCrop, scope: 'current' | 'all') {
	if (scope === 'all') {
		const updated: Record<number, PageCrop> = {}
		for (let p = 1; p <= totalPages.value; p++) {
			updated[p] = { ...crop }
		}
		pageCrops.value = updated
	} else {
		pageCrops.value = {
			...pageCrops.value,
			[cropModalPage.value]: { ...crop },
		}
	}
}

function resetAllCrops() {
	pageCrops.value = {}
}

const hasAnyCrop = computed(() => {
	return Object.values(pageCrops.value).some((c) => (
		c.left > 0.005 ||
		c.top > 0.005 ||
		c.width < 0.995 ||
		c.height < 0.995
	))
})

function isPageCropped(pageNum: number): boolean {
	const c = pageCrops.value[pageNum]
	if (!c) return false
	return (
		c.left > 0.005 ||
		c.top > 0.005 ||
		c.width < 0.995 ||
		c.height < 0.995
	)
}

function getCropIndicatorText(pageNum: number): string {
	const c = pageCrops.value[pageNum]
	if (!c) return ''
	const top = Math.round(c.top * 100)
	const right = Math.round((1 - (c.left + c.width)) * 100)
	const bottom = Math.round((1 - (c.top + c.height)) * 100)
	const left = Math.round(c.left * 100)
	return `${top}% ${right}% ${bottom}% ${left}%`
}

// Saving Rotated and/or Cropped PDF
async function saveRotationsAndCrops() {
	if (!originalPdfBytes.value || !targetFile.value) return
	isSaving.value = true
	errorMessage.value = ''

	try {
		const pdfDoc = await PDFDocument.load(originalPdfBytes.value)
		const pages = pdfDoc.getPages()

		let modifiedRotations = false
		let modifiedCrops = false
		for (let i = 0; i < pages.length; i++) {
			const page = pages[i]
			const pageNum = i + 1

			// 1. Calculate net rotation
			const rotDelta = pageRotations.value[i] ?? 0
			const currentAngle = page.getRotation()?.angle ?? 0
			const netAngle = ((currentAngle + rotDelta) % 360 + 360) % 360

			if (rotDelta !== 0) {
				page.setRotation(degrees(netAngle))
				modifiedRotations = true
			}

			// 2. Apply Crop if any, taking the net rotation into account
			const crop = pageCrops.value[pageNum]
			if (crop && (crop.left > 0.005 || crop.top > 0.005 || crop.width < 0.995 || crop.height < 0.995)) {
				const { x: origX, y: origY, width: origW, height: origH } = page.getCropBox() ?? page.getMediaBox()
				const box = computeCropBoxForRotation(origX, origY, origW, origH, crop, netAngle)
				page.setCropBox(box.x, box.y, box.width, box.height)
				modifiedCrops = true
			}
		}

		if (!modifiedRotations && !modifiedCrops) {
			showSuccess(t('mobilesheetsmanager', 'No changes to save.'))
			isSaving.value = false
			return
		}

		const modifiedBytes = await pdfDoc.save()
		const fileName = getFileName(targetFile.value.path) || 'modified.pdf'
		const modifiedFile = new File([modifiedBytes as Uint8Array<ArrayBuffer>], fileName, { type: 'application/pdf' })

		// Upload the modified file to replace / update
		// 1. Delete original file
		await apiPost('/api/songs/{id}/file/{fileId}/delete', {
			libraryId: props.libraryId,
		}, {
			pathParams: { id: props.song.id, fileId: targetFile.value.id },
		})

		// 2. Upload replacement file
		const formData = new FormData()
		formData.append('file', modifiedFile)
		const uploadRes = await apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
			pathParams: { libraryId: props.libraryId, id: props.song.id },
		})

		const data = uploadRes.data.ocs.data as LibraryData
		const successMsg = (modifiedRotations && modifiedCrops)
			? t('mobilesheetsmanager', 'PDF changes saved successfully.')
			: modifiedRotations
				? t('mobilesheetsmanager', 'Rotated PDF saved successfully.')
				: t('mobilesheetsmanager', 'Cropped PDF saved successfully.')
		showSuccess(successMsg)
		emit('saved', data, props.song.id)
	} catch (err: any) {
		console.error('Failed to save modified PDF:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('mobilesheetsmanager', 'Failed to save PDF: {error}', {
			error: detail,
		})
		showError(errorMessage.value)
	} finally {
		isSaving.value = false
	}
}

const saveRotations = saveRotationsAndCrops
const saveCrops = saveRotationsAndCrops

// Saving Split PDF Segments
async function saveSplits() {
	if (!originalPdfBytes.value || !targetFile.value) return
	if (splitSegments.value.length === 0) {
		errorMessage.value = t('mobilesheetsmanager', 'Please configure at least one segment to split.')
		return
	}

	// Validate segments
	for (const seg of splitSegments.value) {
		if (!seg.title.trim()) {
			errorMessage.value = t('mobilesheetsmanager', 'All segments must have a title.')
			return
		}
		if (seg.startPage < 1 || seg.endPage > totalPages.value || seg.startPage > seg.endPage) {
			errorMessage.value = t('mobilesheetsmanager', 'Invalid page range ({start}–{end}) for "{title}".', {
				start: seg.startPage,
				end: seg.endPage,
				title: seg.title,
			})
			return
		}
	}

	isSaving.value = true
	errorMessage.value = ''

	try {
		const srcPdfDoc = await PDFDocument.load(originalPdfBytes.value)

		// Base metadata to inherit from props.song
		const composerIds = (props.song.composers ?? []).map((c) => c.id)
		const bookIds = (props.song.books ?? []).map((b) => b.id)
		const collectionIds = (props.song.collections ?? []).map((c) => c.id)
		const artistIds = (props.song.artists ?? []).map((a) => a.id)
		const genreIds = (props.song.genres ?? []).map((g) => g.id)
		const keyIds = (props.song.keys ?? []).map((k) => k.id)
		const customGroupIds = (props.song.customGroups ?? []).map((cg) => cg.id)
		const yearIds = (props.song.years ?? []).map((y) => y.id)
		const signatureIds = (props.song.signatures ?? []).map((s) => s.id)
		const sourceTypeIds = (props.song.sourceTypes ?? []).map((st) => st.id)

		let lastLibraryData: LibraryData | null = null
		let firstCreatedSongId: number | undefined = undefined

		for (const seg of splitSegments.value) {
			// Create sub-document
			const newPdfDoc = await PDFDocument.create()
			const pageIndices: number[] = []
			for (let p = seg.startPage; p <= seg.endPage; p++) {
				pageIndices.push(p - 1)
			}

			const copiedPages = await newPdfDoc.copyPages(srcPdfDoc, pageIndices)
			for (let i = 0; i < copiedPages.length; i++) {
				const srcPageNum = seg.startPage + i
				const rot = pageRotations.value[srcPageNum - 1] ?? 0
				if (rot !== 0) {
					copiedPages[i].setRotation(degrees((copiedPages[i].getRotation().angle + rot) % 360))
				}
				newPdfDoc.addPage(copiedPages[i])
			}

			const splitBytes = await newPdfDoc.save()
			const sanitizedTitle = seg.title.trim().replace(/[/\\?%*:|"<>]/g, '-')
			const splitFileName = `${sanitizedTitle}.pdf`
			const splitFile = new File([splitBytes as Uint8Array<ArrayBuffer>], splitFileName, { type: 'application/pdf' })

			// Create new song in MobileSheets
			const createSongRes = await apiPost('/api/songs', {
				libraryId: props.libraryId,
				title: seg.title.trim(),
				composerIds,
				bookIds,
				collectionIds,
				artistIds,
				genreIds,
				keyIds,
				customGroupIds,
				yearIds,
				signatureIds,
				sourceTypeIds,
				stars: props.song.stars ?? 0,
				difficulty: props.song.difficulty ?? 0,
				keywords: props.song.keywords ?? '',
				custom: props.song.custom ?? '',
				custom2: props.song.custom2 ?? '',
			} as any)

			const createdData = createSongRes.data.ocs.data as any
			const newSongId = createdData.createdSongId ?? createdData.id

			if (!newSongId) {
				throw new Error(t('mobilesheetsmanager', 'Failed to retrieve ID for created split song: {title}', { title: seg.title }))
			}

			if (firstCreatedSongId === undefined) {
				firstCreatedSongId = Number(newSongId)
			}

			// Upload split PDF file
			const formData = new FormData()
			formData.append('file', splitFile)
			const uploadRes = await apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
				pathParams: { libraryId: props.libraryId, id: newSongId },
			})

			lastLibraryData = uploadRes.data.ocs.data as LibraryData
		}

		// Target song to open after splitting: the first newly created split song
		const targetOpenSongId = firstCreatedSongId

		// Handle chosen action for original song / file
		if (originalSongAction.value === 'delete-song' && hasExactlyOneFile.value) {
			const delSongRes = await apiDelete('/api/songs/{id}', {
				pathParams: { id: props.song.id },
				queryParams: { libraryId: props.libraryId, deleteFiles: true },
			})
			lastLibraryData = (delSongRes.data?.ocs?.data as LibraryData) ?? lastLibraryData
			showSuccess(t('mobilesheetsmanager', 'Successfully split PDF into {count} songs and deleted original song.', { count: splitSegments.value.length }))
			if (lastLibraryData) {
				emit('saved', lastLibraryData, targetOpenSongId)
			} else {
				emit('cancel')
			}
			return
		} else if (originalSongAction.value === 'delete-file' && targetFile.value) {
			const delRes = await apiPost('/api/songs/{id}/file/{fileId}/delete', {
				libraryId: props.libraryId,
			}, {
				pathParams: { id: props.song.id, fileId: targetFile.value.id },
			})
			lastLibraryData = (delRes.data?.ocs?.data as LibraryData) ?? lastLibraryData
		}

		showSuccess(t('mobilesheetsmanager', 'Successfully split PDF into {count} songs.', { count: splitSegments.value.length }))
		if (lastLibraryData) {
			emit('saved', lastLibraryData, targetOpenSongId)
		} else {
			emit('cancel')
		}
	} catch (err: any) {
		console.error('Failed to split PDF:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('mobilesheetsmanager', 'Failed to split PDF: {error}', {
			error: detail,
		})
		showError(errorMessage.value)
	} finally {
		isSaving.value = false
	}
}

onMounted(() => {
	loadPdf()
})

watch(() => targetFile.value?.path, () => {
	loadPdf()
})
</script>

<template>
	<div :class="$style.container">
		<!-- Top Bar / Sub-header -->
		<header :class="$style.header">
			<div :class="$style.headerLeft">
				<NcButton
					variant="secondary"
					:title="t('mobilesheetsmanager', 'Back to song')"
					:aria-label="t('mobilesheetsmanager', 'Back to song')"
					@click="emit('cancel')">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>
				<div :class="$style.fileMeta">
					<span :class="$style.songTitle">{{ song.title }}</span>
					<span v-if="targetFile" :class="$style.fileName">
						({{ getFileName(targetFile.path) }} — {{ totalPages }} {{ totalPages === 1 ? t('mobilesheetsmanager', 'page') : t('mobilesheetsmanager', 'pages') }})
					</span>
				</div>
			</div>

			<!-- Mode Switcher Tabs -->
			<div :class="$style.modeTabs">
				<button
					type="button"
					:class="[$style.tabButton, activeMode === 'rotate' && $style.tabButtonActive]"
					@click="activeMode = 'rotate'">
					<RotateRight :size="18" />
					<span>{{ t('mobilesheetsmanager', 'Rotate Pages') }}</span>
				</button>
				<button
					type="button"
					:class="[$style.tabButton, activeMode === 'crop' && $style.tabButtonActive]"
					@click="activeMode = 'crop'">
					<CropIcon :size="18" />
					<span>{{ t('mobilesheetsmanager', 'Crop Pages') }}</span>
				</button>
				<button
					type="button"
					:class="[$style.tabButton, activeMode === 'split' && $style.tabButtonActive]"
					@click="activeMode = 'split'">
					<CallSplit :size="18" />
					<span>{{ t('mobilesheetsmanager', 'Split PDF') }}</span>
				</button>
			</div>

			<div :class="$style.headerRight">
				<NcButton
					v-if="activeMode === 'rotate'"
					variant="primary"
					:disabled="isSaving || isLoading || (!hasAnyRotation && !hasAnyCrop)"
					:title="t('mobilesheetsmanager', 'Save rotated PDF')"
					@click="saveRotations">
					<template #icon>
						<ContentSaveOutlineIcon :size="20" />
					</template>
					{{ isSaving ? t('mobilesheetsmanager', 'Saving…') : t('mobilesheetsmanager', 'Save Rotations') }}
				</NcButton>

				<NcButton
					v-else-if="activeMode === 'crop'"
					variant="primary"
					:disabled="isSaving || isLoading || (!hasAnyCrop && !hasAnyRotation)"
					:title="t('mobilesheetsmanager', 'Save cropped PDF')"
					@click="saveCrops">
					<template #icon>
						<ContentSaveOutlineIcon :size="20" />
					</template>
					{{ isSaving ? t('mobilesheetsmanager', 'Saving…') : t('mobilesheetsmanager', 'Save Crops') }}
				</NcButton>

				<NcButton
					v-else-if="activeMode === 'split'"
					variant="primary"
					:disabled="isSaving || isLoading || splitSegments.length === 0"
					:title="t('mobilesheetsmanager', 'Split and create songs')"
					@click="saveSplits">
					<template #icon>
						<CallSplit :size="20" />
					</template>
					{{ isSaving ? t('mobilesheetsmanager', 'Processing…') : t('mobilesheetsmanager', 'Split & Create Songs') }}
				</NcButton>
			</div>
		</header>

		<!-- Error Banner -->
		<div v-if="errorMessage" :class="$style.errorBanner">
			{{ errorMessage }}
		</div>

		<!-- Main Workspace Area -->
		<div v-if="isLoading" :class="$style.loadingArea">
			<NcLoadingIcon :size="48" />
			<span>{{ t('mobilesheetsmanager', 'Loading PDF pages…') }}</span>
		</div>

		<div v-else :class="$style.contentLayout">
			<!-- Left / Main Panel: Page Preview Grid -->
			<div :class="$style.previewSection">
				<!-- Global Toolbar for Active Mode -->
				<div :class="$style.toolbar">
					<template v-if="activeMode === 'rotate'">
						<span :class="$style.toolbarTitle">{{ t('mobilesheetsmanager', 'Quick Actions:') }}</span>
						<NcButton size="small" variant="secondary" @click="rotateAll(90)">
							<template #icon>
								<RotateRight :size="16" />
							</template>
							{{ t('mobilesheetsmanager', 'Rotate All 90° CW') }}
						</NcButton>
						<NcButton size="small" variant="secondary" @click="rotateAll(-90)">
							<template #icon>
								<RotateLeft :size="16" />
							</template>
							{{ t('mobilesheetsmanager', 'Rotate All 90° CCW') }}
						</NcButton>
						<NcButton size="small" variant="tertiary" :disabled="!hasAnyRotation" @click="resetRotations">
							{{ t('mobilesheetsmanager', 'Reset All') }}
						</NcButton>
					</template>

					<template v-else-if="activeMode === 'crop'">
						<span :class="$style.toolbarTitle">
							{{ t('mobilesheetsmanager', 'Click on any page or "Crop" button to adjust crop margins:') }}
						</span>
						<NcButton
							size="small"
							variant="secondary"
							:title="t('mobilesheetsmanager', 'Crop first page and apply to all')"
							@click="openCropModal(1)">
							<template #icon>
								<CropIcon :size="16" />
							</template>
							{{ t('mobilesheetsmanager', 'Crop All Pages…') }}
						</NcButton>
						<NcButton
							size="small"
							variant="tertiary"
							:disabled="!hasAnyCrop"
							:title="t('mobilesheetsmanager', 'Reset all page crops to full page')"
							@click="resetAllCrops">
							{{ t('mobilesheetsmanager', 'Reset All Crops') }}
						</NcButton>
					</template>

					<template v-else-if="activeMode === 'split'">
						<span :class="$style.toolbarTitle">
							{{ t('mobilesheetsmanager', 'Select pages in grid to assign to segments:') }}
						</span>
						<span v-if="selectedPages.length > 0" :class="$style.selectionBadge">
							{{ t('mobilesheetsmanager', '{count} pages selected', { count: selectedPages.length }) }}
						</span>
						<NcButton
							v-if="selectedPages.length > 0"
							size="small"
							variant="tertiary"
							@click="selectedPages = []">
							{{ t('mobilesheetsmanager', 'Clear Selection') }}
						</NcButton>
					</template>
				</div>

				<!-- Thumbnail Grid -->
				<div :class="$style.grid">
					<div
						v-for="pageNum in totalPages"
						:key="pageNum"
						:class="[
							$style.pageCard,
							selectedPages.includes(pageNum) && $style.pageCardSelected,
							activeMode === 'crop' && isPageCropped(pageNum) && $style.pageCardCropped,
						]"
						@click="activeMode === 'split' ? togglePageSelection(pageNum) : activeMode === 'crop' ? openCropModal(pageNum) : undefined">
						<!-- Segment color indicators bar -->
						<div v-if="activeMode === 'split'" :class="$style.segmentPills">
							<span
								v-for="(seg, sIdx) in getSegmentsForPage(pageNum)"
								:key="seg.id"
								:class="$style.segmentPill"
								:style="{ backgroundColor: getSegmentColor(splitSegments.indexOf(seg)) }"
								:title="seg.title">
								{{ splitSegments.indexOf(seg) + 1 }}
							</span>
						</div>

						<div :class="$style.canvasWrapper">
							<div
								:ref="(el) => setContainerRef(pageNum, el)"
								:class="$style.thumbnailSlot"
								:style="{
									transform: `rotate(${pageRotations[pageNum - 1] || 0}deg)`,
									transition: 'transform 0.15s ease-out'
								}"></div>
						</div>

						<div :class="$style.cardFooter">
							<span :class="$style.pageNumLabel">
								{{ t('mobilesheetsmanager', 'p. {page}', { page: pageNum }) }}
								<span v-if="pageRotations[pageNum - 1]" :class="$style.rotationBadge">
									{{ pageRotations[pageNum - 1] }}°
								</span>
								<span v-if="activeMode === 'crop' && isPageCropped(pageNum)" :class="$style.cropBadge" :title="getCropIndicatorText(pageNum)">
									{{ t('mobilesheetsmanager', 'Cropped') }}
								</span>
							</span>

							<!-- Individual Page Rotate Buttons -->
							<div v-if="activeMode === 'rotate'" :class="$style.cardActions">
								<NcButton
									size="small"
									variant="tertiary"
									:title="t('mobilesheetsmanager', 'Rotate page 90° CCW')"
									@click.stop="rotatePage(pageNum - 1, -90)">
									<template #icon>
										<RotateLeft :size="16" />
									</template>
								</NcButton>
								<NcButton
									size="small"
									variant="tertiary"
									:title="t('mobilesheetsmanager', 'Rotate page 90° CW')"
									@click.stop="rotatePage(pageNum - 1, 90)">
									<template #icon>
										<RotateRight :size="16" />
									</template>
								</NcButton>
							</div>

							<!-- Crop button in Crop mode -->
							<div v-else-if="activeMode === 'crop'" :class="$style.cardActions">
								<NcButton
									size="small"
									:variant="isPageCropped(pageNum) ? 'primary' : 'tertiary'"
									:title="t('mobilesheetsmanager', 'Crop page {page}', { page: pageNum })"
									@click.stop="openCropModal(pageNum)">
									<template #icon>
										<CropIcon :size="16" />
									</template>
									{{ t('mobilesheetsmanager', 'Crop') }}
								</NcButton>
							</div>

							<!-- Selection indicator in split mode -->
							<div v-else-if="activeMode === 'split'" :class="$style.selectCheckbox">
								<CheckboxMarkedCircleOutline
									v-if="selectedPages.includes(pageNum)"
									:size="18"
									:class="$style.checkedIcon" />
								<span v-else :class="$style.uncheckedDot"></span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Right Sidebar: Split Segments Configuration -->
			<aside v-if="activeMode === 'split'" :class="$style.splitSidebar">
				<div :class="$style.sidebarHeader">
					<h4>{{ t('mobilesheetsmanager', 'Split Segments') }}</h4>
					<NcButton size="small" variant="secondary" @click="addSegment">
						<template #icon>
							<PlusIcon :size="16" />
						</template>
						{{ t('mobilesheetsmanager', 'Add Part') }}
					</NcButton>
				</div>

				<p :class="$style.sidebarHelp">
					{{ t('mobilesheetsmanager', 'Each segment creates a new song in the same book, copying metadata from the original song.') }}
				</p>

				<!-- Segments list -->
				<div :class="$style.segmentList">
					<div
						v-for="(seg, idx) in splitSegments"
						:key="seg.id"
						:class="$style.segmentCard"
						:style="{ borderLeftColor: getSegmentColor(idx) }">
						<div :class="$style.segmentCardHeader">
							<span
								:class="$style.segmentNumberBadge"
								:style="{ backgroundColor: getSegmentColor(idx) }">
								{{ idx + 1 }}
							</span>
							<NcTextField
								v-model="seg.title"
								:label="t('mobilesheetsmanager', 'Song Title')"
								hide-label
								:class="$style.segmentTitleInput"
								:placeholder="t('mobilesheetsmanager', 'Song title…')" />
							<NcButton
								v-if="splitSegments.length > 1"
								size="small"
								variant="tertiary"
								:title="t('mobilesheetsmanager', 'Remove part')"
								@click="removeSegment(idx)">
								<template #icon>
									<DeleteIcon :size="16" />
								</template>
							</NcButton>
						</div>

						<div :class="$style.segmentCardBody">
							<div :class="$style.rangeGroup">
								<label :class="$style.rangeLabel">{{ t('mobilesheetsmanager', 'Pages:') }}</label>
								<input
									v-model.number="seg.startPage"
									type="number"
									min="1"
									:max="totalPages"
									:class="$style.pageInput" />
								<span>{{ t('mobilesheetsmanager', 'to') }}</span>
								<input
									v-model.number="seg.endPage"
									type="number"
									:min="seg.startPage"
									:max="totalPages"
									:class="$style.pageInput" />
							</div>

							<NcButton
								v-if="selectedPages.length > 0"
								size="small"
								variant="secondary"
								:class="$style.assignBtn"
								@click="assignSelectedPagesToSegment(seg)">
								<template #icon>
									<ArrowRight :size="14" />
								</template>
								{{ t('mobilesheetsmanager', 'Assign selected ({range})', {
									range: `${Math.min(...selectedPages)}–${Math.max(...selectedPages)}`
								}) }}
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Options for Original Song -->
				<OriginalSongActionSelector
					v-model="originalSongAction"
					:attached-files-count="attachedFilesCount" />
			</aside>
		</div>

		<!-- Page Crop Modal -->
		<SongPdfCropModal
			v-model:open="isCropModalOpen"
			:page-num="cropModalPage"
			:total-pages="totalPages"
			:pdf="activePdfDoc"
			:initial-crop="pageCrops[cropModalPage]"
			:all-pages-count="totalPages"
			:rotation="pageRotations[cropModalPage - 1]"
			@apply="handleApplyCrop"
			@change-page="cropModalPage = $event" />
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	background-color: var(--color-main-background);
	overflow: hidden;
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	gap: 16px;
	flex-shrink: 0;
}

.headerLeft {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.fileMeta {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.songTitle {
	font-weight: bold;
	font-size: 15px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.fileName {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.modeTabs {
	display: flex;
	align-items: center;
	background-color: var(--color-background-hover);
	border-radius: 20px;
	padding: 2px;
	gap: 2px;
}

.tabButton {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border: none;
	background: transparent;
	border-radius: 18px;
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: all 0.15s ease;
}

.tabButtonActive {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.headerRight {
	display: flex;
	align-items: center;
	gap: 8px;
}

.errorBanner {
	background-color: var(--color-error);
	color: var(--color-primary-text);
	padding: 8px 16px;
	font-size: 13px;
}

.loadingArea {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	gap: 12px;
	color: var(--color-text-maxcontrast);
}

.contentLayout {
	display: flex;
	flex: 1;
	min-height: 0;
	overflow: hidden;
}

.previewSection {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
	height: 100%;
	overflow: hidden;
}

.toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.toolbarTitle {
	font-size: 13px;
	font-weight: 500;
	margin-right: 4px;
}

.selectionBadge {
	font-size: 12px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	padding: 2px 8px;
	border-radius: 12px;
	font-weight: bold;
}

.grid {
	flex: 1;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 16px;
	padding: 16px;
	overflow-y: auto;
}

.pageCard {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	background-color: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: 8px;
	padding: 8px;
	cursor: pointer;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
	user-select: none;
}

.pageCard:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.pageCardSelected {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.pageCardCropped {
	border-color: #10b981;
}

.cropBadge {
	font-size: 11px;
	color: #059669;
	background-color: #d1fae5;
	padding: 1px 6px;
	border-radius: 10px;
	font-weight: 600;
	margin-left: 4px;
}

.segmentPills {
	position: absolute;
	top: 4px;
	left: 4px;
	display: flex;
	gap: 3px;
	z-index: 2;
}

.segmentPill {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	color: #fff;
	font-size: 11px;
	font-weight: bold;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.canvasWrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 140px;
	height: 190px;
	background-color: #f3f4f6;
	border-radius: 4px;
	overflow: hidden;
}

.thumbnailSlot {
	display: flex;
	align-items: center;
	justify-content: center;
}

.thumbnailSlot :global(canvas) {
	max-width: 140px;
	max-height: 190px;
	object-fit: contain;
	display: block;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.cardFooter {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	margin-top: 8px;
}

.pageNumLabel {
	font-size: 12px;
	font-weight: 500;
}

.rotationBadge {
	font-size: 11px;
	color: var(--color-primary-element);
	font-weight: bold;
	margin-left: 4px;
}

.cardActions {
	display: flex;
	gap: 2px;
}

.selectCheckbox {
	display: flex;
	align-items: center;
}

.checkedIcon {
	color: var(--color-primary-element);
}

.uncheckedDot {
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border);
}

/* Sidebar for Split */
.splitSidebar {
	width: 320px;
	border-left: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
	padding: 16px;
	overflow-y: auto;
	flex-shrink: 0;
	gap: 16px;
}

.sidebarHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.sidebarHeader h4 {
	margin: 0;
	font-size: 16px;
}

.sidebarHelp {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.segmentList {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.segmentCard {
	border: 1px solid var(--color-border);
	border-left-width: 4px;
	border-radius: 6px;
	padding: 10px;
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.segmentCardHeader {
	display: flex;
	align-items: center;
	gap: 6px;
}

.segmentNumberBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	color: #fff;
	font-size: 11px;
	font-weight: bold;
	flex-shrink: 0;
}

.segmentTitleInput {
	flex: 1;
	min-width: 0;
}

.segmentCardBody {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.rangeGroup {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}

.rangeLabel {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.pageInput {
	width: 52px;
	padding: 3px 6px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 13px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.assignBtn {
	align-self: flex-start;
}
</style>

