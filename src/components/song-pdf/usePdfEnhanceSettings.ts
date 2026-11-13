import type { StorageAdapter } from '../../utils/settings-store'
import {
	createFilterOperation,
	getLastUsedFilters,
	getLastUsedScope,
	saveLastUsedEnhanceSettings,
} from './pdf-enhance-settings'
import type { ScanOperation, ScanOpType } from './scan-types'

export interface UsePdfEnhanceSettingsOptions {
	storageAdapter?: StorageAdapter
}

/**
 * Composable for managing PDF enhancement dialog settings and last-used filters.
 */
export function usePdfEnhanceSettings(options?: UsePdfEnhanceSettingsOptions) {
	const storage = options?.storageAdapter

	function getInitialOperations(
		initialOperations?: ScanOperation[] | null,
		generateId?: () => string,
	): ScanOperation[] {
		if (Array.isArray(initialOperations)) {
			return JSON.parse(JSON.stringify(initialOperations))
		}
		return getLastUsedFilters(storage, generateId)
	}

	function getInitialScope(): 'all' | 'current' {
		return getLastUsedScope(storage)
	}

	function saveSettings(
		operations: ScanOperation[],
		scope: 'all' | 'current',
		customSettings?: Record<string, any>,
	): void {
		saveLastUsedEnhanceSettings(operations, scope, customSettings, storage)
	}

	function createOperation(
		type: ScanOpType,
		overrides?: Record<string, any>,
		generateId?: () => string,
	): ScanOperation {
		return createFilterOperation(type, overrides, generateId, storage)
	}

	return {
		getInitialOperations,
		getInitialScope,
		saveSettings,
		createOperation,
	}
}
