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
			autoScrollSpeed: 50,
			autoScrollDelay: 3,
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
			autoScrollSpeed: 25,
			autoScrollDelay: 5,
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
				autoScrollSpeed: 50,
				autoScrollDelay: 3,
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
			autoScrollSpeed: 25,
			autoScrollDelay: 5,
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
				autoScrollSpeed: 25,
				autoScrollDelay: 5,
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
				autoScrollSpeed: 50,
				autoScrollDelay: 3,
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

		// Night ink adaptation: preserves hue while inverting HSL lightness into [0.08..0.94]
		expect(adaptAnnotationColorForNight('#000000', false)).toBe('#000000')
		expect(adaptAnnotationColorForNight('#000000', true)).toBe('#f0f0f0')
		expect(adaptAnnotationColorForNight('#ffffff', true)).toBe('#141414')
		// Dark red (#8b0000) flips to a bright readable red (high R, lower G=B)
		const nightRed = adaptAnnotationColorForNight('#8b0000', true)
		expect(nightRed).toMatch(/^#[0-9a-f]{6}$/)
		const nr = Number.parseInt(nightRed.slice(1, 3), 16)
		const ng = Number.parseInt(nightRed.slice(3, 5), 16)
		const nb = Number.parseInt(nightRed.slice(5, 7), 16)
		expect(nr).toBeGreaterThan(220)
		expect(ng).toBe(nb)
		expect(nr).toBeGreaterThan(ng)
		// Dark blue (#003380) flips to a bright readable blue (high B, lower R)
		const nightBlue = adaptAnnotationColorForNight('#003380', true)
		const br = Number.parseInt(nightBlue.slice(1, 3), 16)
		const bb = Number.parseInt(nightBlue.slice(5, 7), 16)
		expect(bb).toBeGreaterThan(220)
		expect(bb).toBeGreaterThan(br)
	})
})
