<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LibraryData, Song, SongFile } from '../../types/song'
import { computed, nextTick, onUnmounted, ref, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import MagnifyPlusOutline from 'vue-material-design-icons/MagnifyPlusOutline.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import { mergeSongFiles } from '../../api/songFiles'
import { describeRequestFailure } from '../../utils/api'
import { getCacheBustedFileUrl, getFileName, toUint8Array } from '../../utils/file'
import { destroyPdfJsDocument, loadPdfJsDocument, setBoundedRecordEntry } from '../../utils/pdfjs'
import { usePageOrderDragDrop } from './usePageOrderDragDrop'
import { renderPageImage } from './usePageOrderThumbnails'
import SongPageOrderZoomModal from './SongPageOrderZoomModal.vue'
import SongPdfSaveProgress from './SongPdfSaveProgress.vue'
import {
	buildInitialMergePages,
	buildMergedFileName,
	buildMergedPdf,
	createMergePageEntry,
	duplicateMergePage,
	getSourceFileColor,
	removeMergePage,
	reorderMergePages,
	reorderPagesByFileOrder,
	rotateMergePage,
	type MergeOriginalsAction,
	type MergePageEntry,
	type MergeSourcePdf,
} from './pdf-merge-engine'

interface LoadedSourceFile extends MergeSourcePdf {
	file: SongFile
	included: boolean
}

const props = defineProps<{
	open: boolean
	song: Song
	libraryId: number
	pdfFiles: SongFile[]
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'merged': [data: LibraryData]
}>()

const isLoading = ref(false)
const isSaving = ref(false)
const saveProgressPercent = ref(0)
const saveProgressText = ref('')
const errorMessage = ref('')

const loadedSources = ref<LoadedSourceFile[]>([])
const pdfJsDocs = shallowRef<Map<number | string, any>>(new Map())
const mergePages = ref<MergePageEntry[]>([])
const thumbnails = ref<Record<string, string>>({})

const originalsAction = ref<MergeOriginalsAction>('delete-originals')

// Card width zoom controls
const DEFAULT_CARD_WIDTH = 156
const MIN_CARD_WIDTH = 110
const MAX_CARD_WIDTH = 280
const cardWidth = ref(DEFAULT_CARD_WIDTH)

function zoomIn() {
	cardWidth.value = Math.min(MAX_CARD_WIDTH, cardWidth.value + 28)
}

function zoomOut() {
	cardWidth.value = Math.max(MIN_CARD_WIDTH, cardWidth.value - 28)
}

function resetZoom() {
	cardWidth.value = DEFAULT_CARD_WIDTH
}

// Drag & drop state (reused from page-order editor)
const {
	draggedItem,
	dropTargetIndex,
	dropIndicatorSide,
	handleSequenceDragStart,
	handleCardDragOver,
	handleCardDragLeave,
	handleCardDrop,
	handleDragEnd,
} = usePageOrderDragDrop()

function handlePageCardDrop(event: DragEvent, targetIndex: number) {
	handleCardDrop(event, targetIndex, {
		onInsert: () => {},
		onReorder: (from, to) => {
			mergePages.value = reorderMergePages(mergePages.value, from, to)
		},
	})
}

// Zoom lightbox state
const isZoomModalOpen = ref(false)
const zoomSequenceIndex = ref(0)
const highResCache = ref<Record<string, string>>({})
const highResOrder = new Set<string>()
const isLoadingHighRes = ref(false)

let activeLoadToken = 0

function thumbKey(fileId: number | string, pageNum: number): string {
	return `${String(fileId)}:${pageNum}`
}

const includedSources = computed(() => loadedSources.value.filter((s) => s.included))

const canSubmit = computed(() => {
	if (isLoading.value || isSaving.value) return false
	if (includedSources.value.length < 2) return false
	if (mergePages.value.length === 0) return false
	return true
})

const sourceByFileId = computed(() => {
	const map = new Map<number | string, { source: LoadedSourceFile; index: number }>()
	loadedSources.value.forEach((source, index) => {
		map.set(source.fileId, { source, index })
	})
	return map
})

function getPageSourceLabel(entry: MergePageEntry): string {
	const info = sourceByFileId.value.get(entry.fileId)
	const idx = info ? info.index + 1 : entry.fileIndex + 1
	return t('sheetmusic', 'PDF {num} · p.{page}', { num: idx, page: entry.sourcePageNum }, { escape: false })
}

function getPageSourceTitle(entry: MergePageEntry): string {
	const info = sourceByFileId.value.get(entry.fileId)
	const name = info?.source.fileName ?? ''
	return t('sheetmusic', '{file} — Page {page}', { file: name, page: entry.sourcePageNum }, { escape: false })
}

function getPageSourceColor(entry: MergePageEntry): string {
	const info = sourceByFileId.value.get(entry.fileId)
	return getSourceFileColor(info ? info.index : entry.fileIndex)
}

async function disposeLoadedDocs() {
	const docs = pdfJsDocs.value
	pdfJsDocs.value = new Map()
	for (const doc of docs.values()) {
		try {
			await destroyPdfJsDocument(doc)
		} catch {
			// Ignore cleanup errors
		}
	}
}

async function renderThumbnailsForSources(token: number) {
	for (const source of loadedSources.value) {
		if (token !== activeLoadToken) return
		const doc = pdfJsDocs.value.get(source.fileId)
		if (!doc) continue

		for (let p = 1; p <= source.pageCount; p++) {
			if (token !== activeLoadToken) return
			const key = thumbKey(source.fileId, p)
			if (thumbnails.value[key]) continue

			const dataUrl = await renderPageImage(doc, p, 340)
			if (dataUrl && token === activeLoadToken) {
				thumbnails.value = { ...thumbnails.value, [key]: dataUrl }
			}
		}
	}
}

async function loadAllPdfs() {
	const token = ++activeLoadToken
	isLoading.value = true
	errorMessage.value = ''
	loadedSources.value = []
	mergePages.value = []
	thumbnails.value = {}
	highResCache.value = {}
	highResOrder.clear()
	originalsAction.value = 'delete-originals'

	await disposeLoadedDocs()

	try {
		const nextSources: LoadedSourceFile[] = []
		const nextDocs = new Map<number | string, any>()

		for (const file of props.pdfFiles) {
			if (token !== activeLoadToken) return
			const targetUrl = getCacheBustedFileUrl(file.path, `${file.id}-${props.song.lastModified ?? ''}-${Date.now()}`)
			const response = await axios.get(targetUrl, {
				responseType: 'arraybuffer',
				headers: {
					'Cache-Control': 'no-cache',
					'Pragma': 'no-cache',
				},
			})
			const bytes = toUint8Array(response.data)
			const doc = await loadPdfJsDocument(bytes.slice())
			nextDocs.set(file.id, doc)
			nextSources.push({
				fileId: file.id,
				fileName: getFileName(file.path),
				bytes,
				pageCount: doc.numPages,
				file,
				included: true,
			})
		}

		if (token !== activeLoadToken) {
			for (const doc of nextDocs.values()) {
				void destroyPdfJsDocument(doc)
			}
			return
		}

		pdfJsDocs.value = nextDocs
		loadedSources.value = nextSources
		mergePages.value = buildInitialMergePages(nextSources)
		isLoading.value = false

		await nextTick()
		void renderThumbnailsForSources(token)
	} catch (err: any) {
		if (token !== activeLoadToken) return
		console.error('Failed to load PDFs for merging:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to load PDF files: {error}', { error: detail }, { escape: false })
		showError(errorMessage.value)
		isLoading.value = false
	}
}

watch(
	() => props.open,
	(isOpen) => {
		if (isOpen) {
			void loadAllPdfs()
		} else {
			activeLoadToken++
			void disposeLoadedDocs()
		}
	},
	{ immediate: true },
)

onUnmounted(() => {
	activeLoadToken++
	void disposeLoadedDocs()
})

// Source file actions
function toggleSourceIncluded(index: number) {
	const source = loadedSources.value[index]
	if (!source) return
	source.included = !source.included

	if (!source.included) {
		mergePages.value = mergePages.value.filter((p) => p.fileId !== source.fileId)
	} else {
		const addedPages: MergePageEntry[] = []
		for (let p = 1; p <= source.pageCount; p++) {
			addedPages.push(createMergePageEntry(source.fileId, index, p, 0))
		}
		const orderedIds = loadedSources.value.filter((s) => s.included).map((s) => s.fileId)
		mergePages.value = reorderPagesByFileOrder([...mergePages.value, ...addedPages], orderedIds)
	}
}

function moveSource(fromIndex: number, direction: -1 | 1) {
	const toIndex = fromIndex + direction
	if (toIndex < 0 || toIndex >= loadedSources.value.length) return
	const next = [...loadedSources.value]
	const [moved] = next.splice(fromIndex, 1)
	next.splice(toIndex, 0, moved)
	loadedSources.value = next

	const orderedIds = next.filter((s) => s.included).map((s) => s.fileId)
	mergePages.value = reorderPagesByFileOrder(mergePages.value, orderedIds)
}

function resetPagesOrder() {
	const includedSet = new Set(includedSources.value.map((s) => s.fileId))
	mergePages.value = buildInitialMergePages(loadedSources.value, includedSet)
}

// Page-level actions
function movePage(index: number, direction: -1 | 1) {
	const target = index + direction
	if (target < 0 || target >= mergePages.value.length) return
	mergePages.value = reorderMergePages(mergePages.value, index, target)
}

function rotatePage(index: number, delta: number) {
	mergePages.value = rotateMergePage(mergePages.value, index, delta)
}

function duplicatePage(index: number) {
	mergePages.value = duplicateMergePage(mergePages.value, index)
}

function removePage(index: number) {
	mergePages.value = removeMergePage(mergePages.value, index)
}

// Zoom Lightbox
const activeZoomEntry = computed(() => mergePages.value[zoomSequenceIndex.value] ?? null)
const activeZoomKey = computed(() =>
	activeZoomEntry.value ? thumbKey(activeZoomEntry.value.fileId, activeZoomEntry.value.sourcePageNum) : '',
)

async function ensureHighResForEntry(entry: MergePageEntry) {
	const key = thumbKey(entry.fileId, entry.sourcePageNum)
	if (highResCache.value[key]) return
	const doc = pdfJsDocs.value.get(entry.fileId)
	if (!doc) return

	isLoadingHighRes.value = true
	try {
		const url = await renderPageImage(doc, entry.sourcePageNum, 1600)
		if (url) {
			highResCache.value = setBoundedRecordEntry(highResCache.value, highResOrder, key, url)
		}
	} finally {
		isLoadingHighRes.value = false
	}
}

function openPageZoom(index: number) {
	zoomSequenceIndex.value = index
	isZoomModalOpen.value = true
	const entry = mergePages.value[index]
	if (entry) {
		void ensureHighResForEntry(entry)
	}
}

function goToZoomIndex(nextIndex: number) {
	if (nextIndex < 0 || nextIndex >= mergePages.value.length) return
	zoomSequenceIndex.value = nextIndex
	const entry = mergePages.value[nextIndex]
	if (entry) {
		void ensureHighResForEntry(entry)
	}
}

// Save / Merge execution
async function handleMerge() {
	if (!canSubmit.value) return

	isSaving.value = true
	errorMessage.value = ''
	saveProgressPercent.value = 5
	saveProgressText.value = t('sheetmusic', 'Merging PDF pages…')

	try {
		const mergedBytes = await buildMergedPdf(
			loadedSources.value,
			mergePages.value,
			(current, total) => {
				saveProgressPercent.value = Math.round(10 + (current / Math.max(1, total)) * 55)
				saveProgressText.value = t(
					'sheetmusic',
					'Combining page {current} of {total}…',
					{ current, total },
					{ escape: false },
				)
			},
		)

		const pageCount = mergePages.value.length
		const fileName = buildMergedFileName(props.song.title)
		const mergedFile = new File([mergedBytes as Uint8Array<ArrayBuffer>], fileName, { type: 'application/pdf' })
		const deleteFileIds = originalsAction.value === 'delete-originals'
			? includedSources.value.map((s) => s.fileId)
			: []

		saveProgressPercent.value = 80
		saveProgressText.value = t('sheetmusic', 'Saving merged PDF and updating file paths…')

		const mergeRes = await mergeSongFiles(props.libraryId, props.song.id, mergedFile, {
			deleteFileIds,
			pageCount,
			pageOrder: pageCount === 1 ? '1' : `1-${pageCount}`,
		})
		const finalLibraryData = (mergeRes.data?.ocs?.data as LibraryData) ?? null

		saveProgressPercent.value = 100
		saveProgressText.value = t('sheetmusic', 'Done!')

		showSuccess(t(
			'sheetmusic',
			'Merged {files} PDFs into {pages} pages.',
			{ files: includedSources.value.length, pages: pageCount },
			{ escape: false },
		))

		if (finalLibraryData) {
			emit('merged', finalLibraryData)
		}
		emit('update:open', false)
	} catch (err: any) {
		console.error('Failed to merge PDFs:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to merge PDFs: {error}', { error: detail }, { escape: false })
		showError(errorMessage.value)
	} finally {
		isSaving.value = false
		saveProgressPercent.value = 0
		saveProgressText.value = ''
	}
}
</script>

<template>
	<NcDialog
		v-if="open"
		:open="open"
		:name="t('sheetmusic', 'Merge PDFs')"
		size="large"
		:class="$style.largeDialog"
		@update:open="emit('update:open', $event)">
		<template #actions>
			<NcButton
				type="button"
				variant="secondary"
				:disabled="isSaving"
				data-test="merge-pdfs-cancel"
				@click="emit('update:open', false)">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				type="button"
				variant="primary"
				:disabled="!canSubmit"
				data-test="merge-pdfs-submit"
				@click="handleMerge">
				<template #icon>
					<NcLoadingIcon v-if="isSaving" :size="20" />
					<MergeIcon v-else :size="20" />
				</template>
				{{ isSaving ? t('sheetmusic', 'Merging…') : t('sheetmusic', 'Merge PDFs') }}
			</NcButton>
		</template>

		<div :class="$style.dialogContent">
			<SongPdfSaveProgress
				v-if="isSaving"
				:progress-text="saveProgressText"
				:progress-percent="saveProgressPercent" />

			<p v-if="errorMessage" :class="$style.errorBanner" role="alert">
				{{ errorMessage }}
			</p>

			<div v-if="isLoading" :class="$style.loadingState" data-test="merge-pdfs-loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('sheetmusic', 'Loading PDF documents…') }}</span>
			</div>

			<template v-else>
				<!-- Source PDFs selection & file-level order -->
				<section :class="$style.sourcesSection">
					<div :class="$style.sectionHeader">
						<span :class="$style.sectionTitle">
							{{ t('sheetmusic', 'Source PDFs ({selected} of {total} selected)', { selected: includedSources.length, total: loadedSources.length }, { escape: false }) }}
						</span>
						<span :class="$style.sectionHint">
							{{ t('sheetmusic', 'Reorder files or drag individual pages below.') }}
						</span>
					</div>
					<ul :class="$style.sourceList" data-test="merge-source-list">
						<li
							v-for="(source, idx) in loadedSources"
							:key="source.fileId"
							:class="[$style.sourceItem, !source.included && $style.sourceItemExcluded]"
							:data-test="`merge-source-${source.fileId}`">
							<label :class="$style.sourceCheckboxLabel">
								<input
									type="checkbox"
									:checked="source.included"
									:disabled="isSaving"
									:data-test="`merge-source-checkbox-${source.fileId}`"
									@change="toggleSourceIncluded(idx)">
								<span
									:class="$style.sourceColorDot"
									:style="{ backgroundColor: getSourceFileColor(idx) }">
									{{ idx + 1 }}
								</span>
								<span :class="$style.sourceFileName" :title="source.fileName">
									{{ source.fileName }}
								</span>
								<span :class="$style.sourcePageCount">
									({{ source.pageCount === 1 ? t('sheetmusic', '1 page') : t('sheetmusic', '{count} pages', { count: source.pageCount }, { escape: false }) }})
								</span>
							</label>
							<div :class="$style.sourceMoveButtons">
								<NcButton
									type="button"
									variant="tertiary"
									:disabled="isSaving || idx === 0"
									:title="t('sheetmusic', 'Move file up')"
									:aria-label="t('sheetmusic', 'Move {file} up', { file: source.fileName }, { escape: false })"
									:data-test="`merge-source-up-${source.fileId}`"
									@click="moveSource(idx, -1)">
									<template #icon>
										<ArrowUp :size="18" />
									</template>
								</NcButton>
								<NcButton
									type="button"
									variant="tertiary"
									:disabled="isSaving || idx === loadedSources.length - 1"
									:title="t('sheetmusic', 'Move file down')"
									:aria-label="t('sheetmusic', 'Move {file} down', { file: source.fileName }, { escape: false })"
									:data-test="`merge-source-down-${source.fileId}`"
									@click="moveSource(idx, 1)">
									<template #icon>
										<ArrowDown :size="18" />
									</template>
								</NcButton>
							</div>
						</li>
					</ul>
				</section>

				<!-- Page grid toolbar -->
				<div :class="$style.gridToolbar">
					<div :class="$style.summaryText" data-test="merge-page-summary">
						{{ t('sheetmusic', 'Merged Result: {count} page | Merged Result: {count} pages', { count: mergePages.length }, mergePages.length, { escape: false }) }}
					</div>
					<div :class="$style.toolbarControls">
						<NcButton
							type="button"
							variant="secondary"
							:disabled="isSaving || includedSources.length === 0"
							:title="t('sheetmusic', 'Reset page order and rotations')"
							data-test="merge-reset-pages"
							@click="resetPagesOrder">
							<template #icon>
								<Restore :size="18" />
							</template>
							{{ t('sheetmusic', 'Reset') }}
						</NcButton>
						<div :class="$style.zoomControls">
							<NcButton
								type="button"
								variant="secondary"
								:disabled="cardWidth <= MIN_CARD_WIDTH"
								:title="t('sheetmusic', 'Smaller preview')"
								:aria-label="t('sheetmusic', 'Smaller preview')"
								@click="zoomOut">
								<template #icon>
									<MagnifyMinus :size="18" />
								</template>
							</NcButton>
							<NcButton
								type="button"
								variant="secondary"
								:title="t('sheetmusic', 'Reset preview size')"
								:aria-label="t('sheetmusic', 'Reset preview size')"
								@click="resetZoom">
								{{ cardWidth }}px
							</NcButton>
							<NcButton
								type="button"
								variant="secondary"
								:disabled="cardWidth >= MAX_CARD_WIDTH"
								:title="t('sheetmusic', 'Larger preview')"
								:aria-label="t('sheetmusic', 'Larger preview')"
								@click="zoomIn">
								<template #icon>
									<MagnifyPlus :size="18" />
								</template>
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Rearrangeable Page Grid -->
				<div
					v-if="mergePages.length > 0"
					:class="$style.pagesGrid"
					:style="{ '--merge-card-width': `${cardWidth}px` }"
					data-test="merge-pages-grid">
					<div
						v-for="(entry, index) in mergePages"
						:key="entry.id"
						:draggable="!isSaving"
						:class="[
							$style.pageCard,
							draggedItem?.type === 'sequence-card' && draggedItem.fromIndex === index && $style.pageCardDragging,
							dropTargetIndex === index && dropIndicatorSide === 'left' && $style.dropIndicatorLeft,
							dropTargetIndex === index && dropIndicatorSide === 'right' && $style.dropIndicatorRight,
						]"
						:data-test="`merge-page-card-${index}`"
						@dragstart="handleSequenceDragStart($event, index, entry.sourcePageNum)"
						@dragover="handleCardDragOver($event, index)"
						@dragleave="handleCardDragLeave($event, index)"
						@drop="handlePageCardDrop($event, index)"
						@dragend="handleDragEnd">
						<div :class="$style.cardHeader">
							<span :class="$style.outputPageBadge">#{{ index + 1 }}</span>
							<span
								:class="$style.sourceBadge"
								:style="{ backgroundColor: getPageSourceColor(entry) }"
								:title="getPageSourceTitle(entry)">
								{{ getPageSourceLabel(entry) }}
							</span>
						</div>

						<div :class="$style.thumbWrap" @click="openPageZoom(index)">
							<img
								v-if="thumbnails[thumbKey(entry.fileId, entry.sourcePageNum)]"
								:src="thumbnails[thumbKey(entry.fileId, entry.sourcePageNum)]"
								:alt="getPageSourceTitle(entry)"
								:class="$style.thumbImg"
								:style="entry.rotation ? { transform: `rotate(${entry.rotation}deg)` } : undefined">
							<div v-else :class="$style.thumbPlaceholder">
								<NcLoadingIcon :size="20" />
							</div>
							<span v-if="entry.rotation" :class="$style.rotationBadge">
								{{ entry.rotation }}°
							</span>
						</div>

						<div :class="$style.cardActions">
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving || index === 0"
								:title="t('sheetmusic', 'Move page left')"
								:aria-label="t('sheetmusic', 'Move page left')"
								:data-test="`merge-page-left-${index}`"
								@click="movePage(index, -1)">
								<ArrowLeft :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving || index === mergePages.length - 1"
								:title="t('sheetmusic', 'Move page right')"
								:aria-label="t('sheetmusic', 'Move page right')"
								:data-test="`merge-page-right-${index}`"
								@click="movePage(index, 1)">
								<ArrowRight :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Rotate 90° counter-clockwise')"
								:aria-label="t('sheetmusic', 'Rotate 90° counter-clockwise')"
								:data-test="`merge-page-rot-ccw-${index}`"
								@click="rotatePage(index, -90)">
								<RotateLeft :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Rotate 90° clockwise')"
								:aria-label="t('sheetmusic', 'Rotate 90° clockwise')"
								:data-test="`merge-page-rot-cw-${index}`"
								@click="rotatePage(index, 90)">
								<RotateRight :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Duplicate page')"
								:aria-label="t('sheetmusic', 'Duplicate page')"
								:data-test="`merge-page-duplicate-${index}`"
								@click="duplicatePage(index)">
								<ContentCopy :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Zoom page')"
								:aria-label="t('sheetmusic', 'Zoom page')"
								:data-test="`merge-page-zoom-${index}`"
								@click="openPageZoom(index)">
								<MagnifyPlusOutline :size="16" />
							</button>
							<button
								type="button"
								:class="[$style.cardActionBtn, $style.cardActionDanger]"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Remove page')"
								:aria-label="t('sheetmusic', 'Remove page')"
								:data-test="`merge-page-remove-${index}`"
								@click="removePage(index)">
								<Delete :size="16" />
							</button>
						</div>
					</div>
				</div>
				<p v-else :class="$style.emptyNotice">
					{{ t('sheetmusic', 'Select at least 2 PDFs and keep at least 1 page to merge.') }}
				</p>

				<!-- Original files handling -->
				<section :class="$style.optionsSection">
					<div :class="$style.sectionTitle">
						{{ t('sheetmusic', 'Original PDF files') }}
					</div>
					<div :class="$style.radioGroup" role="radiogroup" :aria-label="t('sheetmusic', 'Original PDF files handling')">
						<label :class="[$style.radioOption, originalsAction === 'delete-originals' && $style.radioOptionActive]">
							<input
								v-model="originalsAction"
								type="radio"
								name="mergeOriginalsAction"
								value="delete-originals"
								:disabled="isSaving"
								data-test="merge-action-delete-originals">
							<div>
								<div :class="$style.radioLabel">
									{{ t('sheetmusic', 'Delete original merged PDFs') }}
								</div>
								<div :class="$style.radioHint">
									{{ t('sheetmusic', 'Saves the merged PDF, deletes the selected original PDFs, and updates file paths according to library naming rules.') }}
								</div>
							</div>
						</label>

						<label :class="[$style.radioOption, originalsAction === 'keep-originals' && $style.radioOptionActive]">
							<input
								v-model="originalsAction"
								type="radio"
								name="mergeOriginalsAction"
								value="keep-originals"
								:disabled="isSaving"
								data-test="merge-action-keep-originals">
							<div>
								<div :class="$style.radioLabel">
									{{ t('sheetmusic', 'Keep original PDFs') }}
								</div>
								<div :class="$style.radioHint">
									{{ t('sheetmusic', 'Adds the merged PDF alongside the original PDFs and updates file paths according to library naming rules.') }}
								</div>
							</div>
						</label>
					</div>
				</section>
			</template>
		</div>

		<!-- High-Res Zoom Lightbox -->
		<SongPageOrderZoomModal
			v-model:open="isZoomModalOpen"
			:page-num="zoomSequenceIndex + 1"
			:total-pages="mergePages.length"
			:high-res-url="highResCache[activeZoomKey]"
			:thumbnail-url="thumbnails[activeZoomKey]"
			:is-loading-high-res="isLoadingHighRes"
			:show-append-action="false"
			:rotation-deg="activeZoomEntry?.rotation ?? 0"
			@prev="goToZoomIndex(zoomSequenceIndex - 1)"
			@next="goToZoomIndex(zoomSequenceIndex + 1)" />
	</NcDialog>
