/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface ContinuousRenderTask {
	cancel?: () => void
}

export interface ObserveContinuousPdfPagesOptions {
	root: HTMLElement
	placeholders: HTMLElement[]
	preRenderedPlaceholders: Iterable<HTMLElement>
	renderedPlaceholders: Set<HTMLElement>
	isStale: () => boolean
	renderPage: (
		pageNum: number,
		placeholder: HTMLElement,
		onRenderTask: (task: ContinuousRenderTask | null) => void,
	) => Promise<void>
	onEvict: (placeholder: HTMLElement) => void
	maxRenderedPages?: number
	maxConcurrentRenders?: number
	rootMargin?: string
}

export const DEFAULT_MAX_CONTINUOUS_RENDERED_PAGES = 16
export const DEFAULT_MAX_CONCURRENT_CONTINUOUS_RENDERS = 3

/**
 * Observes continuous-mode PDF page placeholders with an IntersectionObserver,
 * schedules visibility-driven renders with bounded concurrency, cancels offscreen
 * in-flight tasks, and LRU-evicts offscreen canvases beyond `maxRenderedPages`.
 */
export function observeContinuousPdfPages(
	options: ObserveContinuousPdfPagesOptions,
): IntersectionObserver {
	const {
		root,
		placeholders,
		preRenderedPlaceholders,
		renderedPlaceholders,
		isStale,
		renderPage,
		onEvict,
		maxRenderedPages = DEFAULT_MAX_CONTINUOUS_RENDERED_PAGES,
		maxConcurrentRenders = DEFAULT_MAX_CONCURRENT_CONTINUOUS_RENDERS,
		rootMargin = '600px 0px 600px 0px',
	} = options

	const visiblePlaceholders = new Set<HTMLElement>()
	const renderedOrder = new Set<HTMLElement>()
	const pendingQueue: HTMLElement[] = []
	const pendingSet = new Set<HTMLElement>()
	const activeTasks = new Map<HTMLElement, ContinuousRenderTask>()
	let activeWorkers = 0

	for (const el of preRenderedPlaceholders) {
		if (renderedPlaceholders.has(el)) {
			renderedOrder.add(el)
		}
	}

	const evictOffscreenPlaceholders = () => {
		if (renderedOrder.size <= maxRenderedPages) return
		for (const el of renderedOrder) {
			if (renderedOrder.size <= maxRenderedPages) break
			if (visiblePlaceholders.has(el)) continue
			renderedOrder.delete(el)
			renderedPlaceholders.delete(el)
			if (el.dataset.pageHeight) {
				el.style.minHeight = `${el.dataset.pageHeight}px`
			}
			if (el.dataset.pageWidth) {
				el.style.minWidth = `${el.dataset.pageWidth}px`
			}
			onEvict(el)
			el.replaceChildren()
		}
	}

	const pumpQueue = () => {
		while (activeWorkers < maxConcurrentRenders && pendingQueue.length > 0) {
			const targetEl = pendingQueue.shift()!
			pendingSet.delete(targetEl)
			if (isStale() || renderedPlaceholders.has(targetEl) || !visiblePlaceholders.has(targetEl)) {
				continue
			}
			const pageNum = Number(targetEl.dataset.pageNum)
			if (!pageNum) continue
			activeWorkers++
			void renderPage(pageNum, targetEl, (task) => {
				if (task) {
					activeTasks.set(targetEl, task)
				} else {
					activeTasks.delete(targetEl)
				}
			}).finally(() => {
				activeWorkers--
				if (isStale()) return
				if (renderedPlaceholders.has(targetEl)) {
					renderedOrder.delete(targetEl)
					renderedOrder.add(targetEl)
					evictOffscreenPlaceholders()
				}
				pumpQueue()
			})
		}
	}

	const observer = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				const targetEl = entry.target as HTMLElement
				if (entry.isIntersecting) {
					visiblePlaceholders.add(targetEl)
					if (renderedPlaceholders.has(targetEl)) {
						renderedOrder.delete(targetEl)
						renderedOrder.add(targetEl)
					} else if (!pendingSet.has(targetEl)) {
						pendingSet.add(targetEl)
						pendingQueue.push(targetEl)
						pumpQueue()
					}
				} else {
					visiblePlaceholders.delete(targetEl)
					if (pendingSet.has(targetEl)) {
						pendingSet.delete(targetEl)
						const idx = pendingQueue.indexOf(targetEl)
						if (idx >= 0) pendingQueue.splice(idx, 1)
					}
					const activeTask = activeTasks.get(targetEl)
					if (activeTask) {
						try {
							activeTask.cancel?.()
						} catch {
							// Ignore cancel errors
						}
					}
					evictOffscreenPlaceholders()
				}
			}
		},
		{ root, rootMargin },
	)

	for (const placeholder of placeholders) {
		observer.observe(placeholder)
	}

	return observer
}
