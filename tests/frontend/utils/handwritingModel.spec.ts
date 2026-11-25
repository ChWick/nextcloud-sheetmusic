/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/api/annotations', () => ({
	fetchHandwritingModel: vi.fn(),
	saveHandwritingModel: vi.fn(() => Promise.resolve(1)),
	deleteHandwritingModel: vi.fn(() => Promise.resolve()),
}))

vi.mock('../../../src/composables/useOnlineStatus', () => ({
	isEffectivelyOffline: vi.fn(() => false),
}))

import {
	deleteHandwritingModel,
	fetchHandwritingModel,
	saveHandwritingModel,
} from '../../../src/api/annotations'
import { isEffectivelyOffline } from '../../../src/composables/useOnlineStatus'
import {
	computeNormalizedCloudDistance,
	prepareCloud,
	SAMPLE_COUNT,
} from '../../../src/utils/handwritingCloud'
import {
	getLearnedSampleCount,
	getLearnedSamples,
	hydrateHandwritingModelFromServer,
	learnHandwritingSample,
	mergeSampleLists,
	resetLearnedHandwritingModel,
} from '../../../src/utils/handwritingModel'

function makeLineStroke(x0: number, y0: number, x1: number, y1: number, steps = 8) {
	return Array.from({ length: steps }, (_, i) => {
		const t = i / (steps - 1)
		return { x: x0 + (x1 - x0) * t, y: y0 + (y1 - y0) * t }
	})
}

describe('handwritingCloud & handwritingModel', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		localStorage.clear()
		vi.clearAllMocks()
		vi.mocked(isEffectivelyOffline).mockReturnValue(false)
		resetLearnedHandwritingModel()
	})

	afterEach(() => {
		resetLearnedHandwritingModel()
		vi.useRealTimers()
	})

	it('resamples strokes into a centered unit-scaled cloud with tangents', () => {
		const cloud = prepareCloud([makeLineStroke(10, 20, 10, 120)])
		expect(cloud.points).toHaveLength(SAMPLE_COUNT)
		expect(cloud.strokeCount).toBe(1)
		expect(cloud.aspectRatio).toBeLessThan(0.05)

		// Distance to itself should be ~0
		expect(computeNormalizedCloudDistance(cloud, cloud)).toBeLessThan(0.01)

		// Distance to an orthogonal stroke should be significantly larger
		const horizontal = prepareCloud([makeLineStroke(10, 20, 110, 20)])
		expect(computeNormalizedCloudDistance(cloud, horizontal)).toBeGreaterThan(0.25)
	})

	it('learns new samples, reinforces similar prototypes, and syncs after debounce', () => {
		const stroke = [makeLineStroke(0.2, 0.2, 0.2, 0.35)]
		learnHandwritingSample(stroke, 1414, '\uED11', { isCorrection: false })

		expect(getLearnedSampleCount()).toBe(1)
		expect(getLearnedSamples()[0].weight).toBe(1.0)

		// Learning a nearly identical stroke reinforces weight instead of duplicating
		learnHandwritingSample(stroke, 1414, '\uED11', { isCorrection: true })
		expect(getLearnedSampleCount()).toBe(1)
		expect(getLearnedSamples()[0].weight).toBe(1.25)

		// Debounced remote sync triggers after 1500ms
		expect(saveHandwritingModel).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1600)
		expect(saveHandwritingModel).toHaveBeenCalledTimes(1)
	})

	it('merges local and remote sample lists keeping newer updates and unique prototypes', () => {
		const c1 = prepareCloud([makeLineStroke(0, 0, 0, 100)])
		const c2 = prepareCloud([makeLineStroke(0, 0, 100, 0)])

		const remote = [{ symbol: '\uED11', ...c1, weight: 1.0, updatedAt: 100 }]
		const local = [
			{ symbol: '\uED11', ...c1, weight: 1.5, updatedAt: 200 },
			{ symbol: '\uE4A4', ...c2, weight: 1.0, updatedAt: 150 },
		]

		const { merged, localHadExtras } = mergeSampleLists(local, remote)
		expect(localHadExtras).toBe(true)
		expect(merged).toHaveLength(2)
		const mergedOne = merged.find((s) => s.symbol === '\uED11')
		expect(mergedOne?.weight).toBe(1.5)
	})

	it('hydrates from server and merges offline samples', async () => {
		const remoteCloud = prepareCloud([makeLineStroke(0, 0, 100, 100)])
		vi.mocked(fetchHandwritingModel).mockResolvedValueOnce([
			{ symbol: '\uE262', ...remoteCloud, weight: 1.5, updatedAt: 500 },
		])

		const count = await hydrateHandwritingModelFromServer(true)
		expect(count).toBe(1)
		expect(getLearnedSamples()[0].symbol).toBe('\uE262')
	})

	it('resets local cache and calls deleteHandwritingModel when syncRemote is true', () => {
		learnHandwritingSample([makeLineStroke(0.1, 0.1, 0.2, 0.2)], 1414, '\uE522')
		expect(getLearnedSampleCount()).toBe(1)

		resetLearnedHandwritingModel({ syncRemote: true })
		expect(getLearnedSampleCount()).toBe(0)
		expect(deleteHandwritingModel).toHaveBeenCalledTimes(1)
	})
})
