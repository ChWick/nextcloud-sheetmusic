/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { MemoryStorageAdapter } from '../../../src/utils/settings-store'
import {
	applyPressureSensitivity,
	computePressureCalibrationRange,
	computeTipCalibrationOffset,
	computeVelocityPressure,
	createStylusSettingsStore,
	DEFAULT_STYLUS_SETTINGS,
	isStylusEraserEvent,
	isVaryingHardwarePressureEvent,
	mapRawPressure,
	validateStylusSettings,
} from '../../../src/utils/stylus-settings'
import {
	generatePressureStrokePath,
	hasPressurePoints,
	translateAnnotation,
} from '../../../src/utils/annotation'
import type { SheetAnnotation } from '../../../src/types/annotation'

describe('stylus-settings utilities', () => {
	it('validates and clamps out-of-range settings', () => {
		const validated = validateStylusSettings({
			offsetX: 99,
			offsetY: -12.3,
			pressureMode: 'invalid',
			pressureSensitivity: 2.5,
			pressureMin: 0.8,
			pressureMax: 0.5,
			pressureGamma: 10,
			stylusOnlyDrawing: true,
			autoPalmRejection: false,
			hardwareEraser: false,
		})

		expect(validated.offsetX).toBe(40)
		expect(validated.offsetY).toBe(-12.5)
		expect(validated.pressureMode).toBe('auto')
		expect(validated.pressureSensitivity).toBe(1)
		expect(validated.pressureMin).toBe(0.8)
		expect(validated.pressureMax).toBeGreaterThanOrEqual(0.9)
		expect(validated.pressureGamma).toBe(3)
		expect(validated.stylusOnlyDrawing).toBe(true)
		expect(validated.autoPalmRejection).toBe(false)
		expect(validated.hardwareEraser).toBe(false)
	})

	it('persists and loads settings via SettingsStore', () => {
		const storage = new MemoryStorageAdapter()
		const store = createStylusSettingsStore(storage)
		expect(store.get()).toEqual(DEFAULT_STYLUS_SETTINGS)

		store.set({ offsetX: 3.5, offsetY: -2, stylusOnlyDrawing: true })
		const loaded = store.get()
		expect(loaded.offsetX).toBe(3.5)
		expect(loaded.offsetY).toBe(-2)
		expect(loaded.stylusOnlyDrawing).toBe(true)
	})

	it('maps raw pressure using min/max range and gamma curve', () => {
		const linear = { ...DEFAULT_STYLUS_SETTINGS, pressureMin: 0.1, pressureMax: 0.9, pressureGamma: 1 }
		expect(mapRawPressure(0.1, linear)).toBe(0)
		expect(mapRawPressure(0.5, linear)).toBe(0.5)
		expect(mapRawPressure(0.9, linear)).toBe(1)

		const soft = { ...linear, pressureGamma: 0.5 }
		const firm = { ...linear, pressureGamma: 2.0 }
		expect(mapRawPressure(0.5, soft)).toBeGreaterThan(0.5)
		expect(mapRawPressure(0.5, firm)).toBeLessThan(0.5)
	})

	it('scales pressure modulation with applyPressureSensitivity', () => {
		expect(applyPressureSensitivity(1, 0)).toBe(0.5)
		expect(applyPressureSensitivity(0, 0)).toBe(0.5)
		expect(applyPressureSensitivity(1, 1)).toBe(1)
		expect(applyPressureSensitivity(0, 1)).toBe(0)
		expect(applyPressureSensitivity(1, 0.5)).toBe(0.75)
	})

	it('computes higher simulated pressure for slow strokes than fast strokes', () => {
		const slow = computeVelocityPressure(2, 20, 0.5)
		const fast = computeVelocityPressure(80, 16, 0.5)
		expect(slow).toBeGreaterThan(fast)
	})

	it('detects varying hardware pressure and stylus eraser events', () => {
		expect(isVaryingHardwarePressureEvent('pen', 0.32)).toBe(true)
		expect(isVaryingHardwarePressureEvent('pen', 0.5)).toBe(false)
		expect(isVaryingHardwarePressureEvent('mouse', 0.32)).toBe(false)

		expect(isStylusEraserEvent({ pointerType: 'pen', button: 5, buttons: 32 })).toBe(true)
		expect(isStylusEraserEvent({ pointerType: 'pen', button: -1, buttons: 32 })).toBe(true)
		expect(isStylusEraserEvent({ pointerType: 'pen', button: 0, buttons: 1 })).toBe(false)
		expect(isStylusEraserEvent({ pointerType: 'mouse', button: 5, buttons: 32 })).toBe(false)
	})

	it('computes mean tip calibration offset and pressure range calibration', () => {
		const offset = computeTipCalibrationOffset([
			{ targetX: 100, targetY: 100, tapX: 96, tapY: 103 },
			{ targetX: 200, targetY: 200, tapX: 194, tapY: 203 },
		])
		expect(offset).toEqual({ offsetX: 5, offsetY: -3 })

		const range = computePressureCalibrationRange([0.08, 0.12, 0.3, 0.5, 0.72, 0.88, 0.92])
		expect(range).toEqual({ pressureMin: 0.08, pressureMax: 0.92 })
		expect(computePressureCalibrationRange([0.5, 0.5, 0.5])).toBeNull()
	})

	it('generates closed pressure stroke outline paths and preserves pressure on translate', () => {
		const ann: SheetAnnotation = {
			uuid: 'p-1',
			songId: 1,
			fileId: 1,
			pageNumber: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.3,
			height: 0.1,
			style: { stroke: '#e53935', strokeWidth: 4, lineStyle: 'solid' },
			data: {
				points: [
					{ x: 0.1, y: 0.1, p: 0.2 },
					{ x: 0.2, y: 0.15, p: 0.8 },
					{ x: 0.35, y: 0.12, p: 0.4 },
				],
			},
		}

		expect(hasPressurePoints(ann)).toBe(true)
		expect(hasPressurePoints({ ...ann, style: { ...ann.style, lineStyle: 'dashed' } })).toBe(false)

		const d = generatePressureStrokePath(ann.data.points!, 4, 1000)
		expect(d.startsWith('M ')).toBe(true)
		expect(d.endsWith(' Z')).toBe(true)
		expect(d).toContain(' A ')

		const dot = generatePressureStrokePath([{ x: 0.5, y: 0.5, p: 0.6 }], 4, 1000)
		expect(dot).toContain(' A ')
		expect(dot.endsWith(' Z')).toBe(true)

		const moved = translateAnnotation(ann, 0.05, -0.02)
		expect(moved.data.points?.[0].p).toBe(0.2)
		expect(moved.data.points?.[1].p).toBe(0.8)
	})
})
