import { describe, expect, it, vi } from 'vitest'
import {
	createEntityTag,
	findExistingEntity,
	matchSelected,
	prefillByName,
	resolveSongEntityIds,
} from '../../../src/components/song-editor/entity-utils'

describe('SongEditor entity utilities', () => {
	it('matches selected entities by id and typed labels without case or whitespace differences', () => {
		const entities = [
			{ id: 1, name: 'Johann Sebastian Bach' },
			{ id: 2, name: 'Wolfgang Amadeus Mozart' },
		]

		expect(matchSelected(entities, [{ id: 2 }])).toEqual([entities[1]])
		expect(findExistingEntity(entities, '  johann sebastian bach  ', entity => entity.name)).toEqual(entities[0])
		expect(findExistingEntity(entities, '  ', entity => entity.name)).toBeUndefined()
	})

	it('prefills matching names from existing entities and creates tags for unmatched names', () => {
		const existing = [{ id: 1, name: 'Bach' }]
		const makeTag = vi.fn((name: string) => ({ name }))

		expect(prefillByName(['bach', 'New Composer'], existing, entity => entity.name, makeTag)).toEqual([
			existing[0],
			{ name: 'New Composer' },
		])
		expect(makeTag).toHaveBeenCalledOnce()
		expect(createEntityTag(existing, entity => entity.name, name => ({ name }))(' Bach ')).toBe(existing[0])
	})

	it('resolves selected IDs, reuses case-insensitive matches and creates missing entities', async () => {
		const createEntity = vi.fn(async (endpoint: string, labelField: 'name' | 'title', label: string) => {
			expect(endpoint).toBe('/api/books')
			expect(labelField).toBe('title')
			expect(label).toBe('New Album')
			return 30
		})

		const ids = await resolveSongEntityIds([
			{
				idField: 'bookIds',
				endpoint: '/api/books',
				labelField: 'title',
				options: [{ id: 10, title: 'Real Book' }],
				selected: [
					{ id: 11, title: 'Selected by ID' },
					{ title: ' real book ' },
					{ title: 'New Album' },
				],
			},
			{
				idField: 'composerIds',
				endpoint: '/api/composers',
				labelField: 'name',
				options: [],
				selected: [],
			},
		], createEntity)

		expect(ids.bookIds).toEqual([11, 10, 30])
		expect(ids.composerIds).toEqual([])
		expect(createEntity).toHaveBeenCalledOnce()
	})

	it('creates multiple missing entities in parallel and deduplicates identical new labels', async () => {
		let inFlight = 0
		let maxInFlight = 0
		let nextId = 100
		const createEntity = vi.fn(async () => {
			inFlight++
			maxInFlight = Math.max(maxInFlight, inFlight)
			await new Promise((resolve) => setTimeout(resolve, 10))
			inFlight--
			return nextId++
		})

		const ids = await resolveSongEntityIds([
			{
				idField: 'composerIds',
				endpoint: '/api/composers',
				labelField: 'name',
				options: [],
				selected: [
					{ name: 'Debussy' },
					{ name: ' debussy ' },
					{ name: 'Ravel' },
				],
			},
			{
				idField: 'genreIds',
				endpoint: '/api/genres',
				labelField: 'name',
				options: [],
				selected: [{ name: 'Impressionism' }],
			},
		], createEntity)

		expect(createEntity).toHaveBeenCalledTimes(3)
		expect(maxInFlight).toBe(3)
		expect(ids.composerIds[0]).toBe(ids.composerIds[1])
		expect(ids.composerIds[2]).not.toBe(ids.composerIds[0])
		expect(ids.genreIds).toHaveLength(1)
	})
})
