/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type StageAction =
	| 'none'
	| 'autoscroll-or-next-page'
	| 'next-page'
	| 'prev-page'
	| 'next-song'
	| 'prev-song'
	| 'toggle-hud'
	| 'toggle-metronome'
	| 'toggle-autoscroll'
	| 'toggle-night-mode'
	| 'toggle-annotations'

export type TapZoneLayout = 'left-right' | 'top-bottom' | 'right-wide' | 'disabled'

export type PedalSlotKey =
	| 'pedalNext'
	| 'pedalPrev'
	| 'pedalAux1'
	| 'pedalAux2'
	| 'keySpace'
	| 'keyEnter'

export interface StageInputMapping {
	tapZoneLayout: TapZoneLayout
	pedalNext: StageAction
	pedalPrev: StageAction
	pedalAux1: StageAction
	pedalAux2: StageAction
	keySpace: StageAction
	keyEnter: StageAction
}

export const DEFAULT_STAGE_INPUT_MAPPING: StageInputMapping = {
	tapZoneLayout: 'left-right',
	pedalNext: 'next-page',
	pedalPrev: 'prev-page',
	pedalAux1: 'none',
	pedalAux2: 'none',
	keySpace: 'autoscroll-or-next-page',
	keyEnter: 'next-page',
}

const VALID_ACTIONS = new Set<StageAction>([
	'none',
	'autoscroll-or-next-page',
	'next-page',
	'prev-page',
	'next-song',
	'prev-song',
	'toggle-hud',
	'toggle-metronome',
	'toggle-autoscroll',
	'toggle-night-mode',
	'toggle-annotations',
])

const VALID_TAP_LAYOUTS = new Set<TapZoneLayout>([
	'left-right',
	'top-bottom',
	'right-wide',
	'disabled',
])

const STORAGE_KEY = 'sheetmusic:stage-input-mapping:v1'

function sanitizeAction(raw: unknown, fallback: StageAction): StageAction {
	return typeof raw === 'string' && VALID_ACTIONS.has(raw as StageAction)
		? (raw as StageAction)
		: fallback
}

/**
 * Validate and normalize raw persisted stage input mapping.
 *
 * @param raw Unknown parsed JSON value
 */
export function validateStageInputMapping(raw: unknown): StageInputMapping {
	const obj = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
	return {
		tapZoneLayout: typeof obj.tapZoneLayout === 'string' && VALID_TAP_LAYOUTS.has(obj.tapZoneLayout as TapZoneLayout)
			? (obj.tapZoneLayout as TapZoneLayout)
			: DEFAULT_STAGE_INPUT_MAPPING.tapZoneLayout,
		pedalNext: sanitizeAction(obj.pedalNext, DEFAULT_STAGE_INPUT_MAPPING.pedalNext),
		pedalPrev: sanitizeAction(obj.pedalPrev, DEFAULT_STAGE_INPUT_MAPPING.pedalPrev),
		pedalAux1: sanitizeAction(obj.pedalAux1, DEFAULT_STAGE_INPUT_MAPPING.pedalAux1),
		pedalAux2: sanitizeAction(obj.pedalAux2, DEFAULT_STAGE_INPUT_MAPPING.pedalAux2),
		keySpace: sanitizeAction(obj.keySpace, DEFAULT_STAGE_INPUT_MAPPING.keySpace),
		keyEnter: sanitizeAction(obj.keyEnter, DEFAULT_STAGE_INPUT_MAPPING.keyEnter),
	}
}

/**
 * Load persisted stage input mapping from localStorage.
 */
export function loadStageInputMapping(): StageInputMapping {
	if (typeof window === 'undefined') return { ...DEFAULT_STAGE_INPUT_MAPPING }
	try {
		const raw = window.localStorage.getItem(STORAGE_KEY)
		if (!raw) return { ...DEFAULT_STAGE_INPUT_MAPPING }
		return validateStageInputMapping(JSON.parse(raw))
	} catch {
		return { ...DEFAULT_STAGE_INPUT_MAPPING }
	}
}

/**
 * Save stage input mapping to localStorage.
 *
 * @param mapping Validated stage input mapping
 */
export function saveStageInputMapping(mapping: StageInputMapping): void {
	if (typeof window === 'undefined') return
	try {
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify(validateStageInputMapping(mapping)))
	} catch {
		// Ignore storage quota errors
	}
}

/**
 * Resolve which stage action a tap at normalized `(relX, relY)` should trigger
 * for the active tap-zone layout.
 *
 * @param layout Active tap-zone layout
 * @param relX Normalized horizontal coordinate in [0..1]
 * @param relY Normalized vertical coordinate in [0..1]
 */
export function resolveTapZoneAction(
	layout: TapZoneLayout,
	relX: number,
	relY: number,
): StageAction {
	if (layout === 'disabled') {
		return 'toggle-hud'
	}
	if (layout === 'top-bottom') {
		if (relY < 0.25) return 'prev-page'
		if (relY > 0.75) return 'next-page'
		return 'toggle-hud'
	}
	if (layout === 'right-wide') {
		if (relX < 0.15) return 'prev-page'
		if (relX > 0.5) return 'next-page'
		return 'toggle-hud'
	}
	// Default: left-right (25% / 50% / 25%)
	if (relX < 0.25) return 'prev-page'
	if (relX > 0.75) return 'next-page'
	return 'toggle-hud'
}

/**
 * Resolve which stage action a keyboard / Bluetooth pedal key should trigger.
 * Returns `null` when the key is not bound to any pedal/controller slot.
 *
 * @param key KeyboardEvent.key value
 * @param mapping Active stage input mapping
 */
export function resolveKeyAction(
	key: string,
	mapping: StageInputMapping,
): StageAction | null {
	switch (key) {
	case 'ArrowRight':
	case 'PageDown':
		return mapping.pedalNext
	case 'ArrowLeft':
	case 'PageUp':
	case 'Backspace':
		return mapping.pedalPrev
	case 'ArrowUp':
		return mapping.pedalAux1
	case 'ArrowDown':
		return mapping.pedalAux2
	case ' ':
		return mapping.keySpace
	case 'Enter':
		return mapping.keyEnter
	default:
		return null
	}
}
