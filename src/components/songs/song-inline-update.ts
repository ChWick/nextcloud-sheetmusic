import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import { resolveSongEntityIds } from '../song-editor/entity-utils'
import { ENTITY_COLUMNS } from './song-view-columns'
import type { LibraryData, Song } from '../../types/song'

export type InlineSongUpdates = Partial<Omit<Song, 'composers' | 'books' | 'collections' | 'artists' | 'genres' | 'keys' | 'customGroups' | 'years' | 'signatures' | 'sourceTypes' | 'instruments' | 'arrangers'>> & {
	composers?: Array<{ id?: number, name?: string }>
	books?: Array<{ id?: number, title?: string }>
	collections?: Array<{ id?: number, name?: string }>
	artists?: Array<{ id?: number, name?: string }>
	genres?: Array<{ id?: number, name?: string }>
	keys?: Array<{ id?: number, name?: string }>
	customGroups?: Array<{ id?: number, name?: string }>
	years?: Array<{ id?: number, name?: string }>
	signatures?: Array<{ id?: number, name?: string }>
	sourceTypes?: Array<{ id?: number, name?: string }>
	instruments?: Array<{ id?: number, name?: string }>
	arrangers?: Array<{ id?: number, name?: string }>
}

export async function executeInlineSongUpdate(
	libraryId: number,
	song: Song,
	updates: InlineSongUpdates,
	libraryData: Partial<LibraryData>,
	onUpdated: (data: LibraryData) => void,
): Promise<void> {
	const nextTitle = (updates.title !== undefined ? updates.title : song.title ?? '').trim()
	if (!nextTitle) {
		showError(t('sheetmusic', 'Song title cannot be empty.'))
		return
	}

	try {
		const entityIds = await resolveSongEntityIds(
			ENTITY_COLUMNS.map((col) => ({
				idField: col.idField,
				endpoint: col.endpoint,
				labelField: col.nameKey,
				selected: (updates[col.songProp] ?? (song as any)[col.songProp] ?? []) as Array<{ id?: number, name?: string, title?: string }>,
				options: ((libraryData as any)[col.songProp] ?? (song as any)[col.songProp] ?? []) as Array<{ id: number, name?: string, title?: string }>,
			})),
			async (endpoint, labelField, label) => {
				const res = await apiPost(endpoint as any, {
					libraryId,
					[labelField]: label,
				} as any)
				return res.data.ocs.data.id
			},
		)

		const payload = {
			libraryId,
			title: nextTitle,
			...entityIds,
			opus: (updates.opus !== undefined ? updates.opus : song.opus ?? '').trim(),
			catalogNumber: (updates.catalogNumber !== undefined ? updates.catalogNumber : song.catalogNumber ?? '').trim(),
			songNumber: (updates.songNumber !== undefined ? updates.songNumber : song.songNumber ?? '').trim(),
			stars: Number(updates.stars !== undefined ? updates.stars : song.stars) > 0
				? Number(updates.stars !== undefined ? updates.stars : song.stars)
				: null,
			difficulty: Number(updates.difficulty !== undefined ? updates.difficulty : song.difficulty) > 0
				? Number(updates.difficulty !== undefined ? updates.difficulty : song.difficulty)
				: null,
			duration: Number(updates.duration !== undefined ? updates.duration : song.duration) > 0
				? Number(updates.duration !== undefined ? updates.duration : song.duration)
				: null,
			keywords: (updates.keywords !== undefined ? updates.keywords : song.keywords ?? '').trim(),
			custom: (updates.custom !== undefined ? updates.custom : song.custom ?? '').trim(),
			custom2: (updates.custom2 !== undefined ? updates.custom2 : song.custom2 ?? '').trim(),
			notes: (song.notes ?? []).map((n) => ({ id: n.id, note: n.note, title: n.title ?? '' })),
			tempos: (song.tempos ?? []).map((tp) => tp.tempo).filter((val) => Number(val) > 0),
			bookmarks: (song.bookmarks ?? []).map((b) => ({ id: b.id, name: b.name, pageNum: b.pageNum })),
			audioFiles: (song.audioFiles ?? []).map((a) => ({ id: a.id, title: a.title, file: a.file })),
		}

		const response = await apiPost('/api/songs/{id}', payload, {
			pathParams: { id: song.id },
		})
		if (response.data?.ocs?.data) {
			onUpdated(response.data.ocs.data)
		}
		showSuccess(t('sheetmusic', 'Song "{title}" updated successfully.', { title: nextTitle }, { escape: false }))
	} catch (error) {
		console.error('Failed to update song inline:', error)
		showError(describeRequestFailure(error, t('sheetmusic', 'Failed to save song changes.')))
	}
}