</template>

<style module>
.largeDialog :global(.modal-container) {
	width: 94vw !important;
	max-width: 1440px !important;
	height: 90vh !important;
	max-height: 940px !important;
	display: flex !important;
	flex-direction: column !important;
	overflow: hidden !important;
}

.largeDialog :global(.modal-container__content),
.largeDialog :global(.dialog),
.largeDialog :global(.dialog__wrapper) {
	display: flex !important;
	flex-direction: column !important;
	flex: 1 1 auto !important;
	min-height: 0 !important;
	height: 100% !important;
	overflow: hidden !important;
}

.largeDialog :global(.dialog__content) {
	display: flex !important;
	flex-direction: column !important;
	flex: 1 1 auto !important;
	min-height: 0 !important;
	overflow-y: auto !important;
	overflow-x: hidden !important;
	padding: 8px 16px 14px !important;
}

.largeDialog :global(.dialog__actions) {
	flex-shrink: 0 !important;
	background-color: var(--color-main-background, #fff) !important;
	border-top: 1px solid var(--color-border) !important;
	padding: 10px 18px !important;
	margin: 0 !important;
	z-index: 50 !important;
}

.dialogContent {
	display: flex;
	flex-direction: column;
	gap: 14px;
	min-width: 0;
	width: 100%;
	box-sizing: border-box;
}

.errorBanner {
	margin: 0;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-error-light, rgba(233, 50, 45, 0.12));
	border: 1px solid var(--color-error);
	color: var(--color-error);
	font-size: 0.875rem;
}

