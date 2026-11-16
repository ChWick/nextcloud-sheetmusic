import { describe, expect, it } from 'vitest'
import {
	createLibraryPerformanceStore,
	createSetlistPerformanceStore,
	DEFAULT_PERFORMANCE_SETTINGS,
	loadEffectivePerformanceSettings,
} from '../../../src/utils/performance-settings'
import { MemoryStorageAdapter } from '../../../src/utils/settings-store'

describe('performance-settings', () => {
	it('falls back to default settings when nothing is stored', () => {
		const storage = new MemoryStorageAdapter()
		const effective = loadEffectivePerformanceSettings(10, 99, storage)
		expect(effective).toEqual({
			settings: DEFAULT_PERFORMANCE_SETTINGS,
			overrideEnabled: false,
		})
	})

	it('uses library defaults unless setlist override is enabled', () => {
		const storage = new MemoryStorageAdapter()
		const libStore = createLibraryPerformanceStore(10, storage)
		libStore.save({
			layoutChoice: 'double',
			doublePageTurnMode: 'spread',
			zoomChoice: 'fit-width',
			showAnnotations: true,
		})

		const setlistStore = createSetlistPerformanceStore(10, 5, storage)
		setlistStore.save({
			layoutChoice: 'single',
			doublePageTurnMode: 'step',
			zoomChoice: 'fit-page',
			showAnnotations: false,
			overrideEnabled: false,
		})

		// Override disabled -> returns library defaults
		expect(loadEffectivePerformanceSettings(10, 5, storage)).toEqual({
			settings: {
				layoutChoice: 'double',
				doublePageTurnMode: 'spread',
				zoomChoice: 'fit-width',
				showAnnotations: true,
			},
			overrideEnabled: false,
		})

		// Enable override -> returns setlist-specific settings
		setlistStore.save({
			layoutChoice: 'single',
			doublePageTurnMode: 'step',
			zoomChoice: 'fit-page',
			showAnnotations: false,
			overrideEnabled: true,
		})

		expect(loadEffectivePerformanceSettings(10, 5, storage)).toEqual({
			settings: {
				layoutChoice: 'single',
				doublePageTurnMode: 'step',
				zoomChoice: 'fit-page',
				showAnnotations: false,
			},
			overrideEnabled: true,
		})

		// Another setlist in the same library still gets library defaults
		expect(loadEffectivePerformanceSettings(10, 6, storage)).toEqual({
			settings: {
				layoutChoice: 'double',
				doublePageTurnMode: 'spread',
				zoomChoice: 'fit-width',
				showAnnotations: true,
			},
			overrideEnabled: false,
		})
	})
})
