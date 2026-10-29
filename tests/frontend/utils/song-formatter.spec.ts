import { describe, expect, it } from 'vitest'
import type { Song } from '../../../src/types/song'
import { formatArtists, formatBooks, formatCollections, formatComposers, formatCustomGroups, formatDateTimestamp, formatGenres, formatInstruments, formatKeys, formatSignatures, formatSourceTypes, formatYears, sortSongs } from '../../../src/utils/song-formatter'

describe('song-formatter utilities', () => {
	const sampleSong: Song = {
		id: 1,
		title: 'Symphony No. 5',
		composers: [{ id: 1, name: 'Beethoven' }, { id: 2, name: 'Mozart' }],
		books: [{ id: 10, title: 'Real Book Vol 1' }],
		collections: [{ id: 100, name: 'Classical' }],
		artists: [{ id: 50, name: 'Vienna Philharmonic' }],
		genres: [{ id: 7, name: 'Symphonic' }],
		keys: [{ id: 3, name: 'C Minor' }],
		customGroups: [{ id: 4, name: 'Orchestra' }],
		years: [{ id: 8, name: '1808', year: 1808 }],
		signatures: [{ id: 9, name: '2/4' }],
		sourceTypes: [{ id: 11, name: 'Sheet Music', type: 'Sheet Music' }],
		instruments: [{ id: 12, name: 'Piano' }],
	}

	const emptySong: Song = {
		id: 2,
		title: 'Untitled',
	}

	it('formats composers correctly', () => {
		expect(formatComposers(sampleSong)).toBe('Beethoven, Mozart')
		expect(formatComposers(emptySong)).toBe('—')
		expect(formatComposers(emptySong, '')).toBe('')
	})

	it('formats books correctly', () => {
		expect(formatBooks(sampleSong)).toBe('Real Book Vol 1')
		expect(formatBooks(emptySong)).toBe('—')
	})

	it('formats collections correctly', () => {
		expect(formatCollections(sampleSong)).toBe('Classical')
		expect(formatCollections(emptySong)).toBe('—')
	})

	it('formats artists correctly', () => {
		expect(formatArtists(sampleSong)).toBe('Vienna Philharmonic')
		expect(formatArtists(emptySong)).toBe('—')
	})

	it('formats genres correctly', () => {
		expect(formatGenres(sampleSong)).toBe('Symphonic')
		expect(formatGenres(emptySong)).toBe('—')
	})

	it('formats keys correctly', () => {
		expect(formatKeys(sampleSong)).toBe('C Minor')
		expect(formatKeys(emptySong)).toBe('—')
	})

	it('formats custom groups correctly', () => {
		expect(formatCustomGroups(sampleSong)).toBe('Orchestra')
		expect(formatCustomGroups(emptySong)).toBe('—')
	})

	it('formats years correctly', () => {
		expect(formatYears(sampleSong)).toBe('1808')
		expect(formatYears(emptySong)).toBe('—')
	})

	it('formats time signatures correctly', () => {
		expect(formatSignatures(sampleSong)).toBe('2/4')
		expect(formatSignatures(emptySong)).toBe('—')
	})

	it('formats source types correctly', () => {
		expect(formatSourceTypes(sampleSong)).toBe('Sheet Music')
		expect(formatSourceTypes(emptySong)).toBe('—')
	})

	it('formats instruments correctly', () => {
		expect(formatInstruments(sampleSong)).toBe('Piano')
		expect(formatInstruments(emptySong)).toBe('—')
	})

	it('sorts songs by instrument and opus', () => {
		const songs: Song[] = [
			{ id: 1, title: 'Song 1', instruments: [{ id: 1, name: 'Violin' }], opus: 'Op. 20' },
			{ id: 2, title: 'Song 2', instruments: [{ id: 2, name: 'Cello' }], opus: 'Op. 5' },
		]

		const sortedByInst = sortSongs(songs, 'instrument', 'asc')
		expect(sortedByInst[0].id).toBe(2) // Cello before Violin

		const sortedByOpus = sortSongs(songs, 'opus', 'asc')
		expect(sortedByOpus[0].id).toBe(2) // Op. 5 before Op. 20 with numeric sort
	})

	it('sorts songs by title ascending and descending', () => {
		const songs: Song[] = [
			{ id: 1, title: 'B Song' },
			{ id: 2, title: 'A Song' },
			{ id: 3, title: 'C Song' },
		]

		const asc = sortSongs(songs, 'title', 'asc')
		expect(asc.map(s => s.title)).toEqual(['A Song', 'B Song', 'C Song'])

		const desc = sortSongs(songs, 'title', 'desc')
		expect(desc.map(s => s.title)).toEqual(['C Song', 'B Song', 'A Song'])
	})

	it('sorts songs by composer', () => {
		const songs: Song[] = [
			{ id: 1, title: 'Song 1', composers: [{ id: 1, name: 'Mozart' }] },
			{ id: 2, title: 'Song 2', composers: [{ id: 2, name: 'Bach' }] },
		]

		const sorted = sortSongs(songs, 'composer', 'asc')
		expect(sorted[0].id).toBe(2) // Bach comes before Mozart
	})

	it('sorts songs by lastModified and dateCreated', () => {
		const songs: Song[] = [
			{ id: 1, title: 'Song 1', lastModified: 2000, dateCreated: 100 },
			{ id: 2, title: 'Song 2', lastModified: 1000, dateCreated: 300 },
			{ id: 3, title: 'Song 3', lastModified: 3000, dateCreated: 200 },
		]

		const sortedByModAsc = sortSongs(songs, 'lastModified', 'asc')
		expect(sortedByModAsc.map(s => s.id)).toEqual([2, 1, 3])

		const sortedByModDesc = sortSongs(songs, 'lastModified', 'desc')
		expect(sortedByModDesc.map(s => s.id)).toEqual([3, 1, 2])

		const sortedByCreatedAsc = sortSongs(songs, 'dateCreated', 'asc')
		expect(sortedByCreatedAsc.map(s => s.id)).toEqual([1, 3, 2])
	})

	it('formats date timestamps in both seconds and milliseconds without falling back to 1970', () => {
		const unixSeconds = 1761687800 // 2025-10-28 in seconds
		const unixMs = 1761687800000 // 2025-10-28 in milliseconds

		const formattedSeconds = formatDateTimestamp(unixSeconds)
		const formattedMs = formatDateTimestamp(unixMs)

		expect(formattedSeconds).toContain('2025')
		expect(formattedSeconds).not.toContain('1970')
		expect(formattedMs).toContain('2025')
		expect(formattedSeconds).toBe(formattedMs)
		expect(formatDateTimestamp(null)).toBe('—')
		expect(formatDateTimestamp(0)).toBe('—')
	})
})
