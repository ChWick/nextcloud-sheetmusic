/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { usePdfResizeObserver } from '../../../src/composables/usePdfResizeObserver'

describe('usePdfResizeObserver', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('debounces resize rerenders and skips when dimensions did not change', () => {
		const onRerender = vi.fn()
		let width = 800
		let height = 600

		const Comp = defineComponent({
			setup() {
				const containerRef = ref<HTMLElement>()
				const api = usePdfResizeObserver({
					containerRef,
					shouldRerenderOnResize: () => true,
					canRerender: () => true,
					onRerender,
					debounceMs: 100,
				})
				return { containerRef, ...api }
			},
			template: '<div ref="containerRef" />',
		})

		const wrapper = mount(Comp)
		const el = wrapper.element as HTMLElement
		Object.defineProperty(el, 'clientWidth', { get: () => width })
		Object.defineProperty(el, 'clientHeight', { get: () => height })

		wrapper.vm.syncObservedSize()

		// No size change -> skipped
		wrapper.vm.scheduleRerender(false)
		vi.advanceTimersByTime(100)
		expect(onRerender).not.toHaveBeenCalled()

		// Size changed -> rerenders once after debounce
		width = 1000
		wrapper.vm.scheduleRerender(false)
		wrapper.vm.scheduleRerender(false)
		vi.advanceTimersByTime(100)
		expect(onRerender).toHaveBeenCalledTimes(1)

		// Forced rerender (e.g. DPR change) fires even without dimension change
		wrapper.vm.scheduleRerender(true)
		vi.advanceTimersByTime(100)
		expect(onRerender).toHaveBeenCalledTimes(2)

		wrapper.unmount()
	})

	it('ignores non-forced resize when shouldRerenderOnResize is false', () => {
		const onRerender = vi.fn()
		const Comp = defineComponent({
			setup() {
				const containerRef = ref<HTMLElement>()
				return usePdfResizeObserver({
					containerRef,
					shouldRerenderOnResize: () => false,
					canRerender: () => true,
					onRerender,
					debounceMs: 50,
				})
			},
			template: '<div />',
		})

		const wrapper = mount(Comp)
		wrapper.vm.scheduleRerender(false)
		vi.advanceTimersByTime(50)
		expect(onRerender).not.toHaveBeenCalled()

		wrapper.vm.scheduleRerender(true)
		vi.advanceTimersByTime(50)
		expect(onRerender).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})
})
