/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'
import { LocalStorageAdapter } from '../utils/settings-store'
import {
	createStylusSettingsStore,
	DEFAULT_STYLUS_SETTINGS,
	validateStylusSettings,
	type StylusSettings,
} from '../utils/stylus-settings'

const PEN_DETECTED_STORAGE_KEY = 'sheetmusic:stylus-pen-detected:v1'
const penFlagStorage = new LocalStorageAdapter()
const store = createStylusSettingsStore()
const sharedSettings = ref<StylusSettings>(store.get())
const penDetected = ref(penFlagStorage.getItem(PEN_DETECTED_STORAGE_KEY) === '1')
let lastPenActivityTime = 0

export function useStylusSettings() {
	function updateSettings(patch: Partial<StylusSettings>) {
		const next = validateStylusSettings({ ...sharedSettings.value, ...patch })
		sharedSettings.value = next
		store.save(next)
	}

	function resetSettings() {
		const defaults = { ...DEFAULT_STYLUS_SETTINGS }
		sharedSettings.value = defaults
		store.reset()
		penDetected.value = false
		penFlagStorage.removeItem(PEN_DETECTED_STORAGE_KEY)
	}

	function markPenDetected() {
		lastPenActivityTime = Date.now()
		if (!penDetected.value) {
			penDetected.value = true
			penFlagStorage.setItem(PEN_DETECTED_STORAGE_KEY, '1')
		}
	}

	function isPenRecentlyActive(cooldownMs = 500): boolean {
		return lastPenActivityTime > 0 && Date.now() - lastPenActivityTime < cooldownMs
	}

	const isTouchDrawingBlocked = computed(() =>
		sharedSettings.value.stylusOnlyDrawing
		|| (sharedSettings.value.autoPalmRejection && penDetected.value),
	)

	const hasCustomCalibration = computed(() =>
		sharedSettings.value.offsetX !== 0
		|| sharedSettings.value.offsetY !== 0
		|| sharedSettings.value.stylusOnlyDrawing
		|| sharedSettings.value.lockTouchZoomWhileAnnotating,
	)

	return {
		settings: sharedSettings,
		penDetected,
		isTouchDrawingBlocked,
		hasCustomCalibration,
		updateSettings,
		resetSettings,
		markPenDetected,
		isPenRecentlyActive,
	}
}

/**
 * Reset shared reactive state (for unit tests).
 */
export function resetStylusSettingsStateForTests() {
	store.reset()
	penFlagStorage.removeItem(PEN_DETECTED_STORAGE_KEY)
	sharedSettings.value = { ...DEFAULT_STYLUS_SETTINGS }
	penDetected.value = false
	lastPenActivityTime = 0
}
