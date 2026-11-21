/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { SettingsStore, type StorageAdapter } from './settings-store'

export type PressureMode = 'auto' | 'hardware' | 'velocity' | 'off'

export interface StylusSettings {
	/** Horizontal tip offset in CSS px applied to pen input (-40..40) */
	offsetX: number
	/** Vertical tip offset in CSS px applied to pen input (-40..40) */
	offsetY: number
	/** How stroke pressure is determined */
	pressureMode: PressureMode
	/** Strength of pressure-to-width modulation [0..1] */
	pressureSensitivity: number
	/** Minimum raw pressure mapped to 0 [0..0.85] */
	pressureMin: number
	/** Maximum raw pressure mapped to 1 [0.15..1] */
	pressureMax: number
	/** Response curve exponent [0.3..3.0] (<1 soft, 1 linear, >1 firm) */
	pressureGamma: number
	/** Only allow stylus (and mouse) to draw; touch scrolls/zooms */
	stylusOnlyDrawing: boolean
	/** Automatically enable palm rejection once pen input is seen */
	autoPalmRejection: boolean
	/** Allow stylus eraser end (button 5 / buttons & 32) to erase */
	hardwareEraser: boolean
}

export interface CalibrationSample {
	targetX: number
	targetY: number
	tapX: number
	tapY: number
}

export const STYLUS_STORAGE_KEY = 'sheetmusic:stylus-settings:v1'

export const DEFAULT_STYLUS_SETTINGS: StylusSettings = {
	offsetX: 0,
	offsetY: 0,
	pressureMode: 'auto',
	pressureSensitivity: 0.75,
	pressureMin: 0.05,
	pressureMax: 0.95,
	pressureGamma: 1.0,
	stylusOnlyDrawing: false,
	autoPalmRejection: true,
	hardwareEraser: true,
}

const VALID_PRESSURE_MODES = new Set<PressureMode>(['auto', 'hardware', 'velocity', 'off'])

function clamp(value: number, min: number, max: number): number {
	return Math.min(max, Math.max(min, value))
}

function roundToHalf(value: number): number {
	return Math.round(value * 2) / 2
}

function finiteNumber(val: unknown, fallback: number): number {
	return typeof val === 'number' && Number.isFinite(val) ? val : fallback
}

export function validateStylusSettings(raw: unknown): StylusSettings {
	const obj = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>

	const pressureMin = clamp(
		Math.round(finiteNumber(obj.pressureMin, DEFAULT_STYLUS_SETTINGS.pressureMin) * 100) / 100,
		0,
		0.85,
	)
	const rawMax = clamp(
		Math.round(finiteNumber(obj.pressureMax, DEFAULT_STYLUS_SETTINGS.pressureMax) * 100) / 100,
		0.15,
		1,
	)
	const pressureMax = Math.max(pressureMin + 0.1, rawMax)

	return {
		offsetX: roundToHalf(clamp(finiteNumber(obj.offsetX, DEFAULT_STYLUS_SETTINGS.offsetX), -40, 40)),
		offsetY: roundToHalf(clamp(finiteNumber(obj.offsetY, DEFAULT_STYLUS_SETTINGS.offsetY), -40, 40)),
		pressureMode: VALID_PRESSURE_MODES.has(obj.pressureMode as PressureMode)
			? (obj.pressureMode as PressureMode)
			: DEFAULT_STYLUS_SETTINGS.pressureMode,
		pressureSensitivity: clamp(
			Math.round(finiteNumber(obj.pressureSensitivity, DEFAULT_STYLUS_SETTINGS.pressureSensitivity) * 100) / 100,
			0,
			1,
		),
		pressureMin,
		pressureMax: clamp(Math.round(pressureMax * 100) / 100, 0.15, 1),
		pressureGamma: clamp(
			Math.round(finiteNumber(obj.pressureGamma, DEFAULT_STYLUS_SETTINGS.pressureGamma) * 100) / 100,
			0.3,
			3.0,
		),
		stylusOnlyDrawing: typeof obj.stylusOnlyDrawing === 'boolean'
			? obj.stylusOnlyDrawing
			: DEFAULT_STYLUS_SETTINGS.stylusOnlyDrawing,
		autoPalmRejection: typeof obj.autoPalmRejection === 'boolean'
			? obj.autoPalmRejection
			: DEFAULT_STYLUS_SETTINGS.autoPalmRejection,
		hardwareEraser: typeof obj.hardwareEraser === 'boolean'
			? obj.hardwareEraser
			: DEFAULT_STYLUS_SETTINGS.hardwareEraser,
	}
}

