import { beforeEach, describe, expect, it } from 'vitest'
import { usePdfEnhanceSettings } from '../../../src/components/song-pdf/usePdfEnhanceSettings'
import {
	clearFilterRegistry,
	createDefaultOperations,
	createFilterOperation,
	generateDefaultOpId,
	getFilterDefinition,
	getLastUsedFilters,
	getLastUsedScope,
	getPdfEnhanceSettingsStore,
	getRegisteredFilterTypes,
	loadPdfEnhanceSettings,
	LocalStorageAdapter,
	MemoryStorageAdapter,
	PDF_ENHANCE_STORAGE_KEY,
	registerFilterType,
	resetDefaultPdfEnhanceSettingsStore,
	resetPdfEnhanceSettings,
	sanitizeFilterOperations,
	saveLastUsedEnhanceSettings,
	savePdfEnhanceSettings,
	SettingsStore,
	unregisterFilterType,
	validateEnhanceSettings,
	type FilterDefinition,
	type PdfEnhanceDialogSettings,
} from '../../../src/components/song-pdf/pdf-enhance-settings'
import type { BaseScanOp, ScanOperation } from '../../../src/components/song-pdf/scan-types'

describe('pdf-enhance-settings.ts', () => {
	let memoryStorage: MemoryStorageAdapter

	beforeEach(() => {
		memoryStorage = new MemoryStorageAdapter()
		resetDefaultPdfEnhanceSettingsStore()
		clearFilterRegistry()
	})

	describe('Generic StorageAdapters and SettingsStore', () => {
		it('stores and retrieves items using MemoryStorageAdapter', () => {
			memoryStorage.setItem('test_key', 'test_value')
			expect(memoryStorage.getItem('test_key')).toBe('test_value')
			expect(memoryStorage.getItem('nonexistent')).toBeNull()

			memoryStorage.removeItem('test_key')
			expect(memoryStorage.getItem('test_key')).toBeNull()
		})

		it('supports generic SettingsStore with defaults, updates, and resets', () => {
			interface MyCustomSettings {
				contrast: number
				mode: 'dark' | 'light'
			}

			const defaultSettings: MyCustomSettings = {
				contrast: 50,
				mode: 'light',
			}

			const store = new SettingsStore<MyCustomSettings>({
				storageKey: 'custom_settings_test',
				defaultSettings,
				storage: memoryStorage,
			})

			// Returns defaults initially
			expect(store.get()).toEqual({ contrast: 50, mode: 'light' })

			// Partial update with set()
			store.set({ contrast: 75 })
			expect(store.get()).toEqual({ contrast: 75, mode: 'light' })

			// Setter and getter
			store.setSetting('mode', 'dark')
			expect(store.getSetting('mode')).toBe('dark')

			// Full save
			store.save({ contrast: 100, mode: 'light' })
			expect(store.get()).toEqual({ contrast: 100, mode: 'light' })

			// Functional update
			store.update((curr) => ({ ...curr, contrast: curr.contrast + 10 }))
			expect(store.get().contrast).toBe(110)

			// Reset back to defaults
			store.reset()
			expect(store.get()).toEqual({ contrast: 50, mode: 'light' })
		})

		it('handles corrupted JSON in storage by gracefully falling back to defaults', () => {
			memoryStorage.setItem('corrupted_key', '{not valid json...}')

			const store = new SettingsStore<{ count: number }>({
				storageKey: 'corrupted_key',
				defaultSettings: { count: 42 },
				storage: memoryStorage,
			})

			expect(store.get()).toEqual({ count: 42 })
		})

		it('supports schema migrations in generic SettingsStore', () => {
			memoryStorage.setItem('versioned_key', JSON.stringify({ version: 1, oldField: 'hello' }))

			const store = new SettingsStore<{ version: number; newField: string }>({
				storageKey: 'versioned_key',
				defaultSettings: { version: 2, newField: 'default' },
				storage: memoryStorage,
				version: 2,
				migrate: (savedVer, raw) => ({
					version: 2,
					newField: `migrated-${raw.oldField}`,
				}),
			})

			expect(store.get()).toEqual({ version: 2, newField: 'migrated-hello' })
		})
	})

	describe('Extensible Filter Registry', () => {
		it('registers built-in filters by default', () => {
			const types = getRegisteredFilterTypes()
			expect(types).toContain('gaussian')
			expect(types).toContain('grayLevelMapping')
			expect(types).toContain('adaptiveThreshold')
			expect(types).toContain('compression')
		})

		it('allows registering and unregistering custom filter extensions', () => {
			interface DeskewOp extends BaseScanOp {
				type: 'deskew'
				angleThreshold: number
			}

			const deskewDef: FilterDefinition<DeskewOp> = {
				type: 'deskew',
				label: 'Automatic Deskew',
				createDefault: (id = generateDefaultOpId()) => ({
					id,
					type: 'deskew',
					angleThreshold: 0.5,
					enabled: true,
				}),
				sanitize: (raw) => ({
					id: raw.id ?? generateDefaultOpId(),
					type: 'deskew',
					angleThreshold: typeof raw.angleThreshold === 'number' ? raw.angleThreshold : 0.5,
					enabled: typeof raw.enabled === 'boolean' ? raw.enabled : true,
				}),
			}

			registerFilterType(deskewDef)
			expect(getRegisteredFilterTypes()).toContain('deskew')
			expect(getFilterDefinition('deskew')?.label).toBe('Automatic Deskew')

			const op = createFilterOperation('deskew', { angleThreshold: 1.2 }) as DeskewOp
			expect(op.type).toBe('deskew')
			expect(op.angleThreshold).toBe(1.2)
			expect(op.enabled).toBe(true)

			unregisterFilterType('deskew')
			expect(getRegisteredFilterTypes()).not.toContain('deskew')
		})

		it('sanitizes and preserves custom/unregistered operations without losing attributes', () => {
			const customOp = {
				id: 'custom-123',
				type: 'unsharpMask',
				sigma: 1.5,
				amount: 2.0,
				enabled: true,
			}

			const sanitized = sanitizeFilterOperations([customOp])
			expect(sanitized).toHaveLength(1)
			expect(sanitized[0].type).toBe('unsharpMask')
			expect((sanitized[0] as any).sigma).toBe(1.5)
			expect((sanitized[0] as any).amount).toBe(2.0)
		})
	})

	describe('PDF Enhancement Dialog Settings Persistence', () => {
		it('loads default settings when storage is empty', () => {
			const settings = loadPdfEnhanceSettings(memoryStorage)
			expect(settings.version).toBe(1)
			expect(settings.applyScope).toBe('all')
			expect(settings.operations.length).toBe(3)
			expect(settings.operations[0].type).toBe('gaussian')
			expect(settings.operations[1].type).toBe('grayLevelMapping')
			expect(settings.operations[2].type).toBe('adaptiveThreshold')
		})

		it('saves and restores last used filters and apply scope', () => {
			const customOps: ScanOperation[] = [
				createFilterOperation('gaussian', { size: 5 }, undefined, memoryStorage),
				createFilterOperation('compression', { format: 'grayscale-8bit' }, undefined, memoryStorage),
			]

			saveLastUsedEnhanceSettings(customOps, 'current', { customColorBoost: true }, memoryStorage)

			const restoredOps = getLastUsedFilters(memoryStorage)
			expect(restoredOps).toHaveLength(2)
			expect(restoredOps[0].type).toBe('gaussian')
			expect((restoredOps[0] as any).size).toBe(5)
			expect(restoredOps[1].type).toBe('compression')
			expect((restoredOps[1] as any).format).toBe('grayscale-8bit')

			const restoredScope = getLastUsedScope(memoryStorage)
			expect(restoredScope).toBe('current')

			const settings = loadPdfEnhanceSettings(memoryStorage)
			expect(settings.customSettings?.customColorBoost).toBe(true)
		})

		it('persists per-filter parameter defaults from applied operations', () => {
			// Apply a Gaussian filter with kernel size 7
			const ops: ScanOperation[] = [
				createFilterOperation('gaussian', { size: 7 }, undefined, memoryStorage),
				createFilterOperation('adaptiveThreshold', { windowSize: 35, delta: 18 }, undefined, memoryStorage),
			]

			saveLastUsedEnhanceSettings(ops, 'all', undefined, memoryStorage)

			// When a new Gaussian filter is added, it re-uses the last-used parameters (size 7)
			const newGaussian = createFilterOperation('gaussian', undefined, undefined, memoryStorage)
			expect((newGaussian as any).size).toBe(7)

			// When a new Adaptive Threshold filter is added, it re-uses windowSize 35, delta 18
			const newThreshold = createFilterOperation('adaptiveThreshold', undefined, undefined, memoryStorage)
			expect((newThreshold as any).windowSize).toBe(35)
			expect((newThreshold as any).delta).toBe(18)
		})

		it('supports resetting settings back to defaults', () => {
			const customOps: ScanOperation[] = [
				createFilterOperation('compression', { format: 'bw-1bit' }, undefined, memoryStorage),
			]
			saveLastUsedEnhanceSettings(customOps, 'current', undefined, memoryStorage)
			expect(getLastUsedScope(memoryStorage)).toBe('current')

			resetPdfEnhanceSettings(memoryStorage)
			expect(getLastUsedScope(memoryStorage)).toBe('all')
			expect(getLastUsedFilters(memoryStorage)).toHaveLength(3)
		})
	})

	describe('usePdfEnhanceSettings composable', () => {
		it('provides clean reactive helpers to load, save, and create operations', () => {
			const {
				getInitialOperations,
				getInitialScope,
				saveSettings,
				createOperation,
			} = usePdfEnhanceSettings({ storageAdapter: memoryStorage })

			// Initially returns default scope 'all'
			expect(getInitialScope()).toBe('all')

			// Returns initial operations if given
			const customInitial = [createOperation('gaussian', { size: 5 })]
			expect(getInitialOperations(customInitial)).toEqual(customInitial)

			// Falls back to last used filters when null/undefined
			const defaultOps = getInitialOperations(null)
			expect(defaultOps).toHaveLength(3)

			// Saving settings updates the store
			const newOps: ScanOperation[] = [createOperation('compression', { quality: 80 })]
			saveSettings(newOps, 'current')

			expect(getInitialScope()).toBe('current')
			const reloadedOps = getInitialOperations(null)
			expect(reloadedOps).toHaveLength(1)
			expect(reloadedOps[0].type).toBe('compression')
		})
	})
})
