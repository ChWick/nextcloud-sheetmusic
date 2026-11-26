import { describe, expect, it } from 'vitest'
import {
	adaptAnnotationColorForNight,
	createLibraryPerformanceStore,
	createSetlistPerformanceStore,
	DEFAULT_PERFORMANCE_SETTINGS,
	getDefaultSepiaForMode,
	getEffectiveStageBackground,
	getScoreFilterCss,
	isNightScoreMode,
	loadEffectivePerformanceSettings,
	sanitizeScoreBrightness,
	sanitizeScoreSepia,
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
			scoreSepia: 0,
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
			scoreSepia: 45,
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
				scoreSepia: 0,
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
			scoreSepia: 45,
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
				scoreSepia: 45,
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
				scoreSepia: 0,
			},
			overrideEnabled: false,
		})
	})

	it('builds CSS filters, stage backgrounds, clamps brightness/sepia, and adapts dark ink in night mode', () => {
		expect(isNightScoreMode('night')).toBe(true)
		expect(isNightScoreMode('night-sepia')).toBe(true)
		expect(isNightScoreMode('sepia')).toBe(false)
		expect(isNightScoreMode('normal')).toBe(false)

		expect(sanitizeScoreBrightness(10)).toBe(40)
		expect(sanitizeScoreBrightness(150)).toBe(100)
		expect(sanitizeScoreBrightness(75.4)).toBe(75)
		expect(sanitizeScoreBrightness('bad')).toBe(100)

		expect(sanitizeScoreSepia(-10)).toBe(0)
		expect(sanitizeScoreSepia(120)).toBe(100)
		expect(getDefaultSepiaForMode('sepia')).toBe(45)
		expect(getDefaultSepiaForMode('night-sepia')).toBe(35)
		expect(getDefaultSepiaForMode('night')).toBe(0)

		expect(getScoreFilterCss('normal', 100)).toBe('')
		expect(getScoreFilterCss('normal', 70)).toBe('brightness(0.70)')
		expect(getScoreFilterCss('sepia', 100)).toBe('sepia(0.45) contrast(0.95)')
		expect(getScoreFilterCss('sepia', 100, 60)).toBe('sepia(0.60) contrast(0.95)')
		expect(getScoreFilterCss('night', 100)).toBe('invert(0.92) hue-rotate(180deg)')
		expect(getScoreFilterCss('night-sepia', 80)).toBe('invert(0.9) sepia(0.35) hue-rotate(180deg) brightness(0.80)')

		expect(getEffectiveStageBackground('', 'normal')).toBe('')
		expect(getEffectiveStageBackground('', 'night')).toBe('#0f0f10')
		expect(getEffectiveStageBackground('', 'night-sepia')).toBe('#0f0f10')
		expect(getEffectiveStageBackground('', 'sepia')).toBe('#ede4d3')
		expect(getEffectiveStageBackground('', 'normal', 25)).toBe('#ede4d3')
		expect(getEffectiveStageBackground('#123456', 'night')).toBe('#123456')

		// Night ink adaptation: black/dark-gray flips light; colored markings stay untouched
		expect(adaptAnnotationColorForNight('#000000', false)).toBe('#000000')
		expect(adaptAnnotationColorForNight('#000000', true)).toBe('#f0f0f0')
		expect(adaptAnnotationColorForNight('#222222', true)).toBe('#dddddd')
		expect(adaptAnnotationColorForNight('#e53935', true)).toBe('#e53935')
		expect(adaptAnnotationColorForNight('#0052cc', true)).toBe('#0052cc')
		expect(adaptAnnotationColorForNight('#ffeb3b', true)).toBe('#ffeb3b')
	})
})
