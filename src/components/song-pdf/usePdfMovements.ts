import { computed, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import { getFileName } from '../../utils/file'
import type { Bookmark, LibraryData, Song, SongFile } from '../../types/song'
import { embedMovementsInPdfBytes } from './pdf-outline-engine'

export type MovementSegment = {
	id: string
	name: string
	pageNum: number
	endPage?: number | null
}

export function usePdfMovements(options: {
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

	const movements = ref<MovementSegment[]>([])
	const isExtractingOutline = ref(false)
	let movementCounter = 0

	function initMovementsFromSong(existingBookmarks?: Bookmark[]) {
		movementCounter = 0
		if (existingBookmarks && existingBookmarks.length > 0) {
			movements.value = existingBookmarks.map((b, idx) => {
				movementCounter = Math.max(movementCounter, idx + 1)
				return {
					id: `mvt-${idx + 1}`,
					name: b.name,
					pageNum: b.pageNum,
					endPage: b.endPage ?? null,
				}
			})
		} else {
			movements.value = []
		}
	}

	function addMovement() {
		movementCounter++
		const lastMvt = movements.value[movements.value.length - 1]
		let nextStart = 1
		if (lastMvt) {
			const end = lastMvt.endPage ?? lastMvt.pageNum
			nextStart = Math.min(totalPages.value || 1, end < (totalPages.value || 1) ? end + 1 : end)
		}
		movements.value.push({
			id: `mvt-${movementCounter}`,
			name: `${t('sheetmusic', 'Movement')} ${movements.value.length + 1}`,
			pageNum: nextStart,
			endPage: totalPages.value || nextStart,
		})
	}

	function addMovementFromSelection(startPage: number, endPage: number) {
		movementCounter++
		const s = Math.max(1, Math.min(startPage, endPage))
		const total = totalPages.value > 0 ? totalPages.value : Math.max(startPage, endPage)
		const e = Math.min(total, Math.max(startPage, endPage))
		movements.value.push({
			id: `mvt-${movementCounter}`,
			name: `${t('sheetmusic', 'Movement')} ${movements.value.length + 1}`,
			pageNum: s,
			endPage: e,
		})
	}

	function removeMovement(id: string) {
		movements.value = movements.value.filter((m) => m.id !== id)
	}

	function updateMovement(id: string, updates: Partial<MovementSegment>) {
		const idx = movements.value.findIndex((m) => m.id === id)
		if (idx === -1) return
		const existing = movements.value[idx]
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
		movements.value[idx] = updated
	}

	function assignPagesToMovement(id: string, startPage: number, endPage: number) {
		const s = Math.max(1, Math.min(startPage, endPage))
		const total = totalPages.value > 0 ? totalPages.value : Math.max(startPage, endPage)
		const e = Math.min(total, Math.max(startPage, endPage))
		updateMovement(id, { pageNum: s, endPage: e })
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
			const newMovements: MovementSegment[] = extracted.map((item, idx) => {
				const nextItem = extracted[idx + 1]
				const endPage = nextItem && nextItem.pageNum > item.pageNum ? nextItem.pageNum - 1 : total
				return {
					id: `mvt-${idx + 1}`,
					name: item.title,
					pageNum: item.pageNum,
					endPage: Math.max(item.pageNum, endPage),
				}
			})

			movementCounter = newMovements.length
			movements.value = newMovements

			showSuccess(t('sheetmusic', 'Successfully imported {count} movements from PDF outline.', { count: newMovements.length }))
			return newMovements.length
		} catch (e) {
			showError(describeRequestFailure(e, t('sheetmusic', 'Failed to extract PDF outline.')))
			return 0
		} finally {
			isExtractingOutline.value = false
		}
	}

	async function executeSaveMovements(onProgress?: (percent: number, text: string) => void) {
		const currentSong = song.value
		if (!currentSong) return

		onProgress?.(30, t('sheetmusic', 'Saving movements…'))

		const validMovements = movements.value.filter((m) => m.name.trim() !== '')

		const payload: Record<string, any> = {
			libraryId: libraryId.value,
			title: currentSong.title,
			sortTitle: currentSong.sortTitle ?? '',
			composerIds: (currentSong.composers ?? []).map((c) => c.id),
			bookIds: (currentSong.books ?? []).map((b) => b.id),
			collectionIds: (currentSong.collections ?? []).map((c) => c.id),
			artistIds: (currentSong.artists ?? []).map((a) => a.id),
			genreIds: (currentSong.genres ?? []).map((g) => g.id),
			keyIds: (currentSong.keys ?? []).map((k) => k.id),
			customGroupIds: (currentSong.customGroups ?? []).map((cg) => cg.id),
			yearIds: (currentSong.years ?? []).map((y) => y.id),
			signatureIds: (currentSong.signatures ?? []).map((s) => s.id),
			sourceTypeIds: (currentSong.sourceTypes ?? []).map((st) => st.id),
			instrumentIds: (currentSong.instruments ?? []).map((i) => i.id),
			stars: currentSong.stars ?? 0,
			difficulty: currentSong.difficulty ?? 0,
			duration: currentSong.duration ?? 0,
			keywords: currentSong.keywords ?? '',
			custom: currentSong.custom ?? '',
			custom2: currentSong.custom2 ?? '',
			opus: currentSong.opus ?? '',
			catalogNumber: currentSong.catalogNumber ?? '',
			songNumber: currentSong.songNumber ?? '',
			audioFiles: currentSong.audioFiles ?? [],
			bookmarks: validMovements.map((m, idx) => ({
				name: m.name.trim(),
				pageNum: m.pageNum,
				endPage: m.endPage ?? null,
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
				const outlineItems = validMovements.map((m) => ({
					title: m.name.trim(),
					pageNum: m.pageNum,
				}))
				const updatedBytes = await embedMovementsInPdfBytes(originalPdfBytes.value, outlineItems)
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
			showSuccess(t('sheetmusic', 'Saved movements for "{title}".', { title: currentSong.title }))
			onSaved(data, currentSong.id)
		} catch (e) {
			showError(describeRequestFailure(e, t('sheetmusic', 'Could not save movements.')))
		}
	}

	return {
		movements,
		isExtractingOutline,
		initMovementsFromSong,
		addMovement,
		addMovementFromSelection,
		removeMovement,
		updateMovement,
		assignPagesToMovement,
		extractFromPdfOutline,
		executeSaveMovements,
	}
}
