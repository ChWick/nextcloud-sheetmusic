import type { DoublePageTurnMode } from './page-turn'
import { SettingsStore, type StorageAdapter } from './settings-store'

export type PerformanceLayoutChoice = 'auto' | 'single' | 'double' | 'continuous'
export type PerformanceZoomChoice = 'fit-page' | 'fit-width'

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
}

export interface SetlistPerformanceOverride extends PerformanceViewerSettings {
	overrideEnabled: boolean
}

export interface PerformanceBackgroundPreset {
	value: string
	label: string
	previewColor: string
}

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
}

const VALID_LAYOUTS = new Set<PerformanceLayoutChoice>(['auto', 'single', 'double', 'continuous'])
const VALID_TURN_MODES = new Set<DoublePageTurnMode>(['half', 'step', 'spread'])
const VALID_ZOOMS = new Set<PerformanceZoomChoice>(['fit-page', 'fit-width'])
const HEX_COLOR_RE = /^#[0-9a-f]{6}$/i

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
