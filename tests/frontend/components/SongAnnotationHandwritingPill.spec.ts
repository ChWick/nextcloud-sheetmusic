/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationHandwritingPill from '../../../src/components/song-viewer/SongAnnotationHandwritingPill.vue'
import type { HandwritingSwapState } from '../../../src/composables/useHandwritingInput'

function makeSwapState(count = 7): HandwritingSwapState {
	const syms = ['1', '2', '3', '4', '5', '♯', '♭'].slice(0, count)
	return {
		uuid: 'hw-1',
		x: 0.5,
		y: 0.5,
		initialSymbol: syms[0],
		activeSymbol: syms[0],
		candidates: syms.map((symbol, idx) => ({
			symbol,
			label: `Symbol ${symbol}`,
			score: 0.9 - idx * 0.08,
		})),
		rawStrokes: [[{ x: 0.5, y: 0.48 }, { x: 0.5, y: 0.52 }]],
		vbHeight: 1414,
	}
}

describe('SongAnnotationHandwritingPill.vue', () => {
	it('shows top 3 candidates, ellipsis revealing 4 more, keep-ink, and delete buttons', async () => {
		const wrapper = mount(SongAnnotationHandwritingPill, {
			props: { swapState: makeSwapState(7) },
		})

		// Primary row shows first 3 candidates + ellipsis + keep-ink + delete
		expect(wrapper.find('[data-test="handwriting-candidate-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-candidate-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-candidate-3"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-more"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-more-menu"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="handwriting-keep-ink"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-delete"]').exists()).toBe(true)

		// Clicking ellipsis reveals the next 4 candidates and extends timeout
		await wrapper.find('[data-test="handwriting-more"]').trigger('click')
		expect(wrapper.emitted('extend-timeout')).toHaveLength(1)
		expect(wrapper.find('[data-test="handwriting-more-menu"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-candidate-4"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-candidate-5"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-candidate-♯"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handwriting-candidate-♭"]').exists()).toBe(true)

		// Selecting an overflow candidate emits select
		await wrapper.find('[data-test="handwriting-candidate-5"]').trigger('click')
		expect(wrapper.emitted('select')).toEqual([['5']])

		// Keep as handwriting and delete buttons emit their events
		await wrapper.find('[data-test="handwriting-keep-ink"]').trigger('click')
		expect(wrapper.emitted('keep-ink')).toHaveLength(1)

		await wrapper.find('[data-test="handwriting-delete"]').trigger('click')
		expect(wrapper.emitted('delete')).toHaveLength(1)
	})
})
