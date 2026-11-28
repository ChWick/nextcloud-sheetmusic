/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { createPdfDocCache, scheduleIdleTask } from '../../../src/utils/pdf-doc-cache'

function makeMockDoc(name: string) {
	return { name, destroy: vi.fn().mockResolvedValue(undefined) }
}

describe('pdf-doc-cache', () => {
	it('maintains LRU order, evicts oldest unprotected doc, and destroys evicted docs', () => {
		const cache = createPdfDocCache(2)
		const docA = makeMockDoc('A')
		const docB = makeMockDoc('B')
		const docC = makeMockDoc('C')

		cache.set('/a.pdf', docA)
		cache.set('/b.pdf', docB)
		expect(cache.size()).toBe(2)

		// Touch A so B becomes least recently used
		expect(cache.get('/a.pdf')).toBe(docA)

		// Adding C evicts B (not A)
		cache.set('/c.pdf', docC)
		expect(cache.has('/a.pdf')).toBe(true)
		expect(cache.has('/b.pdf')).toBe(false)
		expect(cache.has('/c.pdf')).toBe(true)
		expect(docB.destroy).toHaveBeenCalledTimes(1)
		expect(docA.destroy).not.toHaveBeenCalled()
	})

	it('respects protectedPaths during eviction and destroys all retained docs on destroyAll', () => {
		const cache = createPdfDocCache(2)
		const docA = makeMockDoc('A')
		const docB = makeMockDoc('B')
		const docC = makeMockDoc('C')
		const extra = makeMockDoc('extra')

		cache.set('/a.pdf', docA)
		cache.set('/b.pdf', docB)

		// Protect /a.pdf so /b.pdf is evicted instead of /a.pdf
		cache.set('/c.pdf', docC, ['/a.pdf'])
		expect(cache.has('/a.pdf')).toBe(true)
		expect(cache.has('/b.pdf')).toBe(false)
		expect(docB.destroy).toHaveBeenCalledTimes(1)

		cache.destroyAll(extra)
		expect(cache.size()).toBe(0)
		expect(docA.destroy).toHaveBeenCalledTimes(1)
		expect(docC.destroy).toHaveBeenCalledTimes(1)
		expect(extra.destroy).toHaveBeenCalledTimes(1)

		// Inserting after destroyAll immediately destroys the late-arriving doc
		const lateDoc = makeMockDoc('late')
		cache.set('/late.pdf', lateDoc)
		expect(cache.size()).toBe(0)
		expect(lateDoc.destroy).toHaveBeenCalledTimes(1)
	})

	it('schedules and cancels idle tasks cleanly', () => {
		vi.useFakeTimers()
		const fn = vi.fn()
		const cancel = scheduleIdleTask(fn, { fallbackDelay: 50 })
		cancel()
		vi.advanceTimersByTime(200)
		expect(fn).not.toHaveBeenCalled()

		const fn2 = vi.fn()
		scheduleIdleTask(fn2, { fallbackDelay: 50 })
		vi.advanceTimersByTime(60)
		expect(fn2).toHaveBeenCalledTimes(1)
		vi.useRealTimers()
	})
})