.loadingState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	min-height: 240px;
	color: var(--color-text-maxcontrast);
}

.sourcesSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.sectionHeader {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.sectionTitle {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.sectionHint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.sourceList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sourceItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	padding: 6px 10px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.sourceItemExcluded {
	opacity: 0.55;
}

.sourceCheckboxLabel {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1 1 0;
	min-width: 0;
	cursor: pointer;
}

.sourceColorDot {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	color: #fff;
	font-size: 0.75rem;
	font-weight: 700;
	flex-shrink: 0;
}

.sourceFileName {
	font-weight: 600;
	font-size: 0.875rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sourcePageCount {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.sourceMoveButtons {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.gridToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.summaryText {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.toolbarControls {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.zoomControls {
	display: flex;
	align-items: center;
	gap: 4px;
}

.pagesGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(var(--merge-card-width, 156px), 1fr));
	gap: 12px;
	padding: 10px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	min-height: 220px;
}

.pageCard {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	cursor: grab;
	user-select: none;
	transition: box-shadow 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
}

.pageCard:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.pageCardDragging {
	opacity: 0.45;
	cursor: grabbing;
}

.dropIndicatorLeft {
	box-shadow: -4px 0 0 0 var(--color-primary-element);
}

.dropIndicatorRight {
	box-shadow: 4px 0 0 0 var(--color-primary-element);
}

.cardHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

.outputPageBadge {
	font-size: 0.78rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.sourceBadge {
	display: inline-flex;
	align-items: center;
	padding: 1px 6px;
	border-radius: 999px;
	color: #fff;
	font-size: 0.7rem;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 100%;
}

.thumbWrap {
	position: relative;
	aspect-ratio: 3 / 4;
	width: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #fff;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
	cursor: zoom-in;
}

.thumbImg {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	display: block;
	transition: transform 0.15s ease;
}

.thumbPlaceholder {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	background: var(--color-background-hover);
}

.rotationBadge {
	position: absolute;
	bottom: 4px;
	right: 4px;
	padding: 1px 5px;
	border-radius: 4px;
	background: rgba(0, 0, 0, 0.72);
	color: #fff;
	font-size: 0.7rem;
	font-weight: 600;
}

.cardActions {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 2px;
}

.cardActionBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	padding: 0;
	border: 1px solid transparent;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.cardActionBtn:hover:not(:disabled) {
	background: var(--color-background-dark, var(--color-background-hover));
	border-color: var(--color-border);
}

.cardActionBtn:disabled {
	opacity: 0.35;
	cursor: not-allowed;
}

.cardActionDanger:hover:not(:disabled) {
	color: var(--color-error);
}

.emptyNotice {
	margin: 0;
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
}

.optionsSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.radioGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.radioOption {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	cursor: pointer;
}

.radioOptionActive {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.radioLabel {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.radioHint {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}
</style>
