import type { DoublePageTurnMode } from './page-turn'
import { SettingsStore, type StorageAdapter } from './settings-store'

export type PerformanceLayoutChoice = 'auto' | 'single' | 'double' | 'continuous'
export type PerformanceZoomChoice = 'fit-page' | 'fit-width'
export type ScoreColorMode = 'normal' | 'sepia' | 'night' | 'night-sepia'

export const SCORE_BRIGHTNESS_MIN = 40
export const SCORE_BRIGHTNESS_MAX = 100
export const SCORE_SEPIA_MIN = 0
export const SCORE_SEPIA_MAX = 100
export const AUTO_SCROLL_SPEED_MIN = 5
export const AUTO_SCROLL_SPEED_MAX = 200
export const AUTO_SCROLL_SPEED_DEFAULT = 35
export const AUTO_SCROLL_DELAY_MIN = 0
export const AUTO_SCROLL_DELAY_MAX = 30

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
	/** Preferred auto-scroll speed in px/second (5..200) */
	autoScrollSpeed: number
	/** Preferred auto-scroll start delay in seconds (0..30) */
	autoScrollDelay: number
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
	autoScrollSpeed: AUTO_SCROLL_SPEED_DEFAULT,
	autoScrollDelay: 0,
}

const VALID_LAYOUTS = new Set<PerformanceLayoutChoice>(['auto', 'single', 'double', 'continuous'])
const VALID_TURN_MODES = new Set<DoublePageTurnMode>(['half', 'step', 'spread'])
const VALID_ZOOMS = new Set<PerformanceZoomChoice>(['fit-page', 'fit-width'])
const VALID_SCORE_COLOR_MODES = new Set<ScoreColorMode>(['normal', 'sepia', 'night', 'night-sepia'])
const HEX_COLOR_RE = /^#[0-9a-f]{6}$/i
const HEX_SHORT_RE = /^#[0-9a-f]{3}$/i

function clampInt(raw: unknown, min: number, max: number, fallback: number): number {
	if (typeof raw !== 'number' || !Number.isFinite(raw)) {
		return fallback
	}
	return Math.min(max, Math.max(min, Math.round(raw)))
}

export function sanitizeScoreBrightness(raw: unknown): number {
	return clampInt(raw, SCORE_BRIGHTNESS_MIN, SCORE_BRIGHTNESS_MAX, SCORE_BRIGHTNESS_MAX)
}

export function sanitizeScoreSepia(raw: unknown): number {
	return clampInt(raw, SCORE_SEPIA_MIN, SCORE_SEPIA_MAX, SCORE_SEPIA_MIN)
}

export function sanitizeAutoScrollSpeed(raw: unknown): number {
	return clampInt(raw, AUTO_SCROLL_SPEED_MIN, AUTO_SCROLL_SPEED_MAX, AUTO_SCROLL_SPEED_DEFAULT)
}

export function sanitizeAutoScrollDelay(raw: unknown): number {
	return clampInt(raw, AUTO_SCROLL_DELAY_MIN, AUTO_SCROLL_DELAY_MAX, AUTO_SCROLL_DELAY_MIN)
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

function rgbToHsl(r: number, g: number, b: number): [number, number, number] {
	const rn = r / 255
	const gn = g / 255
	const bn = b / 255
	const max = Math.max(rn, gn, bn)
	const min = Math.min(rn, gn, bn)
	const l = (max + min) / 2
	if (max === min) return [0, 0, l]
	const d = max - min
	const s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
	let h = 0
	if (max === rn) h = ((gn - bn) / d + (gn < bn ? 6 : 0)) / 6
	else if (max === gn) h = ((bn - rn) / d + 2) / 6
	else h = ((rn - gn) / d + 4) / 6
	return [h, s, l]
}

function hslToRgb(h: number, s: number, l: number): [number, number, number] {
	if (s === 0) {
		const v = Math.round(l * 255)
		return [v, v, v]
	}
	const q = l < 0.5 ? l * (1 + s) : l + s - l * s
	const p = 2 * l - q
	const hue2rgb = (t: number) => {
		let tn = t
		if (tn < 0) tn += 1
		if (tn > 1) tn -= 1
		if (tn < 1 / 6) return p + (q - p) * 6 * tn
		if (tn < 1 / 2) return q
		if (tn < 2 / 3) return p + (q - p) * (2 / 3 - tn) * 6
		return p
	}
	return [
		Math.round(hue2rgb(h + 1 / 3) * 255),
		Math.round(hue2rgb(h) * 255),
		Math.round(hue2rgb(h - 1 / 3) * 255),
	]
}

/**
 * In Night Mode, preserve the color's hue & saturation while inverting HSL
 * lightness (mapped into [0.08..0.94] to match the #141414..#f0f0f0 dark stage).
 * Black (#000) becomes crisp off-white (#f0f0f0), white-out (#fff) becomes
 * dark stage (#141414), and dark red/blue/green become bright same-hue inks.
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
	} else if (lower === 'white') {
		r = 255; g = 255; b = 255
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
	const [h, s, l] = rgbToHsl(r, g, b)
	const invertedL = 0.08 + (1 - l) * 0.86
	const [nr, ng, nb] = hslToRgb(h, s, invertedL)
	return `#${nr.toString(16).padStart(2, '0')}${ng.toString(16).padStart(2, '0')}${nb.toString(16).padStart(2, '0')}`
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
		autoScrollSpeed: sanitizeAutoScrollSpeed(obj.autoScrollSpeed),
		autoScrollDelay: sanitizeAutoScrollDelay(obj.autoScrollDelay),
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
			const { overrideEnabled: _ignored, ...settings } = setlistOverride
			return {
				settings,
				overrideEnabled: true,
			}
		}
	}
	return {
		settings: libraryDefaults,
		overrideEnabled: false,
	}
}
