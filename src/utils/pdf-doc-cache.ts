/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { destroyPdfJsDocument } from './pdfjs'

/**
 * Schedules a low-priority task via `requestIdleCallback` with a `setTimeout`
 * fallback and returns a cancel handle.
 *
 * @param task Callback to execute when idle
 * @param options Scheduling options
 * @param options.idleTimeout Maximum idle wait in ms for `requestIdleCallback`
 * @param options.fallbackDelay Delay in ms when `requestIdleCallback` is unavailable
 */
export function scheduleIdleTask(
	task: () => void,
	options: { idleTimeout?: number; fallbackDelay?: number } = {},
): () => void {
	const { idleTimeout = 500, fallbackDelay = 120 } = options
	if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
		const handle = (window as any).requestIdleCallback(task, { timeout: idleTimeout })
		return () => (window as any).cancelIdleCallback?.(handle)
	}
	const handle = setTimeout(task, fallbackDelay)
	return () => clearTimeout(handle)
}

export interface PdfDocCache {
	has(path: string): boolean
	get(path: string): any | null
	set(path: string, doc: any, protectedPaths?: Iterable<string | undefined>): void
	destroyAll(extraDoc?: any): void
	size(): number
}

/**
 * Creates a small bounded LRU cache for parsed PDF.js document proxies that
 * destroys evicted documents (while never evicting active/protected paths) and
 * cleans up all retained documents on unmount.
 *
 * @param maxEntries Maximum number of parsed PDF documents retained simultaneously
 */
export function createPdfDocCache(maxEntries = 3): PdfDocCache {
	const cache = new Map<string, any>()
	let destroyed = false

	return {
		has(path: string): boolean {
			return cache.has(path)
		},

		get(path: string): any | null {
			const existing = cache.get(path)
			if (!existing) return null
			cache.delete(path)
			cache.set(path, existing)
			return existing
		},

		set(path: string, doc: any, protectedPaths?: Iterable<string | undefined>): void {
			if (!path || !doc) return
			if (destroyed) {
				void destroyPdfJsDocument(doc)
				return
			}
			const protectedSet = new Set<string>()
			if (protectedPaths) {
				for (const p of protectedPaths) {
					if (p) protectedSet.add(p)
				}
			}

			const prev = cache.get(path)
			if (prev && prev !== doc) {
				void destroyPdfJsDocument(prev)
			}
			cache.delete(path)
			cache.set(path, doc)

			while (cache.size > maxEntries) {
				let evictedKey: string | null = null
				for (const key of cache.keys()) {
					if (key !== path && !protectedSet.has(key)) {
						evictedKey = key
						break
					}
				}
				if (!evictedKey) break
				const evictedDoc = cache.get(evictedKey)
				cache.delete(evictedKey)
				if (evictedDoc && evictedDoc !== doc) {
					void destroyPdfJsDocument(evictedDoc)
				}
			}
		},

		destroyAll(extraDoc?: any): void {
			destroyed = true
			const seen = new Set<any>()
			for (const doc of cache.values()) {
				if (doc && !seen.has(doc)) {
					seen.add(doc)
					void destroyPdfJsDocument(doc)
				}
			}
			cache.clear()
			if (extraDoc && !seen.has(extraDoc)) {
				void destroyPdfJsDocument(extraDoc)
			}
		},

		size(): number {
			return cache.size
		},
	}
}
