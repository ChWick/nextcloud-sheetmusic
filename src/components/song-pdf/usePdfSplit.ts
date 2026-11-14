import { computed, ref, type ComputedRef, type Ref, type ShallowRef } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { PDFDocument, degrees } from 'pdf-lib'
import { apiDelete, apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import type { LibraryData, Song, SongFile } from '../../types/song'
import { ENTITY_COLUMNS, SONG_SCALAR_FIELDS } from '../songs/song-view-columns'
import type { OriginalSongAction } from './OriginalSongActionSelector.vue'
import { getSegmentColor, type SplitSegment } from './SongPdfSplitSidebar.vue'
import type { SplitSegmentSummary } from './SongPdfPageCard.vue'

export function usePdfSplit(options: {
	song: ComputedRef<Song> | Ref<Song>
	libraryId: ComputedRef<number> | Ref<number>
	totalPages: Ref<number>
	pageRotations: Ref<number[]>
	originalPdfBytes: ShallowRef<Uint8Array | null>
	targetFile: ComputedRef<SongFile | undefined>
	onSaved: (data: LibraryData, targetSongId?: number) => void
	onCancel: () => void
}) {
	const {
		song,
		libraryId,
		totalPages,
		pageRotations,
		originalPdfBytes,
		targetFile,
		onSaved,
		onCancel,
	} = options

	const originalSongAction = ref<OriginalSongAction>('keep')
	const attachedFilesCount = computed(() => song.value.files?.length ?? 0)
	const hasExactlyOneFile = computed(() => attachedFilesCount.value === 1)
	const splitSegments = ref<SplitSegment[]>([])
	const selectedPages = ref<number[]>([])
	let segmentCounter = 0

	function initSplitSegments(numPages: number, songTitle: string) {
		segmentCounter = 1
		splitSegments.value = [
			{
				id: `seg-${segmentCounter}`,
				title: `${songTitle} - Part 1`,
				startPage: 1,
				endPage: numPages,
			},
		]
	}

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
			title: `${song.value.title} - Part ${splitSegments.value.length + 1}`,
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

	function getPageSegmentsSummary(pageNum: number): SplitSegmentSummary[] {
		return splitSegments.value
			.map((seg, index) => ({ seg, index }))
			.filter(({ seg }) => pageNum >= seg.startPage && pageNum <= seg.endPage)
			.map(({ seg, index }) => ({
				id: seg.id,
				title: seg.title,
				index: index + 1,
				color: getSegmentColor(index),
			}))
	}

	async function saveSplits(
		setIsSaving: (saving: boolean) => void,
		setErrorMessage: (msg: string) => void,
		onProgress?: (percent: number, text: string, current?: number, total?: number) => Promise<void> | void,
	) {
		if (!originalPdfBytes.value || !targetFile.value) return
		if (splitSegments.value.length === 0) {
			const msg = t('sheetmusic', 'Please configure at least one segment to split.')
			setErrorMessage(msg)
			showError(msg)
			return
		}

		// Validate segments
		for (const seg of splitSegments.value) {
			if (!seg.title.trim()) {
				const msg = t('sheetmusic', 'All segments must have a title.')
				setErrorMessage(msg)
				showError(msg)
				return
			}
			if (seg.startPage < 1 || seg.endPage > totalPages.value || seg.startPage > seg.endPage) {
				const msg = t('sheetmusic', 'Invalid page range ({start}–{end}) for "{title}".', {
					start: seg.startPage,
					end: seg.endPage,
					title: seg.title,
				}, { escape: false })
				setErrorMessage(msg)
				showError(msg)
				return
			}
		}

		setIsSaving(true)
		setErrorMessage('')
		const totalSegments = splitSegments.value.length
		await onProgress?.(5, t('sheetmusic', 'Preparing PDF for splitting…'), 0, totalSegments)

		try {
			const srcPdfDoc = await PDFDocument.load(originalPdfBytes.value)

			// Base metadata to inherit from song
			const currentSong = song.value
			const inheritedMetadata: Record<string, unknown> = {}
			for (const col of ENTITY_COLUMNS) {
				inheritedMetadata[col.idField] = col.getItems(currentSong).map((item) => item.id)
			}
			for (const field of SONG_SCALAR_FIELDS) {
				const raw = currentSong[field.key]
				inheritedMetadata[field.key] = field.type === 'number'
					? (typeof raw === 'number' && raw > 0 ? raw : undefined)
					: (raw ?? '')
			}

			let lastLibraryData: LibraryData | null = null
			let firstCreatedSongId: number | undefined = undefined

			for (let segIdx = 0; segIdx < splitSegments.value.length; segIdx++) {
				const seg = splitSegments.value[segIdx]
				const currentSegNum = segIdx + 1
				await onProgress?.(
					Math.round(5 + (segIdx / Math.max(1, totalSegments)) * 85),
					t('sheetmusic', 'Splitting segment {current} of {total}: "{title}"…', {
						current: currentSegNum,
						total: totalSegments,
						title: seg.title.trim(),
					}, { escape: false }),
					currentSegNum,
					totalSegments,
				)

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
					libraryId: libraryId.value,
					title: seg.title.trim(),
					...inheritedMetadata,
				} as any)

				const createdData = createSongRes.data.ocs.data as any
				const newSongId = createdData.createdSongId ?? createdData.id

				if (!newSongId) {
					throw new Error(t('sheetmusic', 'Failed to retrieve ID for created split song: {title}', { title: seg.title }, { escape: false }))
				}

				if (firstCreatedSongId === undefined) {
					firstCreatedSongId = Number(newSongId)
				}

				// Upload split PDF file
				await onProgress?.(
					Math.round(5 + ((segIdx + 0.5) / Math.max(1, totalSegments)) * 85),
					t('sheetmusic', 'Uploading segment {current} of {total}: "{title}"…', {
						current: currentSegNum,
						total: totalSegments,
						title: seg.title.trim(),
					}, { escape: false }),
					currentSegNum,
					totalSegments,
				)
				const segPageCount = seg.endPage - seg.startPage + 1
				const formData = new FormData()
				formData.append('file', splitFile)
				formData.append('pageCount', String(segPageCount))
				formData.append('pageOrder', `1-${segPageCount}`)
				const uploadRes = await apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
					pathParams: { libraryId: libraryId.value, id: newSongId },
				})

				lastLibraryData = uploadRes.data.ocs.data as LibraryData
			}

			await onProgress?.(95, t('sheetmusic', 'Finalizing split songs…'), totalSegments, totalSegments)

			// Target song to open after splitting: the first newly created split song
			const targetOpenSongId = firstCreatedSongId

			// Handle chosen action for original song / file
			if (originalSongAction.value === 'delete-song' && hasExactlyOneFile.value) {
				const delSongRes = await apiDelete('/api/songs/{id}', {
					pathParams: { id: currentSong.id },
					queryParams: { libraryId: libraryId.value, deleteFiles: true },
				})
				lastLibraryData = (delSongRes.data?.ocs?.data as LibraryData) ?? lastLibraryData
				showSuccess(t('sheetmusic', 'Successfully split "{title}" into {count} songs and deleted original song.', {
					title: currentSong.title,
					count: splitSegments.value.length,
				}, { escape: false }))
				if (lastLibraryData) {
					onSaved(lastLibraryData, targetOpenSongId)
				} else {
					onCancel()
				}
				return
			} else if (originalSongAction.value === 'delete-file' && targetFile.value) {
				const delRes = await apiPost('/api/songs/{id}/file/{fileId}/delete', {
					libraryId: libraryId.value,
				}, {
					pathParams: { id: currentSong.id, fileId: targetFile.value.id },
				})
				lastLibraryData = (delRes.data?.ocs?.data as LibraryData) ?? lastLibraryData
			}

			showSuccess(t('sheetmusic', 'Successfully split "{title}" into {count} songs.', {
				title: currentSong.title,
				count: splitSegments.value.length,
			}, { escape: false }))
			if (lastLibraryData) {
				onSaved(lastLibraryData, targetOpenSongId)
			} else {
				onCancel()
			}
		} catch (err: any) {
			console.error('Failed to split PDF:', err)
			const detail = describeRequestFailure(err, err?.message ?? String(err))
			const msg = t('sheetmusic', 'Failed to split PDF: {error}', { error: detail }, { escape: false })
			setErrorMessage(msg)
			showError(msg)
		} finally {
			setIsSaving(false)
		}
	}

	return {
		originalSongAction,
		attachedFilesCount,
		hasExactlyOneFile,
		splitSegments,
		selectedPages,
		initSplitSegments,
		addSegment,
		removeSegment,
		assignSelectedPagesToSegment,
		togglePageSelection,
		getSegmentsForPage,
		getPageSegmentsSummary,
		saveSplits,
	}
}
