import type { DoublePageTurnMode } from './page-turn'
import { SettingsStore, type StorageAdapter } from './settings-store'

export type PerformanceLayoutChoice = 'auto' | 'single' | 'double' | 'continuous'
export type PerformanceZoomChoice = 'fit-page' | 'fit-width'
export type ScoreColorMode = 'normal' | 'sepia' | 'night' | 'night-sepia'

export const SCORE_BRIGHTNESS_MIN = 40
export const SCORE_BRIGHTNESS_MAX = 100
export const SCORE_SEPIA_MIN = 0
export const SCORE_SEPIA_MAX = 100

export interface PerformanceViewerSettings {
	layoutChoice: PerformanceLayoutChoice
	doublePageTurnMode: DoublePageTurnMode
	zoomChoice: PerformanceZoomChoice
	showAnnotations: boolean
	interleaveSongs: boolean
	/** Custom stage background color ('', '#ffffff', '#000000', etc.); empty string uses theme default */
	backgroundColor: string
	/** On-the-fly whitespace cropping of PDF pages to maximize note area */
	tightCrop: boolean
	/** Score inversion / tinting preset for dark stages or reduced glare */
	scoreColorMode: ScoreColorMode
	/** Score dimming percentage (40..100) for dark stages / pit orchestras */
	scoreBrightness: number
	/** Continuous sepia tint percentage (0..100) */
	scoreSepia: number
}

export interface SetlistPerformanceOverride extends PerformanceViewerSettings {
	overrideEnabled: boolean
}

export interface PerformanceBackgroundPreset {
	value: string
	label: string
	previewColor: string
}

export interface ScoreColorPreset {
	value: ScoreColorMode
	label: string
	previewBg: string
	previewInk: string
}

export const SCORE_COLOR_PRESETS: ScoreColorPreset[] = [
	{ value: 'normal', label: 'Normal', previewBg: '#ffffff', previewInk: '#111111' },
	{ value: 'sepia', label: 'Sepia', previewBg: '#f4ead5', previewInk: '#2b2114' },
	{ value: 'night', label: 'Night invert', previewBg: '#141414', previewInk: '#eaeaea' },
	{ value: 'night-sepia', label: 'Warm night', previewBg: '#1a1510', previewInk: '#e6cfa8' },
]

export const PERFORMANCE_BACKGROUND_PRESETS: PerformanceBackgroundPreset[] = [
	{ value: '', label: 'Theme default', previewColor: 'var(--color-main-background, #ffffff)' },
	{ value: '#ffffff', label: 'White', previewColor: '#ffffff' },
	{ value: '#f5f0e6', label: 'Warm paper', previewColor: '#f5f0e6' },
	{ value: '#e8e8e8', label: 'Light gray', previewColor: '#e8e8e8' },
	{ value: '#1e1e1e', label: 'Dark charcoal', previewColor: '#1e1e1e' },
	{ value: '#000000', label: 'Black', previewColor: '#000000' },
]

export const DEFAULT_PERFORMANCE_SETTINGS: PerformanceViewerSettings = {
	layoutChoice: 'auto',
	doublePageTurnMode: 'half',
	zoomChoice: 'fit-page',
	showAnnotations: true,
	interleaveSongs: false,
	backgroundColor: '',
	tightCrop: false,
	scoreColorMode: 'normal',
	scoreBrightness: SCORE_BRIGHTNESS_MAX,
	scoreSepia: 0,
}

const VALID_LAYOUTS = new Set<PerformanceLayoutChoice>(['auto', 'single', 'double', 'continuous'])
const VALID_TURN_MODES = new Set<DoublePageTurnMode>(['half', 'step', 'spread'])
const VALID_ZOOMS = new Set<PerformanceZoomChoice>(['fit-page', 'fit-width'])
const VALID_SCORE_COLOR_MODES = new Set<ScoreColorMode>(['normal', 'sepia', 'night', 'night-sepia'])
const HEX_COLOR_RE = /^#[0-9a-f]{6}$/i
const HEX_SHORT_RE = /^#[0-9a-f]{3}$/i

export function sanitizeScoreBrightness(raw: unknown): number {
	if (typeof raw !== 'number' || !Number.isFinite(raw)) {
		return SCORE_BRIGHTNESS_MAX
	}
	return Math.min(SCORE_BRIGHTNESS_MAX, Math.max(SCORE_BRIGHTNESS_MIN, Math.round(raw)))
}

