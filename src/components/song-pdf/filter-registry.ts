import type {
	AdaptiveThresholdOp,
	CompressionOp,
	GaussianOp,
	GrayLevelMappingOp,
	ScanOperation,
} from './scan-types'

/**
 * Filter operation definition interface allowing generic extension of supported filters.
 */
export interface FilterDefinition<T extends ScanOperation = ScanOperation> {
	type: string
	label?: string
	createDefault: (id?: string) => T
	sanitize?: (raw: any) => T
}

let opCounter = 0
export function generateDefaultOpId(): string {
	return `op-${Date.now()}-${++opCounter}-${Math.random().toString(36).slice(2, 7)}`
}

const filterRegistry = new Map<string, FilterDefinition>()

export function registerFilterType<T extends ScanOperation = ScanOperation>(definition: FilterDefinition<T>): void {
	filterRegistry.set(definition.type, definition as FilterDefinition<ScanOperation>)
}

export function unregisterFilterType(type: string): void {
	filterRegistry.delete(type)
}

export function getFilterDefinition(type: string): FilterDefinition | undefined {
	return filterRegistry.get(type)
}

export function getRegisteredFilterTypes(): string[] {
	return Array.from(filterRegistry.keys())
}

export function clearFilterRegistry(): void {
	filterRegistry.clear()
	registerBuiltInFilters()
}

/**
 * Register built-in PDF enhancement filters.
 */
function registerBuiltInFilters(): void {
	registerFilterType<GaussianOp>({
		type: 'gaussian',
		label: 'Gaussian Filter',
		createDefault: (id = generateDefaultOpId()) => ({
			id,
			type: 'gaussian',
			size: 3,
			enabled: true,
		}),
		sanitize: (raw: any) => ({
			id: typeof raw.id === 'string' && raw.id ? raw.id : generateDefaultOpId(),
			type: 'gaussian',
			size: typeof raw.size === 'number' && raw.size >= 1 ? Math.round(raw.size) : 3,
			enabled: typeof raw.enabled === 'boolean' ? raw.enabled : true,
		}),
	})

	registerFilterType<GrayLevelMappingOp>({
		type: 'grayLevelMapping',
		label: 'Gray Level Mapping (Curves)',
		createDefault: (id = generateDefaultOpId()) => ({
			id,
			type: 'grayLevelMapping',
			lowerCutoff: 40,
			upperCutoff: 215,
			colorMode: 'grayscale',
			enabled: true,
		}),
		sanitize: (raw: any) => ({
			id: typeof raw.id === 'string' && raw.id ? raw.id : generateDefaultOpId(),
			type: 'grayLevelMapping',
			lowerCutoff: typeof raw.lowerCutoff === 'number' ? Math.max(0, Math.min(255, raw.lowerCutoff)) : 40,
			upperCutoff: typeof raw.upperCutoff === 'number' ? Math.max(0, Math.min(255, raw.upperCutoff)) : 215,
			colorMode: raw.colorMode === 'color' ? 'color' : 'grayscale',
			outMin: typeof raw.outMin === 'number' ? raw.outMin : 0,
			outMax: typeof raw.outMax === 'number' ? raw.outMax : 255,
			enabled: typeof raw.enabled === 'boolean' ? raw.enabled : true,
		}),
	})

	registerFilterType<AdaptiveThresholdOp>({
		type: 'adaptiveThreshold',
		label: 'Adaptive Thresholding',
		createDefault: (id = generateDefaultOpId()) => ({
			id,
			type: 'adaptiveThreshold',
			windowSize: 25,
			delta: 12,
			blackValue: 0,
			whiteValue: 255,
			softness: 15,
			enabled: true,
		}),
		sanitize: (raw: any) => ({
			id: typeof raw.id === 'string' && raw.id ? raw.id : generateDefaultOpId(),
			type: 'adaptiveThreshold',
			windowSize: typeof raw.windowSize === 'number' && raw.windowSize >= 3 ? Math.round(raw.windowSize) : 25,
			delta: typeof raw.delta === 'number' ? raw.delta : 12,
			blackValue: typeof raw.blackValue === 'number' ? raw.blackValue : 0,
			whiteValue: typeof raw.whiteValue === 'number' ? raw.whiteValue : 255,
			lowerLimit: typeof raw.lowerLimit === 'number' ? raw.lowerLimit : undefined,
			upperLimit: typeof raw.upperLimit === 'number' ? raw.upperLimit : undefined,
			colorMode: raw.colorMode === 'preserveColor' ? 'preserveColor' : 'binarize',
			softness: typeof raw.softness === 'number' ? Math.max(0, raw.softness) : 15,
			enabled: typeof raw.enabled === 'boolean' ? raw.enabled : true,
		}),
	})

	registerFilterType<CompressionOp>({
		type: 'compression',
		label: 'Image Compression',
		createDefault: (id = generateDefaultOpId()) => ({
			id,
			type: 'compression',
			format: 'jpeg',
			quality: 75,
			colorMode: 'grayscale',
			enabled: true,
		}),
		sanitize: (raw: any) => ({
			id: typeof raw.id === 'string' && raw.id ? raw.id : generateDefaultOpId(),
			type: 'compression',
			format: raw.format ?? 'jpeg',
			quality: typeof raw.quality === 'number' ? Math.max(10, Math.min(100, raw.quality)) : 75,
			colorMode: raw.colorMode === 'color' ? 'color' : 'grayscale',
			enabled: typeof raw.enabled === 'boolean' ? raw.enabled : true,
		}),
	})
}

// Initialize registry with built-in filters
registerBuiltInFilters()

/**
 * Safely sanitizes an array of scan operations, generating fresh unique IDs
 * if requested, validating built-in filters, and preserving custom extension filters.
 */
export function sanitizeFilterOperations(rawOps: unknown, generateId?: () => string): ScanOperation[] {
	if (!Array.isArray(rawOps)) {
		return []
	}
	const result: ScanOperation[] = []
	for (const raw of rawOps) {
		if (!raw || typeof raw !== 'object' || typeof raw.type !== 'string') {
			continue
		}
		const def = getFilterDefinition(raw.type)
		const id = generateId ? generateId() : (typeof raw.id === 'string' && raw.id ? raw.id : generateDefaultOpId())
		if (def?.sanitize) {
			result.push({ ...def.sanitize(raw), id })
		} else {
			// For generic/custom or extension filters: preserve all properties safely
			result.push({
				...raw,
				id,
				type: raw.type,
				enabled: typeof raw.enabled === 'boolean' ? raw.enabled : true,
			})
		}
	}
	return result
}
