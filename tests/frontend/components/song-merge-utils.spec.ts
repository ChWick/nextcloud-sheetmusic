import { describe, expect, it } from 'vitest'
import {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	areEntitiesEqual,
	copyFieldIntoDraft,
	createDraftFromSong,
	createMergedDraft,
	draftToMergedSongData,
	entityName,
	formatEntityList,
	toSelectOptions,
	wouldSongsCollide,
} from '../../../src/components/song-editor/song-merge-utils'
import type { Song } from '../../../src/types/song'

describe('song-merge-utils', () => {
	describe('single source of truth coverage', () => {
		it('includes all 12 entity types and all 9 scalar fields', () => {
			expect(ENTITY_COLUMNS.map(c => c.songProp)).toEqual([
				'composers',
				'books',
				'collections',
				'artists',
				'genres',
				'keys',
				'customGroups',
				'years',
				'signatures',
				'sourceTypes',
				'instruments',
				'arrangers',
			])
			expect(SONG_SCALAR_FIELDS.map(f => f.key)).toEqual([
				'opus',
				'catalogNumber',
				'songNumber',
				'stars',
				'difficulty',
				'duration',
				'keywords',
				'custom',
				'custom2',
			])
		})

		it('creates merged draft, copies fields, and converts to MergedSongData', () => {
			const songA: Song = {
				id: 1,
				title: 'Chorale',
				composers: [{ id: 10, name: 'Bach' }],
				arrangers: [{ id: 20, name: 'Busoni' }],
				duration: 180,
				stars: 5,
				opus: 'BWV 639',
			}
			const songB: Song = {
				id: 2,
				title: 'Chorale Prelude',
				composers: [{ id: 10, name: 'Bach' }],
				arrangers: [{ id: 21, name: 'Reger' }],
				duration: 200,
				stars: 5,
				opus: 'BWV 639',
			}

			const merged = createMergedDraft(songA, songB)
			expect(merged.title).toBe('')
			expect(merged.entities.composers).toEqual([{ id: 10, label: 'Bach' }])
			expect(merged.entities.arrangers).toEqual([])
			expect(merged.scalars.stars).toBe(5)
			expect(merged.scalars.duration).toBeNull()
			expect(merged.scalars.opus).toBe('BWV 639')

			copyFieldIntoDraft(merged, 'title', songA)
			copyFieldIntoDraft(merged, 'arrangers', songB)
			copyFieldIntoDraft(merged, 'duration', songA)

			const data = draftToMergedSongData(merged)
			expect(data.title).toBe('Chorale')
			expect(data.composerIds).toEqual([10])
			expect(data.arrangerIds).toEqual([21])
			expect(data.duration).toBe(180)
			expect(data.stars).toBe(5)
			expect(data.difficulty).toBeNull()
			expect(data.opus).toBe('BWV 639')
		})
	})

	describe('entityName', () => {
		it('returns name, title, year, or id string in priority order', () => {
			expect(entityName({ id: 1, name: 'Bach' })).toBe('Bach')
			expect(entityName({ id: 2, title: 'Cantata' })).toBe('Cantata')
			expect(entityName({ id: 3, year: 1720 })).toBe('1720')
			expect(entityName({ id: 4 })).toBe('4')
		})
	})

	describe('toSelectOptions', () => {
		it('transforms items to id and label select options', () => {
			expect(toSelectOptions([{ id: 10, name: 'Chopin' }, { id: 20, name: 'Liszt' }])).toEqual([
				{ id: 10, label: 'Chopin' },
				{ id: 20, label: 'Liszt' },
			])
			expect(toSelectOptions(undefined)).toEqual([])
		})
	})

	describe('areEntitiesEqual', () => {
		it('returns true when entity arrays have identical IDs regardless of order', () => {
			expect(areEntitiesEqual([{ id: 1 }, { id: 2 }], [{ id: 2 }, { id: 1 }])).toBe(true)
			expect(areEntitiesEqual([], [])).toBe(true)
			expect(areEntitiesEqual(undefined, undefined)).toBe(true)
		})

		it('returns false when lengths or contents differ', () => {
			expect(areEntitiesEqual([{ id: 1 }], [{ id: 1 }, { id: 2 }])).toBe(false)
			expect(areEntitiesEqual([{ id: 1 }, { id: 3 }], [{ id: 1 }, { id: 2 }])).toBe(false)
		})
	})

	describe('formatEntityList', () => {
		it('formats lists with commas or returns fallback', () => {
			expect(formatEntityList([{ id: 1, name: 'Bach' }, { id: 2, name: 'Mozart' }])).toBe('Bach, Mozart')
			expect(formatEntityList([])).toBe('(None)')
			expect(formatEntityList(undefined, 'Custom None')).toBe('Custom None')
		})
	})

	describe('wouldSongsCollide', () => {
		const baseSongA: Song = {
			id: 1,
			title: 'Prelude',
			files: [{ id: 101, path: '/files/Prelude.pdf', type: 1 }],
		}
		const baseSongB: Song = {
			id: 2,
			title: 'Prelude',
			files: [{ id: 102, path: '/files/Prelude.pdf', type: 1 }],
		}

		it('detects collision when songs share an active physical file path', () => {
			const collides = wouldSongsCollide({
				songA: baseSongA,
				songB: baseSongB,
				titleA: 'Prelude 1',
				titleB: 'Prelude 2',
				composersA: [],
				composersB: [],
				artistsA: [],
				artistsB: [],
				booksA: [],
				booksB: [],
				collectionsA: [],
				collectionsB: [],
				detachedFilesA: [],
				detachedFilesB: [],
			})
			expect(collides).toBe(true)
		})

		it('does not detect collision if shared file is detached in one song', () => {
			const collides = wouldSongsCollide({
				songA: baseSongA,
				songB: baseSongB,
				titleA: 'Prelude 1',
				titleB: 'Prelude 2',
				composersA: [],
				composersB: [],
				artistsA: [],
				artistsB: [],
				booksA: [],
				booksB: [],
				collectionsA: [],
				collectionsB: [],
				detachedFilesA: [101],
				detachedFilesB: [],
			})
			expect(collides).toBe(false)
		})

		it('detects naming rule collision when songs have identical metadata and file extension', () => {
			const distinctSongA: Song = {
				id: 1,
				title: 'Prelude',
				files: [{ id: 101, path: '/files/A/Prelude.pdf', type: 1 }],
			}
			const distinctSongB: Song = {
				id: 2,
				title: 'Prelude',
				files: [{ id: 102, path: '/files/B/Prelude.pdf', type: 1 }],
			}
			const collides = wouldSongsCollide({
				songA: distinctSongA,
				songB: distinctSongB,
				titleA: 'Prelude',
				titleB: 'prelude',
				composersA: [{ id: 10 }],
				composersB: [{ id: 10 }],
				artistsA: [],
				artistsB: [],
				booksA: [],
				booksB: [],
				collectionsA: [],
				collectionsB: [],
				detachedFilesA: [],
				detachedFilesB: [],
			})
			expect(collides).toBe(true)
		})

		it('resolves collision when titles are differentiated', () => {
			const distinctSongA: Song = {
				id: 1,
				title: 'Prelude',
				files: [{ id: 101, path: '/files/A/Prelude.pdf', type: 1 }],
			}
			const distinctSongB: Song = {
				id: 2,
				title: 'Prelude',
				files: [{ id: 102, path: '/files/B/Prelude.pdf', type: 1 }],
			}
			const collides = wouldSongsCollide({
				songA: distinctSongA,
				songB: distinctSongB,
				titleA: 'Prelude (1)',
				titleB: 'Prelude (2)',
				composersA: [{ id: 10 }],
				composersB: [{ id: 10 }],
				artistsA: [],
				artistsB: [],
				booksA: [],
				booksB: [],
				collectionsA: [],
				collectionsB: [],
				detachedFilesA: [],
				detachedFilesB: [],
			})
			expect(collides).toBe(false)
		})
	})
})