export function sanitizeScoreSepia(raw: unknown): number {
	if (typeof raw !== 'number' || !Number.isFinite(raw)) {
		return SCORE_SEPIA_MIN
	}
	return Math.min(SCORE_SEPIA_MAX, Math.max(SCORE_SEPIA_MIN, Math.round(raw)))
}

export function getDefaultSepiaForMode(mode: ScoreColorMode): number {
	if (mode === 'sepia') return 45
	if (mode === 'night-sepia') return 35
	return 0
}

export function isNightScoreMode(mode: ScoreColorMode): boolean {
	return mode === 'night' || mode === 'night-sepia'
}

export function getScoreFilterCss(
	mode: ScoreColorMode,
	brightness = SCORE_BRIGHTNESS_MAX,
	sepia?: number,
): string {
	const parts: string[] = []
	const sepiaPct = sepia !== undefined ? sanitizeScoreSepia(sepia) : getDefaultSepiaForMode(mode)
	if (isNightScoreMode(mode)) {
		parts.push(sepiaPct > 0 ? 'invert(0.9)' : 'invert(0.92)')
		if (sepiaPct > 0) {
			parts.push(`sepia(${(sepiaPct / 100).toFixed(2)})`)
		}
		parts.push('hue-rotate(180deg)')
	} else if (sepiaPct > 0) {
		parts.push(`sepia(${(sepiaPct / 100).toFixed(2)})`, 'contrast(0.95)')
	}
	const clamped = sanitizeScoreBrightness(brightness)
	if (clamped < SCORE_BRIGHTNESS_MAX) {
		parts.push(`brightness(${(clamped / 100).toFixed(2)})`)
	}
	return parts.join(' ')
}

export function getEffectiveStageBackground(backgroundColor: string, mode: ScoreColorMode, sepia = 0): string {
	if (backgroundColor) return backgroundColor
	if (isNightScoreMode(mode)) return '#0f0f10'
	if (mode === 'sepia' || sanitizeScoreSepia(sepia) > 0) return '#ede4d3'
	return ''
}

/**
 * In Night Mode, flip near-black / dark-gray annotation ink so black pencil,
 * text, and SMuFL stamps stay legible on dark inverted pages while colored
 * markings (red, blue, yellow, etc.) keep their original hues.
 */
export function adaptAnnotationColorForNight(
	rawColor: string | undefined,
	isNight: boolean,
	fallback = '#000000',
): string {
	const color = (rawColor || fallback).trim()
	if (!isNight) return color
	const lower = color.toLowerCase()
	let r: number
	let g: number
	let b: number
	if (lower === 'black') {
		r = 0; g = 0; b = 0
	} else if (HEX_COLOR_RE.test(lower)) {
		r = Number.parseInt(lower.slice(1, 3), 16)
		g = Number.parseInt(lower.slice(3, 5), 16)
		b = Number.parseInt(lower.slice(5, 7), 16)
	} else if (HEX_SHORT_RE.test(lower)) {
		r = Number.parseInt(lower[1] + lower[1], 16)
		g = Number.parseInt(lower[2] + lower[2], 16)
		b = Number.parseInt(lower[3] + lower[3], 16)
	} else {
		return color
	}
	const spread = Math.max(r, g, b) - Math.min(r, g, b)
	const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
	if (spread < 35 && luminance < 0.28) {
		const invR = Math.min(240, 255 - r)
		const invG = Math.min(240, 255 - g)
		const invB = Math.min(240, 255 - b)
		return `#${invR.toString(16).padStart(2, '0')}${invG.toString(16).padStart(2, '0')}${invB.toString(16).padStart(2, '0')}`
	}
	return color
}

export function sanitizeBackgroundColor(raw: unknown): string {
	if (typeof raw !== 'string') return ''
	const trimmed = raw.trim()
	return HEX_COLOR_RE.test(trimmed) ? trimmed.toLowerCase() : ''
}

