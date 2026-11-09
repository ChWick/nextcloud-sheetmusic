import { describe, expect, it } from 'vitest'
import { countMatchingSongs, matchesSublibraryFilters } from '../../../src/utils/sublibrary'
import type { Song } from '../../../src/types/song'

describe('sublibrary utils', () => {
	const mockSongs = [
		{
			id: 1,
			title: 'Toccata and Fugue',
			instruments: [{ id: 1, name: 'Orgel' }],
			composers: [{ id: 1, name: 'J.S. Bach' }],
		},
		{
			id: 2,
			title: 'Clair de Lune',
			instruments: [{ id: 2, name: 'Klavier' }],
			composers: [{ id: 2, name: 'Debussy' }],
		},
		{
			id: 3,
			title: 'Chorale Prelude',
			instruments: [{ id: 1, name: 'Orgel' }, { id: 2, name: 'Klavier' }],
			composers: [{ id: 1, name: 'J.S. Bach' }],
		},
		{
			id: 4,
			title: 'Real Book Lead Sheet',
			books: [{ id: 10, title: 'The Real Book Vol 1' }],
		},
	] as unknown as Song[]

	describe('matchesSublibraryFilters', () => {
		it('returns true if no filters are provided', () => {
			expect(matchesSublibraryFilters({}, mockSongs[0])).toBe(true)
			expect(matchesSublibraryFilters(null, mockSongs[0])).toBe(true)
		})

		it('matches by single instrument', () => {
			expect(matchesSublibraryFilters({ instrument: ['Orgel'] }, mockSongs[0])).toBe(true)
			expect(matchesSublibraryFilters({ instrument: ['Orgel'] }, mockSongs[1])).toBe(false)
			expect(matchesSublibraryFilters({ instrument: ['Orgel'] }, mockSongs[2])).toBe(true)
		})

		it('matches case-insensitively and trims whitespace', () => {
			expect(matchesSublibraryFilters({ instrument: ['  orgel  '] }, mockSongs[0])).toBe(true)
		})

		it('matches by book title using title property', () => {
			expect(matchesSublibraryFilters({ book: ['The Real Book Vol 1'] }, mockSongs[3])).toBe(true)
			expect(matchesSublibraryFilters({ book: ['Fake Book'] }, mockSongs[3])).toBe(false)
		})

		it('requires all filter types to match (AND logic across types)', () => {
			expect(matchesSublibraryFilters({
				instrument: ['Orgel'],
				composer: ['J.S. Bach'],
			}, mockSongs[0])).toBe(true)

			expect(matchesSublibraryFilters({
				instrument: ['Orgel'],
				composer: ['Debussy'],
			}, mockSongs[0])).toBe(false)
		})
	})

	describe('countMatchingSongs', () => {
		it('returns null if songs list is empty or undefined', () => {
			expect(countMatchingSongs({ instrument: ['Orgel'] }, null)).toBeNull()
			expect(countMatchingSongs({ instrument: ['Orgel'] }, [])).toBeNull()
		})

		it('returns total count if filters are empty', () => {
			expect(countMatchingSongs({}, mockSongs)).toBe(4)
			expect(countMatchingSongs(null, mockSongs)).toBe(4)
		})

		it('counts matching songs correctly', () => {
			expect(countMatchingSongs({ instrument: ['Orgel'] }, mockSongs)).toBe(2)
			expect(countMatchingSongs({ instrument: ['Klavier'] }, mockSongs)).toBe(2)
			expect(countMatchingSongs({ instrument: ['Flöte'] }, mockSongs)).toBe(0)
		})
	})
})
