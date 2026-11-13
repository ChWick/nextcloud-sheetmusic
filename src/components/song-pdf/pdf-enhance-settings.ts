import {
	LocalStorageAdapter,
	MemoryStorageAdapter,
	SettingsStore,
	type SettingsStoreOptions,
	type StorageAdapter,
} from '../../utils/settings-store'
import {
	clearFilterRegistry,
	generateDefaultOpId,
	getFilterDefinition,
	getRegisteredFilterTypes,
	registerFilterType,
	sanitizeFilterOperations,
	unregisterFilterType,
	type FilterDefinition,
} from './filter-registry'
import type { ScanOperation, ScanOpType } from './scan-types'

// Re-export storage and filter registry primitives for convenience
export {
	LocalStorageAdapter,
	MemoryStorageAdapter,
	SettingsStore,
	type SettingsStoreOptions,
	type StorageAdapter,
	clearFilterRegistry,
	generateDefaultOpId,
	getFilterDefinition,
	getRegisteredFilterTypes,
	registerFilterType,
	sanitizeFilterOperations,
	unregisterFilterType,
	type FilterDefinition,
}

/**
 * Schema for stored PDF enhancement dialog settings.
 * Extensible with custom fields, per-filter defaults, and custom settings bag.
 */
export interface PdfEnhanceDialogSettings {
	version: number
	operations: ScanOperation[]
	applyScope: 'all' | 'current'
	/**
	 * Extensible map of filter-specific defaults / last-used parameters.
	 * Keyed by filter type (e.g. 'gaussian', 'adaptiveThreshold', or custom filter types).
	 */
	filterDefaults?: Record<string, Record<string, any>>
	/**
	 * Extensible dictionary for custom dialog preferences.
	 */
	customSettings?: Record<string, any>
	[key: string]: any
}

export const PDF_ENHANCE_STORAGE_KEY = 'sheetmusic_pdf_enhance_settings'

export function createDefaultOperations(generateId?: () => string): ScanOperation[] {
	const gen = generateId ?? generateDefaultOpId
	const gaussianDef = getFilterDefinition('gaussian')
	const grayLevelDef = getFilterDefinition('grayLevelMapping')
	const thresholdDef = getFilterDefinition('adaptiveThreshold')

	return [
		gaussianDef
			? { ...gaussianDef.createDefault(gen()), size: 3 }
			: { id: gen(), type: 'gaussian', size: 3, enabled: true },
		grayLevelDef
			? { ...grayLevelDef.createDefault(gen()), lowerCutoff: 40, upperCutoff: 215, colorMode: 'grayscale' }
			: { id: gen(), type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 215, colorMode: 'grayscale', enabled: true },
		thresholdDef
			? { ...thresholdDef.createDefault(gen()), windowSize: 25, delta: 12, blackValue: 0, whiteValue: 255, softness: 15 }
			: { id: gen(), type: 'adaptiveThreshold', windowSize: 25, delta: 12, blackValue: 0, whiteValue: 255, softness: 15, enabled: true },
	]
}

export function getDefaultEnhanceSettings(): PdfEnhanceDialogSettings {
	return {
		version: 1,
		operations: createDefaultOperations(),
		applyScope: 'all',
		filterDefaults: {
			gaussian: { size: 3 },
			grayLevelMapping: { lowerCutoff: 40, upperCutoff: 215, colorMode: 'grayscale' },
			adaptiveThreshold: { windowSize: 25, delta: 12, blackValue: 0, whiteValue: 255, softness: 15 },
			compression: { format: 'jpeg', quality: 75, colorMode: 'grayscale' },
		},
		customSettings: {},
	}
}

export function validateEnhanceSettings(raw: unknown): PdfEnhanceDialogSettings {
	const defaults = getDefaultEnhanceSettings()
	if (!raw || typeof raw !== 'object') {
		return defaults
	}
	const obj = raw as Record<string, any>
	const operations = Array.isArray(obj.operations)
		? sanitizeFilterOperations(obj.operations)
		: defaults.operations
	const applyScope = obj.applyScope === 'current' || obj.applyScope === 'all'
		? obj.applyScope
		: defaults.applyScope
	const filterDefaults = obj.filterDefaults && typeof obj.filterDefaults === 'object'
		? { ...defaults.filterDefaults, ...obj.filterDefaults }
		: defaults.filterDefaults
	const customSettings = obj.customSettings && typeof obj.customSettings === 'object'
		? { ...obj.customSettings }
		: {}

	return {
		...obj,
		version: typeof obj.version === 'number' ? obj.version : defaults.version,
		operations,
		applyScope,
		filterDefaults,
		customSettings,
	}
}

