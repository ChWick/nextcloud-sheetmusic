/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LibraryData, Song, SongFile } from '../../types/song'
import { computed, nextTick, onUnmounted, ref, shallowRef, watch, type Ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mergeSongFiles } from '../../api/songFiles'
import { describeRequestFailure } from '../../utils/api'
import { getFileName } from '../../utils/file'
import { fetchUncachedBinaryFile } from '../../utils/file-binary'
import { destroyPdfJsDocument, loadPdfJsDocument, setBoundedRecordEntry } from '../../utils/pdfjs'
import { renderPageImage } from './usePageOrderThumbnails'
import {
	buildInitialMergePages,
	buildMergedFileName,
	buildMergedPdf,
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

export interface LoadedSourceFile extends MergeSourcePdf {
	file: SongFile
	included: boolean
}

export interface UsePdfMergeOptions {
	open: Ref<boolean>
	song: Ref<Song>
	libraryId: Ref<number>
	pdfFiles: Ref<SongFile[]>
	onMerged: (data: LibraryData) => void
	onClose: () => void
}

export function thumbKey(fileId: number | string, pageNum: number): string {
	return `${String(fileId)}:${pageNum}`
}

export function usePdfMerge(options: UsePdfMergeOptions) {
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

	// Zoom lightbox state
	const isZoomModalOpen = ref(false)
	const zoomSequenceIndex = ref(0)
	const highResCache = ref<Record<string, string>>({})
	const highResOrder = new Set<string>()
	const isLoadingHighRes = ref(false)

	let activeLoadToken = 0

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

			for (const file of options.pdfFiles.value) {
				if (token !== activeLoadToken) return
				const bytes = await fetchUncachedBinaryFile(
					file.path,
					`${file.id}-${options.song.value.lastModified ?? ''}-${Date.now()}`,
				)
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
		options.open,
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
			const addedPages = buildInitialMergePages(loadedSources.value, new Set([source.fileId]))
			const orderedIds = includedSources.value.map((s) => s.fileId)
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
	function reorderPages(from: number, to: number) {
		mergePages.value = reorderMergePages(mergePages.value, from, to)
	}

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

	// Zoom lightbox
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

	function goToZoomIndex(nextIndex: number) {
		if (nextIndex < 0 || nextIndex >= mergePages.value.length) return
		zoomSequenceIndex.value = nextIndex
		const entry = mergePages.value[nextIndex]
		if (entry) {
			void ensureHighResForEntry(entry)
		}
	}

	function openPageZoom(index: number) {
		isZoomModalOpen.value = true
		goToZoomIndex(index)
	}

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
			const fileName = buildMergedFileName(options.song.value.title)
			const mergedFile = new File([mergedBytes as Uint8Array<ArrayBuffer>], fileName, { type: 'application/pdf' })
			const deleteFileIds = originalsAction.value === 'delete-originals'
				? includedSources.value.map((s) => s.fileId)
				: []

			saveProgressPercent.value = 80
			saveProgressText.value = t('sheetmusic', 'Saving merged PDF and updating file paths…')

			const mergeRes = await mergeSongFiles(options.libraryId.value, options.song.value.id, mergedFile, {
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
				options.onMerged(finalLibraryData)
			}
			options.onClose()
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

	return {
		isLoading,
		isSaving,
		saveProgressPercent,
		saveProgressText,
		errorMessage,
		loadedSources,
		includedSources,
		mergePages,
		thumbnails,
		originalsAction,
		canSubmit,
		isZoomModalOpen,
		zoomSequenceIndex,
		highResCache,
		isLoadingHighRes,
		activeZoomEntry,
		activeZoomKey,
		getPageSourceLabel,
		getPageSourceTitle,
		getPageSourceColor,
		toggleSourceIncluded,
		moveSource,
		resetPagesOrder,
		reorderPages,
		movePage,
		rotatePage,
		duplicatePage,
		removePage,
		openPageZoom,
		goToZoomIndex,
		handleMerge,
	}
}
