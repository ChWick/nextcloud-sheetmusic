import { computed, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import { getFileName } from '../../utils/file'
import type { Bookmark, LibraryData, Song, SongFile } from '../../types/song'
import { ENTITY_COLUMNS } from '../songs/song-view-columns'
import { embedBookmarksInPdfBytes } from './pdf-outline-engine'

export type BookmarkSegment = {
	id: string
	name: string
	pageNum: number
	endPage?: number | null
}
export type MovementSegment = BookmarkSegment

export function usePdfBookmarks(options: {
	song: ComputedRef<Song> | Ref<Song>
	libraryId: ComputedRef<number> | Ref<number>
	totalPages: Ref<number>
	activePdfDoc: ShallowRef<any>
	originalPdfBytes?: Ref<Uint8Array | null> | ShallowRef<Uint8Array | null>
	targetFile?: ComputedRef<SongFile | undefined> | Ref<SongFile | undefined>
	autoPdfMetadata?: ComputedRef<boolean> | Ref<boolean>
	onPdfUpdated?: (bytes: Uint8Array) => Promise<void> | void
	onSaved: (data: LibraryData, targetSongId?: number) => void
	onCancel: () => void
}) {
	const {
		song,
		libraryId,
		totalPages,
		activePdfDoc,
		originalPdfBytes,
		targetFile,
		autoPdfMetadata,
		onPdfUpdated,
		onSaved,
		onCancel,
	} = options

	const bookmarks = ref<BookmarkSegment[]>([])
	const isExtractingOutline = ref(false)
	let bookmarkCounter = 0

	function initBookmarksFromSong(existingBookmarks?: Bookmark[]) {
		bookmarkCounter = 0
		if (existingBookmarks && existingBookmarks.length > 0) {
			bookmarks.value = existingBookmarks.map((b, idx) => {
				bookmarkCounter = Math.max(bookmarkCounter, idx + 1)
				return {
					id: `bm-${idx + 1}`,
					name: b.name,
					pageNum: b.pageNum,
					endPage: b.endPage ?? null,
				}
			})
		} else {
			bookmarks.value = []
		}
	}

	function addBookmark() {
		bookmarkCounter++
		const lastBm = bookmarks.value[bookmarks.value.length - 1]
		let nextStart = 1
		if (lastBm) {
			const end = lastBm.endPage ?? lastBm.pageNum
			nextStart = Math.min(totalPages.value || 1, end < (totalPages.value || 1) ? end + 1 : end)
		}
		bookmarks.value.push({
			id: `bm-${bookmarkCounter}`,
			name: `${t('sheetmusic', 'Bookmark')} ${bookmarks.value.length + 1}`,
			pageNum: nextStart,
			endPage: totalPages.value || nextStart,
		})
	}

	function addBookmarkFromSelection(startPage: number, endPage: number) {
		bookmarkCounter++
		const s = Math.max(1, Math.min(startPage, endPage))
		const total = totalPages.value > 0 ? totalPages.value : Math.max(startPage, endPage)
		const e = Math.min(total, Math.max(startPage, endPage))
		bookmarks.value.push({
			id: `bm-${bookmarkCounter}`,
			name: `${t('sheetmusic', 'Bookmark')} ${bookmarks.value.length + 1}`,
			pageNum: s,
			endPage: e,
		})
	}

	function removeBookmark(id: string) {
		bookmarks.value = bookmarks.value.filter((b) => b.id !== id)
	}

	function updateBookmark(id: string, updates: Partial<BookmarkSegment>) {
		const idx = bookmarks.value.findIndex((b) => b.id === id)
		if (idx === -1) return
		const existing = bookmarks.value[idx]
		const updated = { ...existing, ...updates }
		if (updated.pageNum < 1) updated.pageNum = 1
		if (totalPages.value > 0 && updated.pageNum > totalPages.value) {
			updated.pageNum = totalPages.value
		}
		if (updated.endPage !== null && updated.endPage !== undefined) {
			if (updated.endPage < updated.pageNum) {
				updated.endPage = updated.pageNum
			}
			if (totalPages.value > 0 && updated.endPage > totalPages.value) {
				updated.endPage = totalPages.value
			}
		}
		bookmarks.value[idx] = updated
	}

	function assignPagesToBookmark(id: string, startPage: number, endPage: number) {
		const s = Math.max(1, Math.min(startPage, endPage))
		const total = totalPages.value > 0 ? totalPages.value : Math.max(startPage, endPage)
		const e = Math.min(total, Math.max(startPage, endPage))
		updateBookmark(id, { pageNum: s, endPage: e })
	}

	async function extractFromPdfOutline(): Promise<number> {
		const pdf = activePdfDoc.value
		if (!pdf || typeof pdf.getOutline !== 'function') {
			showError(t('sheetmusic', 'PDF document is not yet loaded.'))
			return 0
		}

		isExtractingOutline.value = true
		try {
			const outline = await pdf.getOutline()
			if (!outline || !Array.isArray(outline) || outline.length === 0) {
				showError(t('sheetmusic', 'No embedded Table of Contents / bookmarks found in this PDF.'))
				return 0
			}

			const extracted: Array<{ title: string; pageNum: number }> = []

			for (const item of outline) {
				const title = String(item.title ?? '').trim()
				if (!title) continue

				let dest = item.dest
				if (typeof dest === 'string') {
					dest = await pdf.getDestination(dest)
				}
				if (!dest || !Array.isArray(dest) || dest.length === 0) continue

				const pageRef = dest[0]
				let pageIndex = -1
				if (typeof pageRef === 'number') {
					pageIndex = pageRef
				} else if (pageRef && typeof pageRef === 'object') {
					pageIndex = await pdf.getPageIndex(pageRef)
				}

				if (pageIndex >= 0) {
					extracted.push({
						title,
						pageNum: pageIndex + 1,
					})
				}
			}

			if (extracted.length === 0) {
				showError(t('sheetmusic', 'Could not resolve any page targets from the PDF outline.'))
				return 0
			}

			// Sort by page number
			extracted.sort((a, b) => a.pageNum - b.pageNum)

			const total = totalPages.value > 0 ? totalPages.value : 1
			const newBookmarks: BookmarkSegment[] = extracted.map((item, idx) => {
				const nextItem = extracted[idx + 1]
				const endPage = nextItem && nextItem.pageNum > item.pageNum ? nextItem.pageNum - 1 : total
				return {
					id: `bm-${idx + 1}`,
					name: item.title,
					pageNum: item.pageNum,
					endPage: Math.max(item.pageNum, endPage),
				}
			})

			bookmarkCounter = newBookmarks.length
			bookmarks.value = newBookmarks

			showSuccess(t('sheetmusic', 'Successfully imported {count} bookmarks from PDF outline.', { count: newBookmarks.length }))
			return newBookmarks.length
		} catch (e) {
			showError(describeRequestFailure(e, t('sheetmusic', 'Failed to extract PDF outline.')))
			return 0
		} finally {
			isExtractingOutline.value = false
		}
	}

	async function executeSaveBookmarks(onProgress?: (percent: number, text: string) => void) {
		const currentSong = song.value
		if (!currentSong) return

		onProgress?.(30, t('sheetmusic', 'Saving bookmarks…'))

		const validBookmarks = bookmarks.value.filter((b) => b.name.trim() !== '')

		const payload: Record<string, any> = {
			libraryId: libraryId.value,
			title: currentSong.title,
			sortTitle: currentSong.sortTitle ?? '',
			...Object.fromEntries(
				ENTITY_COLUMNS.map((col) => [col.idField, col.getItems(currentSong).map((item) => item.id)]),
			),
			stars: Number(currentSong.stars) > 0 ? Number(currentSong.stars) : null,
			difficulty: Number(currentSong.difficulty) > 0 ? Number(currentSong.difficulty) : null,
			duration: Number(currentSong.duration) > 0 ? Number(currentSong.duration) : null,
			keywords: currentSong.keywords ?? '',
			custom: currentSong.custom ?? '',
			custom2: currentSong.custom2 ?? '',
			opus: currentSong.opus ?? '',
			catalogNumber: currentSong.catalogNumber ?? '',
			songNumber: currentSong.songNumber ?? '',
			audioFiles: currentSong.audioFiles ?? [],
			bookmarks: validBookmarks.map((b, idx) => ({
				name: b.name.trim(),
				pageNum: b.pageNum,
				endPage: b.endPage ?? null,
				position: idx,
				showInLibrary: 1,
			})),
		}

		try {
			onProgress?.(50, t('sheetmusic', 'Updating song metadata…'))
			const response = await apiPost('/api/songs/{id}', payload, {
				pathParams: { id: currentSong.id },
			})
			let data = response.data.ocs.data as LibraryData

			if (autoPdfMetadata?.value !== false && originalPdfBytes?.value && targetFile?.value && /\.pdf$/i.test(targetFile.value.path)) {
				onProgress?.(75, t('sheetmusic', 'Updating PDF Table of Contents…'))
				const outlineItems = validBookmarks.map((b) => ({
					title: b.name.trim(),
					pageNum: b.pageNum,
				}))
				const updatedBytes = await embedBookmarksInPdfBytes(originalPdfBytes.value, outlineItems)
				originalPdfBytes.value = updatedBytes

				onProgress?.(90, t('sheetmusic', 'Uploading PDF to server…'))
				const fileName = getFileName(targetFile.value.path) || 'song.pdf'
				const modifiedFile = new File([updatedBytes as Uint8Array<ArrayBuffer>], fileName, { type: 'application/pdf' })
				const formData = new FormData()
				formData.append('file', modifiedFile)

				const uploadRes = await (apiPost as any)('/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update', formData, {
					pathParams: { libraryId: libraryId.value, id: currentSong.id, fileId: targetFile.value.id },
				})
				data = uploadRes.data.ocs.data as LibraryData

				if (onPdfUpdated) {
					await onPdfUpdated(updatedBytes)
				}
			}

			onProgress?.(100, t('sheetmusic', 'Done!'))
			showSuccess(t('sheetmusic', 'Saved bookmarks for "{title}".', { title: currentSong.title }))
			onSaved(data, currentSong.id)
		} catch (e) {
			showError(describeRequestFailure(e, t('sheetmusic', 'Could not save bookmarks.')))
		}
	}

	return {
		bookmarks,
		isExtractingOutline,
		initBookmarksFromSong,
		addBookmark,
		addBookmarkFromSelection,
		removeBookmark,
		updateBookmark,
		assignPagesToBookmark,
		extractFromPdfOutline,
		executeSaveBookmarks,
		// Aliases for compatibility
		movements: bookmarks,
		initMovementsFromSong: initBookmarksFromSong,
		addMovement: addBookmark,
		addMovementFromSelection: addBookmarkFromSelection,
		removeMovement: removeBookmark,
		updateMovement: updateBookmark,
		assignPagesToMovement: assignPagesToBookmark,
		executeSaveMovements: executeSaveBookmarks,
	}
}

export const usePdfMovements = usePdfBookmarks
