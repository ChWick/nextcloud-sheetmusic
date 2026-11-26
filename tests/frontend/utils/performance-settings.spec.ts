import { describe, expect, it } from 'vitest'
import {
	createLibraryPerformanceStore,
	createSetlistPerformanceStore,
	DEFAULT_PERFORMANCE_SETTINGS,
	getEffectiveStageBackground,
	getScoreFilterCss,
	isNightScoreMode,
	loadEffectivePerformanceSettings,
	sanitizeScoreBrightness,
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
			interleaveSongs: true,
			backgroundColor: '#1e1e1e',
			tightCrop: true,
			scoreColorMode: 'night',
			scoreBrightness: 80,
		})

		const setlistStore = createSetlistPerformanceStore(10, 5, storage)
		setlistStore.save({
			layoutChoice: 'single',
			doublePageTurnMode: 'step',
			zoomChoice: 'fit-page',
			showAnnotations: false,
			interleaveSongs: false,
			backgroundColor: '#f5f0e6',
			tightCrop: false,
			scoreColorMode: 'sepia',
			scoreBrightness: 90,
			overrideEnabled: false,
		})

		// Override disabled -> returns library defaults
		expect(loadEffectivePerformanceSettings(10, 5, storage)).toEqual({
			settings: {
				layoutChoice: 'double',
				doublePageTurnMode: 'spread',
				zoomChoice: 'fit-width',
				showAnnotations: true,
				interleaveSongs: true,
				backgroundColor: '#1e1e1e',
				tightCrop: true,
				scoreColorMode: 'night',
				scoreBrightness: 80,
			},
			overrideEnabled: false,
		})

		// Enable override -> returns setlist-specific settings
		setlistStore.save({
			layoutChoice: 'single',
			doublePageTurnMode: 'step',
			zoomChoice: 'fit-page',
			showAnnotations: false,
			interleaveSongs: false,
			backgroundColor: '#f5f0e6',
			tightCrop: false,
			scoreColorMode: 'sepia',
			scoreBrightness: 90,
			overrideEnabled: true,
		})

		expect(loadEffectivePerformanceSettings(10, 5, storage)).toEqual({
			settings: {
				layoutChoice: 'single',
				doublePageTurnMode: 'step',
				zoomChoice: 'fit-page',
				showAnnotations: false,
				interleaveSongs: false,
				backgroundColor: '#f5f0e6',
				tightCrop: false,
				scoreColorMode: 'sepia',
				scoreBrightness: 90,
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
				interleaveSongs: true,
				backgroundColor: '#1e1e1e',
				tightCrop: true,
				scoreColorMode: 'night',
				scoreBrightness: 80,
			},
			overrideEnabled: false,
		})
	})

	it('builds CSS filters, stage backgrounds, and clamps brightness for night/tint modes', () => {
		expect(isNightScoreMode('night')).toBe(true)
		expect(isNightScoreMode('night-sepia')).toBe(true)
		expect(isNightScoreMode('sepia')).toBe(false)
		expect(isNightScoreMode('normal')).toBe(false)

		expect(sanitizeScoreBrightness(10)).toBe(40)
		expect(sanitizeScoreBrightness(150)).toBe(100)
		expect(sanitizeScoreBrightness(75.4)).toBe(75)
		expect(sanitizeScoreBrightness('bad')).toBe(100)

		expect(getScoreFilterCss('normal', 100)).toBe('')
		expect(getScoreFilterCss('normal', 70)).toBe('brightness(0.70)')
		expect(getScoreFilterCss('sepia', 100)).toBe('sepia(0.45) contrast(0.95)')
		expect(getScoreFilterCss('night', 100)).toBe('invert(0.92) hue-rotate(180deg)')
		expect(getScoreFilterCss('night-sepia', 80)).toBe('invert(0.9) sepia(0.35) hue-rotate(180deg) brightness(0.80)')

		expect(getEffectiveStageBackground('', 'normal')).toBe('')
		expect(getEffectiveStageBackground('', 'night')).toBe('#0f0f10')
		expect(getEffectiveStageBackground('', 'night-sepia')).toBe('#0f0f10')
		expect(getEffectiveStageBackground('', 'sepia')).toBe('#ede4d3')
		expect(getEffectiveStageBackground('#123456', 'night')).toBe('#123456')
	})
})