export function createStylusSettingsStore(storage?: StorageAdapter): SettingsStore<StylusSettings> {
	return new SettingsStore<StylusSettings>({
		storageKey: STYLUS_STORAGE_KEY,
		defaultSettings: () => ({ ...DEFAULT_STYLUS_SETTINGS }),
		validator: validateStylusSettings,
		storage,
	})
}

/**
 * Map a raw PointerEvent pressure [0..1] using calibrated min/max thresholds and gamma curve.
 */
export function mapRawPressure(rawPressure: number, settings: StylusSettings): number {
	if (!Number.isFinite(rawPressure)) return 0.5
	const min = settings.pressureMin
	const max = Math.max(min + 0.05, settings.pressureMax)
	const normalized = clamp((rawPressure - min) / (max - min), 0, 1)
	const curved = Math.pow(normalized, settings.pressureGamma)
	return Math.round(clamp(curved, 0, 1) * 1000) / 1000
}

/**
 * Apply pressureSensitivity to blend a normalized pressure [0..1] toward neutral 0.5.
 */
export function applyPressureSensitivity(pressure: number, sensitivity: number): number {
	const s = clamp(sensitivity, 0, 1)
	const effective = 0.5 + (clamp(pressure, 0, 1) - 0.5) * s
	return Math.round(clamp(effective, 0, 1) * 1000) / 1000
}

/**
 * Estimate simulated pressure [0..1] from pointer movement velocity.
 * Slower strokes produce higher pressure (thicker), faster strokes produce lower pressure (thinner).
 */
export function computeVelocityPressure(
	distPx: number,
	dtMs: number,
	prevPressure = 0.5,
): number {
	const safeDt = Math.max(1, dtMs)
	const speedPxPerMs = Math.max(0, distPx) / safeDt
	// Typical handwriting speed is 0.1..2.5 px/ms
	const normalizedSpeed = clamp(speedPxPerMs / 2.2, 0, 1)
	const target = 0.85 - normalizedSpeed * 0.65
	// Exponential moving average for smooth transitions
	const smoothed = prevPressure * 0.65 + target * 0.35
	return Math.round(clamp(smoothed, 0.15, 0.9) * 1000) / 1000
}

/**
 * Detect whether a PointerEvent reports real hardware pressure (vs constant synthetic 0 / 0.5 / 1).
 */
export function isVaryingHardwarePressureEvent(pointerType: string, pressure: number): boolean {
	if (pointerType !== 'pen') return false
	if (!Number.isFinite(pressure) || pressure <= 0) return false
	return Math.abs(pressure - 0.5) > 0.001 && Math.abs(pressure - 1) > 0.001
}

/**
 * Detect whether a PointerEvent comes from the hardware eraser end of a stylus.
 */
export function isStylusEraserEvent(e: Pick<PointerEvent, 'pointerType' | 'button' | 'buttons'>): boolean {
	if (e.pointerType !== 'pen') return false
	return e.button === 5 || (e.buttons & 32) !== 0
}

/**
 * Compute mean tip offset (in CSS px) from calibration target taps.
 * Offset is added to raw pen clientX/clientY so ink lands on the visual target.
 */
export function computeTipCalibrationOffset(samples: CalibrationSample[]): { offsetX: number; offsetY: number } {
	if (samples.length === 0) {
		return { offsetX: 0, offsetY: 0 }
	}
	let sumX = 0
	let sumY = 0
	for (const s of samples) {
		sumX += s.targetX - s.tapX
		sumY += s.targetY - s.tapY
	}
	return {
		offsetX: roundToHalf(clamp(sumX / samples.length, -40, 40)),
		offsetY: roundToHalf(clamp(sumY / samples.length, -40, 40)),
	}
}

/**
 * Compute calibrated pressureMin / pressureMax from recorded raw hardware pressure samples.
 */
export function computePressureCalibrationRange(
	rawSamples: number[],
): { pressureMin: number; pressureMax: number } | null {
	const valid = rawSamples.filter((v) => Number.isFinite(v) && v > 0 && v <= 1).sort((a, b) => a - b)
	if (valid.length < 4) return null
	const lowIdx = Math.floor(valid.length * 0.05)
	const highIdx = Math.min(valid.length - 1, Math.floor(valid.length * 0.95))
	const minVal = clamp(Math.round(valid[lowIdx] * 100) / 100, 0, 0.8)
	const maxVal = clamp(Math.round(valid[highIdx] * 100) / 100, minVal + 0.15, 1)
	if (maxVal - minVal < 0.1) return null
	return { pressureMin: minVal, pressureMax: maxVal }
}
