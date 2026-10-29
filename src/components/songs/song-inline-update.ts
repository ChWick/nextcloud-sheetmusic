import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import type { LibraryData, Song } from '../../types/song'

export async function executeInlineSongUpdate(
	libraryId: number,
	song: Song,
	updates: Partial<Song>,
	onUpdated: (data: LibraryData) => void,
): Promise<void> {
	const nextTitle = (updates.title !== undefined ? updates.title : song.title ?? '').trim()
	if (!nextTitle) {
		showError(t('sheetmusic', 'Song title cannot be empty.'))
		return
	}

	const payload = {
		libraryId,
		title: nextTitle,
		composerIds: (song.composers ?? []).map((c) => c.id),
		bookIds: (song.books ?? []).map((b) => b.id),
		collectionIds: (song.collections ?? []).map((c) => c.id),
		artistIds: (song.artists ?? []).map((a) => a.id),
		genreIds: (song.genres ?? []).map((g) => g.id),
		keyIds: (song.keys ?? []).map((k) => k.id),
		customGroupIds: (song.customGroups ?? []).map((cg) => cg.id),
		yearIds: (song.years ?? []).map((y) => y.id),
		signatureIds: (song.signatures ?? []).map((s) => s.id),
		sourceTypeIds: (song.sourceTypes ?? []).map((st) => st.id),
		instrumentIds: (song.instruments ?? []).map((i) => i.id),
		opus: (updates.opus !== undefined ? updates.opus : song.opus ?? '').trim(),
		catalogNumber: (updates.catalogNumber !== undefined ? updates.catalogNumber : song.catalogNumber ?? '').trim(),
		songNumber: (updates.songNumber !== undefined ? updates.songNumber : song.songNumber ?? '').trim(),
		stars: Number(updates.stars !== undefined ? updates.stars : song.stars) || 0,
		difficulty: Number(updates.difficulty !== undefined ? updates.difficulty : song.difficulty) || 0,
		duration: Number(updates.duration !== undefined ? updates.duration : song.duration) || 0,
		keywords: (updates.keywords !== undefined ? updates.keywords : song.keywords ?? '').trim(),
		custom: (updates.custom !== undefined ? updates.custom : song.custom ?? '').trim(),
		custom2: (updates.custom2 !== undefined ? updates.custom2 : song.custom2 ?? '').trim(),
		notes: (song.notes ?? []).map((n) => ({ id: n.id, note: n.note, title: n.title ?? '' })),
		tempos: (song.tempos ?? []).map((tp) => tp.tempo).filter((val) => Number(val) > 0),
		bookmarks: (song.bookmarks ?? []).map((b) => ({ id: b.id, name: b.name, pageNum: b.pageNum })),
		audioFiles: (song.audioFiles ?? []).map((a) => ({ id: a.id, title: a.title, file: a.file })),
	}

	try {
		const response = await apiPost('/api/songs/{id}', payload, {
			pathParams: { id: song.id },
		})
		if (response.data?.ocs?.data) {
			onUpdated(response.data.ocs.data)
		}
		showSuccess(t('sheetmusic', 'Song "{title}" updated successfully.', { title: nextTitle }))
	} catch (error) {
		console.error('Failed to update song inline:', error)
		showError(describeRequestFailure(error, t('sheetmusic', 'Failed to save song changes.')))
	}
}