let defaultStoreInstance: SettingsStore<PdfEnhanceDialogSettings> | null = null

export function getPdfEnhanceSettingsStore(storage?: StorageAdapter): SettingsStore<PdfEnhanceDialogSettings> {
	if (storage) {
		return new SettingsStore<PdfEnhanceDialogSettings>({
			storageKey: PDF_ENHANCE_STORAGE_KEY,
			defaultSettings: getDefaultEnhanceSettings,
			storage,
			validator: validateEnhanceSettings,
			version: 1,
		})
	}
	if (!defaultStoreInstance) {
		defaultStoreInstance = new SettingsStore<PdfEnhanceDialogSettings>({
			storageKey: PDF_ENHANCE_STORAGE_KEY,
			defaultSettings: getDefaultEnhanceSettings,
			validator: validateEnhanceSettings,
			version: 1,
		})
	}
	return defaultStoreInstance
}

export function resetDefaultPdfEnhanceSettingsStore(): void {
	defaultStoreInstance = null
}

export function loadPdfEnhanceSettings(storage?: StorageAdapter): PdfEnhanceDialogSettings {
	return getPdfEnhanceSettingsStore(storage).get()
}

export function savePdfEnhanceSettings(settings: Partial<PdfEnhanceDialogSettings>, storage?: StorageAdapter): void {
	getPdfEnhanceSettingsStore(storage).set(settings)
}

export function getLastUsedFilters(storage?: StorageAdapter, generateId?: () => string): ScanOperation[] {
	const settings = loadPdfEnhanceSettings(storage)
	return sanitizeFilterOperations(settings.operations, generateId)
}

export function getLastUsedScope(storage?: StorageAdapter): 'all' | 'current' {
	const settings = loadPdfEnhanceSettings(storage)
	return settings.applyScope === 'current' ? 'current' : 'all'
}

export function saveLastUsedEnhanceSettings(
	operations: ScanOperation[],
	applyScope: 'all' | 'current' = 'all',
	customSettings?: Record<string, any>,
	storage?: StorageAdapter,
): void {
	const store = getPdfEnhanceSettingsStore(storage)
	const current = store.get()

	// Update per-filter defaults from the applied operations
	const updatedFilterDefaults = { ...(current.filterDefaults ?? {}) }
	for (const op of operations) {
		if (op && op.type) {
			const { id, enabled, type, ...params } = op as any
			updatedFilterDefaults[type] = {
				...(updatedFilterDefaults[type] ?? {}),
				...params,
			}
		}
	}

	store.save({
		...current,
		operations: JSON.parse(JSON.stringify(operations)),
		applyScope,
		filterDefaults: updatedFilterDefaults,
		...(customSettings ? { customSettings: { ...(current.customSettings ?? {}), ...customSettings } } : {}),
	})
}

export function createFilterOperation(
	type: ScanOpType,
	overrides?: Record<string, any>,
	generateId?: () => string,
	storage?: StorageAdapter,
): ScanOperation {
	const id = generateId ? generateId() : generateDefaultOpId()
	let savedDefaults: Record<string, any> = {}
	try {
		const raw = (storage ?? new LocalStorageAdapter()).getItem(PDF_ENHANCE_STORAGE_KEY)
		if (raw) {
			const parsed = JSON.parse(raw)
			if (parsed?.filterDefaults?.[type]) {
				savedDefaults = parsed.filterDefaults[type]
			}
		}
	} catch {
		// Ignore parse errors
	}
	const def = getFilterDefinition(type)

	if (def) {
		const base = def.createDefault(id)
		return {
			...base,
			...savedDefaults,
			...overrides,
			id,
			type,
		}
	}

	// For custom/unregistered extension types:
	return {
		id,
		type,
		enabled: true,
		...savedDefaults,
		...overrides,
	} as ScanOperation
}

export function resetPdfEnhanceSettings(storage?: StorageAdapter): void {
	getPdfEnhanceSettingsStore(storage).reset()
}
