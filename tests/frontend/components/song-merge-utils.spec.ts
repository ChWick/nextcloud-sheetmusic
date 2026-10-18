import { describe, expect, it } from 'vitest'
import {
	areEntitiesEqual,
	entityName,
	formatEntityList,
	toSelectOptions,
	wouldSongsCollide,
} from '../../../src/components/song-editor/song-merge-utils'
import type { Song } from '../../../src/types/song'

describe('song-merge-utils', () => {
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

