/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { observeContinuousPdfPages } from '../../../src/utils/pdf-continuous-renderer'

type IoCallback = (entries: Array<{ target: Element; isIntersecting: boolean }>) => void

describe('observeContinuousPdfPages', () => {
	let ioCallback: IoCallback | null = null
	const observeSpy = vi.fn()
	const disconnectSpy = vi.fn()
	const originalIO = globalThis.IntersectionObserver

	beforeEach(() => {
		ioCallback = null
		observeSpy.mockReset()
		disconnectSpy.mockReset()
		;(globalThis as any).IntersectionObserver = class MockIO {
			constructor(cb: IoCallback) {
				ioCallback = cb
			}
			observe = observeSpy
			disconnect = disconnectSpy
		}
	})

	afterEach(() => {
		globalThis.IntersectionObserver = originalIO
	})

	function makePlaceholder(pageNum: number): HTMLElement {
		const el = document.createElement('div')
		el.dataset.pageNum = String(pageNum)
		el.dataset.pageWidth = '400'
		el.dataset.pageHeight = '600'
		return el
	}

	it('limits concurrent renders and cancels active tasks when scrolled out of view', async () => {
		const root = document.createElement('div')
		const placeholders = [1, 2, 3].map(makePlaceholder)
		const renderedPlaceholders = new Set<HTMLElement>()
		const resolvers: Array<() => void> = []
		const cancelSpy = vi.fn()

		const renderPage = vi.fn((_pageNum: number, el: HTMLElement, onTask) => {
			onTask({ cancel: cancelSpy })
			return new Promise<void>((resolve) => {
				resolvers.push(() => {
					renderedPlaceholders.add(el)
					onTask(null)
					resolve()
				})
			})
		})

		observeContinuousPdfPages({
			root,
			placeholders,
			preRenderedPlaceholders: [],
			renderedPlaceholders,
			isStale: () => false,
			renderPage,
			onEvict: vi.fn(),
			maxConcurrentRenders: 1,
		})

		expect(observeSpy).toHaveBeenCalledTimes(3)

		// All 3 become visible; only 1 should start due to concurrency limit
		ioCallback?.(placeholders.map((target) => ({ target, isIntersecting: true })))
		expect(renderPage).toHaveBeenCalledTimes(1)

		// Scroll page 1 out of view -> active task is cancelled
		ioCallback?.([{ target: placeholders[0], isIntersecting: false }])
		expect(cancelSpy).toHaveBeenCalledTimes(1)

		// Finish page 1 promise -> page 2 should start next
		resolvers[0]()
		await Promise.resolve()
		await Promise.resolve()
		expect(renderPage).toHaveBeenCalledTimes(2)
		expect(renderPage).toHaveBeenLastCalledWith(2, placeholders[1], expect.any(Function))
	})

	it('evicts oldest offscreen rendered pages beyond maxRenderedPages and restores dimensions', async () => {
		const root = document.createElement('div')
		const placeholders = [1, 2, 3].map(makePlaceholder)
		const child = document.createElement('canvas')
		placeholders[0].appendChild(child)
		const renderedPlaceholders = new Set<HTMLElement>([placeholders[0], placeholders[1]])
		const onEvict = vi.fn()

		const renderPage = vi.fn(async (_pageNum: number, el: HTMLElement) => {
			renderedPlaceholders.add(el)
		})

		observeContinuousPdfPages({
			root,
			placeholders,
			preRenderedPlaceholders: [placeholders[0], placeholders[1]],
			renderedPlaceholders,
			isStale: () => false,
			renderPage,
			onEvict,
			maxRenderedPages: 2,
		})

		// Page 3 enters view and renders -> exceeds maxRenderedPages=2, page 1 is offscreen & oldest
		ioCallback?.([{ target: placeholders[2], isIntersecting: true }])
		await Promise.resolve()
		await Promise.resolve()

		expect(onEvict).toHaveBeenCalledWith(placeholders[0])
		expect(renderedPlaceholders.has(placeholders[0])).toBe(false)
		expect(placeholders[0].style.minHeight).toBe('600px')
		expect(placeholders[0].style.minWidth).toBe('400px')
		expect(placeholders[0].children.length).toBe(0)
	})
})