export function validatePerformanceSettings(raw: unknown): PerformanceViewerSettings {
	const obj = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
	return {
		layoutChoice: VALID_LAYOUTS.has(obj.layoutChoice as PerformanceLayoutChoice)
			? (obj.layoutChoice as PerformanceLayoutChoice)
			: DEFAULT_PERFORMANCE_SETTINGS.layoutChoice,
		doublePageTurnMode: VALID_TURN_MODES.has(obj.doublePageTurnMode as DoublePageTurnMode)
			? (obj.doublePageTurnMode as DoublePageTurnMode)
			: DEFAULT_PERFORMANCE_SETTINGS.doublePageTurnMode,
		zoomChoice: VALID_ZOOMS.has(obj.zoomChoice as PerformanceZoomChoice)
			? (obj.zoomChoice as PerformanceZoomChoice)
			: DEFAULT_PERFORMANCE_SETTINGS.zoomChoice,
		showAnnotations: typeof obj.showAnnotations === 'boolean'
			? obj.showAnnotations
			: DEFAULT_PERFORMANCE_SETTINGS.showAnnotations,
		interleaveSongs: typeof obj.interleaveSongs === 'boolean'
			? obj.interleaveSongs
			: DEFAULT_PERFORMANCE_SETTINGS.interleaveSongs,
		backgroundColor: sanitizeBackgroundColor(obj.backgroundColor),
		tightCrop: typeof obj.tightCrop === 'boolean'
			? obj.tightCrop
			: DEFAULT_PERFORMANCE_SETTINGS.tightCrop,
		scoreColorMode: VALID_SCORE_COLOR_MODES.has(obj.scoreColorMode as ScoreColorMode)
			? (obj.scoreColorMode as ScoreColorMode)
			: DEFAULT_PERFORMANCE_SETTINGS.scoreColorMode,
		scoreBrightness: sanitizeScoreBrightness(obj.scoreBrightness),
		scoreSepia: typeof obj.scoreSepia === 'number'
			? sanitizeScoreSepia(obj.scoreSepia)
			: getDefaultSepiaForMode(
				VALID_SCORE_COLOR_MODES.has(obj.scoreColorMode as ScoreColorMode)
					? (obj.scoreColorMode as ScoreColorMode)
					: DEFAULT_PERFORMANCE_SETTINGS.scoreColorMode,
			),
	}
}

export function validateSetlistOverride(raw: unknown): SetlistPerformanceOverride {
	const obj = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
	return {
		...validatePerformanceSettings(obj),
		overrideEnabled: typeof obj.overrideEnabled === 'boolean' ? obj.overrideEnabled : false,
	}
}

export function createLibraryPerformanceStore(libraryId: number, storage?: StorageAdapter): SettingsStore<PerformanceViewerSettings> {
	return new SettingsStore<PerformanceViewerSettings>({
		storageKey: `sheetmusic:performance-viewer:library:${libraryId}`,
		defaultSettings: () => ({ ...DEFAULT_PERFORMANCE_SETTINGS }),
		validator: validatePerformanceSettings,
		storage,
	})
}

export function createSetlistPerformanceStore(
	libraryId: number,
	setlistId: number,
	storage?: StorageAdapter,
): SettingsStore<SetlistPerformanceOverride> {
	return new SettingsStore<SetlistPerformanceOverride>({
		storageKey: `sheetmusic:performance-viewer:setlist:${libraryId}:${setlistId}`,
		defaultSettings: () => ({
			...DEFAULT_PERFORMANCE_SETTINGS,
			overrideEnabled: false,
		}),
		validator: validateSetlistOverride,
		storage,
	})
}

export function loadEffectivePerformanceSettings(
	libraryId: number,
	setlistId?: number,
	storage?: StorageAdapter,
): { settings: PerformanceViewerSettings; overrideEnabled: boolean } {
	const libraryDefaults = createLibraryPerformanceStore(libraryId, storage).get()
	if (setlistId !== undefined && setlistId > 0) {
		const setlistOverride = createSetlistPerformanceStore(libraryId, setlistId, storage).get()
		if (setlistOverride.overrideEnabled) {
			return {
				settings: {
					layoutChoice: setlistOverride.layoutChoice,
					doublePageTurnMode: setlistOverride.doublePageTurnMode,
					zoomChoice: setlistOverride.zoomChoice,
					showAnnotations: setlistOverride.showAnnotations,
					interleaveSongs: setlistOverride.interleaveSongs,
					backgroundColor: setlistOverride.backgroundColor,
					tightCrop: setlistOverride.tightCrop,
					scoreColorMode: setlistOverride.scoreColorMode,
					scoreBrightness: setlistOverride.scoreBrightness,
					scoreSepia: setlistOverride.scoreSepia,
				},
				overrideEnabled: true,
			}
		}
	}
	return {
		settings: libraryDefaults,
		overrideEnabled: false,
	}
}
